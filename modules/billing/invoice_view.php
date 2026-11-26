<?php
// One invoice, built from its billing_invoices row. Only the owner can view it.
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

$invoice_id = intval($_GET['id'] ?? 0);
$inv = null;
if ($invoice_id > 0) {
    $stmt = mysqli_prepare($db, "SELECT * FROM {$table_prefix}billing_invoices WHERE invoice_id = ? AND user_id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $user_id);
        mysqli_stmt_execute($stmt);
        $inv = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
}

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$state = $inv ? billing_invoice_state($inv) : '';
$currency = $inv ? ($inv['currency'] ?: 'USD') : 'USD';
$money = fn($v) => $currency . ' ' . number_format((float)$v, 2);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo $inv ? 'Invoice #' . $e($inv['invoice_id']) : 'Invoice not found'; ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/header.css">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<div class="container-wide panel">
<?php if (!$inv): ?>
  <h1>Invoice not found</h1>
  <p>This invoice does not exist or is not on your account.</p>
<?php else: ?>
  <h1>Invoice #<?php echo $e($inv['invoice_id']); ?></h1>
  <p>Status: <strong><?php echo $e(ucfirst($state)); ?></strong></p>
  <table class="cart-table">
    <tbody>
      <tr><th>Invoice date</th><td><?php echo $e($inv['invoice_date']); ?></td></tr>
      <?php if ($state === 'paid'): ?>
      <tr><th>Paid date</th><td><?php echo $e($inv['paid_date'] ?? ''); ?></td></tr>
      <tr><th>Payment method</th><td><?php echo $e($inv['payment_method'] ?? ''); ?></td></tr>
      <tr><th>Transaction ID</th><td><?php echo $e($inv['payment_txid'] ?? ''); ?></td></tr>
      <?php endif; ?>
      <tr><th>Billed to</th><td><?php echo $e($inv['customer_name']); ?><br><?php echo $e($inv['customer_email']); ?></td></tr>
    </tbody>
  </table>

  <h2>Items</h2>
  <table class="cart-table">
    <thead>
      <tr><th>Description</th><th>Qty</th><th>Amount</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><?php echo $e($inv['description'] ?: $inv['home_name']); ?></td>
        <td><?php echo (int)$inv['qty']; ?></td>
        <td><?php echo $e($money($inv['amount'])); ?></td>
      </tr>
      <?php if ((float)$inv['discount_amount'] > 0): ?>
      <tr><td>Discount</td><td></td><td>-<?php echo $e($money($inv['discount_amount'])); ?></td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="2">Total</th><th><?php echo $e($money($inv['amount'])); ?></th></tr>
    </tfoot>
  </table>

  <p>
    <a href="invoices.php">All invoices</a>
    &nbsp;<button type="button" class="gsw-btn" onclick="window.print()">Print</button>
  </p>
<?php endif; ?>
</div>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
