<?php

function assertSourceContains(string $file, string $needle, string $message): void
{
    $source = file_get_contents($file);
    if ($source === false || strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);

assertSourceContains($root . '/admin_invoices.php', "includes/admin_auth.php", 'admin invoice page must require admin authorization');
assertSourceContains($root . '/admin_invoices.php', "billing_action_validate_csrf", 'admin invoice page must validate CSRF');
assertSourceContains($root . '/admin_invoices.php', "adminUpdateInvoicePayment", 'admin invoice payment edit must use lifecycle service');

assertSourceContains($root . '/admin_orders.php', '$db->isAdmin($user_id)', 'admin orders page must check panel admin authorization');
assertSourceContains($root . '/admin_orders.php', "billing_action_validate_csrf", 'admin orders page must validate CSRF for term edits');
assertSourceContains($root . '/admin_orders.php', "adminChangeServiceTerm", 'admin service-term edit must use lifecycle service');

assertSourceContains($root . '/includes/provision_body.php', "unsafe_get_request", 'provisioning must reject GET state-changing requests');
assertSourceContains($root . '/includes/provision_body.php', "billing_action_validate_csrf", 'provisioning must validate CSRF for form requests');

assertSourceContains($root . '/renew_server.php', "\$_SERVER['REQUEST_METHOD'] !== 'POST'", 'renew endpoint must reject non-POST requests');
assertSourceContains($root . '/renew_server.php', "billing_action_validate_csrf", 'renew endpoint must validate CSRF');
assertSourceContains($root . '/renew_server.php', "createRenewalInvoice", 'renew endpoint must use lifecycle renewal creation');

assertSourceContains($root . '/webhook.php', "paypal/webhook.php", 'legacy webhook entrypoint must route to canonical PayPal webhook handler');
assertSourceContains($root . '/paypal/webhook.php', "recordGatewayAttempt", 'PayPal webhook must record pending/failed gateway attempts without mutating invoice truth');
assertSourceContains($root . '/paypal/webhook.php', "capture_amount_mismatch", 'PayPal webhook must reject mismatched capture totals');
assertSourceContains($root . '/paypal/webhook.php', "capture_no_matching_invoice", 'PayPal webhook must leave unmatched captures retryable');
assertSourceContains($root . '/paypal/webhook.php', "payment_already_recorded", 'PayPal webhook must recognize duplicate capture fulfillment by transaction id');
assertSourceContains($root . '/paypal/webhook.php', "approved_logged", 'PayPal webhook must treat duplicate approved callbacks as final audit events');
assertSourceContains($root . '/classes/PayPalGateway.php', "PayPal-Request-Id", 'PayPal gateway must send provider idempotency request ids');

echo "BillingRequestPathSourceTest passed\n";
