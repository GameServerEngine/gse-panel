<?php
/**
 * Free Checkout Handler
 *
 * Processes a zero-dollar cart when a coupon reduces the total to $0.
 * Marks invoices paid (method=coupon, txid=free-<timestamp>),
 * creates billing_orders rows, and triggers automatic server provisioning.
 *
 * POST params: coupon_id, coupon_code
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/login_required.php';
require_once __DIR__ . '/includes/coupon_helper.php';

function billing_free_money_to_cents(float $amount): int
{
    return (int) round($amount * 100);
}

$userId = intval($_SESSION['website_user_id'] ?? $_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . billing_url('login.php'));
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . billing_url('cart.php'));
    exit;
}

// DB connection
$mysqli = mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
if (!$mysqli) {
    die('<p>Database connection failed. Please <a href="' . htmlspecialchars(billing_url('serverlist.php'), ENT_QUOTES, 'UTF-8') . '">return to the shop</a> or contact support.</p>');
}
mysqli_set_charset($mysqli, 'utf8mb4');

// Fetch unpaid invoices for this user (prepared statement)
$invoices = [];
$stmt = mysqli_prepare($mysqli, "SELECT * FROM {$table_prefix}billing_invoices
                             WHERE user_id = ?
                               AND (status = 'due' OR status = '')
                               AND (payment_status IS NULL OR payment_status NOT IN ('paid','cancelled','refunded'))
                             ORDER BY invoice_id ASC");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $invoices[] = $row;
    }
    mysqli_stmt_close($stmt);
}

if (empty($invoices)) {
    if ($mysqli instanceof mysqli) {
        mysqli_close($mysqli);
    }
    header('Location: ' . billing_url('cart.php') . '?msg=empty');
    exit;
}

// Validate coupon from POST / session
$couponId   = intval($_POST['coupon_id'] ?? $_SESSION['cart_coupon_id'] ?? 0);
$couponCode = trim($_POST['coupon_code'] ?? $_SESSION['cart_coupon_code'] ?? '');
$discountPct = 0.0;
$coupon = null;
$serviceIndex = billing_coupon_build_service_index($mysqli, $table_prefix, $invoices);

if ($couponCode !== '') {
    $safe = mysqli_real_escape_string($mysqli, $couponCode);
    $cr   = mysqli_query($mysqli, "SELECT * FROM {$table_prefix}billing_coupons
                               WHERE code = '$safe' AND is_active = 1 LIMIT 1");
    if ($cr && mysqli_num_rows($cr) === 1) {
        $coupon      = mysqli_fetch_assoc($cr);
        $validation = billing_coupon_validate_for_invoices($coupon, $invoices, $serviceIndex);
        if (empty($validation['valid']) || ($couponId > 0 && (int)$coupon['coupon_id'] !== $couponId)) {
            billing_coupon_clear_session();
            if ($mysqli instanceof mysqli) {
                mysqli_close($mysqli);
            }
            header('Location: ' . billing_url('cart.php') . '?msg=coupon_invalid');
            exit;
        }
        $couponId = (int)$coupon['coupon_id'];
        $discountPct = (float)($coupon['discount_percent'] ?? 0);
        mysqli_free_result($cr);
    } else {
        billing_coupon_clear_session();
        if ($mysqli instanceof mysqli) {
            mysqli_close($mysqli);
        }
        header('Location: ' . billing_url('cart.php') . '?msg=coupon_invalid');
        exit;
    }
}

// Calculate total and verify it is $0 after discount
$totalAmountCents = 0;
foreach ($invoices as $inv) {
    $totalAmountCents += billing_coupon_line_amount_cents($inv);
}
$discountMap = billing_coupon_discount_map($invoices, $coupon, $serviceIndex);
$discountAmountCents = min($totalAmountCents, array_sum($discountMap));
$finalAmountCents = max(0, $totalAmountCents - $discountAmountCents);

if ($finalAmountCents !== 0) {
    // Coupon no longer covers the full amount — redirect to cart
    if ($mysqli instanceof mysqli) {
        mysqli_close($mysqli);
    }
    header('Location: ' . billing_url('cart.php') . '?msg=coupon_insufficient');
    exit;
}

// Process the free checkout
$now   = date('Y-m-d H:i:s');
$txid  = 'free-' . time() . '-' . $userId;

require_once __DIR__ . '/classes/BillingRepository.php';
require_once __DIR__ . '/classes/BillingService.php';
require_once __DIR__ . '/classes/BillingLifecycleService.php';

$repo = new BillingRepository($mysqli, $table_prefix);
$lifecycle = new BillingLifecycleService($repo);
$newOrderIds = [];
$duration_meta = static function (array $invoice): array {
    return BillingLifecycleService::durationMeta($invoice);
};
$invoicesPaid = 0;

foreach ($invoices as $inv) {
    $invoiceId = intval($inv['invoice_id']);
    $invoiceBase = round((float)($inv['subtotal'] ?? $inv['total_due'] ?? $inv['amount'] ?? 0), 2);
    $lineDiscount = billing_cents_to_money_value((int)($discountMap[$invoiceId] ?? 0));
    $lineTotal = max(0.0, round($invoiceBase - $lineDiscount, 2));
    $meta = $duration_meta($inv);

    $result = $lifecycle->processPaidInvoice($invoiceId, [
        'transaction_external_id' => $txid,
        'payment_method' => 'coupon',
        'amount' => $lineTotal,
        'currency' => 'USD',
        'paid_at' => $now,
        'raw_response' => ['coupon_id' => $couponId, 'discount_pct' => $discountPct, 'original_amount' => (float)($inv['amount'] ?? 0)],
    ], [
        'coupon_id' => $couponId,
        'discount_amount' => $lineDiscount,
        'subtotal' => $invoiceBase,
        'amount' => $lineTotal,
        'total_due' => $lineTotal,
        'invoice_duration' => $meta['invoice_duration'],
        'rate_type' => $meta['rate_type'],
    ]);

    if (empty($result['success'])) {
        continue;
    }
    if (!empty($result['became_paid'])) {
        $invoicesPaid++;
    }
    $orderId = intval($result['order_id'] ?? 0);
    if ($orderId > 0 && !in_array($orderId, $newOrderIds, true)) {
        $newOrderIds[] = $orderId;
    }
}

if ($couponId > 0 && $invoicesPaid > 0) {
    mysqli_query($mysqli, "UPDATE {$table_prefix}billing_coupons
                       SET current_uses = current_uses + 1
                       WHERE coupon_id = " . intval($couponId));
}

// Clear coupon from session
unset($_SESSION['cart_coupon_code'], $_SESSION['cart_coupon_id']);

// Attempt automatic provisioning via panel bridge
$autoProvision = ['provisioned_count' => 0, 'failed_count' => 0, 'details' => [], 'trace_log_path' => 'modules/billing/logs/provisioning_trace.log'];
if (!empty($newOrderIds)) {
    require_once __DIR__ . '/includes/panel_bridge.php';
    $panelCtx = billing_panel_bootstrap();
    if ($panelCtx && isset($panelCtx['db'])) {
        $GLOBALS['db']       = $panelCtx['db'];
        $GLOBALS['settings'] = $panelCtx['settings'];
        require_once __DIR__ . '/create_servers.php';
        $autoProvision = billing_invoke_provision(['order_ids' => $newOrderIds, 'user_id' => $userId, 'is_admin' => true]);
    } else {
        $autoProvision = [
            'provisioned_count' => 0,
            'failed_count' => count($newOrderIds),
            'details' => [],
            'trace_log_path' => 'modules/billing/logs/provisioning_trace.log',
            'trace_error' => 'Panel bootstrap failed before billing provisioning could start.',
        ];
    }
    // If panel bootstrap fails the order is Active and admins can provision via the orders panel.
}
if (function_exists('billing_store_provision_session_result')) {
    billing_store_provision_session_result($txid, [
        'source' => 'checkout_free.php',
        'txid' => $txid,
        'order_ids' => $newOrderIds,
        'result' => $autoProvision,
    ]);
}

if ($mysqli instanceof mysqli) {
    mysqli_close($mysqli);
}

header('Location: ' . billing_url('payment_success.php') . '?order_id=' . urlencode($txid) . '&source=free');
exit;
