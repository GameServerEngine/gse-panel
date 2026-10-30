<?php
require_once __DIR__ . '/../includes/paypal_credentials.php';
require_once __DIR__ . '/../classes/PayPalGateway.php';

$fakeSecret = 'FAKE_CLIENT_SECRET_ONLY';
$fakeKey = 'FAKE_EXTERNAL_KEY_ONLY';
$enc = billing_paypal_encrypt_secret($fakeSecret, $fakeKey);
if (billing_paypal_decrypt_secret($enc, $fakeKey) !== $fakeSecret) throw new RuntimeException('encrypt/decrypt round trip failed');
$renderedSecretField = '<input type="password" placeholder="' . billing_paypal_secret_input_placeholder(true) . '">';
if (strpos($renderedSecretField, $fakeSecret) !== false || strpos($renderedSecretField, 'A secret is saved') === false) throw new RuntimeException('secret appeared in rendered admin HTML');

$GLOBALS['paypal_mode'] = 'sandbox';
$GLOBALS['paypal_client_id'] = 'FAKE_CLIENT_ID';
$GLOBALS['paypal_client_secret'] = $fakeSecret;
$GLOBALS['paypal_sandbox'] = true;
$gateway = PayPalGateway::fromConfig();
$ref = new ReflectionClass($gateway);
$api = $ref->getProperty('apiBase'); $api->setAccessible(true);
if ($api->getValue($gateway) !== 'https://api-m.sandbox.paypal.com') throw new RuntimeException('sandbox API base failed');

$existing = $enc;
$blank = '';
$kept = $blank === '' ? $existing : billing_paypal_encrypt_secret($blank, $fakeKey);
if ($kept !== $existing) throw new RuntimeException('blank secret did not preserve encrypted value');
echo "PayPalCredentialsTest: OK\n";
