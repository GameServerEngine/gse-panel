<?php
/**
 * Where PayPal sends the customer back after approving the payment (?token=<PayPal order id>).
 * The page asks api/capture_order.php to take the money and provision the servers, then moves
 * on to payment_success.php. Capturing twice is harmless: PayPal answers the same order again.
 */
// The ordering site's own session (same as cart.php), started before anything else.
if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
require_once dirname(__DIR__) . '/billing_bootstrap.php';

$orderId = (string)($_GET['token'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{5,64}$/', $orderId)) {
    header('Location: ' . billing_url('cart.php?payment=cancelled'));
    exit;
}
$captureUrl = billing_url('api/capture_order.php');
$successUrl = billing_url('payment_success.php?order_id=' . rawurlencode($orderId));
$cartUrl = billing_url('cart.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finishing your payment</title>
</head>
<body style="font-family:sans-serif;text-align:center;padding:60px 20px;">
    <h2>Finishing your payment...</h2>
    <p id="paypal-return-status">Please wait, this takes a few seconds. Do not close this page.</p>
    <script>
    (function () {
        var status = document.getElementById('paypal-return-status');
        fetch(<?php echo json_encode($captureUrl); ?>, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: <?php echo json_encode($orderId); ?> })
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, message: 'Unexpected reply from the server.' }; });
        }).then(function (body) {
            if (body.success === false || body.status !== 'COMPLETED') {
                status.innerHTML = 'The payment could not be completed: ' + (body.message || 'unknown problem') +
                    '. If PayPal charged you, contact support with order <?php echo htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8'); ?>. ' +
                    '<a href="<?php echo htmlspecialchars($cartUrl, ENT_QUOTES, 'UTF-8'); ?>">Back to the cart</a>';
                return;
            }
            window.location.href = <?php echo json_encode($successUrl); ?>;
        }).catch(function () {
            status.innerHTML = 'Network problem while finishing the payment. Reload this page to try again.';
        });
    })();
    </script>
</body>
</html>
