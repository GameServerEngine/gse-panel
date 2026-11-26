<?php
/**
 * Display state for a billing_invoices row.
 * Shared by My Account, the invoice list and the single-invoice page so they always agree.
 */
if (!function_exists('billing_invoice_state')) {
    function billing_invoice_state(array $row): string
    {
        $pay   = strtolower((string)($row['payment_status'] ?? ''));
        $state = strtolower((string)($row['status'] ?? ''));

        if ($pay === 'paid' || $state === 'paid') {
            return 'paid';
        }
        if ($pay === 'refunded') {
            return 'refunded';
        }
        if ($state === 'cancelled' || $pay === 'cancelled') {
            return 'cancelled';
        }
        if ($state === 'expired') {
            return 'expired';
        }
        if ($state === 'in-cart' || $state === 'in_cart') {
            return 'in-cart';
        }
        return 'pending';
    }
}
