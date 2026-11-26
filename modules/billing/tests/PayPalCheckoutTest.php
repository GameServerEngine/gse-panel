<?php
require_once __DIR__ . '/../classes/PaymentGatewayInterface.php';
require_once __DIR__ . '/../classes/PayPalGateway.php';

// PayPal answers a new order with links; the customer must be sent to the "approve" one.
$order = array('id' => 'ORDER123', 'links' => array(
    array('rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123'),
    array('rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123'),
));
if (PayPalGateway::approveUrl($order) !== 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123') {
    throw new RuntimeException('payer-action link not found');
}
$order['links'][1]['rel'] = 'approve';
if (PayPalGateway::approveUrl($order) === '') {
    throw new RuntimeException('approve link not found');
}
if (PayPalGateway::approveUrl(array('id' => 'X')) !== '') {
    throw new RuntimeException('a missing link must give an empty string');
}

// The cart no longer loads the PayPal browser script; it uses the redirect button.
$cart = file_get_contents(__DIR__ . '/../cart.php');
if (strpos($cart, 'paypal.com/sdk/js') !== false || strpos($cart, 'paypal-button-container') !== false) {
    throw new RuntimeException('cart.php still contains the old PayPal script');
}
if (strpos($cart, 'api/paypal_checkout.php') === false) {
    throw new RuntimeException('cart.php does not call the PayPal checkout endpoint');
}
echo "PayPalCheckoutTest passed\n";

// The saved PayPal keys live on the __global__ row; an older row with no keys must not win.
$credentials = file_get_contents(dirname(__DIR__) . '/includes/paypal_credentials.php');
if (strpos($credentials, "ORDER BY (game_key = '__global__') DESC, config_id ASC") === false) {
    fwrite(STDERR, "FAIL: billing_paypal_config must read the __global__ row first\n");
    exit(1);
}
echo "PayPal credentials row order ok\n";
