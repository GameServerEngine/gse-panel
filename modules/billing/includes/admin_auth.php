<?php
// Admin authorization include — include early (before output) on admin pages.
// The admin pages only run inside the control panel.
require_once(__DIR__ . '/session_bridge.php');
require_once(dirname(__DIR__) . '/billing_bootstrap.php');

if (!function_exists('billing_is_panel_context')) {
    function billing_is_panel_context(): bool
    {
        return !empty($_SESSION['user_id'])
            && isset($_GET['m']) && $_GET['m'] === 'billing'
            && in_array(basename((string)($_SERVER['PHP_SELF'] ?? '')), array('index.php', 'home.php'), true);
    }
}

// The panel has already authenticated the request and is rendering this file
// inside its layout. Never redirect or emit a standalone login page here.
if (billing_is_panel_context()) {
    // The panel bootstrap has already loaded its config in this include scope.
    // Copy only the runtime connection values; never load or persist billing config.
    foreach (array('db_host', 'db_user', 'db_pass', 'db_name', 'db_port', 'table_prefix', 'db_type') as $billingDbVar) {
        if (array_key_exists($billingDbVar, $GLOBALS)) {
            ${$billingDbVar} = $GLOBALS[$billingDbVar];
        }
    }
    if (strtolower((string)($_SESSION['users_role'] ?? '')) !== 'admin') {
        echo '<div class="failure">Billing admin requires a panel administrator account.</div>';
        exit();
    }
    return;
}

// Billing administration lives only in the control panel. A request that reaches this file
// outside the panel is sent to the panel's Billing page, where the panel does the login.
header('Location: ' . (function_exists('panel_home_url') ? panel_home_url() : '/panel/') . 'home.php?m=billing&p=billing');
exit();
