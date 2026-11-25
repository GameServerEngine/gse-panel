<?php
require_once __DIR__ . '/../classes/StripeClient.php';
require_once __DIR__ . '/../includes/stripe_billing.php';

function stripeExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Expected: ' . $message);
    }
}

// --- request encoding ---
$enc = StripeClient::encode(array('a' => array('b' => array(array('c' => 1))), 'flag' => true, 'off' => false, 'skip' => null));
parse_str($enc, $back);
stripeExpect($back['a']['b'][0]['c'] === '1', 'nested arrays become a[b][0][c]');
stripeExpect($back['flag'] === 'true' && $back['off'] === 'false', 'booleans become true/false');
stripeExpect(!isset($back['skip']), 'nulls are left out');

// --- webhook signatures ---
$secret = 'whsec_testsecret';
$payload = '{"id":"evt_1","type":"invoice.paid"}';
$t = 1700000000;
$sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
stripeExpect(StripeClient::verifySignature($payload, "t=$t,v1=$sig", $secret, 300, $t + 10), 'a correct signature passes');
stripeExpect(StripeClient::verifySignature($payload, "t=$t,v1=deadbeef,v1=$sig", $secret, 300, $t), 'one good signature among several passes');
stripeExpect(!StripeClient::verifySignature($payload . ' ', "t=$t,v1=$sig", $secret, 300, $t), 'a changed body fails');
stripeExpect(!StripeClient::verifySignature($payload, "t=$t,v1=$sig", 'whsec_other', 300, $t), 'the wrong secret fails');
stripeExpect(!StripeClient::verifySignature($payload, "t=$t,v1=$sig", $secret, 300, $t + 301), 'an old signature fails (replay)');
stripeExpect(!StripeClient::verifySignature($payload, "v1=$sig", $secret, 300, $t), 'a missing timestamp fails');
stripeExpect(!StripeClient::verifySignature($payload, '', $secret), 'no header fails');

// --- billing intervals ---
stripeExpect(stripe_recurring_for_invoice(array('invoice_duration' => 'month', 'qty' => 3)) === array('interval' => 'month', 'interval_count' => 3), '3 months');
stripeExpect(stripe_recurring_for_invoice(array('rate_type' => 'daily', 'qty' => 7)) === array('interval' => 'day', 'interval_count' => 7), '7 days');
stripeExpect(stripe_recurring_for_invoice(array('invoice_duration' => 'year', 'qty' => 1)) === array('interval' => 'year', 'interval_count' => 1), '1 year');
stripeExpect(stripe_recurring_for_invoice(array('invoice_duration' => 'year', 'qty' => 2)) === null, '2 years cannot repeat at Stripe');
stripeExpect(stripe_recurring_for_invoice(array('invoice_duration' => 'month', 'qty' => 13)) === null, '13 months cannot repeat at Stripe');

// --- amounts ---
$a = stripe_invoice_amounts(array('subtotal' => '10.00', 'total_due' => '8.00'));
stripeExpect($a === array('regular_cents' => 1000, 'now_cents' => 800), 'regular price and discounted first payment');
$a = stripe_invoice_amounts(array('subtotal' => '10.00', 'total_due' => '10.00'), 2.5);
stripeExpect($a['now_cents'] === 750, 'a cart coupon lowers the first payment');
$a = stripe_invoice_amounts(array('subtotal' => '10.00', 'total_due' => '10.00'), 50);
stripeExpect($a['now_cents'] === 0, 'a discount never goes below zero');
$a = stripe_invoice_amounts(array('amount' => '4.00'));
stripeExpect($a === array('regular_cents' => 400, 'now_cents' => 400), 'falls back to amount');

// --- checkout parameters ---
$invoice = array('invoice_id' => 42, 'order_id' => 0, 'user_id' => 7, 'home_id' => 0, 'invoice_kind' => 'game',
    'invoice_duration' => 'month', 'qty' => 1, 'subtotal' => '12.00', 'total_due' => '12.00', 'currency' => 'USD', 'description' => 'Arma 3 (24 slots)');
$urls = array('return' => 'https://shop/r?s={CHECKOUT_SESSION_ID}', 'success' => 'https://shop/ok', 'cancel' => 'https://shop/no');
$p = stripe_checkout_params($invoice, 'cus_1', $urls, array('ui' => 'form'));
stripeExpect($p['mode'] === 'subscription' && $p['ui_mode'] === 'form', 'a game server is an embedded-form subscription');
stripeExpect($p['return_url'] === $urls['return'] && !isset($p['success_url']) && !isset($p['cancel_url']), 'the form uses return_url only');
stripeExpect($p['payment_method_collection'] === 'always' && $p['submit_type'] === 'auto', 'Checkout Studio settings for subscriptions');
stripeExpect($p['phone_number_collection'] === array('enabled' => false) && $p['integration_identifier'] === 'custom_embedded_web_0001', 'more Studio settings');
stripeExpect($p['billing_address_collection'] === 'auto' && $p['automatic_tax'] === array('enabled' => false), 'address auto, tax off by default');
stripeExpect($p['line_items'][0]['price_data']['unit_amount'] === 1200 && $p['line_items'][0]['price_data']['recurring'] === array('interval' => 'month', 'interval_count' => 1), 'monthly price 12.00');
stripeExpect($p['metadata']['invoice_id'] === '42' && $p['subscription_data']['metadata']['invoice_id'] === '42' && $p['client_reference_id'] === '42', 'the invoice id travels with the session and the subscription');
stripeExpect($p['customer'] === 'cus_1', 'the customer is set');

$service = array('invoice_kind' => 'service') + $invoice;
$p = stripe_checkout_params($service, 'cus_1', $urls, array('ui' => 'hosted', 'coupon_id' => 'co_1', 'panel_coupon_id' => 9, 'automatic_tax' => true));
stripeExpect($p['mode'] === 'payment' && !isset($p['ui_mode']) && !isset($p['payment_method_collection']), 'a service invoice is a one-time hosted payment');
stripeExpect($p['success_url'] === 'https://shop/ok' && $p['cancel_url'] === 'https://shop/no', 'the hosted page uses success and cancel links');
stripeExpect(!empty($p['invoice_creation']['enabled']) && isset($p['payment_intent_data']['metadata']['invoice_id']), 'one-time payments get a Stripe invoice and metadata');
stripeExpect($p['discounts'] === array(array('coupon' => 'co_1')) && $p['metadata']['panel_coupon_id'] === '9', 'discount coupon and the panel coupon id');
stripeExpect($p['automatic_tax'] === array('enabled' => true) && $p['billing_address_collection'] === 'required', 'tax on needs the full address');

$long = array('invoice_duration' => 'year', 'qty' => 3) + $invoice;
$p = stripe_checkout_params($long, 'cus_1', $urls, array('ui' => 'form'));
stripeExpect($p['mode'] === 'payment' && !isset($p['payment_method_collection']), 'a 3-year term is a one-time payment');

echo 'StripeBillingTest passed' . PHP_EOL;
