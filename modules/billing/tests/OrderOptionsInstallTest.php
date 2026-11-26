<?php
// Order option tables: the install SQL is the single list used by the Billing database step and by
// the Order Options page; the payment method switch counts as on until an admin saves it.
// Run with: php modules/billing/tests/OrderOptionsInstallTest.php
require_once __DIR__ . '/../includes/order_options.php';
require_once __DIR__ . '/../includes/payment_methods.php';

function assertOptionsSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$sql = order_options_install_sql('gsp_');
assertOptionsSame(4, count($sql), 'four statements: two tables, the first option, the old service switched off');
assertOptionsSame(true, strpos($sql[0], 'CREATE TABLE IF NOT EXISTS `gsp_billing_order_options`') === 0, 'options table is created only when missing');
assertOptionsSame(true, strpos($sql[1], 'CREATE TABLE IF NOT EXISTS `gsp_billing_invoice_options`') === 0, 'invoice options table is created only when missing');
assertOptionsSame(true, strpos($sql[2], 'WHERE NOT EXISTS') !== false, 'the first option is added only once, so running the step later does not add it twice');
assertOptionsSame(true, strpos($sql[3], '`gsp_billing_service_types`') !== false, 'the prefix is used on every table');
assertOptionsSame($sql, order_options_install_sql('gsp_'), 'same prefix gives the same SQL');

assertOptionsSame(true, billing_payment_method_enabled(false, 'gsp_', 'paypal'), 'without a database the method counts as on');

echo "OrderOptionsInstallTest OK\n";
