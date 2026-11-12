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
        // Settings opens first. The old billing_operations link still opens Invoices.
        $section = $selected !== '' ? $selected : ($page === 'billing_operations' ? 'invoices' : 'config');

        echo '<style>'
            . '.billing-admin-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px}'
            . '.billing-admin-tab{display:inline-block;padding:8px 16px;border:1px solid #3a5a7a;border-radius:6px;background:transparent;color:#cfe3f5;font-weight:600;text-decoration:none}'
            . '.billing-admin-tab:hover{background:#243a52}'
            . '.billing-admin-tab.is-active{background:#2f7fd1;border-color:#2f7fd1;color:#fff}'
            . '</style>';
        echo '<nav class="billing-admin-tabs" aria-label="Billing administration">';
        foreach ($tabs as $key => $tab) {
            $isActive = $key === $section;
            echo '<a class="billing-admin-tab' . ($isActive ? ' is-active' : '') . '"'
                . ($isActive ? ' aria-current="page"' : '')
                . ' href="home.php?m=billing&amp;p=billing&amp;s=' . rawurlencode($key) . '">'
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
