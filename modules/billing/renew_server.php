<?php
// Silent renew endpoint: mark an existing order as 'renew' and redirect back.
// This endpoint produces no visible output — it always redirects back to the referrer.

// Require login and configuration
if (session_status() === PHP_SESSION_NONE) {
    session_name("gameserverpanel_web");
    session_start();
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/login_required.php');
require_once(__DIR__ . '/includes/action_security.php');
require_once(__DIR__ . '/includes/log.php');
require_once(__DIR__ . '/classes/BillingRepository.php');
require_once(__DIR__ . '/classes/BillingLifecycleService.php');

// Variables from config.inc.php (helps IDEs understand scope)
/** @var string $db_host Database host */
/** @var string $db_user Database user */
/** @var string $db_pass Database password */
/** @var string $db_name Database name */
/** @var string $table_prefix Table prefix for database tables */

// Connect to DB (use mysqli like other website modules)
$db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
if (!$db) {
    // fail silently and redirect back
    $back = $_SERVER['HTTP_REFERER'] ?? 'my_account.php';
    header('Location: ' . $back);
    exit;
}

// Resolve user id from session (same logic as other pages)
$user_id = intval($_SESSION['website_user_id'] ?? $_SESSION['user_id'] ?? 0);
if ($user_id <= 0 && isset($_SESSION['website_username']) && !empty($_SESSION['website_username'])) {
    $safe_uname = mysqli_real_escape_string($db, $_SESSION['website_username']);
    $qr = mysqli_query($db, "SELECT user_id FROM {$table_prefix}users WHERE users_login = '$safe_uname' LIMIT 1");
    if ($qr && mysqli_num_rows($qr) === 1) {
        $rr = mysqli_fetch_assoc($qr);
        $user_id = intval($rr['user_id'] ?? 0);
        if ($user_id > 0) {
            $_SESSION['website_user_id'] = $user_id;
        }
    }
}

$redirect_to = 'cart.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect_to);
    exit;
}

if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'billing_renew_csrf')) {
    header('Location: my_account.php?msg=' . urlencode('Invalid security token. Reload the page and try again.'));
    exit;
}

$order_id = intval($_POST['order_id'] ?? 0);
$duration = (isset($_POST['duration']) && $_POST['duration'] === 'year') ? 'year' : 'month';

if ($order_id <= 0 || $user_id <= 0) {
    header('Location: ' . $redirect_to);
    exit;
}

// Fetch order and verify ownership (get all needed fields for invoice creation)
$stmt = $db->prepare("SELECT order_id, user_id, service_id, qty, invoice_duration, price, home_id, home_name, ip, max_players, remote_control_password, ftp_password FROM {$table_prefix}billing_orders WHERE order_id = ? LIMIT 1");
if (!$stmt) {
    header('Location: ' . $redirect_to);
    exit;
}
$stmt->bind_param('i', $order_id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    $stmt->close();
    header('Location: ' . $redirect_to);
    exit;
}
$order = $res->fetch_assoc();
$stmt->close();

if (intval($order['user_id']) !== intval($user_id)) {
    // Not the owner — silently redirect
    header('Location: ' . $redirect_to);
    exit;
}

// Determine price for selected duration by looking up service prices
$service_id = intval($order['service_id'] ?? 0);
$price_val = floatval($order['price'] ?? 0.0);
if ($service_id > 0) {
    $sstmt = $db->prepare("SELECT price_monthly, price_year FROM {$table_prefix}billing_services WHERE service_id = ? LIMIT 1");
    if ($sstmt) {
        $sstmt->bind_param('i', $service_id);
        $sstmt->execute();
        $sres = $sstmt->get_result();
        if ($sres && $sres->num_rows > 0) {
            $srow = $sres->fetch_assoc();
            if ($duration === 'year' && !empty($srow['price_year']) && floatval($srow['price_year']) > 0) {
                $price_val = floatval($srow['price_year']);
            } else {
                $price_val = floatval($srow['price_monthly']);
            }
        }
        $sstmt->close();
    }
}

// Get user email for invoice
$user_email = '';
$user_name = '';
$user_stmt = $db->prepare("SELECT users_email, users_login, users_fname, users_lname FROM {$table_prefix}users WHERE user_id = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result();
    if ($user_res && $user_res->num_rows > 0) {
        $user_row = $user_res->fetch_assoc();
        $user_email = $user_row['users_email'] ?? '';
        $fname = $user_row['users_fname'] ?? '';
        $lname = $user_row['users_lname'] ?? '';
        $user_name = trim($fname . ' ' . $lname);
        if (empty($user_name)) {
            $user_name = $user_row['users_login'] ?? '';
        }
    }
    $user_stmt->close();
}

// Get service name for invoice description
$service_name = '';
$service_stmt = $db->prepare('SELECT service_name FROM ' . $table_prefix . 'billing_services WHERE service_id = ? LIMIT 1');
if ($service_stmt) {
    $service_stmt->bind_param('i', $service_id);
    $service_stmt->execute();
    $service_res = $service_stmt->get_result();
    if ($service_res && $service_res->num_rows > 0) {
        $service_row = $service_res->fetch_assoc();
        $service_name = $service_row['service_name'] ?? 'Game Server';
    }
    $service_stmt->close();
}

// Create invoice for renewal
$qty = 1;
$price_formatted = number_format($price_val, 2, '.', '');
$home_name = $order['home_name'] ?? 'Server Renewal';
$description = "Renewal: " . $service_name . " - " . $home_name . " (" . $duration . ")";
$now = date('Y-m-d H:i:s');
$repo = new BillingRepository($db, $table_prefix);
$lifecycle = new BillingLifecycleService($repo);
$home_id = intval($order['home_id'] ?? 0);
$invoiceResult = $lifecycle->createRenewalInvoice([
    'home_id' => $home_id,
    'home_name' => $home_name,
    'user_id' => $user_id,
    'next_invoice_date' => $order['end_date'] ?? $now,
], [
    'order_id' => $order_id,
    'service_id' => $service_id,
    'description' => $description,
    'invoice_duration' => $duration,
    'rate_type' => $duration === 'year' ? 'yearly' : 'monthly',
    'rate_per_player' => $price_val,
    'players' => intval($order['max_players'] ?? 0),
    'qty' => $qty,
    'subtotal' => (float)$price_formatted,
    'total_due' => (float)$price_formatted,
    'currency' => 'USD',
]);

if (!empty($invoiceResult['success'])) {
        $new_invoice_id = intval($invoiceResult['invoice_id']);
        // Log the renewal invoice creation
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $msg = "USER-RENEW: User {$user_id} created renewal invoice {$new_invoice_id} for order {$order_id}";
        $escaped_msg = mysqli_real_escape_string($db, $msg);
        $escaped_ip = mysqli_real_escape_string($db, $client_ip);

        // Try to log to panel logger
        $logger_table = null;
        $check = mysqli_query($db, "SHOW TABLES LIKE '{$table_prefix}logger'");
        if ($check && mysqli_num_rows($check) > 0) {
            $logger_table = '{$table_prefix}logger';
        } else {
            $reslt = mysqli_query($db, "SHOW TABLES LIKE '%logger'");
            if ($reslt && mysqli_num_rows($reslt) > 0) {
                $row = mysqli_fetch_row($reslt);
                $logger_table = $row[0];
            }
        }
        if ($logger_table) {
            $dt = date('Y-m-d H:i:s');
            $ins = "INSERT INTO `" . $logger_table . "` (`date`, `user_id`, `ip`, `message`) VALUES ('{$dt}', " . intval($user_id) . ", '{$escaped_ip}', '{$escaped_msg}')";
            @mysqli_query($db, $ins);
        }
}

// Done — redirect back silently
header('Location: ' . $redirect_to);
exit;
