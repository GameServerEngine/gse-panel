<?php
/**
 * Starts Stripe Checkout for one of the signed-in customer's unpaid invoices.
 * POST invoice_id, csrf (billing_stripe_checkout_csrf) and ui (form, the default, or hosted).
 * Answers JSON {client_secret} for the embedded form, {url} for the hosted page, or {error}.
 * A cart coupon applies here exactly as it does for PayPal: it lowers the first payment only.
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
require_once __DIR__ . '/../includes/stripe_billing.php';
header('Content-Type: application/json');

function sc_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function sc_log(string $line): void
{
    $dir = dirname(__DIR__) . '/logs';
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/stripe_checkout.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sc_reply(405, array('error' => 'POST only'));
}
$userId = (int)($_SESSION['website_user_id'] ?? 0);
if ($userId <= 0) {
    sc_reply(401, array('error' => 'Please log in again.'));
}
if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'billing_stripe_checkout_csrf')) {
    sc_reply(403, array('error' => 'This page has expired. Reload it and try again.'));
}
$invoiceId = (int)($_POST['invoice_id'] ?? 0);
$ui = (($_POST['ui'] ?? 'form') === 'hosted') ? 'hosted' : 'form';

$db = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', intval($db_port ?? 3306) ?: 3306);
if (!$db) {
    sc_reply(503, array('error' => 'Payments are unavailable right now. Please try again shortly.'));
}
mysqli_set_charset($db, 'utf8mb4');
$prefix = (string)($table_prefix ?? '');

$cfg = billing_stripe_config($db, $prefix);
if (!$cfg['configured']) {
    sc_reply(503, array('error' => 'Card payments are not available right now.'));
}
$repo = new BillingRepository($db, $prefix);
$invoice = $repo->getInvoice($invoiceId);
if (!$invoice || (int)$invoice['user_id'] !== $userId) {
    sc_reply(404, array('error' => 'Invoice not found.'));
}
if (strtolower((string)($invoice['payment_status'] ?? '')) !== 'unpaid') {
    sc_reply(409, array('error' => 'This invoice is already paid or closed.'));
}

// The cart coupon, checked the same way cart.php checks it.
$extraDiscount = 0.0;
$panelCouponId = 0;
$code = trim((string)($_SESSION['cart_coupon_code'] ?? ''));
if ($code !== '') {
    $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_coupons` WHERE code = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $coupon = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($coupon) {
        $index = billing_coupon_build_service_index($db, $prefix, array($invoice));
        $check = billing_coupon_validate_for_invoices($coupon, array($invoice), $index);
        if (!empty($check['valid'])) {
            $map = billing_coupon_discount_map(array($invoice), $coupon, $index);
            $extraDiscount = round(((int)($map[$invoiceId] ?? array_sum($map))) / 100, 2);
            $panelCouponId = (int)($coupon['coupon_id'] ?? 0);
        }
    }
}

$client = new StripeClient($cfg['secret_key']);
$customer = stripe_customer_for_user($db, $prefix, $client, $userId, $cfg['mode']);
if (!$customer['ok']) {
    sc_log('customer for user ' . $userId . ' failed: ' . $customer['error']);
    sc_reply(502, array('error' => 'Card payments could not be started. Please try again or contact support.'));
}

$amounts = stripe_invoice_amounts($invoice, $extraDiscount);
if ($amounts['now_cents'] <= 0 || $amounts['regular_cents'] <= 0) {
    sc_reply(409, array('error' => 'This invoice has nothing to pay by card.'));
}
$stripeCoupon = null;
if ($amounts['now_cents'] < $amounts['regular_cents']) {
    $off = $amounts['regular_cents'] - $amounts['now_cents'];
    $currency = strtolower(trim((string)($invoice['currency'] ?? '')) ?: 'usd');
    $res = $client->request('POST', 'coupons', array(
        'amount_off' => $off,
        'currency' => $currency,
        'duration' => 'once',
        'max_redemptions' => 1,
        'name' => 'Discount on invoice #' . $invoiceId,
        'metadata' => array('invoice_id' => (string)$invoiceId, 'panel_coupon_id' => (string)$panelCouponId),
    ), 'gse-coupon-' . $cfg['mode'] . '-' . $invoiceId . '-' . $off . '-' . date('YmdH'));
    if (!$res['ok']) {
        sc_log('coupon for invoice ' . $invoiceId . ' failed: ' . $res['error']);
        sc_reply(502, array('error' => 'Card payments could not be started. Please try again or contact support.'));
    }
    $stripeCoupon = (string)$res['data']['id'];
}

$base = billing_stripe_site_base();
$params = stripe_checkout_params($invoice, $customer['id'], array(
    'return' => $base . '/stripe/return.php?session_id={CHECKOUT_SESSION_ID}',
    'success' => $base . '/stripe/return.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel' => $base . '/cart.php?payment=cancelled',
), array(
    'ui' => $ui,
    'automatic_tax' => $cfg['automatic_tax'],
    'coupon_id' => $stripeCoupon,
    'extra_discount' => $extraDiscount,
    'panel_coupon_id' => $panelCouponId,
));
$res = $client->request('POST', 'checkout/sessions', $params, null, $ui === 'form' ? StripeClient::FORM_API_VERSION : null);
if (!$res['ok'] || ($ui === 'form' ? empty($res['data']['client_secret']) : empty($res['data']['url']))) {
    sc_log('checkout for invoice ' . $invoiceId . ' failed: ' . $res['error']);
    sc_reply(502, array('error' => 'Card payments could not be started. Please try again or contact support.'));
}
sc_log('checkout ' . $res['data']['id'] . ' started for invoice ' . $invoiceId . ' (' . $params['mode'] . ', ' . $ui . ')');
if ($ui === 'form') {
    sc_reply(200, array('client_secret' => (string)$res['data']['client_secret']));
}
sc_reply(200, array('url' => (string)$res['data']['url']));
