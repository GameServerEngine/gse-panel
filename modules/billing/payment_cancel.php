<?php
/**
 * Payment Cancelled Page
 * User lands here if they cancel the PayPal payment
 */

require_once(__DIR__ . '/billing_bootstrap.php');
require_once(__DIR__ . '/includes/session_bridge.php');

$invoice_ref = isset($_GET['invoice']) ? $_GET['invoice'] : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled - GameServers.World</title>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>

<main class="container-wide pad-40">
    <section class="panel">
        <p class="gsw-section-eyebrow">Checkout</p>
        <h1>Payment Cancelled</h1>
        <p>Your payment was cancelled. No charges have been made to your account.</p>
        <?php if ($invoice_ref): ?>
        <p><strong>Invoice Reference:</strong> <?php echo htmlspecialchars($invoice_ref); ?></p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>What would you like to do?</h2>
        <ul>
            <li><strong>Return to Cart:</strong> Your items are still in your cart. You can complete the payment anytime.</li>
            <li><strong>Continue Shopping:</strong> Browse our game server options and add more to your cart.</li>
            <li><strong>Need Help?:</strong> Contact our support team if you encountered any issues during checkout.</li>
        </ul>
    </section>

    <div class="gsw-hero-actions">
        <a class="gsw-btn" href="<?php echo htmlspecialchars(billing_url('cart.php'), ENT_QUOTES, 'UTF-8'); ?>">Return to Cart</a>
        <a class="gsw-btn-secondary" href="<?php echo htmlspecialchars(billing_url('serverlist.php'), ENT_QUOTES, 'UTF-8'); ?>">Continue Shopping</a>
        <a class="gsw-btn-secondary" href="<?php echo htmlspecialchars(billing_url('support.php'), ENT_QUOTES, 'UTF-8'); ?>">Contact Support</a>
    </div>
</main>

<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
