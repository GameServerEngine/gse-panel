<?php
require_once __DIR__ . '/../includes/action_security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}

$_SESSION = [];
$token = billing_action_csrf_token('billing_test_csrf');
if ($token === '') {
    throw new RuntimeException('csrf token was not generated');
}
if (!billing_action_validate_csrf($token, 'billing_test_csrf')) {
    throw new RuntimeException('valid csrf token was rejected');
}
if (billing_action_validate_csrf('wrong-token', 'billing_test_csrf')) {
    throw new RuntimeException('invalid csrf token was accepted');
}

$safe = billing_action_safe_redirect('fallback.php', 'admin_invoices.php?msg=ok');
if ($safe !== 'admin_invoices.php?msg=ok') {
    throw new RuntimeException('safe relative redirect rejected');
}
$unsafe = billing_action_safe_redirect('fallback.php', 'https://example.invalid/');
if ($unsafe !== 'fallback.php') {
    throw new RuntimeException('unsafe absolute redirect accepted');
}

echo "BillingActionSecurityTest passed\n";

