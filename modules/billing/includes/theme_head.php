<?php
// Stylesheets shared by every storefront page. Uses the panel's helper when the
// bootstrap has loaded it, and falls back to paths relative to the page otherwise
// (order.php includes this before bootstrap.php).
if (function_exists('panel_asset_url')) {
    $gswTheme = panel_asset_url('themes/SimpleBootstrap/css/');
} else {
    $gswTheme = '../../themes/SimpleBootstrap/css/';
}
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($gswTheme . 'bootstrap.min.css', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($gswTheme . 'main.css', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="css/header.css">
