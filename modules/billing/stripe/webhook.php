<?php
/**
 * Stripe webhook endpoint: https://<ordering site>/stripe/webhook.php
 *
 * Checks the Stripe-Signature header against the endpoint's signing secret, stores each event
 * once and applies it (includes/stripe_billing.php). Answers 500 when an event could not be
 * applied, so Stripe sends it again later, and 200 otherwise. Logs to logs/stripe_webhook.log.
 */
$_billing_dir = dirname(__DIR__);
require_once $_billing_dir . '/includes/config_loader.php';
require_once $_billing_dir . '/includes/stripe_billing.php';

header('Content-Type: application/json');

function stripe_wh_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function stripe_wh_log(string $line): void
{
    $dir = dirname(__DIR__) . '/logs';
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/stripe_webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    stripe_wh_reply(405, array('error' => 'POST only'));
}
$payload = (string)file_get_contents('php://input');

$db = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', intval($db_port ?? 3306) ?: 3306);
if (!$db) {
    stripe_wh_log('database unavailable');
    stripe_wh_reply(500, array('error' => 'database unavailable'));
}
mysqli_set_charset($db, 'utf8mb4');
$prefix = (string)($table_prefix ?? '');

$cfg = billing_stripe_config($db, $prefix);
if ($cfg['webhook_secret'] === '') {
    stripe_wh_log('no webhook signing secret configured');
    stripe_wh_reply(500, array('error' => 'not configured'));
}
if (!StripeClient::verifySignature($payload, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $cfg['webhook_secret'])) {
    stripe_wh_log('rejected: bad or old signature');
    stripe_wh_reply(400, array('error' => 'bad signature'));
}
$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id'])) {
    stripe_wh_reply(400, array('error' => 'bad payload'));
}
if (!empty($event['livemode']) !== ($cfg['mode'] === 'live')) {
    stripe_wh_log('ignored ' . $event['id'] . ': sent in the other mode (test vs live)');
    stripe_wh_reply(200, array('received' => true, 'ignored' => 'mode'));
}

try {
    if (stripe_event_claim($db, $prefix, $event) === 'duplicate') {
        stripe_wh_reply(200, array('received' => true, 'duplicate' => true));
    }
    $result = stripe_handle_event($db, $prefix, $event);
} catch (Throwable $e) {
    $result = array('status' => 'failed', 'note' => 'error: ' . $e->getMessage());
}
try {
    stripe_event_finish($db, $prefix, (string)$event['id'], $result['status'], $result['note']);
} catch (Throwable $e) {
    stripe_wh_log('could not record the result of ' . $event['id'] . ': ' . $e->getMessage());
}
stripe_wh_log($event['id'] . ' ' . (string)($event['type'] ?? '') . ' => ' . $result['status'] . ': ' . $result['note']);
stripe_wh_reply($result['status'] === 'failed' ? 500 : 200, array('received' => true, 'status' => $result['status']));
