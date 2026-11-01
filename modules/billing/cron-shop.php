<?php
/*
 *
 * GSP / GSP - GameServer Panel / Game Server Panel
 * Copyright (C) 2008 - 2017 The GSP Development Team
 *
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *
 * BILLING CRON - Three-Status Lifecycle
 * ========================================
 *
 * Operates on server_homes.billing_status (separate from game-server runtime state).
 *
 * Status values:
 *   Active   - Server is current; no unpaid renewal invoice.
 *   Invoiced - Renewal invoice generated; payment due.
 *   Expired  - Invoice not paid by due date; server awaiting deletion.
 *
 * Steps run each night:
 *   A. Active   -> Invoiced  : next_invoice_date has arrived -> create {prefix}invoices record.
 *   B. Invoiced -> Expired   : server_expiration_date passed and invoice unpaid.
 *   C. Expired  -> Deleted   : past delete_after_expired_days grace window -> remove server.
 *   D. Paid invoices (safety net): set server and invoice back to Active.
 *
 * Prerequisites (run once):
 *   sql/update_billing_status_active_invoiced_expired.sql
 */

chdir(realpath(dirname(__FILE__))); /* Change to the billing module directory */
chdir("../.."); /* Step back to the GSP/GSP web root */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define("CONFIG_FILE", "includes/config.inc.php");
require_once("includes/functions.php");
require_once("includes/helpers.php");
require_once("includes/html_functions.php");
require_once("modules/config_games/server_config_parser.php");
require_once("includes/lib_remote.php");
require_once("modules/billing/classes/BillingRepository.php");
require_once("modules/billing/classes/BillingLifecycleService.php");
require_once("modules/gamemanager/home_handling_functions.php");
require_once(CONFIG_FILE);

// Connect using the panel's DB helper (provides $db with logger(), resultQuery(), etc.)
$db = createDatabaseConnection(
    $db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix,
    isset($db_port) ? $db_port : null
);

$panel_settings = $db->getSettings();
if (!empty($panel_settings['time_zone'])) {
    date_default_timezone_set($panel_settings['time_zone']);
}

$billing_mysqli = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
$billing_lifecycle = null;
if ($billing_mysqli) {
    mysqli_set_charset($billing_mysqli, 'utf8mb4');
    $billing_lifecycle = new BillingLifecycleService(new BillingRepository($billing_mysqli, $table_prefix));
} else {
    $db->logger("BILLING-CRON: WARNING lifecycle service database connection failed: " . mysqli_connect_error());
}

$rundate = date('Y-m-d H:i:s');
$db->logger("BILLING-CRON: ===== Lifecycle automation started at {$rundate} =====");

// ----------------------------------------------------------------
// Load global billing config (grace_days, delete_after_expired_days)
// Falls back to safe defaults when {prefix}billing_config is empty.
// ----------------------------------------------------------------
$cfg_rows = $db->resultQuery(
    "SELECT * FROM {$table_prefix}billing_config WHERE game_key IS NULL AND enabled = 1 ORDER BY config_id ASC LIMIT 1"
);
$global_cfg           = is_array($cfg_rows) && !empty($cfg_rows) ? $cfg_rows[0] : [];
$grace_days           = intval($global_cfg['grace_days']                ?? 0);
$delete_after_days    = intval($global_cfg['delete_after_expired_days'] ?? 7);
$default_rate_type    = $global_cfg['rate_type']                        ?? 'monthly';

$db->logger("BILLING-CRON: Config => grace_days={$grace_days}, delete_after={$delete_after_days}, rate={$default_rate_type}");

// Suspend immediately at expiry. The existing panel stop path is used so all
// lifecycle/RPC handling remains identical to a user-initiated stop.
$db->logger("BILLING-CRON: --- Step 0: suspend unpaid homes at expiry ---");
$to_suspend = $db->resultQuery("SELECT sh.home_id FROM {$table_prefix}server_homes sh
    WHERE sh.billing_enabled = 1 AND sh.billing_status = 'Invoiced'
      AND sh.server_expiration_date IS NOT NULL AND sh.server_expiration_date < NOW()
      AND COALESCE(sh.cancel_at_expiry, 0) = 0 AND COALESCE(sh.billing_suspended, 0) = 0
      AND EXISTS (SELECT 1 FROM {$table_prefix}billing_invoices inv WHERE inv.invoice_id = sh.last_invoice_id
                  AND inv.billing_status = 'Invoiced' AND inv.paid_date IS NULL)");
foreach ((array)$to_suspend as $srv) {
    $home_id = (int)$srv['home_id'];
    $stopped = true;
    $addresses = $db->getHomeIpPorts($home_id);
    if (empty($addresses)) $stopped = false;
    foreach ((array)$addresses as $addr) {
        if (!exec_operation('stop', $home_id, (int)($addr['force_mod_id'] ?? 0), $addr['ip'], $addr['port'], true)) $stopped = false;
    }
    if ($stopped && $db->query("UPDATE {$table_prefix}server_homes SET billing_suspended = 1 WHERE home_id = {$home_id}")) {
        $db->logger("BILLING-CRON: Step 0 - SUSPENDED home {$home_id}");
    } else {
        $db->logger("BILLING-CRON: Step 0 - FAILED home {$home_id}");
    }
}

// Retry starts for homes that were paid after suspension but whose immediate
// restart did not succeed. The suspension flag remains set until all starts pass.
$db->logger("BILLING-CRON: --- Step 0c: retry billing restart ---");
$retry_now = time();
$to_retry = $db->resultQuery("SELECT sh.home_id, sh.billing_enabled, sh.billing_suspended, sh.server_expiration_date
    FROM {$table_prefix}server_homes sh
    WHERE sh.billing_suspended = 1 AND sh.billing_enabled = 1
      AND sh.server_expiration_date >= NOW()");
foreach ((array)$to_retry as $srv) {
    if (!BillingLifecycleService::shouldRetryBillingRestart($srv, $retry_now)) continue;
    $home_id = (int)$srv['home_id'];
    $started = true;
    $addresses = $db->getHomeIpPorts($home_id);
    if (empty($addresses)) $started = false;
    foreach ((array)$addresses as $addr) {
        if (!exec_operation('start', $home_id, (int)($addr['force_mod_id'] ?? 0), $addr['ip'], $addr['port'], true)) $started = false;
    }
    if ($started && $db->query("UPDATE {$table_prefix}server_homes SET billing_suspended = 0 WHERE home_id = {$home_id}")) {
        $db->logger("BILLING-CRON: Step 0c - RESTARTED home {$home_id}");
    } else {
        $db->logger("BILLING-CRON: Step 0c - restart failed home {$home_id}");
    }
}

// One reminder per home and expiry date.
$db->logger("BILLING-CRON: --- Step 0b: one-week expiry reminders ---");
$reminders = $db->resultQuery("SELECT sh.home_id, sh.home_name, sh.server_expiration_date, u.users_email
    FROM {$table_prefix}server_homes sh LEFT JOIN {$table_prefix}users u ON u.user_id = sh.user_id_main
    WHERE sh.billing_enabled = 1 AND sh.billing_status = 'Active' AND COALESCE(sh.cancel_at_expiry, 0) = 0
      AND DATE(sh.server_expiration_date) = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND (sh.billing_reminder_sent_for IS NULL OR sh.billing_reminder_sent_for <> DATE(sh.server_expiration_date))");
foreach ((array)$reminders as $srv) {
    $home_id = (int)$srv['home_id']; $expiry = date('Y-m-d', strtotime($srv['server_expiration_date']));
    if (!empty($srv['users_email']) && mymail($srv['users_email'], 'Server expiry reminder - ' . ($srv['home_name'] ?? $home_id),
        "Your server '{$srv['home_name']}' expires on " . date('F j, Y', strtotime($expiry)) . ". Please renew to keep it active.", $db->getSettings())) {
        $safe = $db->realEscapeSingle($expiry);
        $db->query("UPDATE {$table_prefix}server_homes SET billing_reminder_sent_for = '{$safe}' WHERE home_id = {$home_id}");
        $db->logger("BILLING-CRON: Step 0b - reminder sent for home {$home_id} ({$expiry})");
    }
}

// ======================================================================
// STEP A - Active -> Invoiced
//   Find billing-enabled servers whose next_invoice_date has arrived
//   and that do not already have an open 'Invoiced' renewal invoice.
// ======================================================================
$db->logger("BILLING-CRON: --- Step A: Active -> Invoiced ---");

$due_for_invoice = $db->resultQuery("
    SELECT sh.home_id, sh.home_name, sh.user_id_main AS user_id,
           sh.next_invoice_date, sh.server_expiration_date,
           bo.price, bo.invoice_duration, bo.qty, bo.order_id, bo.service_id,
           COALESCE(bs.price_monthly, 0) AS svc_price_monthly,
           COALESCE(bs.price_year, 0) AS svc_price_year,
           u.users_email,
           CONCAT(COALESCE(u.users_fname,''), ' ', COALESCE(u.users_lname,'')) AS customer_name
    FROM {$table_prefix}server_homes sh
    LEFT JOIN {$table_prefix}users u ON u.user_id = sh.user_id_main
    LEFT JOIN {$table_prefix}billing_orders bo
           ON bo.home_id = sh.home_id AND bo.status = 'Active'
    LEFT JOIN {$table_prefix}billing_services bs ON bs.service_id = bo.service_id
    WHERE sh.billing_enabled = 1
      AND sh.billing_status  = 'Active'
      AND sh.next_invoice_date IS NOT NULL
      AND sh.next_invoice_date <= NOW()
      AND COALESCE(sh.cancel_at_expiry, 0) = 0
      AND bo.order_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM {$table_prefix}billing_invoices inv
          WHERE inv.home_id = sh.home_id AND inv.billing_status = 'Invoiced'
      )
    ORDER BY sh.home_id ASC
");

if (is_array($due_for_invoice)) {
    foreach ($due_for_invoice as $srv) {
        $home_id   = intval($srv['home_id']);
        $user_id   = intval($srv['user_id']);
        $home_name = $srv['home_name'] ?? 'Server #' . $home_id;
        $qty       = max(1, intval($srv['qty'] ?? 1));

        // Normalise rate_type to the ENUM values used in {prefix}invoices
        $raw_rate = strtolower($srv['invoice_duration'] ?? $default_rate_type);
        $rate_map = ['day' => 'daily', 'month' => 'monthly', 'year' => 'yearly'];
        $rate_type = $rate_map[$raw_rate] ?? $raw_rate;

        // price_per_player is legacy configuration and is not used for renewals.
        $price_per_player = 0.0;
        $player_slots     = max(0, intval($srv['qty'] ?? 0));
        $subtotal = ($raw_rate === 'year') ? (float)($srv['svc_price_year'] ?? 0) : (float)($srv['svc_price_monthly'] ?? 0);
        $total_due = $subtotal;

        // Renewal is due on the current service expiration date.
        $period_map = ['daily' => '+1 day', 'monthly' => '+1 month', 'yearly' => '+1 year'];
        $due_date_ts = strtotime((string)($srv['server_expiration_date'] ?? $srv['next_invoice_date'] ?? $rundate));
        $due_date    = date('Y-m-d H:i:s', $due_date_ts);

        // Guard: skip if an invoice for this exact period already exists
        $exists = $db->resultQuery("
            SELECT invoice_id FROM {$table_prefix}billing_invoices
            WHERE home_id = {$home_id}
              AND due_date = '" . $db->realEscapeSingle($due_date) . "'
            LIMIT 1
        ");
        if (is_array($exists) && !empty($exists)) {
            $db->logger("BILLING-CRON: Step A - SKIP home {$home_id}: invoice for this period already exists");
            continue;
        }

        if (!$billing_lifecycle) {
            $db->logger("BILLING-CRON: Step A - FAIL home {$home_id}: lifecycle service unavailable");
            continue;
        }
        $invoiceResult = $billing_lifecycle->createRenewalInvoice([
            'home_id' => $home_id,
            'home_name' => $home_name,
            'user_id' => $user_id,
            'next_invoice_date' => $srv['server_expiration_date'] ?? $srv['next_invoice_date'] ?? $rundate,
        ], [
            'order_id' => intval($srv['order_id'] ?? 0),
            'service_id' => intval($srv['service_id'] ?? 0),
            'description' => "Renewal: {$home_name}",
            'invoice_duration' => $raw_rate,
            'rate_type' => $rate_type,
            'rate_per_player' => $price_per_player,
            'players' => $player_slots,
            'qty' => $qty,
            'subtotal' => $subtotal,
            'total_due' => $total_due,
        ]);
        if (empty($invoiceResult['success'])) {
            $db->logger("BILLING-CRON: Step A - FAIL home {$home_id}: " . ($invoiceResult['error'] ?? 'unknown'));
            continue;
        }
        $new_invoice_id = intval($invoiceResult['invoice_id']);
        $due_date = $invoiceResult['period_end'] ?? $due_date;

        $db->logger("BILLING-CRON: Step A - INVOICED home {$home_id} (invoice #{$new_invoice_id}, due {$due_date})");

        // Send renewal notice
        if (!empty($srv['users_email'])) {
            $settings = $db->getSettings();
            $subject  = "Renewal Invoice for {$home_name} - " . ($panel_settings['panel_name'] ?? 'Game Server Panel');
            $message  = "Your server '{$home_name}' (ID: {$home_id}) has a renewal invoice due on "
                      . date('F j, Y', $due_date_ts) . "."
                      . "<br><br>Amount Due: \$" . number_format($total_due, 2)
                      . "<br>Due Date: " . date('F j, Y', $due_date_ts)
                      . "<br><br>Please log in to pay your invoice and keep your server active."
                      . "<br><br>Thank you!";
            if (!mymail($srv['users_email'], $subject, $message, $settings)) {
                $db->logger("BILLING-CRON: Step A - Email FAILED for home {$home_id}");
            }
        }
    }
}

// ======================================================================
// STEP B - Invoiced -> Expired
//   Servers whose expiration date has passed and whose last invoice
//   is still unpaid.
// ======================================================================
$db->logger("BILLING-CRON: --- Step B: Invoiced -> Expired (grace_days={$grace_days}) ---");

$past_due = $db->resultQuery("
    SELECT sh.home_id, sh.home_name, sh.user_id_main AS user_id,
           sh.last_invoice_id, sh.server_expiration_date,
           u.users_email
    FROM {$table_prefix}server_homes sh
    LEFT JOIN {$table_prefix}users u ON u.user_id = sh.user_id_main
    WHERE sh.billing_enabled      = 1
      AND sh.billing_status       = 'Invoiced'
      AND sh.server_expiration_date IS NOT NULL
      AND DATE(sh.server_expiration_date) < DATE_SUB(CURDATE(), INTERVAL {$grace_days} DAY)
      AND EXISTS (SELECT 1 FROM {$table_prefix}billing_orders bo_g WHERE bo_g.home_id = sh.home_id)
      AND (
          sh.last_invoice_id IS NULL
          OR EXISTS (
              SELECT 1 FROM {$table_prefix}billing_invoices inv
              WHERE inv.invoice_id    = sh.last_invoice_id
                AND inv.billing_status = 'Invoiced'
                AND inv.paid_date     IS NULL
          )
      )
    ORDER BY sh.home_id ASC
");

if (is_array($past_due)) {
    foreach ($past_due as $srv) {
        $home_id         = intval($srv['home_id']);
        $last_invoice_id = intval($srv['last_invoice_id'] ?? 0);

        if (!$billing_lifecycle) {
            $db->logger("BILLING-CRON: Step B - FAIL home {$home_id}: lifecycle service unavailable");
            continue;
        }
        $expireResult = $billing_lifecycle->expireServer($home_id, $last_invoice_id);
        if (empty($expireResult['success'])) {
            $db->logger("BILLING-CRON: Step B - FAIL home {$home_id}: " . ($expireResult['error'] ?? 'unknown'));
            continue;
        }

        $db->logger("BILLING-CRON: Step B - EXPIRED home {$home_id}");

        // Notify user
        if (!empty($srv['users_email'])) {
            $settings  = $db->getSettings();
            $home_name = $srv['home_name'] ?? 'Server #' . $home_id;
            $subject   = "Server Expired - {$home_name} - " . ($panel_settings['panel_name'] ?? 'Game Server Panel');
            $message   = "Your server '{$home_name}' (ID: {$home_id}) has expired due to non-payment."
                       . "<br><br>The server will be permanently deleted in {$delete_after_days} day(s) if payment is not received."
                       . "<br><br>Please log in and pay your outstanding invoice to restore service."
                       . "<br><br>Thank you.";
            if (!mymail($srv['users_email'], $subject, $message, $settings)) {
                $db->logger("BILLING-CRON: Step B - Email FAILED for home {$home_id}");
            }
        }
    }
}

// ======================================================================
// STEP C - Expired -> Deleted
//   Servers that have been Expired longer than delete_after_expired_days.
// ======================================================================
$db->logger("BILLING-CRON: --- Step C: Expired -> Deleted (window={$delete_after_days}d) ---");

$to_delete = $db->resultQuery("
    SELECT sh.home_id, sh.home_name, sh.user_id_main AS user_id,
           sh.server_expiration_date,
           u.users_email
    FROM {$table_prefix}server_homes sh
    LEFT JOIN {$table_prefix}users u ON u.user_id = sh.user_id_main
    WHERE sh.billing_enabled       = 1
      AND sh.billing_status        = 'Expired'
      AND sh.server_expiration_date IS NOT NULL
      AND DATE(sh.server_expiration_date) < DATE_SUB(CURDATE(), INTERVAL {$delete_after_days} DAY)
      AND EXISTS (SELECT 1 FROM {$table_prefix}billing_orders bo_g WHERE bo_g.home_id = sh.home_id)
    ORDER BY sh.home_id ASC
");

if (is_array($to_delete)) {
    foreach ($to_delete as $srv) {
        $home_id   = intval($srv['home_id']);
        $user_id   = intval($srv['user_id']);
        $home_name = $srv['home_name'] ?? 'Server #' . $home_id;

        if (!$billing_lifecycle) {
            $db->logger("BILLING-CRON: Step C - SKIP home {$home_id}: lifecycle service unavailable");
            continue;
        }
        $deletePrep = $billing_lifecycle->prepareDeletion($home_id);
        if (empty($deletePrep['success'])) {
            $db->logger("BILLING-CRON: Step C - SKIP home {$home_id}: " . ($deletePrep['error'] ?? 'delete preparation failed'));
            continue;
        }

        // Fetch home info for remote deletion
        $home_info = $db->getGameHomeWithoutMods($home_id);
        if ($home_info) {
            $server_info = $db->getRemoteServerById($home_info['remote_server_id']);
            if ($server_info) {
                $remote = new GSPRemoteLibrary(
                    $server_info['agent_ip'],
                    $server_info['agent_port'],
                    $server_info['encryption_key'],
                    $server_info['timeout']
                );

                // Stop the running server process
                $server_xml   = read_server_config(SERVER_CONFIG_LOCATION . "/" . $home_info['home_cfg_file'], gsp_os_for_install_row($home_info));
                $control_type = isset($server_xml->control_protocol_type)
                              ? (string)$server_xml->control_protocol_type : "";
                $addresses    = $db->getHomeIpPorts($home_id);
                foreach ((array)$addresses as $addr) {
                    $remote->remote_stop_server(
                        $home_id, $addr['ip'], $addr['port'],
                        $server_xml->control_protocol,
                        $home_info['control_password'],
                        $control_type,
                        $home_info['home_path']
                    );
                }

                // Disable FTP
                $ftp_login = !empty($home_info['ftp_login']) ? $home_info['ftp_login'] : $home_id;
                $remote->ftp_mgr("userdel", $ftp_login);
                $db->changeFtpStatus('disabled', $home_id);

                // Unassign from user
                $db->unassignHomeFrom("user", $user_id, $home_id);

                // Delete home record from panel DB
                $db->deleteGameHome($home_id);

                // Remove server files on remote agent
                $remote->remove_home($home_info['home_path']);

                // Drop any per-server database/user accounts
                @$db->query("DROP USER 'user_{$home_id}'@'%'");
                @$db->query("DROP USER 'user_{$home_id}'@'localhost'");
                @$db->query("DROP USER 'server_{$home_id}'@'%'");
                @$db->query("DROP USER 'server_{$home_id}'@'localhost'");
                @$db->query("DROP DATABASE IF EXISTS user_{$home_id}");
                @$db->query("DROP DATABASE IF EXISTS server_{$home_id}");
            } else {
                $db->logger("BILLING-CRON: Step C - WARNING: no remote server info for home {$home_id}; removing panel record only");
                $db->deleteGameHome($home_id);
            }
        } else {
            $db->logger("BILLING-CRON: Step C - WARNING: home {$home_id} not found in panel DB (already removed)");
        }

        $db->logger("BILLING-CRON: Step C - DELETED home {$home_id}");

        // Notify user
        if (!empty($srv['users_email'])) {
            $settings = $db->getSettings();
            $subject  = "Server Permanently Deleted - {$home_name} - " . ($panel_settings['panel_name'] ?? 'Game Server Panel');
            $message  = "Your server '{$home_name}' (ID: {$home_id}) has been permanently deleted."
                      . "<br><br>The server expired and was removed after the grace period."
                      . "<br><br>If this was an error, contact us immediately - we may be able to restore from backup."
                      . "<br><br>Thank you for being a customer. We hope to serve you again.";
            if (!mymail($srv['users_email'], $subject, $message, $settings)) {
                $db->logger("BILLING-CRON: Step C - Email FAILED for home {$home_id}");
            }
        }
    }
}

// ======================================================================
// STEP D - Paid invoice safety net
//   If a payment was recorded on a {prefix}invoices row but the
//   server_home was not updated (e.g. race condition at capture time),
//   correct it here so the server is restored to Active.
// ======================================================================
$db->logger("BILLING-CRON: --- Step D: Paid invoice safety-net ---");

$paid_invoices = $db->resultQuery("
    SELECT inv.invoice_id, inv.home_id, inv.rate_type,
           sh.billing_status
    FROM {$table_prefix}billing_invoices inv
    INNER JOIN {$table_prefix}server_homes sh ON sh.home_id = inv.home_id
    WHERE inv.billing_status  = 'Invoiced'
      AND sh.billing_status   = 'Invoiced'
      AND (inv.paid_date IS NOT NULL OR inv.payment_txid IS NOT NULL)
    ORDER BY inv.invoice_id ASC
");

if (is_array($paid_invoices)) {
    foreach ($paid_invoices as $inv) {
        $home_id    = intval($inv['home_id']);
        $invoice_id = intval($inv['invoice_id']);
        $rate_type  = $inv['rate_type'] ?? 'monthly';

        if (!$billing_lifecycle) {
            $db->logger("BILLING-CRON: Step D - FAIL home {$home_id}: lifecycle service unavailable");
            continue;
        }
        $invoiceRow = (new BillingRepository($billing_mysqli, $table_prefix))->getInvoice($invoice_id);
        $restoreResult = $billing_lifecycle->processPaidInvoice($invoice_id, [
            'transaction_external_id' => (string)($invoiceRow['payment_txid'] ?? ''),
            'payment_method' => (string)($invoiceRow['payment_method'] ?? 'unknown'),
            'amount' => (float)($invoiceRow['total_due'] ?? $invoiceRow['amount'] ?? 0),
            'currency' => (string)($invoiceRow['currency'] ?? 'USD'),
            'paid_at' => (string)($invoiceRow['paid_date'] ?? date('Y-m-d H:i:s')),
            'raw_response' => ['source' => 'cron-shop.php safety-net'],
        ], $invoiceRow ?: []);
        if (empty($restoreResult['success'])) {
            $db->logger("BILLING-CRON: Step D - FAIL invoice {$invoice_id}: " . ($restoreResult['error'] ?? 'unknown'));
            continue;
        }

        $db->logger("BILLING-CRON: Step D - RESTORED home {$home_id} to Active via paid invoice #{$invoice_id}");
    }
}

$db->logger("BILLING-CRON: ===== Lifecycle automation completed at " . date('Y-m-d H:i:s') . " =====");
if ($billing_mysqli) {
    mysqli_close($billing_mysqli);
}
