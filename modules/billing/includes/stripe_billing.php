<?php
/**
 * Stripe for the ordering site. The panel owns every invoice and receipt; Stripe only takes payments.
 *
 * Pay Now: the cart's invoices go to Stripe as one payment, one line per invoice with the amount the
 * panel decided, on Stripe's own page (or the embedded form, if chosen in Billing > Settings).
 * Auto-renew: the customer saves a card at Stripe (Checkout in setup mode, nothing is charged). The
 * daily billing job then charges it for each renewal invoice on the panel's schedule
 * (stripe_autopay_charge). The panel keeps only Stripe's references, never card data.
 * Webhooks: each signed event is stored once in billing_stripe_events and then applied.
 */
require_once __DIR__ . '/../classes/StripeClient.php';
require_once __DIR__ . '/../classes/BillingRepository.php';
require_once __DIR__ . '/../classes/BillingLifecycleService.php';
require_once __DIR__ . '/stripe_config.php';
require_once __DIR__ . '/billing_autopay.php';

if (!function_exists('stripe_money_to_cents')) {
    function stripe_money_to_cents($amount): int
    {
        return (int)round(((float)$amount) * 100);
    }
}

/** What is due on an invoice, in cents, after an optional discount. */
function stripe_invoice_due_cents(array $invoice, float $discount = 0.0): int
{
    $due = (float)($invoice['total_due'] ?? 0);
    if ($due <= 0) {
        $due = (float)($invoice['amount'] ?? 0);
    }
    if ($due <= 0) {
        $due = (float)($invoice['subtotal'] ?? 0);
    }
    return max(0, stripe_money_to_cents($due - max(0.0, $discount)));
}

function stripe_invoice_label(array $invoice): string
{
    $id = (int)$invoice['invoice_id'];
    $text = trim((string)($invoice['description'] ?? ''));
    if ($text === '') {
        $text = trim((string)($invoice['home_name'] ?? ''));
    }
    return mb_substr('Invoice #' . $id . ($text !== '' ? ' - ' . $text : ''), 0, 250);
}

/**
 * Checkout Session for paying invoices (Pay Now). $options: ui (hosted|form), discounts (invoice id =>
 * amount), automatic_tax (bool), panel_coupon_id. $urls: success, cancel (hosted) or return (form).
 */
function stripe_cart_checkout_params(array $invoices, string $customerId, array $urls, array $options = array()): array
{
    $currency = 'usd';
    $lines = array();
    $ids = array();
    $amounts = array();
    $userId = 0;
    foreach ($invoices as $inv) {
        $id = (int)$inv['invoice_id'];
        $cents = stripe_invoice_due_cents($inv, (float)($options['discounts'][$id] ?? 0));
        if ($cents <= 0) {
            continue;
        }
        if (!$ids) {
            $currency = strtolower(trim((string)($inv['currency'] ?? '')) ?: 'usd');
            $userId = (int)($inv['user_id'] ?? 0);
        }
        $lines[] = array('quantity' => 1, 'price_data' => array('currency' => $currency, 'unit_amount' => $cents,
            'product_data' => array('name' => stripe_invoice_label($inv))));
        $ids[] = $id;
        $amounts[] = $id . ':' . $cents;
    }
    $meta = array('purpose' => 'invoice', 'invoice_ids' => implode(',', $ids), 'invoice_amounts' => implode(',', $amounts),
        'user_id' => (string)$userId, 'source' => 'gse-billing');
    if (!empty($options['panel_coupon_id'])) {
        $meta['panel_coupon_id'] = (string)(int)$options['panel_coupon_id'];
    }
    $params = array(
        'mode' => 'payment',
        'customer' => $customerId,
        'client_reference_id' => mb_substr(implode('-', $ids), 0, 200),
        'line_items' => $lines,
        'metadata' => $meta,
        'payment_intent_data' => array('metadata' => $meta, 'description' => mb_substr('Invoice #' . implode(', #', $ids), 0, 300)),
        'customer_update' => array('address' => 'auto', 'name' => 'auto'),
        'billing_address_collection' => 'auto',
        'automatic_tax' => array('enabled' => !empty($options['automatic_tax'])),
    );
    if (!empty($options['automatic_tax'])) {
        $params['billing_address_collection'] = 'required';
    }
    if (($options['ui'] ?? 'hosted') === 'form') {
        // Embedded form on the cart page, with the settings chosen in Stripe Checkout Studio.
        $params['ui_mode'] = 'form';
        $params['return_url'] = $urls['return'];
        $params['phone_number_collection'] = array('enabled' => false);
        $params['submit_type'] = 'auto';
        $params['integration_identifier'] = 'custom_embedded_web_0001';
    } else {
        $params['success_url'] = $urls['success'];
        $params['cancel_url'] = $urls['cancel'];
    }
    return $params;
}

/** Checkout Session that saves a card at Stripe for a server's auto-renew. Nothing is charged. */
function stripe_autorenew_checkout_params(array $home, string $customerId, string $currency, array $urls): array
{
    $meta = array('purpose' => 'autorenew', 'home_id' => (string)(int)$home['home_id'],
        'user_id' => (string)(int)($home['user_id_main'] ?? 0), 'source' => 'gse-billing');
    return array(
        'mode' => 'setup',
        'customer' => $customerId,
        'currency' => strtolower($currency ?: 'usd'),
        'metadata' => $meta,
        'setup_intent_data' => array('metadata' => $meta, 'description' => mb_substr('Auto-renew for ' . (string)($home['home_name'] ?? ('server #' . $home['home_id'])), 0, 300)),
        'success_url' => $urls['success'],
        'cancel_url' => $urls['cancel'],
    );
}

/** The Stripe customer for a panel user (a reference only), created on first use. Returns array(ok, id, error). */
function stripe_customer_for_user(mysqli $db, string $prefix, StripeClient $client, int $userId, string $mode): array
{
    $stmt = $db->prepare("SELECT customer_id FROM `{$prefix}billing_stripe_customers` WHERE user_id = ? AND mode = ? LIMIT 1");
    $stmt->bind_param('is', $userId, $mode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['customer_id'] !== '') {
        // Make sure the saved customer still exists (it may have been deleted in the Stripe dashboard).
        $check = $client->request('GET', 'customers/' . rawurlencode((string)$row['customer_id']));
        if ($check['ok'] && empty($check['data']['deleted'])) {
            return array('ok' => true, 'id' => (string)$row['customer_id'], 'error' => '');
        }
        if ($check['ok'] === false && ($check['code'] ?? '') !== 'resource_missing') {
            return array('ok' => false, 'id' => '', 'error' => $check['error']);
        }
    }
    $stmt = $db->prepare("SELECT users_login, users_fname, users_lname, users_email FROM `{$prefix}users` WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        return array('ok' => false, 'id' => '', 'error' => 'Your account could not be found.');
    }
    $name = trim((string)$user['users_fname'] . ' ' . (string)$user['users_lname']);
    $res = $client->request('POST', 'customers', array(
        'email' => (string)$user['users_email'],
        'name' => $name !== '' ? $name : (string)$user['users_login'],
        'metadata' => array('user_id' => (string)$userId, 'login' => (string)$user['users_login'], 'source' => 'gse-billing'),
    ), 'gse-customer-' . $mode . '-' . $userId . '-' . date('YmdH') . ($row ? '-replaces-' . $row['customer_id'] : ''));
    // (the key stops a double click making two customers; a replacement gets its own key)
    if (!$res['ok']) {
        return array('ok' => false, 'id' => '', 'error' => $res['error']);
    }
    $customerId = (string)$res['data']['id'];
    $stmt = $db->prepare("INSERT INTO `{$prefix}billing_stripe_customers` (user_id, mode, customer_id, created_at) VALUES (?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id)");
    $stmt->bind_param('iss', $userId, $mode, $customerId);
    $stmt->execute();
    $stmt->close();
    return array('ok' => true, 'id' => $customerId, 'error' => '');
}

/**
 * Builds the server for a newly paid order through the panel bridge, as the PayPal webhook does.
 * Non-fatal: returns a note. Tests switch it off by defining STRIPE_BILLING_NO_PROVISION.
 */
function stripe_try_provision(int $orderId, int $userId): string
{
    if (defined('STRIPE_BILLING_NO_PROVISION')) {
        return 'provisioning skipped (test)';
    }
    $dir = dirname(__DIR__);
    $bridge = $dir . '/includes/panel_bridge.php';
    $create = $dir . '/create_servers.php';
    if (!is_file($bridge) || !is_file($create)) {
        return 'provisioning skipped: panel bridge not found';
    }
    try {
        require_once $bridge;
        if (!function_exists('billing_panel_bootstrap')) {
            return 'provisioning skipped: no panel bootstrap';
        }
        $ctx = billing_panel_bootstrap();
        if (!$ctx || empty($ctx['db'])) {
            return 'provisioning skipped: panel bootstrap failed';
        }
        $GLOBALS['db'] = $ctx['db'];
        $GLOBALS['settings'] = $ctx['settings'] ?? array();
        require_once $create;
        if (!function_exists('billing_invoke_provision')) {
            return 'provisioning skipped: provisioning function missing';
        }
        $r = billing_invoke_provision(array('order_ids' => array($orderId), 'user_id' => $userId, 'is_admin' => true));
        return 'provisioning: ' . (int)($r['provisioned_count'] ?? 0) . ' built, ' . (int)($r['failed_count'] ?? 0) . ' failed';
    } catch (Throwable $e) {
        return 'provisioning error: ' . $e->getMessage();
    }
}

/**
 * Marks one panel invoice paid by Stripe (only once) and builds the server for a new order.
 * Returns array(ok, note).
 */
function stripe_apply_paid_invoice(mysqli $db, string $prefix, int $invoiceId, string $txid, int $cents, string $currency, int $couponId = 0): array
{
    $repo = new BillingRepository($db, $prefix);
    $invoice = $repo->getInvoice($invoiceId);
    if (!$invoice) {
        return array(false, 'invoice ' . $invoiceId . ' not found');
    }
    $paid = round($cents / 100, 2);
    $subtotal = (float)($invoice['subtotal'] ?? 0) > 0 ? (float)$invoice['subtotal'] : $paid;
    $result = (new BillingLifecycleService($repo))->processPaidInvoice($invoiceId, array(
        'transaction_external_id' => $txid,
        'payment_method' => 'stripe',
        'amount' => $paid,
        'currency' => strtoupper($currency ?: 'usd'),
        'paid_at' => date('Y-m-d H:i:s'),
        'raw_response' => array('stripe' => $txid),
    ), array(
        'amount' => $paid,
        'total_due' => $paid,
        'subtotal' => $subtotal,
        'discount_amount' => max(0, round($subtotal - $paid, 2)),
        'invoice_duration' => (string)($invoice['invoice_duration'] ?? 'month'),
        'rate_type' => (string)($invoice['rate_type'] ?? 'monthly'),
        'coupon_id' => $couponId > 0 ? $couponId : null,
    ));
    if (empty($result['success'])) {
        return array(false, 'invoice ' . $invoiceId . ': ' . ($result['error'] ?? 'unknown error'));
    }
    $note = 'invoice ' . $invoiceId . ' paid';
    $orderId = (int)($result['order_id'] ?? 0);
    if ($orderId > 0 && (int)($result['home_id'] ?? 0) <= 0 && !empty($result['became_paid'])
        && (string)($invoice['invoice_kind'] ?? 'game') === 'game') {
        $note .= ' (order ' . $orderId . '; ' . stripe_try_provision($orderId, (int)($invoice['user_id'] ?? 0)) . ')';
    }
    return array(true, $note);
}

/** Splits the invoice_amounts metadata ("12:800,13:1200") into invoice id => cents. */
function stripe_metadata_amounts(array $meta): array
{
    $out = array();
    foreach (explode(',', (string)($meta['invoice_amounts'] ?? '')) as $pair) {
        $bits = explode(':', trim($pair));
        if (count($bits) === 2 && (int)$bits[0] > 0) {
            $out[(int)$bits[0]] = max(0, (int)$bits[1]);
        }
    }
    return $out;
}

/**
 * Charges a server's saved card for a renewal invoice (called by the daily billing job).
 * Returns array(status => paid|failed|skipped, note, reference).
 */
function stripe_autopay_charge(mysqli $db, string $prefix, StripeClient $client, array $autopay, array $invoice): array
{
    $invoiceId = (int)$invoice['invoice_id'];
    $cents = stripe_invoice_due_cents($invoice);
    if ($cents <= 0) {
        return array('status' => 'skipped', 'note' => 'nothing to charge', 'reference' => '');
    }
    if ((string)($autopay['customer_ref'] ?? '') === '' || (string)($autopay['method_ref'] ?? '') === '') {
        return array('status' => 'failed', 'note' => 'no saved payment method', 'reference' => '');
    }
    $currency = strtolower(trim((string)($invoice['currency'] ?? '')) ?: 'usd');
    $res = $client->request('POST', 'payment_intents', array(
        'amount' => $cents,
        'currency' => $currency,
        'customer' => (string)$autopay['customer_ref'],
        'payment_method' => (string)$autopay['method_ref'],
        'off_session' => true,
        'confirm' => true,
        'description' => stripe_invoice_label($invoice) . ' (auto-renew)',
        'metadata' => array('purpose' => 'autopay', 'invoice_id' => (string)$invoiceId, 'home_id' => (string)(int)($invoice['home_id'] ?? 0),
            'user_id' => (string)(int)($invoice['user_id'] ?? 0), 'source' => 'gse-billing'),
    ), 'gse-autopay-' . $invoiceId . '-' . date('Ymd'));
    if ($res['ok'] && (string)($res['data']['status'] ?? '') === 'succeeded') {
        $pi = (string)$res['data']['id'];
        list($ok, $note) = stripe_apply_paid_invoice($db, $prefix, $invoiceId, $pi, (int)$res['data']['amount'], $currency);
        return array('status' => $ok ? 'paid' : 'failed', 'note' => $note, 'reference' => $pi);
    }
    $reference = (string)($res['data']['id'] ?? ($res['data']['error']['payment_intent']['id'] ?? ''));
    if ($res['ok']) {
        $reason = (string)($res['data']['status'] ?? '') === 'requires_action'
            ? 'the bank asked the customer to confirm this payment'
            : 'payment status ' . (string)($res['data']['status'] ?? 'unknown');
        return array('status' => 'failed', 'note' => $reason, 'reference' => $reference);
    }
    $code = (string)($res['data']['error']['decline_code'] ?? ($res['code'] ?? ''));
    return array('status' => 'failed', 'note' => $res['error'] . ($code !== '' ? ' (' . $code . ')' : ''), 'reference' => $reference);
}

/* ---------------------------------------------------------------------------
   Webhook events
--------------------------------------------------------------------------- */

/** Records an event the first time it arrives. Returns 'new', 'retry' (seen but not finished) or 'duplicate'. */
function stripe_event_claim(mysqli $db, string $prefix, array $event): string
{
    $id = (string)($event['id'] ?? '');
    $type = (string)($event['type'] ?? '');
    $object = (string)($event['data']['object']['id'] ?? '');
    $live = !empty($event['livemode']) ? 1 : 0;
    $raw = json_encode($event);
    $stmt = $db->prepare("INSERT IGNORE INTO `{$prefix}billing_stripe_events` (event_id, event_type, object_id, livemode, processing_status, raw_json, created_at)
                          VALUES (?, ?, ?, ?, 'received', ?, NOW())");
    $stmt->bind_param('sssis', $id, $type, $object, $live, $raw);
    $stmt->execute();
    $inserted = $stmt->affected_rows === 1;
    $stmt->close();
    if ($inserted) {
        return 'new';
    }
    $stmt = $db->prepare("SELECT processing_status FROM `{$prefix}billing_stripe_events` WHERE event_id = ? LIMIT 1");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && in_array($row['processing_status'], array('processed', 'ignored'), true)) ? 'duplicate' : 'retry';
}

function stripe_event_finish(mysqli $db, string $prefix, string $eventId, string $status, string $note): void
{
    $note = mb_substr($note, 0, 1000);
    $stmt = $db->prepare("UPDATE `{$prefix}billing_stripe_events` SET processing_status = ?, note = ?, processed_at = NOW() WHERE event_id = ?");
    $stmt->bind_param('sss', $status, $note, $eventId);
    $stmt->execute();
    $stmt->close();
}

/** Applies one verified event. Returns array(status => processed|ignored|failed, note); failed makes Stripe resend. */
function stripe_handle_event(mysqli $db, string $prefix, array $event): array
{
    $object = (array)($event['data']['object'] ?? array());
    switch ((string)($event['type'] ?? '')) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            return stripe_on_checkout_completed($db, $prefix, $object);
        case 'payment_intent.succeeded':
            return stripe_on_payment_intent_succeeded($db, $prefix, $object);
        case 'payment_intent.payment_failed':
            return array('status' => 'processed', 'note' => 'payment failed: ' . (string)($object['last_payment_error']['message'] ?? 'unknown')
                . ' (invoice ' . (string)($object['metadata']['invoice_id'] ?? '?') . ')');
        case 'charge.refunded':
            return stripe_on_charge_refunded($db, $prefix, $object);
        default:
            return array('status' => 'ignored', 'note' => 'event type not used');
    }
}

/** The customer finished Checkout: Pay Now (invoices paid) or auto-renew (card saved). */
function stripe_on_checkout_completed(mysqli $db, string $prefix, array $session): array
{
    if ((string)($session['mode'] ?? '') === 'setup') {
        return stripe_on_autorenew_saved($db, $prefix, $session);
    }
    $paymentStatus = (string)($session['payment_status'] ?? '');
    if (!in_array($paymentStatus, array('paid', 'no_payment_required'), true)) {
        return array('status' => 'ignored', 'note' => 'checkout not paid yet (' . $paymentStatus . ')');
    }
    $meta = (array)($session['metadata'] ?? array());
    $amounts = stripe_metadata_amounts($meta);
    if (!$amounts) {
        return array('status' => 'ignored', 'note' => 'no panel invoices on this checkout');
    }
    $txid = (string)($session['payment_intent'] ?? '') ?: (string)($session['id'] ?? '');
    $currency = (string)($session['currency'] ?? 'usd');
    $notes = array();
    $failed = false;
    foreach ($amounts as $invoiceId => $cents) {
        list($ok, $note) = stripe_apply_paid_invoice($db, $prefix, $invoiceId, $txid, $cents, $currency, (int)($meta['panel_coupon_id'] ?? 0));
        $notes[] = $note;
        $failed = $failed || !$ok;
    }
    return array('status' => $failed ? 'failed' : 'processed', 'note' => implode('; ', $notes));
}

/** Auto-renew set up: keep Stripe's references to the saved card for that server. */
function stripe_on_autorenew_saved(mysqli $db, string $prefix, array $session): array
{
    $meta = (array)($session['metadata'] ?? array());
    $homeId = (int)($meta['home_id'] ?? 0);
    $userId = (int)($meta['user_id'] ?? 0);
    if ($homeId <= 0 || ($meta['purpose'] ?? '') !== 'autorenew') {
        return array('status' => 'ignored', 'note' => 'not an auto-renew setup');
    }
    $stmt = $db->prepare("SELECT user_id_main FROM `{$prefix}server_homes` WHERE home_id = ? LIMIT 1");
    $stmt->bind_param('i', $homeId);
    $stmt->execute();
    $home = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$home || (int)$home['user_id_main'] !== $userId) {
        return array('status' => 'ignored', 'note' => 'server ' . $homeId . ' does not belong to user ' . $userId);
    }
    $setupIntent = $session['setup_intent'] ?? '';
    $method = is_array($setupIntent) ? (string)($setupIntent['payment_method'] ?? '') : '';
    if ($method === '' && is_string($setupIntent) && $setupIntent !== '') {
        $client = billing_stripe_client($db, $prefix);
        if (!$client) {
            return array('status' => 'failed', 'note' => 'Stripe is not configured');
        }
        $si = $client->request('GET', 'setup_intents/' . rawurlencode($setupIntent));
        if (!$si['ok']) {
            return array('status' => 'failed', 'note' => 'could not read the setup: ' . $si['error']);
        }
        $method = (string)($si['data']['payment_method'] ?? '');
    }
    if ($method === '') {
        return array('status' => 'failed', 'note' => 'no saved payment method on the setup');
    }
    billing_autopay_save($db, $prefix, $homeId, $userId, 'stripe', (string)($session['customer'] ?? ''), $method);
    return array('status' => 'processed', 'note' => 'auto-renew on for server ' . $homeId);
}

/** An automatic charge succeeded (normally recorded by the daily job already; this is the safety net). */
function stripe_on_payment_intent_succeeded(mysqli $db, string $prefix, array $pi): array
{
    $meta = (array)($pi['metadata'] ?? array());
    if (($meta['purpose'] ?? '') !== 'autopay' || (int)($meta['invoice_id'] ?? 0) <= 0) {
        return array('status' => 'ignored', 'note' => 'handled by checkout');
    }
    list($ok, $note) = stripe_apply_paid_invoice($db, $prefix, (int)$meta['invoice_id'], (string)$pi['id'],
        (int)($pi['amount_received'] ?? ($pi['amount'] ?? 0)), (string)($pi['currency'] ?? 'usd'));
    return array('status' => $ok ? 'processed' : 'failed', 'note' => $note);
}

/** A refund was made (from the panel or the Stripe dashboard). A full refund cancels the invoices it paid. */
function stripe_on_charge_refunded(mysqli $db, string $prefix, array $charge): array
{
    $txids = array_values(array_filter(array((string)($charge['payment_intent'] ?? ''), (string)($charge['id'] ?? ''))));
    if (!$txids) {
        return array('status' => 'ignored', 'note' => 'no payment reference');
    }
    $in = implode(',', array_fill(0, count($txids), '?'));
    $stmt = $db->prepare("SELECT invoice_id FROM `{$prefix}billing_invoices` WHERE payment_txid IN ($in)");
    $stmt->bind_param(str_repeat('s', count($txids)), ...$txids);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'invoice_id'));
    $stmt->close();
    if (!$ids) {
        return array('status' => 'ignored', 'note' => 'no panel invoice for this charge');
    }
    $amount = (int)($charge['amount'] ?? 0);
    $refunded = (int)($charge['amount_refunded'] ?? 0);
    if ($amount > 0 && $refunded >= $amount) {
        $lifecycle = new BillingLifecycleService(new BillingRepository($db, $prefix));
        foreach ($ids as $id) {
            $lifecycle->cancelInvoice($id, 'refunded');
        }
        return array('status' => 'processed', 'note' => 'fully refunded: invoice ' . implode(', ', $ids));
    }
    return array('status' => 'processed', 'note' => 'partly refunded (' . round($refunded / 100, 2) . ') for invoice ' . implode(', ', $ids));
}
