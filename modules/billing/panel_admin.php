<?php
/**
 * Panel dispatcher for billing administrator pages.
 */
if (!function_exists('exec_gsp_module')) {
    function exec_gsp_module(): void
    {
        $page = (string)($_GET['p'] ?? '');
        $sections = array(
            'billing_setup.php' => array(
                'config' => 'admin_config.php',
                'locations' => 'admin_locations.php',
                'coupons' => 'admin_coupons.php',
            ),
            'billing_operations.php' => array(
                'invoices' => 'admin_invoices.php',
                'orders' => 'admin_orders.php',
                'payments' => 'admin_payments.php',
                'cancellations' => 'admin_cancellations.php',
            ),
        );

        if (!isset($sections[$page])) {
            echo '<div class="failure">Invalid billing admin page.</div>';
            return;
        }

        $selected = $_GET['s'] ?? '';
        $default = $page === 'billing_setup.php' ? 'config' : 'invoices';
        $section = $selected === '' ? $default : (string)$selected;
        echo '<nav class="billing-admin-tabs" aria-label="Billing administration">';
        foreach ($sections[$page] as $key => $file) {
            $label = ucwords(str_replace('_', ' ', $key));
            $active = $key === $section ? ' aria-current="page" class="active"' : '';
            echo '<a' . $active . ' href="home.php?m=billing&amp;p=' . rawurlencode($page) . '&amp;s=' . rawurlencode($key) . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</nav>';

        if (!isset($sections[$page][$section])) {
            echo '<div class="failure">Unknown billing admin section.</div>';
            return;
        }

        $file = $sections[$page][$section];
        include __DIR__ . '/' . $file;

        if ($file === 'admin_orders.php' && function_exists('billing_admin_orders_exec')) {
            billing_admin_orders_exec();
        }
    }
}
