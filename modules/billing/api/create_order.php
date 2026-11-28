<?php
require_once __DIR__ . '/../includes/placement_store.php';
/**
 * PayPal Create Order API Endpoint
 * Uses PayPalGateway class. Credentials come from config — NOT hardcoded here.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
require_once dirname(__DIR__) . '/classes/BillingRepository.php';
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config_loader.php';
require_once __DIR__ . '/../billing_bootstrap.php';
require_once __DIR__ . '/../classes/PaymentGatewayInterface.php';
require_once __DIR__ . '/../classes/PayPalGateway.php';
require_once __DIR__ . '/../classes/GatewayFactory.php';

// Logging
$logDir  = __DIR__ . '/../logs';
@mkdir($logDir, 0755, true);
$logFile   = $logDir . '/paypal_create_order.log';
$requestId = uniqid('req_', true);

function co_log(string $label, $data): void {
    global $logFile, $requestId;
    $entry  = '[' . date('Y-m-d H:i:s') . "] [$requestId] $label\n";
    $entry .= is_array($data) || is_object($data) ? print_r($data, true) : (string)$data;
    $entry .= "\n" . str_repeat('-', 80) . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
$in       = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE || !$in) {
    http_response_code(400);
    echo json_encode([
        'success'    => false,
        'error_code' => 'invalid_json',
        'message'    => 'Invalid JSON in request body.',
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}

co_log('REQUEST', ['amount' => $in['amount'] ?? null, 'invoice_id' => $in['invoice_id'] ?? null]);

$userId = (int)($_SESSION['website_user_id'] ?? 0);
$ref = (string)($in['custom_id'] ?? $in['invoice_id'] ?? '');
if (!$userId || !preg_match('/^(?:cart:)?([1-9][0-9]*(?:,[1-9][0-9]*){0,24})$/D', $ref, $match)) {
    http_response_code(400);
    exit(json_encode(['success'=>false, 'message'=>'Choose your unpaid invoices.']));
}
$ids = array_values(array_unique(array_map('intval', explode(',', $match[1]))));
$db = new mysqli($db_host, $db_user, $db_pass, $db_name, (int)($db_port ?? 3306));
$invoices = (new BillingRepository($db, $table_prefix))->getInvoicesForUserByIds($userId, $ids, true);
if (count($invoices) !== count($ids) || !placement_reserve_invoices($db, $table_prefix, $invoices, time() + 3600)) {
    http_response_code(409);
    exit(json_encode(['success'=>false, 'message'=>'Selected invoices or locations unavailable.']));
}
$amount = array_sum(array_map(static fn($inv) => (int)round(100 * ($inv['total_due'] ?? $inv['amount'])), $invoices));
$params = ['amount'=>number_format($amount / 100, 2, '.', ''), 'currency'=>'USD',
    'custom_id'=>'cart:' . implode(',', $ids), 'return_url'=>billing_abs_url('/paypal/return.php'),
    'cancel_url'=>billing_abs_url('/cart.php')];

try {
    $gateway = GatewayFactory::make('paypal');
    $result  = $gateway->createPayment($params);
} catch (Exception $e) {
    co_log('EXCEPTION', $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'error_code' => 'gateway_error',
        'message'    => $e->getMessage(),
        'debug_id'   => null,
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}

if (!$result['success']) {
    co_log('CREATE_FAILED', $result);
    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'error_code' => $result['error'] ?? 'create_failed',
        'message'    => $result['message'] ?? 'Failed to create PayPal order.',
        'debug_id'   => $result['debug_id'] ?? null,
        'timestamp'  => date('c'),
        'request_id' => $requestId,
    ]);
    exit;
}

$_SESSION['placement_paypal'][(string)$result['provider_order_id']] = $ids;
co_log('CREATE_SUCCESS', ['provider_order_id' => $result['provider_order_id']]);
echo json_encode($result['raw_response']);
