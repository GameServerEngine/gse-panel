<?php
/**
 * PayPal Order Capture Endpoint
 * Uses PayPalGateway, BillingService, and BillingRepository.
 * Credentials come from config — NOT hardcoded here.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../includes/config_loader.php';
require_once __DIR__ . '/../classes/PaymentGatewayInterface.php';
require_once __DIR__ . '/../classes/PayPalGateway.php';
require_once __DIR__ . '/../classes/GatewayFactory.php';
require_once __DIR__ . '/../classes/BillingRepository.php';
require_once __DIR__ . '/../classes/BillingService.php';
require_once __DIR__ . '/../classes/BillingLifecycleService.php';
require_once __DIR__ . '/../includes/coupon_helper.php';

// Logging setup
$logDir  = __DIR__ . '/../logs';
@mkdir($logDir, 0755, true);
$logFile   = $logDir . '/payment_capture.log';
$requestId = uniqid('req_', true);

function cap_log(string $label, $data): void {
    global $logFile, $requestId;
    $entry  = '[' . date('Y-m-d H:i:s') . "] [$requestId] $label\n";
    $entry .= is_array($data) || is_object($data) ? print_r($data, true) : (string)$data;
    $entry .= "\n" . str_repeat('-', 80) . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

// Session (single call)
if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}

$userId = intval($_SESSION['website_user_id'] ?? $_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    cap_log('NO_USER_SESSION', ['session_keys' => array_keys($_SESSION)]);
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => 'no_user_session',
        'message'    => 'You must be logged in to complete payment.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}

// Parse input
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => 'invalid_json',
        'message'    => 'Invalid JSON in request body.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}

$paypalOrderId = $input['order_id'] ?? null;
if (!$paypalOrderId) {
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => 'missing_order_id',
        'message'    => 'Missing PayPal order ID.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}

cap_log('REQUEST', ['order_id' => $paypalOrderId, 'user_id' => $userId]);

// DB connection
$port   = intval($db_port ?? 3306) ?: 3306;
$mysqli = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $port);
if (!$mysqli) {
    cap_log('DB_FAILED', mysqli_connect_error());
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => 'db_connection_failed',
        'message'    => 'Database connection failed.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}
mysqli_set_charset($mysqli, 'utf8mb4');

$prefix = $table_prefix ?? '';
$repo   = new BillingRepository($mysqli, $prefix);
$lifecycle = new BillingLifecycleService($repo);

function cap_invoice_ids_from_custom_id(?string $customId): array {
    if (!is_string($customId) || $customId === '') {
        return [];
    }
    if (ctype_digit($customId)) {
        return [intval($customId)];
    }
    if (stripos($customId, 'cart:') !== 0) {
        return [];
    }
    $parts = explode(',', substr($customId, 5));
    $invoiceIds = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $invoiceIds[] = intval($part);
        }
    }
    return array_values(array_unique($invoiceIds));
}

function cap_get_duration_metadata(array $invoice): array {
    return BillingLifecycleService::durationMeta($invoice);
}

function cap_get_end_date(array $invoice, ?string $fromDate = null): string {
    return BillingLifecycleService::addPeriod($fromDate ?: date('Y-m-d H:i:s'), $invoice);
}

function cap_payment_error(string $errorCode, string $message, mysqli $mysqli, string $requestId): void {
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => $errorCode,
        'message'    => $message,
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    mysqli_close($mysqli);
    exit;
}

// Capture payment via PayPal gateway
try {
    $gateway = GatewayFactory::make('paypal');
} catch (Exception $e) {
    cap_log('GATEWAY_ERROR', $e->getMessage());
    $repo->logPaypalError([
        'context'    => 'gateway_init',
        'error_code' => 'gateway_init_failed',
        'message'    => $e->getMessage(),
        'order_id'   => $paypalOrderId,
        'user_id'    => $userId,
    ]);
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => 'gateway_init_failed',
        'message'    => 'Payment gateway initialisation failed.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    mysqli_close($mysqli);
    exit;
}

$capture = $gateway->handleCallback([
    'order_id' => $paypalOrderId,
    'idempotency_key' => 'capture-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', (string)$paypalOrderId),
]);
cap_log('CAPTURE_RESULT', ['success' => $capture['success'], 'txid' => $capture['transaction_id'] ?? null]);

if (!$capture['success']) {
    cap_log('CAPTURE_FAILED', $capture);
    // Sanitize raw capture data before logging — never store secrets
    $captureForLog = $capture;
    foreach (['client_secret', 'access_token', 'refresh_token'] as $_sk) {
        unset($captureForLog[$_sk]);
    }
    $repo->logPaypalError([
        'context'         => 'capture_order',
        'error_code'      => $capture['error'] ?? 'capture_failed',
        'message'         => $capture['message'] ?? 'PayPal order capture failed.',
        'paypal_debug_id' => $capture['debug_id'] ?? null,
        'order_id'        => $paypalOrderId,
        'user_id'         => $userId,
        'raw_json'        => $captureForLog,
    ]);
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => $capture['error'] ?? 'capture_failed',
        'message'    => $capture['message'] ?? 'PayPal order capture failed. Please try again.',
        'debug_id'   => $capture['debug_id'] ?? null,
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    mysqli_close($mysqli);
    exit;
}

$txid                      = $capture['transaction_id'] ?? '';
$paidAmount                = round((float)($capture['amount'] ?? 0), 2);
$capture['payment_method'] = 'paypal';
$invoiceIds                = cap_invoice_ids_from_custom_id($capture['custom_id'] ?? null);
$invoices                  = !empty($invoiceIds)
    ? $repo->getInvoicesForUserByIds($userId, $invoiceIds, false)
    : $repo->getUnpaidInvoicesForUser($userId);
$invoicesPaid              = 0;
$ordersCreated             = 0;
$invoicesProcessed         = 0;
$newOrderIds               = [];
$now                       = date('Y-m-d H:i:s');
$couponId                  = intval($_SESSION['cart_coupon_id'] ?? 0);
$couponCode                = trim((string)($_SESSION['cart_coupon_code'] ?? ''));
$coupon                    = null;

if ($couponId <= 0 && $couponCode !== '') {
    $coupon = $repo->getCouponByCode($couponCode);
    $couponId = intval($coupon['coupon_id'] ?? 0);
} elseif ($couponCode !== '') {
    $coupon = $repo->getCouponByCode($couponCode);
    if (!$coupon || (int)($coupon['coupon_id'] ?? 0) !== $couponId) {
        $coupon = null;
        $couponId = 0;
    }
}

if (empty($invoices)) {
    cap_log('NO_INVOICES', ['user_id' => $userId, 'custom_id' => $capture['custom_id'] ?? null]);
    ob_clean();
    echo json_encode([
        'success'    => false,
        'error_code' => 'no_matching_invoices',
        'message'    => 'No matching unpaid invoices were found for this payment.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    mysqli_close($mysqli);
    exit;
}

$serviceIndex = billing_coupon_build_service_index($mysqli, $prefix, $invoices);
if ($coupon) {
    $couponValidation = billing_coupon_validate_for_invoices($coupon, $invoices, $serviceIndex);
    if (empty($couponValidation['valid'])) {
        cap_log('COUPON_INVALID', ['coupon_code' => $couponCode, 'reason' => $couponValidation['reason'] ?? 'invalid']);
        billing_coupon_clear_session();
        $coupon = null;
        $couponId = 0;
    }
}

$discountMap = billing_coupon_discount_map($invoices, $coupon, $serviceIndex);
$expectedTotalCents = 0;
foreach ($invoices as $inv) {
    $invoiceId = (int)($inv['invoice_id'] ?? 0);
    $expectedTotalCents += max(0, billing_coupon_line_amount_cents($inv) - (int)($discountMap[$invoiceId] ?? 0));
}
$paidAmountCents = billing_money_to_cents_value($paidAmount);
if ($paidAmountCents !== $expectedTotalCents) {
    cap_log('AMOUNT_MISMATCH', [
        'paid_amount' => $paidAmount,
        'paid_cents' => $paidAmountCents,
        'expected_cents' => $expectedTotalCents,
        'invoice_ids' => array_map(static fn($invoice) => (int)($invoice['invoice_id'] ?? 0), $invoices),
        'coupon_id' => $couponId,
    ]);
    $repo->logPaypalError([
        'context'    => 'capture_order_amount',
        'error_code' => 'amount_mismatch',
        'message'    => 'Captured PayPal amount did not match the server-side cart total.',
        'order_id'   => $paypalOrderId,
        'user_id'    => $userId,
        'raw_json'   => ['paid_cents' => $paidAmountCents, 'expected_cents' => $expectedTotalCents],
    ]);
    cap_payment_error('amount_mismatch', 'Payment amount did not match your current cart. Please refresh the cart and try again.', $mysqli, $requestId);
}

foreach ($invoices as $inv) {
    $invoiceId = intval($inv['invoice_id']);
    $invoiceBase = round((float)($inv['subtotal'] ?? $inv['total_due'] ?? $inv['amount'] ?? 0), 2);
    $lineDiscount = round((float)($discountMap[$invoiceId] ?? 0), 2);
    $lineTotal = round(max(0, $invoiceBase - $lineDiscount), 2);
    $durationMeta = cap_get_duration_metadata($inv);

    $rawCapture = $capture['raw_response'] ?? [];
    if (is_array($rawCapture)) {
        unset($rawCapture['client_secret'], $rawCapture['access_token']); // never log secrets
    }

    $result = $lifecycle->processPaidInvoice($invoiceId, [
        'transaction_external_id' => $txid,
        'payment_method' => 'paypal',
        'amount' => $lineTotal,
        'currency' => (string)($inv['currency'] ?? 'USD'),
        'paid_at' => $now,
        'raw_response' => $rawCapture,
    ], [
        'coupon_id' => $couponId,
        'discount_amount' => $lineDiscount,
        'subtotal' => $invoiceBase,
        'amount' => $lineTotal,
        'total_due' => $lineTotal,
        'invoice_duration' => $durationMeta['invoice_duration'],
        'rate_type' => $durationMeta['rate_type'],
    ]);

    if (empty($result['success'])) {
        cap_log('INVOICE_LIFECYCLE_FAILED', ['invoice_id' => $invoiceId, 'error' => $result['error'] ?? 'unknown']);
        continue;
    }

    $invoicesProcessed++;
    if (!empty($result['became_paid'])) {
        $invoicesPaid++;
    }
    $orderId = intval($result['order_id'] ?? 0);
    if ($orderId > 0) {
        if (!empty($result['created_order'])) {
            $ordersCreated++;
        }
        if ((int)($result['home_id'] ?? 0) <= 0 && !in_array($orderId, $newOrderIds, true)) {
            $newOrderIds[] = $orderId;
        }
    }
    cap_log('INVOICE_PAID', ['invoice_id' => $invoiceId, 'txid' => $txid, 'amount' => $lineTotal, 'result' => $result]);
}

if ($invoicesProcessed === 0) {
    cap_log('NO_INVOICES_PROCESSED', ['user_id' => $userId, 'invoice_ids' => $invoiceIds]);
    cap_payment_error('invoice_processing_failed', 'Payment was captured but no cart invoices could be completed. Please contact support.', $mysqli, $requestId);
}

if ($couponId > 0 && $invoicesPaid > 0) {
    $mysqli->query("UPDATE `{$prefix}billing_coupons`
                    SET current_uses = current_uses + 1
                    WHERE coupon_id = " . intval($couponId));
}

// Auto-provision new servers (orders without a home_id)
$autoProvision = ['provisioned_count' => 0, 'failed_count' => 0];
if (!empty($newOrderIds)) {
    require_once __DIR__ . '/../includes/panel_bridge.php';
    $panelCtx = billing_panel_bootstrap();
    if ($panelCtx && isset($panelCtx['db'])) {
        $GLOBALS['db']       = $panelCtx['db'];
        $GLOBALS['settings'] = $panelCtx['settings'];
        require_once __DIR__ . '/../create_servers.php';
        $autoProvision = billing_invoke_provision(['order_ids' => $newOrderIds, 'user_id' => $userId, 'is_admin' => true]);
        if (($autoProvision['failed_count'] ?? 0) > 0) {
            cap_log('AUTO_PROVISION_PARTIAL_FAILURE', $autoProvision);
        }
    } else {
        cap_log('AUTO_PROVISION_SKIPPED', 'panel bootstrap failed — orders require manual provisioning: ' . implode(',', $newOrderIds));
        $autoProvision = [
            'provisioned_count' => 0,
            'failed_count' => count($newOrderIds),
            'details' => [],
            'trace_log_path' => 'modules/billing/logs/provisioning_trace.log',
            'trace_error' => 'Panel bootstrap failed before billing provisioning could start.',
        ];
    }
}
if (function_exists('billing_store_provision_session_result')) {
    billing_store_provision_session_result($txid, [
        'source' => 'api/capture_order.php',
        'txid' => $txid,
        'order_ids' => $newOrderIds,
        'result' => $autoProvision,
    ]);
}

unset($_SESSION['cart_coupon_code'], $_SESSION['cart_coupon_id']);

mysqli_close($mysqli);

cap_log('COMPLETE', ['invoices_paid' => $invoicesPaid, 'txid' => $txid, 'orders' => $newOrderIds]);

ob_clean();
echo json_encode([
    'success'        => true,
    'status'         => 'COMPLETED',
    'txid'           => $txid,
    'invoices_paid'  => $invoicesPaid,
    'orders_created' => $ordersCreated,
    'provisioned'    => $autoProvision['provisioned_count'] ?? 0,
    'request_id'     => $requestId,
]);
