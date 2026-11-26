<?php
// Invoice history for the signed-in user, read from billing_invoices.
if (session_status() === PHP_SESSION_NONE) {
    session_name("gameserverpanel_web");
    session_start();
}
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/invoice_state.php');

$user_id = intval($_SESSION['website_user_id'] ?? 0);
if ($user_id <= 0) {
    include(__DIR__ . '/login.php');
    exit;
}

$db = mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
if (!$db) {
    die("Connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($db, 'utf8mb4');

$rows = [];
$res = mysqli_query($db, "SELECT invoice_id, description, home_name, amount, currency, status, payment_status, invoice_date, paid_date FROM {$table_prefix}billing_invoices WHERE user_id = {$user_id} ORDER BY invoice_id DESC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
}
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Your Invoices</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/header.css">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<div class="container-wide panel">
  <h1>Your Invoices</h1>
  <?php if (!$rows): ?>
    <p>No invoices found for your account.</p>
  <?php else: ?>
  <table class="cart-table">
      <thead>
        <tr>
          <th>Invoice</th><th>Description</th><th>Amount</th><th>Status</th><th>Date</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $state = billing_invoice_state($r);
          $when  = ($state === 'paid' && !empty($r['paid_date'])) ? $r['paid_date'] : $r['invoice_date'];
      ?>
        <tr>
          <td>#<?php echo $e($r['invoice_id']); ?></td>
          <td><?php echo $e($r['description'] ?: $r['home_name']); ?></td>
          <td><?php echo $e(($r['currency'] ?: 'USD') . ' ' . number_format((float)$r['amount'], 2)); ?></td>
          <td><?php echo $e(ucfirst($state)); ?></td>
          <td><?php echo $e($when); ?></td>
          <td><a href="invoice_view.php?id=<?php echo (int)$r['invoice_id']; ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p><a href="my_account.php">Back to My Account</a></p>
</div>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
