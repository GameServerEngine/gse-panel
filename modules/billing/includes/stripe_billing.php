<?php
/**
 * Stripe billing for the ordering site.
 *
 * Checkout: one Stripe Checkout Session per panel invoice. A game server rental becomes a Stripe
 * subscription (one per server) so renewals are charged automatically. Service invoices, and terms
 * Stripe cannot repeat (longer than a year), are a one-time payment.
 *
 * Webhooks: each signed event is stored once in billing_stripe_events and applied to the panel's
 * invoices and orders through BillingLifecycleService, the same path PayPal payments take.
 */
require_once __DIR__ . '/../classes/StripeClient.php';
require_once __DIR__ . '/../classes/BillingRepository.php';
require_once __DIR__ . '/../classes/BillingLifecycleService.php';
require_once __DIR__ . '/stripe_config.php';

if (!function_exists('stripe_money_to_cents')) {
    function stripe_money_to_cents($amount): int
    {
        return (int)round(((float)$amount) * 100);
    }
}

/** Stripe's billing interval for a game invoice, or null when Stripe cannot repeat it (longer than a year). */
function stripe_recurring_for_invoice(array $invoice): ?array
{
    $duration = strtolower((string)($invoice['invoice_duration'] ?? $invoice['rate_type'] ?? 'month'));
    $map = array('daily' => 'day', 'monthly' => 'month', 'yearly' => 'year');
    $duration = $map[$duration] ?? $duration;
    $qty = max(1, (int)($invoice['qty'] ?? 1));
    if (strpos($duration, 'day') !== false) {
        $interval = 'day';
        $max = 365;
    } elseif (strpos($duration, 'year') !== false) {
        $interval = 'year';
        $max = 1;
    } else {
        $interval = 'month';
        $max = 12;
    }
    return $qty > $max ? null : array('interval' => $interval, 'interval_count' => $qty);
}

/** The regular price of an invoice for one billing period, and what is charged now (after any discount). */
function stripe_invoice_amounts(array $invoice, float $extraDiscount = 0.0): array
{
    $subtotal = (float)($invoice['subtotal'] ?? 0);
    $amount = (float)($invoice['amount'] ?? 0);
    $due = (float)($invoice['total_due'] ?? 0);
    $regular = $subtotal > 0 ? $subtotal : ($amount > 0 ? $amount : $due);
    $now = $due > 0 ? $due : ($amount > 0 ? $amount : $regular);
    $now = max(0.0, $now - max(0.0, $extraDiscount));
    return array('regular_cents' => stripe_money_to_cents($regular), 'now_cents' => stripe_money_to_cents(min($now, $regular)));
}

/**
 * Checkout Session parameters for one invoice.
 * $urls: return (embedded form), success and cancel (hosted page).
 * $options: ui (form|hosted), automatic_tax (bool), coupon_id (Stripe coupon), product_name, extra_discount (float),
 * panel_coupon_id (the panel coupon behind the discount, recorded on the paid invoice).
 */
function stripe_checkout_params(array $invoice, string $customerId, array $urls, array $options = array()): array
{
    $id = (int)$invoice['invoice_id'];
    $currency = strtolower(trim((string)($invoice['currency'] ?? '')) ?: 'usd');
    $amounts = stripe_invoice_amounts($invoice, (float)($options['extra_discount'] ?? 0));
    $name = trim((string)($options['product_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($invoice['description'] ?? '')) ?: (trim((string)($invoice['home_name'] ?? '')) ?: 'Invoice #' . $id);
    }
    $meta = array(
        'invoice_id' => (string)$id,
        'order_id' => (string)(int)($invoice['order_id'] ?? 0),
        'user_id' => (string)(int)($invoice['user_id'] ?? 0),
        'home_id' => (string)(int)($invoice['home_id'] ?? 0),
        'source' => 'gse-billing',
    );
    if (!empty($options['panel_coupon_id'])) {
        $meta['panel_coupon_id'] = (string)(int)$options['panel_coupon_id'];
    }
    $isGame = (string)($invoice['invoice_kind'] ?? 'game') === 'game';
    $recurring = $isGame ? stripe_recurring_for_invoice($invoice) : null;

    $price = array('currency' => $currency, 'unit_amount' => $amounts['regular_cents'], 'product_data' => array('name' => mb_substr($name, 0, 250)));
    $params = array(
        'customer' => $customerId,
        'client_reference_id' => (string)$id,
        'metadata' => $meta,
        'customer_update' => array('address' => 'auto', 'name' => 'auto'),
        'billing_address_collection' => 'auto',
    );
    $form = ($options['ui'] ?? 'hosted') === 'form';
    if ($form) {
        // Embedded payment form on the cart page, with the settings chosen in Stripe Checkout Studio.
        $params['ui_mode'] = 'form';
        $params['return_url'] = $urls['return'];
        $params['phone_number_collection'] = array('enabled' => false);
        $params['submit_type'] = 'auto';
        $params['integration_identifier'] = 'custom_embedded_web_0001';
    } else {
        // Stripe's hosted checkout page (used when the embedded form cannot load).
        $params['success_url'] = $urls['success'];
        $params['cancel_url'] = $urls['cancel'];
    }
    if ($recurring !== null) {
        $price['recurring'] = $recurring;
        $params['mode'] = 'subscription';
        $params['subscription_data'] = array('metadata' => $meta);
        if ($form) {
            $params['payment_method_collection'] = 'always';
        }
    } else {
        $params['mode'] = 'payment';
        $params['payment_intent_data'] = array('metadata' => $meta);
        $params['invoice_creation'] = array('enabled' => true, 'invoice_data' => array('metadata' => $meta));
    }
    $params['line_items'] = array(array('quantity' => 1, 'price_data' => $price));
    if (!empty($options['coupon_id'])) {
        $params['discounts'] = array(array('coupon' => (string)$options['coupon_id']));
    }
    // Stripe Tax follows the admin setting (off unless switched on in Billing > Settings).
    $params['automatic_tax'] = array('enabled' => !empty($options['automatic_tax']));
    if (!empty($options['automatic_tax'])) {
        $params['billing_address_collection'] = 'required';
    }
    return $params;
}

/** The Stripe customer for a panel user, created on first use. Returns array(ok, id, error). */
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

/** The order that a Stripe subscription pays for, or null. */
function stripe_order_for_subscription(mysqli $db, string $prefix, string $subscriptionId): ?array
{
    if ($subscriptionId === '') {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_orders` WHERE stripe_subscription_id = ? ORDER BY order_id DESC LIMIT 1");
    $stmt->bind_param('s', $subscriptionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Applies one verified Stripe event. Returns array(status => processed|ignored|failed, note).
 * 'failed' asks Stripe to send the event again later (the endpoint answers 500).
 */
function stripe_handle_event(mysqli $db, string $prefix, array $event): array
{
    $object = (array)($event['data']['object'] ?? array());
    switch ((string)($event['type'] ?? '')) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            return stripe_on_checkout_paid($db, $prefix, $object);
        case 'invoice.paid':
            return stripe_on_invoice_paid($db, $prefix, $object);
        case 'invoice.payment_failed':
            return stripe_on_invoice_failed($db, $prefix, $object);
        case 'customer.subscription.deleted':
            return stripe_on_subscription_deleted($db, $prefix, $object);
        case 'charge.refunded':
            return stripe_on_charge_refunded($db, $prefix, $object);
        default:
            return array('status' => 'ignored', 'note' => 'event type not used');
    }
}

/**
 * Builds the server for a newly paid order through the panel bridge, as the PayPal webhook does.
 * Non-fatal: returns a note, and the order can still be provisioned by hand. Tests switch it off
 * by defining STRIPE_BILLING_NO_PROVISION.
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

/** First payment: the customer finished Checkout. Marks the panel invoice paid, which creates the order and the server. */
function stripe_on_checkout_paid(mysqli $db, string $prefix, array $session): array
{
    $paymentStatus = (string)($session['payment_status'] ?? '');
    if (!in_array($paymentStatus, array('paid', 'no_payment_required'), true)) {
        return array('status' => 'ignored', 'note' => 'checkout not paid yet (' . $paymentStatus . ')');
    }
    $invoiceId = (int)($session['metadata']['invoice_id'] ?? ($session['client_reference_id'] ?? 0));
    if ($invoiceId <= 0) {
        return array('status' => 'ignored', 'note' => 'no panel invoice on this checkout');
    }
    $repo = new BillingRepository($db, $prefix);
    $lifecycle = new BillingLifecycleService($repo);
    $invoice = $repo->getInvoice($invoiceId);
    if (!$invoice) {
        return array('status' => 'failed', 'note' => 'panel invoice ' . $invoiceId . ' not found');
    }
    $tax = (int)($session['total_details']['amount_tax'] ?? 0);
    $paidCents = max(0, (int)($session['amount_total'] ?? 0) - $tax);
    $paid = round($paidCents / 100, 2);
    $txid = (string)($session['invoice'] ?? '');
    if ($txid === '') {
        $txid = (string)($session['payment_intent'] ?? '');
    }
    if ($txid === '') {
        $txid = (string)($session['id'] ?? '');
    }
    $subtotal = (float)($invoice['subtotal'] ?? 0) > 0 ? (float)$invoice['subtotal'] : $paid;
    $result = $lifecycle->processPaidInvoice($invoiceId, array(
        'transaction_external_id' => $txid,
        'payment_method' => 'stripe',
        'amount' => $paid,
        'currency' => strtoupper((string)($session['currency'] ?? 'usd')),
        'paid_at' => date('Y-m-d H:i:s'),
        'raw_response' => array('checkout_session' => (string)($session['id'] ?? ''), 'subscription' => (string)($session['subscription'] ?? ''),
            'customer' => (string)($session['customer'] ?? ''), 'tax_cents' => $tax),
    ), array(
        'amount' => $paid,
        'total_due' => $paid,
        'subtotal' => $subtotal,
        'discount_amount' => max(0, round($subtotal - $paid, 2)),
        'invoice_duration' => (string)($invoice['invoice_duration'] ?? 'month'),
        'rate_type' => (string)($invoice['rate_type'] ?? 'monthly'),
        'coupon_id' => (int)($session['metadata']['panel_coupon_id'] ?? 0) ?: null,
    ));
    if (empty($result['success'])) {
        return array('status' => 'failed', 'note' => 'could not mark invoice ' . $invoiceId . ' paid: ' . ($result['error'] ?? 'unknown'));
    }
    $orderId = (int)($result['order_id'] ?? 0);
    $subscription = (string)($session['subscription'] ?? '');
    if ($orderId > 0 && $subscription !== '') {
        $customer = (string)($session['customer'] ?? '');
        $stmt = $db->prepare("UPDATE `{$prefix}billing_orders` SET stripe_subscription_id = ?, stripe_customer_id = ? WHERE order_id = ?");
        $stmt->bind_param('ssi', $subscription, $customer, $orderId);
        $stmt->execute();
        $stmt->close();
    }
    $provision = '';
    if ($orderId > 0 && (int)($result['home_id'] ?? 0) <= 0 && (string)($invoice['invoice_kind'] ?? 'game') === 'game') {
        $provision = '; ' . stripe_try_provision($orderId, (int)($invoice['user_id'] ?? 0));
    }
    return array('status' => 'processed', 'note' => 'invoice ' . $invoiceId . ' paid' . ($orderId ? ', order ' . $orderId : '')
        . ($subscription !== '' ? ', subscription ' . $subscription : '') . $provision);
}

/** Automatic renewal: Stripe charged the next period. Records a paid renewal invoice, which extends the server. */
function stripe_on_invoice_paid(mysqli $db, string $prefix, array $inv): array
{
    $reason = (string)($inv['billing_reason'] ?? '');
    if ($reason !== 'subscription_cycle') {
        return array('status' => 'ignored', 'note' => 'billing reason ' . $reason . ' (first payments are handled at checkout)');
    }
    $stripeInvoiceId = (string)($inv['id'] ?? '');
    $order = stripe_order_for_subscription($db, $prefix, (string)($inv['subscription'] ?? ''));
    if (!$order) {
        return array('status' => 'failed', 'note' => 'no order for subscription ' . (string)($inv['subscription'] ?? ''));
    }
    $repo = new BillingRepository($db, $prefix);
    if ($repo->getInvoiceByPaymentTxid($stripeInvoiceId)) {
        return array('status' => 'processed', 'note' => 'renewal ' . $stripeInvoiceId . ' already recorded');
    }
    $orderId = (int)$order['order_id'];
    $homeId = (int)($order['home_id'] ?? 0);
    if ($homeId <= 0) {
        return array('status' => 'failed', 'note' => 'order ' . $orderId . ' has no server yet');
    }
    $lifecycle = new BillingLifecycleService($repo);
    $last = $repo->getLatestInvoiceForOrder($orderId, true) ?: array();
    $home = $repo->getServerHomeBilling($homeId) ?: array();
    $tax = (int)($inv['tax'] ?? 0);
    $paid = round(max(0, (int)($inv['amount_paid'] ?? 0) - $tax) / 100, 2);
    $context = array(
        'order_id' => $orderId,
        'service_id' => (int)($order['service_id'] ?? 0),
        'description' => 'Renewal: ' . (string)($order['home_name'] ?? ''),
        'invoice_duration' => (string)($last['invoice_duration'] ?? 'month'),
        'rate_type' => (string)($last['rate_type'] ?? 'monthly'),
        'rate_per_player' => (float)($last['rate_per_player'] ?? 0),
        'players' => (int)($last['players'] ?? ($order['max_players'] ?? 0)),
        'qty' => (int)($last['qty'] ?? 1),
        'subtotal' => $paid,
        'total_due' => $paid,
        'currency' => strtoupper((string)($inv['currency'] ?? 'usd')),
    );
    $renewal = $lifecycle->createRenewalInvoice(array(
        'home_id' => $homeId,
        'home_name' => (string)($order['home_name'] ?? ''),
        'user_id' => (int)($order['user_id'] ?? 0),
        'next_invoice_date' => (string)($home['server_expiration_date'] ?? ($order['end_date'] ?? date('Y-m-d H:i:s'))),
    ), $context);
    if (empty($renewal['success'])) {
        return array('status' => 'failed', 'note' => 'could not create the renewal invoice: ' . ($renewal['error'] ?? 'unknown'));
    }
    $paidAt = (int)($inv['status_transitions']['paid_at'] ?? 0);
    $result = $lifecycle->processPaidInvoice((int)$renewal['invoice_id'], array(
        'transaction_external_id' => $stripeInvoiceId,
        'payment_method' => 'stripe',
        'amount' => $paid,
        'currency' => $context['currency'],
        'paid_at' => date('Y-m-d H:i:s', $paidAt > 0 ? $paidAt : time()),
        'raw_response' => array('stripe_invoice' => $stripeInvoiceId, 'subscription' => (string)($inv['subscription'] ?? ''), 'tax_cents' => $tax),
    ), $context);
    if (empty($result['success'])) {
        return array('status' => 'failed', 'note' => 'could not mark renewal invoice ' . $renewal['invoice_id'] . ' paid: ' . ($result['error'] ?? 'unknown'));
    }
    return array('status' => 'processed', 'note' => 'renewal invoice ' . $renewal['invoice_id'] . ' paid for order ' . $orderId);
}

/** A renewal charge failed. Stripe retries on its own; if it never succeeds, the daily job suspends the server at expiry. */
function stripe_on_invoice_failed(mysqli $db, string $prefix, array $inv): array
{
    $order = stripe_order_for_subscription($db, $prefix, (string)($inv['subscription'] ?? ''));
    $attempt = (int)($inv['attempt_count'] ?? 0);
    return array('status' => 'processed', 'note' => 'payment failed' . ($order ? ' for order ' . $order['order_id'] : '')
        . ' (attempt ' . $attempt . '); Stripe will retry');
}

/** The subscription ended (cancelled or unpaid): the server runs to its paid date and is then suspended. */
function stripe_on_subscription_deleted(mysqli $db, string $prefix, array $sub): array
{
    $order = stripe_order_for_subscription($db, $prefix, (string)($sub['id'] ?? ''));
    if (!$order) {
        return array('status' => 'ignored', 'note' => 'no order for this subscription');
    }
    $homeId = (int)($order['home_id'] ?? 0);
    if ($homeId > 0) {
        (new BillingRepository($db, $prefix))->updateServerHomeBilling($homeId, array('cancel_at_expiry' => 1));
    }
    return array('status' => 'processed', 'note' => 'order ' . $order['order_id'] . ' will not renew');
}

/** A refund was made (from the panel or the Stripe dashboard). A full refund cancels the panel invoice. */
function stripe_on_charge_refunded(mysqli $db, string $prefix, array $charge): array
{
    $repo = new BillingRepository($db, $prefix);
    $invoice = null;
    foreach (array((string)($charge['invoice'] ?? ''), (string)($charge['payment_intent'] ?? '')) as $txid) {
        if ($txid !== '' && ($invoice = $repo->getInvoiceByPaymentTxid($txid))) {
            break;
        }
    }
    if (!$invoice) {
        return array('status' => 'ignored', 'note' => 'no panel invoice for this charge');
    }
    $amount = (int)($charge['amount'] ?? 0);
    $refunded = (int)($charge['amount_refunded'] ?? 0);
    if ($amount > 0 && $refunded >= $amount) {
        (new BillingLifecycleService($repo))->cancelInvoice((int)$invoice['invoice_id'], 'refunded');
        return array('status' => 'processed', 'note' => 'invoice ' . $invoice['invoice_id'] . ' fully refunded');
    }
    return array('status' => 'processed', 'note' => 'invoice ' . $invoice['invoice_id'] . ' partly refunded (' . round($refunded / 100, 2) . ')');
}

/**
 * Stops a server's Stripe subscription: at the end of the paid term ($atPeriodEnd) or straight away.
 * Returns array(ok, note). A server without a Stripe subscription is fine (ok, nothing to do).
 */
function stripe_cancel_subscription_for_home(mysqli $db, string $prefix, int $homeId, bool $atPeriodEnd): array
{
    $stmt = $db->prepare("SELECT stripe_subscription_id FROM `{$prefix}billing_orders`
                          WHERE home_id = ? AND stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''
                          ORDER BY order_id DESC LIMIT 1");
    if (!$stmt) {
        return array('ok' => true, 'note' => 'no Stripe columns');
    }
    $stmt->bind_param('i', $homeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return array('ok' => true, 'note' => 'no Stripe subscription');
    }
    $client = billing_stripe_client($db, $prefix);
    if (!$client) {
        return array('ok' => false, 'note' => 'Stripe is not configured, so subscription ' . $row['stripe_subscription_id'] . ' was not stopped');
    }
    $sub = (string)$row['stripe_subscription_id'];
    $res = $atPeriodEnd
        ? $client->request('POST', 'subscriptions/' . rawurlencode($sub), array('cancel_at_period_end' => true))
        : $client->request('DELETE', 'subscriptions/' . rawurlencode($sub));
    if (!$res['ok'] && ($res['code'] ?? '') !== 'resource_missing') {
        return array('ok' => false, 'note' => 'Stripe would not stop subscription ' . $sub . ': ' . $res['error']);
    }
    return array('ok' => true, 'note' => 'Stripe subscription ' . $sub . ($atPeriodEnd ? ' ends at the end of the term' : ' cancelled'));
}
