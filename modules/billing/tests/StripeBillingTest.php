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
$payload = '{"id":"evt_1","type":"payment_intent.succeeded"}';
$t = 1700000000;
$sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
stripeExpect(StripeClient::verifySignature($payload, "t=$t,v1=$sig", $secret, 300, $t + 10), 'a correct signature passes');
stripeExpect(StripeClient::verifySignature($payload, "t=$t,v1=deadbeef,v1=$sig", $secret, 300, $t), 'one good signature among several passes');
stripeExpect(!StripeClient::verifySignature($payload . ' ', "t=$t,v1=$sig", $secret, 300, $t), 'a changed body fails');
stripeExpect(!StripeClient::verifySignature($payload, "t=$t,v1=$sig", 'whsec_other', 300, $t), 'the wrong secret fails');
stripeExpect(!StripeClient::verifySignature($payload, "t=$t,v1=$sig", $secret, 300, $t + 301), 'an old signature fails (replay)');
stripeExpect(!StripeClient::verifySignature($payload, '', $secret), 'no header fails');

// --- amounts ---
stripeExpect(stripe_invoice_due_cents(array('total_due' => '8.00', 'subtotal' => '10.00')) === 800, 'what is due comes from total_due');
stripeExpect(stripe_invoice_due_cents(array('amount' => '4.00')) === 400, 'falls back to amount');
stripeExpect(stripe_invoice_due_cents(array('total_due' => '10.00'), 2.5) === 750, 'a discount lowers it');
stripeExpect(stripe_invoice_due_cents(array('total_due' => '10.00'), 50) === 0, 'never below zero');

// --- Pay Now: one payment for the whole cart ---
$inv1 = array('invoice_id' => 12, 'user_id' => 7, 'total_due' => '12.00', 'currency' => 'USD', 'description' => 'Arma 3 (24 slots)');
$inv2 = array('invoice_id' => 13, 'user_id' => 7, 'total_due' => '5.00', 'currency' => 'USD', 'home_name' => 'Rust box');
$urls = array('return' => 'https://shop/r?s={CHECKOUT_SESSION_ID}', 'success' => 'https://shop/ok', 'cancel' => 'https://shop/no');
$p = stripe_cart_checkout_params(array($inv1, $inv2), 'cus_1', $urls, array('discounts' => array(13 => 1.0), 'panel_coupon_id' => 9));
stripeExpect($p['mode'] === 'payment' && !isset($p['ui_mode']), 'Pay Now is a one-time payment on Stripe\'s page by default');
stripeExpect($p['success_url'] === 'https://shop/ok' && $p['cancel_url'] === 'https://shop/no', 'hosted page links');
stripeExpect(count($p['line_items']) === 2 && $p['line_items'][0]['price_data']['unit_amount'] === 1200 && $p['line_items'][1]['price_data']['unit_amount'] === 400, 'one line per invoice with the panel\'s amounts (discount applied)');
stripeExpect($p['line_items'][0]['price_data']['product_data']['name'] === 'Invoice #12 - Arma 3 (24 slots)', 'line names carry the invoice number');
stripeExpect($p['metadata']['invoice_ids'] === '12,13' && $p['metadata']['invoice_amounts'] === '12:1200,13:400' && $p['metadata']['panel_coupon_id'] === '9', 'metadata maps the payment back to the invoices');
stripeExpect($p['payment_intent_data']['metadata']['invoice_ids'] === '12,13', 'the payment itself carries the invoice ids');
stripeExpect(!isset($p['invoice_creation']) && !isset($p['subscription_data']), 'no Stripe invoices and no Stripe subscriptions');
stripeExpect(stripe_metadata_amounts($p['metadata']) === array(12 => 1200, 13 => 400), 'metadata reads back');
$f = stripe_cart_checkout_params(array($inv1), 'cus_1', $urls, array('ui' => 'form', 'automatic_tax' => true));
stripeExpect($f['ui_mode'] === 'form' && $f['return_url'] === $urls['return'] && !isset($f['success_url']), 'the embedded form uses return_url');
stripeExpect($f['submit_type'] === 'auto' && $f['integration_identifier'] === 'custom_embedded_web_0001' && $f['phone_number_collection'] === array('enabled' => false), 'Checkout Studio settings on the form');
stripeExpect($f['automatic_tax'] === array('enabled' => true) && $f['billing_address_collection'] === 'required', 'tax on needs the full address');
$z = stripe_cart_checkout_params(array(array('invoice_id' => 20, 'total_due' => '0')), 'cus_1', $urls);
stripeExpect($z['line_items'] === array(), 'nothing to pay leaves no lines');

// --- auto-renew: save a card, charge nothing ---
$a = stripe_autorenew_checkout_params(array('home_id' => 5, 'user_id_main' => 7, 'home_name' => 'Arma 3'), 'cus_1', 'USD', $urls);
stripeExpect($a['mode'] === 'setup' && $a['currency'] === 'usd' && !isset($a['line_items']), 'auto-renew saves a card in setup mode, nothing is charged');
stripeExpect($a['metadata'] === array('purpose' => 'autorenew', 'home_id' => '5', 'user_id' => '7', 'source' => 'gse-billing'), 'the setup knows its server and customer');
stripeExpect($a['setup_intent_data']['metadata']['home_id'] === '5', 'so does the saved card setup');

echo 'StripeBillingTest passed' . PHP_EOL;
