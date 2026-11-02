<?php
/**
 * Panel dispatcher for billing administrator pages.
 */
if (!function_exists('exec_gsp_module')) {
    function exec_gsp_module(): void
    {
        $page = (string)($_GET['p'] ?? '');
        $allowed = array(
            'admin_config.php',
            'admin_payments.php',
            'admin_orders.php',
            'admin_invoices.php',
            'admin_cancellations.php',
            'admin_coupons.php',
            'admin_locations.php',
        );

        if (!in_array($page, $allowed, true)) {
            echo '<div class="failure">Invalid billing admin page.</div>';
            return;
        }

        include __DIR__ . '/' . $page;

        if ($page === 'admin_orders.php' && function_exists('billing_admin_orders_exec')) {
            billing_admin_orders_exec();
        }
    }
}
