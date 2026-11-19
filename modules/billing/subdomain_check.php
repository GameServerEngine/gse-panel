<?php
// Live name check for the order page. Returns JSON.
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/subdomain_bridge.php');

if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
header('Content-Type: application/json');
header('Cache-Control: no-store');

$loggedIn = !empty($_SESSION['website_user_id']) || !empty($_SESSION['website_username']);
if (!$loggedIn) {
    echo json_encode(array('ok' => false, 'message' => 'Please log in to pick a name.'));
    exit;
}
$now = microtime(true);
if (isset($_SESSION['sd_last_check']) && ($now - $_SESSION['sd_last_check']) < 0.3) {
    echo json_encode(array('ok' => false, 'message' => 'Slow down...'));
    exit;
}
$_SESSION['sd_last_check'] = $now;

$dbh = billing_get_db();
$ctx = billing_sd_load($dbh, $table_prefix);
if (!$ctx) {
    echo json_encode(array('ok' => false, 'message' => 'Server addresses are not available.'));
    exit;
}
list($sdb, $settings, $provider) = $ctx;
$name = isset($_GET['name']) ? (string)$_GET['name'] : '';
$domain = isset($_GET['domain']) ? (string)$_GET['domain'] : '';
list($ok, $msg) = sd_check_available($sdb, $provider, $settings, $name, $domain);
echo json_encode(array('ok' => $ok, 'message' => $msg));
