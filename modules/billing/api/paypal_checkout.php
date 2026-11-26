<?php
/**
 * Pay with PayPal (POST, csrf = billing_paypal_checkout_csrf):
 *   invoice_ids=12,13   Creates one PayPal order for those unpaid invoices and answers {url},
 *                       the PayPal page where the customer approves the payment.
 * PayPal sends the customer back to paypal/return.php, which captures the money.
 * A cart coupon applies exactly as it does for Stripe.
 */
// The ordering site's own session (same as cart.php), started before anything else.
if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
require_once __DIR__ . '/../includes/config_loader.php';
require_once __DIR__ . '/../billing_bootstrap.php';
require_once __DIR__ . '/../includes/action_security.php';
require_once __DIR__ . '/../includes/coupon_helper.php';
require_once __DIR__ . '/../includes/payment_methods.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../classes/BillingRepository.php';
require_once __DIR__ . '/../classes/GatewayFactory.php';
header('Content-Type: application/json');

function pc_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function pc_log(string $line): void
{
    $dir = dirname(__DIR__) . '/logs';
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/paypal_checkout.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pc_reply(405, array('error' => 'POST only'));
}
$userId = (int)($_SESSION['website_user_id'] ?? 0);
if ($userId <= 0) {
    pc_reply(401, array('error' => 'Please log in again.'));
}
if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'billing_paypal_checkout_csrf')) {
    pc_reply(403, array('error' => 'This page has expired. Reload it and try again.'));
}

$db = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', intval($db_port ?? 3306) ?: 3306);
if (!$db) {
    pc_reply(503, array('error' => 'Payments are unavailable right now. Please try again shortly.'));
}
mysqli_set_charset($db, 'utf8mb4');
$prefix = (string)($table_prefix ?? '');
if (!billing_payment_method_enabled($db, $prefix, 'paypal')) {
    pc_reply(503, array('error' => 'PayPal is not available right now.'));
}

// The chosen unpaid invoices, all belonging to this customer.
$ids = array();
foreach (explode(',', (string)($_POST['invoice_ids'] ?? '')) as $id) {
    if ((int)$id > 0) {
        $ids[(int)$id] = (int)$id;
    }
}
if (!$ids || count($ids) > 25) {
    pc_reply(400, array('error' => 'Choose between 1 and 25 invoices to pay.'));
}
$repo = new BillingRepository($db, $prefix);
$invoices = array();
$totalCents = 0;
foreach ($ids as $id) {
    $inv = $repo->getInvoice($id);
    if (!$inv || (int)$inv['user_id'] !== $userId) {
        pc_reply(404, array('error' => 'Invoice #' . $id . ' was not found.'));
    }
    if (strtolower((string)($inv['payment_status'] ?? '')) !== 'unpaid') {
        pc_reply(409, array('error' => 'Invoice #' . $id . ' is already paid or closed. Reload the cart.'));
    }
    $invoices[] = $inv;
    $totalCents += (int)round((float)($inv['total_due'] ?? $inv['amount'] ?? 0) * 100);
}

// The cart coupon, checked the same way cart.php checks it.
$discountCents = 0;
$code = trim((string)($_SESSION['cart_coupon_code'] ?? ''));
if ($code !== '') {
    $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_coupons` WHERE code = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $coupon = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($coupon) {
        $index = billing_coupon_build_service_index($db, $prefix, $invoices);
        $check = billing_coupon_validate_for_invoices($coupon, $invoices, $index);
        if (!empty($check['valid'])) {
            $discountCents = min($totalCents, (int)array_sum(billing_coupon_discount_map($invoices, $coupon, $index)));
        }
    }
}
$payCents = max(0, $totalCents - $discountCents);
if ($payCents <= 0) {
    pc_reply(409, array('error' => 'There is nothing to pay with PayPal.'));
}

$base = billing_stripe_site_base();
$invoiceList = implode(',', $ids);
try {
    $gateway = GatewayFactory::make('paypal');
    $result = $gateway->createPayment(array(
        'amount' => number_format($payCents / 100, 2, '.', ''),
        'currency' => 'USD',
        'custom_id' => 'cart:' . $invoiceList,
        'description' => 'Game server order (invoices ' . $invoiceList . ')',
        'return_url' => $base . billing_url('paypal/return.php'),
        'cancel_url' => $base . billing_url('cart.php?payment=cancelled'),
        'idempotency_key' => 'cart-' . $userId . '-' . $invoiceList . '-' . $payCents . '-' . date('YmdH'),
    ));
} catch (Throwable $e) {
    pc_log('gateway for invoices ' . $invoiceList . ' failed: ' . $e->getMessage());
    pc_reply(502, array('error' => 'PayPal could not be started. Please try again or contact support.'));
}
if (empty($result['success']) || empty($result['approve_url'])) {
    pc_log('order for invoices ' . $invoiceList . ' failed: ' . json_encode(array_diff_key($result, array('raw_response' => 1))));
    pc_reply(502, array('error' => 'PayPal could not be started. Please try again or contact support.'));
}
pc_log('order ' . $result['provider_order_id'] . ' started for invoices ' . $invoiceList . ' (' . $payCents . ' cents)');
pc_reply(200, array('url' => (string)$result['approve_url']));
