<?php
/**
 * Standalone Payment Processing Helper (module-local)
 *
 * This file is intentionally self-contained and does NOT include or rely on
 * panel-wide files. Configure the DB connection information below (edit
 * the $DB_* variables) or provide a module-local `config.local.php` that defines
 * the same variables.
 *
 * Usage:
 *   require_once __DIR__ . '/payment_processor.php';
 *   process_payment_record($record);
 */

// Load panel config first, falling back to a module-local copy if needed.
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/../classes/BillingRepository.php';
require_once __DIR__ . '/../classes/BillingLifecycleService.php';

// Variables from config.inc.php (helps IDEs understand scope)
/** @var string $db_host Database host */
/** @var string $db_user Database user */
/** @var string $db_pass Database password */
/** @var string $db_name Database name */
/** @var string $table_prefix Table prefix for database tables */

// Normalize table prefix variable: many files use $table_prefix (lowercase)
if (!isset($TABLE_PREFIX) && isset($table_prefix)) {
    $TABLE_PREFIX = $table_prefix;
}

/**
 * Create and return a mysqli connection using the site's config values.
 * Returns mysqli or false on failure.
 */
function payment_db_connect() {
    global $db_host, $db_user, $db_pass, $db_name, $db_port;

    if (empty($db_host) || empty($db_user) || empty($db_name)) {
        error_log('[payment_processor] DB globals not available from includes/config.inc.php');
        return false;
    }

    $port = intval($db_port) ?: 3306;
    $db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $port);
    if (!$db) {
        error_log('[payment_processor] mysqli_connect failed: ' . mysqli_connect_error());
        return false;
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

/**
 * Process a payment record. Self-contained: uses module-local DB config.
 * @param array $record { invoice, custom, resource_id, amount }
 * @return bool true if at least one invoice processed
 */
function process_payment_record(array $record) {
    global $TABLE_PREFIX;

    // Normalize inputs
    $invoice_ref = $record['invoice'] ?? null;     // e.g. PayPal invoice id or custom label
    $custom = $record['custom'] ?? null;           // our custom_id (often invoice_id)
    $txid = $record['resource_id'] ?? ($record['txid'] ?? null);
    $amount = isset($record['amount']) ? floatval($record['amount']) : null;

    $db = payment_db_connect();
    if (!$db) return false;

    $now = date('Y-m-d H:i:s');

    $invoices_to_process = [];

    // 1) If custom is a single numeric invoice id, try to fetch that exact invoice
    if ($custom && ctype_digit((string)$custom)) {
        $invoice_id = intval($custom);
        $sql = "SELECT * FROM `" . $TABLE_PREFIX . "billing_invoices`
                WHERE invoice_id = ?
                  AND (status IS NULL OR status = '' OR status = 'due')
                  AND (payment_status IS NULL OR payment_status IN ('unpaid','due'))
                LIMIT 1";
        if ($stmt = mysqli_prepare($db, $sql)) {
            mysqli_stmt_bind_param($stmt, 'i', $invoice_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && $row = mysqli_fetch_assoc($res)) $invoices_to_process[] = $row;
            mysqli_stmt_close($stmt);
        }
    }

    // 2) If we didn't find by custom and invoice_ref is provided, match by description containing invoice_ref
    if (empty($invoices_to_process) && $invoice_ref) {
        $like = '%' . mysqli_real_escape_string($db, $invoice_ref) . '%';
        $sql = "SELECT * FROM `" . $TABLE_PREFIX . "billing_invoices`
                WHERE (status IS NULL OR status = '' OR status = 'due')
                  AND (payment_status IS NULL OR payment_status IN ('unpaid','due'))
                  AND description LIKE ?";
        if ($stmt = mysqli_prepare($db, $sql)) {
            mysqli_stmt_bind_param($stmt, 's', $like);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $invoices_to_process[] = $row;
            }
            mysqli_stmt_close($stmt);
        }
    }

    // 3) As a fallback, match unpaid invoices by amount (exact match). Useful for simple carts.
    if (empty($invoices_to_process) && $amount !== null) {
        $sql = "SELECT * FROM `" . $TABLE_PREFIX . "billing_invoices`
                WHERE (status IS NULL OR status = '' OR status = 'due')
                  AND (payment_status IS NULL OR payment_status IN ('unpaid','due'))
                  AND amount = ?";
        if ($stmt = mysqli_prepare($db, $sql)) {
            mysqli_stmt_bind_param($stmt, 'd', $amount);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $invoices_to_process[] = $row;
            }
            mysqli_stmt_close($stmt);
        }
    }

    $processed_count = 0;
    $repo = new BillingRepository($db, $TABLE_PREFIX);
    $lifecycle = new BillingLifecycleService($repo);
    $coupon_ids_applied = [];

    foreach ((array)$invoices_to_process as $inv) {
        $invoice_id = intval($inv['invoice_id']);

        $result = $lifecycle->processPaidInvoice($invoice_id, [
            'transaction_external_id' => (string)$txid,
            'payment_method' => 'paypal',
            'amount' => (float)($amount ?? $inv['amount'] ?? $inv['total_due'] ?? 0),
            'currency' => (string)($inv['currency'] ?? 'USD'),
            'paid_at' => $now,
            'raw_response' => $record,
        ]);

        if (!empty($result['success'])) {
            $coupon_id = intval($inv['coupon_id'] ?? 0);
            if ($coupon_id > 0 && !empty($result['became_paid'])) {
                $coupon_ids_applied[$coupon_id] = true;
            }
            $processed_count++;
        } else {
            error_log('[payment_processor] Lifecycle failed for invoice ' . $invoice_id . ': ' . ($result['error'] ?? 'unknown'));
        }
    }

    foreach (array_keys($coupon_ids_applied) as $coupon_id) {
        $upd_coupon = "UPDATE `" . $TABLE_PREFIX . "billing_coupons` SET current_uses = current_uses + 1 WHERE coupon_id = ?";
        if ($stmt = mysqli_prepare($db, $upd_coupon)) {
            mysqli_stmt_bind_param($stmt, 'i', $coupon_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    mysqli_close($db);

    if ($processed_count > 0) {
        error_log('[payment_processor] Processed ' . $processed_count . ' invoice(s)');
        return true;
    }

    error_log('[payment_processor] No matching invoices processed for record: ' . json_encode($record));
    return false;
}

?>
