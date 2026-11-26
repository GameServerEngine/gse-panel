<?php
/**
 * Billing schedule and auto-renew.
 *
 * Schedule (Billing > Settings, global billing_config row):
 *   invoice_days  renewal invoice is created this many days before the due date (default 7)
 *   autopay_days  automatic charge starts this many days before the due date and is tried once a
 *                 day until it succeeds; never on or after the due date (default 3)
 *   delete_days   a suspended server is deleted this many days after the due date (default 7)
 * On the due date an unpaid server is suspended and its auto-renew is switched off.
 *
 * Auto-renew keeps, per server, only the provider's references (Stripe customer and saved payment
 * method). No card data is ever stored in the panel.
 */

if (!function_exists('billing_schedule_settings')) {
    function billing_schedule_settings(mysqli $db, string $prefix): array
    {
        $out = array('invoice_days' => 7, 'autopay_days' => 3, 'delete_days' => 7, 'grace_days' => 0);
        try {
            $res = $db->query("SELECT * FROM `{$prefix}billing_config` WHERE game_key IS NULL ORDER BY config_id ASC LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            if ($row) {
                $out['invoice_days'] = isset($row['invoice_days_before']) ? (int)$row['invoice_days_before'] : 7;
                $out['autopay_days'] = isset($row['autopay_days_before']) ? (int)$row['autopay_days_before'] : 3;
                $out['delete_days'] = isset($row['delete_after_expired_days']) ? (int)$row['delete_after_expired_days'] : 7;
                $out['grace_days'] = isset($row['grace_days']) ? (int)$row['grace_days'] : 0;
            }
        } catch (Throwable $e) {
            // defaults
        }
        return billing_schedule_clamp($out);
    }
}

if (!function_exists('billing_schedule_clamp')) {
    /** Keeps the schedule sensible: invoice 1-60 days, charge 1 day up to the invoice day, delete 0-365. */
    function billing_schedule_clamp(array $s): array
    {
        $s['invoice_days'] = max(1, min(60, (int)$s['invoice_days']));
        $s['autopay_days'] = max(1, min($s['invoice_days'], (int)$s['autopay_days']));
        $s['delete_days'] = max(0, min(365, (int)$s['delete_days']));
        $s['grace_days'] = max(0, min(60, (int)($s['grace_days'] ?? 0)));
        return $s;
    }
}

if (!function_exists('billing_schedule_save')) {
    /** Saves the schedule from the admin form. Returns array(ok, message). */
    function billing_schedule_save(mysqli $db, string $prefix, array $in): array
    {
        $s = billing_schedule_clamp(array(
            'invoice_days' => (int)($in['invoice_days_before'] ?? 7),
            'autopay_days' => (int)($in['autopay_days_before'] ?? 3),
            'delete_days' => (int)($in['delete_after_expired_days'] ?? 7),
            'grace_days' => 0,
        ));
        $id = billing_config_global_row_id($db, $prefix);
        if ($id <= 0) {
            return array(false, 'Could not create the billing settings row.');
        }
        $stmt = $db->prepare("UPDATE `{$prefix}billing_config` SET invoice_days_before = ?, autopay_days_before = ?, delete_after_expired_days = ?, grace_days = 0 WHERE config_id = ?");
        if (!$stmt) {
            return array(false, 'The schedule columns are missing. Run the billing module update (database version 17).');
        }
        $stmt->bind_param('iiii', $s['invoice_days'], $s['autopay_days'], $s['delete_days'], $id);
        $stmt->execute();
        $stmt->close();
        return array(true, 'Billing schedule saved: invoice ' . $s['invoice_days'] . ' days before the due date, automatic charge from '
            . $s['autopay_days'] . ' days before, delete ' . $s['delete_days'] . ' days after suspension.');
    }
}

if (!function_exists('billing_config_global_row_id')) {
    /** The global billing_config row, created with today's effective lifecycle values when missing. */
    function billing_config_global_row_id(mysqli $db, string $prefix): int
    {
        $res = $db->query("SELECT config_id FROM `{$prefix}billing_config` WHERE game_key IS NULL ORDER BY config_id ASC LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            return (int)$row['config_id'];
        }
        if (!$db->query("INSERT INTO `{$prefix}billing_config` (game_key, enabled, grace_days, delete_after_expired_days) VALUES (NULL, 1, 0, 7)")) {
            return 0;
        }
        return (int)$db->insert_id;
    }
}

if (!function_exists('billing_autopay_charge_date')) {
    /** The first day an automatic charge is tried for an invoice due on $dueDate. */
    function billing_autopay_charge_date(string $dueDate, int $autopayDays): string
    {
        $ts = strtotime($dueDate);
        return $ts === false ? '' : date('Y-m-d', strtotime('-' . max(1, $autopayDays) . ' days', $ts));
    }
}

if (!function_exists('billing_autopay_should_charge')) {
    /**
     * Whether the daily job charges today. $state: enabled (bool), paid (bool), due (date string),
     * attempted_today (bool). Never on or after the due date. Returns array(charge bool, reason).
     */
    function billing_autopay_should_charge(array $state, int $now, int $autopayDays): array
    {
        if (empty($state['enabled'])) {
            return array(false, 'auto-renew is off');
        }
        if (!empty($state['paid'])) {
            return array(false, 'already paid');
        }
        $due = strtotime((string)($state['due'] ?? ''));
        if ($due === false) {
            return array(false, 'no due date');
        }
        $today = strtotime(date('Y-m-d', $now));
        $dueDay = strtotime(date('Y-m-d', $due));
        if ($today >= $dueDay) {
            return array(false, 'due date reached: no more charging');
        }
        if ($today < strtotime('-' . max(1, $autopayDays) . ' days', $dueDay)) {
            return array(false, 'too early');
        }
        if (!empty($state['attempted_today'])) {
            return array(false, 'already tried today');
        }
        return array(true, 'charge');
    }
}

if (!function_exists('billing_autopay_get')) {
    function billing_autopay_get(mysqli $db, string $prefix, int $homeId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_autopay` WHERE home_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $homeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('billing_autopay_save')) {
    /** Turns auto-renew on for a server with the provider's references. */
    function billing_autopay_save(mysqli $db, string $prefix, int $homeId, int $userId, string $gateway, string $customerRef, string $methodRef): bool
    {
        $stmt = $db->prepare("INSERT INTO `{$prefix}billing_autopay` (home_id, user_id, gateway, customer_ref, method_ref, enabled, enabled_at, disabled_at, disabled_reason)
                              VALUES (?, ?, ?, ?, ?, 1, NOW(), NULL, '')
                              ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), gateway = VALUES(gateway), customer_ref = VALUES(customer_ref),
                                  method_ref = VALUES(method_ref), enabled = 1, enabled_at = NOW(), disabled_at = NULL, disabled_reason = ''");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iisss', $homeId, $userId, $gateway, $customerRef, $methodRef);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('billing_autopay_disable')) {
    /**
     * Turns auto-renew off and forgets the saved payment method (it is also removed at the provider).
     * Returns array(ok, note).
     */
    function billing_autopay_disable(mysqli $db, string $prefix, int $homeId, string $reason): array
    {
        $row = billing_autopay_get($db, $prefix, $homeId);
        if (!$row || (int)$row['enabled'] !== 1) {
            return array(true, 'auto-renew was already off');
        }
        $note = 'auto-renew turned off (' . $reason . ')';
        if ($row['gateway'] === 'stripe' && (string)$row['method_ref'] !== '' && function_exists('billing_stripe_client')) {
            $client = billing_stripe_client($db, $prefix);
            if ($client) {
                $client->request('POST', 'payment_methods/' . rawurlencode((string)$row['method_ref']) . '/detach');
            }
        }
        $reason = mb_substr($reason, 0, 120);
        $stmt = $db->prepare("UPDATE `{$prefix}billing_autopay` SET enabled = 0, method_ref = NULL, disabled_at = NOW(), disabled_reason = ? WHERE home_id = ?");
        $stmt->bind_param('si', $reason, $homeId);
        $stmt->execute();
        $stmt->close();
        return array(true, $note);
    }
}

if (!function_exists('billing_autopay_attempted_today')) {
    function billing_autopay_attempted_today(mysqli $db, string $prefix, int $invoiceId): bool
    {
        $today = date('Y-m-d');
        $stmt = $db->prepare("SELECT 1 FROM `{$prefix}billing_payment_attempts` WHERE invoice_id = ? AND attempt_date = ? LIMIT 1");
        $stmt->bind_param('is', $invoiceId, $today);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $found;
    }
}

if (!function_exists('billing_autopay_record_attempt')) {
    function billing_autopay_record_attempt(mysqli $db, string $prefix, int $invoiceId, int $homeId, string $gateway, string $status, string $reference, string $error): void
    {
        $today = date('Y-m-d');
        $reference = mb_substr($reference, 0, 80);
        $error = mb_substr($error, 0, 255);
        $stmt = $db->prepare("INSERT INTO `{$prefix}billing_payment_attempts` (invoice_id, home_id, gateway, attempt_date, status, reference, error) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisssss', $invoiceId, $homeId, $gateway, $today, $status, $reference, $error);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('billing_autopay_notice')) {
    /** The line shown on an invoice (and its email) when auto-renew is on. '' when it is off. */
    function billing_autopay_notice(?array $autopay, string $dueDate, int $autopayDays): string
    {
        if (!$autopay || (int)($autopay['enabled'] ?? 0) !== 1) {
            return '';
        }
        $method = ($autopay['gateway'] ?? '') === 'stripe' ? 'your saved card (Stripe)' : 'your saved payment method';
        $date = billing_autopay_charge_date($dueDate, $autopayDays);
        if ($date === '') {
            return '';
        }
        return 'Auto-renew is on: this invoice will be charged to ' . $method . ' on ' . date('F j, Y', strtotime($date))
            . '. You can turn auto-renew off in My Servers before then.';
    }
}

if (!function_exists('billing_renewal_amount')) {
    /**
     * What a renewal costs: the order's locked catalog price for the term when there is one, otherwise the
     * order's regular price (paid amount plus any first-payment discount), otherwise the legacy service price.
     */
    function billing_renewal_amount(mysqli $db, string $prefix, int $orderId, float $orderPrice, float $orderDiscount, float $servicePrice): float
    {
        if ($orderId > 0) {
            try {
                $stmt = $db->prepare("SELECT total_price FROM `{$prefix}catalog_order_price` WHERE order_id = ? ORDER BY lock_id DESC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $orderId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row && (float)$row['total_price'] > 0) {
                        return round((float)$row['total_price'], 2);
                    }
                }
            } catch (Throwable $e) {
                // no catalog lock table
            }
        }
        if ($orderPrice + $orderDiscount > 0) {
            return round($orderPrice + max(0.0, $orderDiscount), 2);
        }
        return round(max(0.0, $servicePrice), 2);
    }
}
