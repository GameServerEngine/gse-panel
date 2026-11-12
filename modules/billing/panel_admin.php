<?php
/**
 * Panel dispatcher for the Billing administration page (Administration > Billing).
 *
 * One page with a tab for each billing admin screen:
 *   Invoices, Orders, Payments, Cancellations, Game Catalog, Locations, Coupons, Settings.
 * The tab is chosen with &s=<key>. The older page keys billing_setup and billing_operations
 * still open this page (on Settings and Invoices) so saved links keep working.
 */
if (!function_exists('exec_module')) {
    function exec_module(): void
    {
        $tabs = array(
            'invoices' => array('Invoices', 'admin_invoices.php'),
            'orders' => array('Orders', 'admin_orders.php'),
            'payments' => array('Payments', 'admin_payments.php'),
            'cancellations' => array('Cancellations', 'admin_cancellations.php'),
            'game_catalog' => array('Game Catalog', 'admin_catalog.php'),
            'locations' => array('Locations', 'admin_locations.php'),
            'coupons' => array('Coupons', 'admin_coupons.php'),
            'config' => array('Settings', 'admin_config.php'),
        );

        $page = (string)($_GET['p'] ?? '');
        if (!in_array($page, array('billing', 'billing_setup', 'billing_operations'), true)) {
            echo '<div class="failure">Invalid billing admin page.</div>';
            return;
        }

        $selected = (string)($_GET['s'] ?? '');
        $section = $selected !== '' ? $selected : ($page === 'billing_setup' ? 'config' : 'invoices');

        echo '<nav class="billing-admin-tabs" aria-label="Billing administration">';
        foreach ($tabs as $key => $tab) {
            $active = $key === $section ? ' aria-current="page" class="active"' : '';
            echo '<a' . $active . ' href="home.php?m=billing&amp;p=billing&amp;s=' . rawurlencode($key) . '">'
                . htmlspecialchars($tab[0], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</nav>';

        if (!isset($tabs[$section])) {
            echo '<div class="failure">Unknown billing admin section.</div>';
            return;
        }

        $file = $tabs[$section][1];
        include __DIR__ . '/' . $file;

        if ($file === 'admin_orders.php' && function_exists('billing_admin_orders_exec')) {
            billing_admin_orders_exec();
        }
        if ($file === 'admin_catalog.php') {
            billing_admin_catalog_render();
        }
    }
}
