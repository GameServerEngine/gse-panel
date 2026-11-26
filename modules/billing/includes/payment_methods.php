<?php
// Payment methods an admin can switch on or off (Billing > Settings): 'paypal' and 'stripe'.
// The choice is kept in the panel settings table as billing_<method>_enabled = 1 or 0.
// A method that was never saved counts as on, so sites that already take payments keep working.

if (!function_exists('billing_payment_method_enabled')) {
    function billing_payment_method_enabled($db, string $prefix, string $method): bool
    {
        if (!($db instanceof mysqli)) {
            return true;
        }
        $key = $db->real_escape_string('billing_' . $method . '_enabled');
        try {
            $res = $db->query("SELECT value FROM `{$prefix}settings` WHERE setting = '{$key}' LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
        } catch (Throwable $e) {
            return true;
        }
        return $row === null || (string)$row['value'] !== '0';
    }
}

if (!function_exists('billing_payment_method_set')) {
    function billing_payment_method_set(mysqli $db, string $prefix, string $method, bool $on): void
    {
        $key = $db->real_escape_string('billing_' . $method . '_enabled');
        $value = $on ? '1' : '0';
        $db->query("INSERT INTO `{$prefix}settings` (setting, value) VALUES ('{$key}', '{$value}') ON DUPLICATE KEY UPDATE value = '{$value}'");
    }
}
