<?php
/**
 * Where Stripe Checkout sends the customer back after paying.
 * Confirms the payment straight away in case the webhook has not arrived yet (applying it
 * twice is harmless: the invoice is only marked paid once) and shows the result.
 */
// The ordering site's own session (same as cart.php), started before anything else.
if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
$_billing_dir = dirname(__DIR__);
require_once $_billing_dir . '/includes/config_loader.php';
require_once $_billing_dir . '/billing_bootstrap.php';
require_once $_billing_dir . '/includes/stripe_billing.php';

$title = 'Payment not confirmed';
$message = 'We could not confirm this payment. If you were charged, it will show up shortly; otherwise please try again from your cart.';
$ok = false;
$sessionId = (string)($_GET['session_id'] ?? '');
$userId = (int)($_SESSION['website_user_id'] ?? 0);

if (preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId)) {
    $db = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', intval($db_port ?? 3306) ?: 3306);
    if ($db) {
        mysqli_set_charset($db, 'utf8mb4');
        $prefix = (string)($table_prefix ?? '');
        $client = billing_stripe_client($db, $prefix);
        $res = $client ? $client->request('GET', 'checkout/sessions/' . rawurlencode($sessionId)) : array('ok' => false);
        if (!empty($res['ok'])) {
            $session = $res['data'];
            $owner = (int)($session['metadata']['user_id'] ?? 0);
            if ($userId > 0 && $owner !== $userId) {
                $message = 'This payment belongs to a different account.';
            } elseif ((string)($session['mode'] ?? '') === 'setup') {
                $r = array('status' => 'failed');
                try {
                    $r = stripe_on_checkout_completed($db, $prefix, $session);
                } catch (Throwable $e) {
                    // the webhook will apply it
                }
                $ok = true;
                $title = 'Auto-renew is on';
                $message = 'Thank you! Your card is saved with Stripe for this server. Each renewal invoice will be charged automatically a few days before it is due; you can turn auto-renew off any time in My Servers.';
            } elseif (in_array((string)($session['payment_status'] ?? ''), array('paid', 'no_payment_required'), true)) {
                try {
                    stripe_on_checkout_completed($db, $prefix, $session);
                } catch (Throwable $e) {
                    // the webhook will apply it
                }
                $ok = true;
                $title = 'Paid, thank you!';
                $message = 'Your payment is complete and your invoice is marked paid. A new server is set up straight away; you can follow it under My Servers.';
            } else {
                $title = 'Payment pending';
                $message = 'Your payment is still being processed by the bank. We will set things up as soon as it clears.';
            }
        }
    }
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h($title); ?></title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f5f6f8; color: #1d232b; margin: 0; }
  .box { max-width: 560px; margin: 12vh auto; background: #fff; border-radius: 10px; padding: 28px 32px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); }
  h1 { margin-top: 0; font-size: 1.5rem; color: <?php echo $ok ? '#1e7d45' : '#9a5b00'; ?>; }
  a.btn { display: inline-block; margin: 14px 10px 0 0; padding: 9px 16px; border-radius: 6px; background: #1f6feb; color: #fff; text-decoration: none; }
  a.btn.alt { background: #e6e9ee; color: #1d232b; }
</style>
</head>
<body>
  <div class="box">
    <h1><?php echo $h($title); ?></h1>
    <p><?php echo $h($message); ?></p>
    <a class="btn" href="../my_servers.php">My Servers</a>
    <a class="btn alt" href="../cart.php">Cart</a>
  </div>
</body>
</html>
