<?php
/**
 * Card payments for the signed-in customer (POST, csrf = billing_stripe_checkout_csrf):
 *   action=pay           invoice_ids=12,13   Pay Now: one Stripe payment for those unpaid invoices.
 *                        Answers {url} (Stripe's page) or {client_secret} (embedded form, if chosen in settings).
 *   action=autorenew_on  home_id=5           Save a card at Stripe for this server's auto-renew. Answers {url}.
 *   action=autorenew_off home_id=5           Turn auto-renew off (the saved card is removed at Stripe). Answers {ok}.
 * A cart coupon applies to Pay Now exactly as it does for PayPal.
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
$action = (string)($_POST['action'] ?? 'pay');

$db = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', intval($db_port ?? 3306) ?: 3306);
if (!$db) {
    sc_reply(503, array('error' => 'Payments are unavailable right now. Please try again shortly.'));
}
mysqli_set_charset($db, 'utf8mb4');
$prefix = (string)($table_prefix ?? '');
$cfg = billing_stripe_config($db, $prefix);
$base = billing_stripe_site_base();

// ---- auto-renew off (works even if Stripe was switched off since) ----
if ($action === 'autorenew_off') {
    $homeId = (int)($_POST['home_id'] ?? 0);
    $auto = billing_autopay_get($db, $prefix, $homeId);
    if (!$auto || (int)$auto['user_id'] !== $userId) {
        sc_reply(404, array('error' => 'Server not found.'));
    }
    list($ok, $note) = billing_autopay_disable($db, $prefix, $homeId, 'turned off by the customer');
    sc_log('auto-renew off for server ' . $homeId . ' by user ' . $userId);
    sc_reply(200, array('ok' => $ok, 'message' => 'Auto-renew is off. You can pay each invoice with Pay Now.'));
}

if (!$cfg['configured']) {
    sc_reply(503, array('error' => 'Card payments are not available right now.'));
}
$client = new StripeClient($cfg['secret_key']);
$customer = stripe_customer_for_user($db, $prefix, $client, $userId, $cfg['mode']);
if (!$customer['ok']) {
    sc_log('customer for user ' . $userId . ' failed: ' . $customer['error']);
    sc_reply(502, array('error' => 'Card payments could not be started. Please try again or contact support.'));
}

// ---- auto-renew on: save a card at Stripe, nothing is charged ----
if ($action === 'autorenew_on') {
    $homeId = (int)($_POST['home_id'] ?? 0);
    $stmt = $db->prepare("SELECT home_id, home_name, user_id_main FROM `{$prefix}server_homes` WHERE home_id = ? LIMIT 1");
    $stmt->bind_param('i', $homeId);
    $stmt->execute();
    $home = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$home || (int)$home['user_id_main'] !== $userId) {
        sc_reply(404, array('error' => 'Server not found.'));
    }
    $params = stripe_autorenew_checkout_params($home, $customer['id'], 'usd', array(
        'success' => $base . '/stripe/return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel' => $base . '/my_servers.php?autorenew=cancelled',
    ));
    $res = $client->request('POST', 'checkout/sessions', $params);
    if (!$res['ok'] || empty($res['data']['url'])) {
        sc_log('auto-renew setup for server ' . $homeId . ' failed: ' . $res['error']);
        sc_reply(502, array('error' => 'Auto-renew could not be started. Please try again or contact support.'));
    }
    sc_log('auto-renew setup ' . $res['data']['id'] . ' started for server ' . $homeId);
    sc_reply(200, array('url' => (string)$res['data']['url']));
}

// ---- Pay Now: the chosen unpaid invoices in one payment ----
$ids = array();
foreach (explode(',', (string)($_POST['invoice_ids'] ?? ($_POST['invoice_id'] ?? ''))) as $id) {
    if ((int)$id > 0) {
        $ids[(int)$id] = (int)$id;
    }
}
if (!$ids || count($ids) > 25) {
    sc_reply(400, array('error' => 'Choose between 1 and 25 invoices to pay.'));
}
$repo = new BillingRepository($db, $prefix);
$invoices = array();
foreach ($ids as $id) {
    $inv = $repo->getInvoice($id);
    if (!$inv || (int)$inv['user_id'] !== $userId) {
        sc_reply(404, array('error' => 'Invoice #' . $id . ' was not found.'));
    }
    if (strtolower((string)($inv['payment_status'] ?? '')) !== 'unpaid') {
        sc_reply(409, array('error' => 'Invoice #' . $id . ' is already paid or closed. Reload the cart.'));
    }
    $invoices[] = $inv;
}

// The cart coupon, checked the same way cart.php checks it.
$discounts = array();
$panelCouponId = 0;
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
            foreach (billing_coupon_discount_map($invoices, $coupon, $index) as $invId => $cents) {
                $discounts[(int)$invId] = round(((int)$cents) / 100, 2);
            }
            $panelCouponId = (int)($coupon['coupon_id'] ?? 0);
        }
    }
}

$ui = $cfg['checkout_ui'] === 'form' && (($_POST['ui'] ?? '') !== 'hosted') ? 'form' : 'hosted';
$params = stripe_cart_checkout_params($invoices, $customer['id'], array(
    'return' => $base . '/stripe/return.php?session_id={CHECKOUT_SESSION_ID}',
    'success' => $base . '/stripe/return.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel' => $base . '/cart.php?payment=cancelled',
), array('ui' => $ui, 'discounts' => $discounts, 'automatic_tax' => $cfg['automatic_tax'], 'panel_coupon_id' => $panelCouponId));
if (empty($params['line_items'])) {
    sc_reply(409, array('error' => 'There is nothing to pay by card.'));
}
$res = $client->request('POST', 'checkout/sessions', $params, null, $ui === 'form' ? StripeClient::FORM_API_VERSION : null);
if (!$res['ok'] || ($ui === 'form' ? empty($res['data']['client_secret']) : empty($res['data']['url']))) {
    sc_log('checkout for invoices ' . implode(',', $ids) . ' failed: ' . $res['error']);
    sc_reply(502, array('error' => 'Card payments could not be started. Please try again or contact support.'));
}
sc_log('checkout ' . $res['data']['id'] . ' started for invoices ' . implode(',', $ids) . ' (' . $ui . ')');
sc_reply(200, $ui === 'form' ? array('client_secret' => (string)$res['data']['client_secret']) : array('url' => (string)$res['data']['url']));
