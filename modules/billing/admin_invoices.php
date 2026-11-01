<?php
// Admin invoices management
if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/classes/BillingRepository.php';
require_once __DIR__ . '/classes/BillingLifecycleService.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function billing_admin_datetime_local($value): string
{
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

$db = mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
if (!$db) die('DB connection failed');
mysqli_set_charset($db, 'utf8mb4');
$prefix = $table_prefix ?? 'gsp_';
$repo   = new BillingRepository($db, $prefix);
$lifecycle = new BillingLifecycleService($repo);
$csrf = billing_action_csrf_token('billing_admin_invoice_csrf');

$message = '';
$msgType = 'success';

// Handle POST: admin payment status / paid date edit.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['invoice_id'])) {
    $invId  = intval($_POST['invoice_id']);
    $action = (string)$_POST['action'];
    $returnUrl = 'admin_invoices.php';

    // Fetch invoice to verify it exists
    $invRow = null;
    $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_invoices` WHERE invoice_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $invId);
        $stmt->execute();
        $invRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$invRow) {
        $message = "Invoice #{$invId} not found.";
        $msgType = 'error';
    } elseif (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'billing_admin_invoice_csrf')) {
        $message = 'Invalid security token. Reload the invoice page and try again.';
        $msgType = 'error';
    } elseif ($action !== 'update_payment') {
        $message = 'Unsupported invoice action.';
        $msgType = 'error';
    } elseif (empty($_POST['confirm_action'])) {
        $message = 'Confirmation is required before changing invoice payment state.';
        $msgType = 'error';
    } else {
        $paymentStatus = strtolower(trim((string)($_POST['payment_status'] ?? '')));
        $paidDateInput = trim((string)($_POST['paid_date'] ?? ''));
        $paidDate = $paidDateInput === '' ? null : str_replace('T', ' ', $paidDateInput) . (strlen($paidDateInput) === 16 ? ':00' : '');
        $result = $lifecycle->adminUpdateInvoicePayment($invId, $paymentStatus, $paidDate, [
            'admin_user_id' => intval($_SESSION['website_user_id'] ?? $_SESSION['user_id'] ?? 0),
            'payment_method' => 'manual',
            'amount' => (float)($invRow['total_due'] ?? $invRow['amount'] ?? 0),
            'currency' => (string)($invRow['currency'] ?? 'USD'),
        ]);

        if (!empty($result['success'])) {
            $updated = ["invoice payment_status={$paymentStatus}"];
            if ($paymentStatus === 'paid') {
                $updated[] = 'legacy status=paid';
                if (!empty($result['order_id'])) {
                    $updated[] = 'linked order #' . intval($result['order_id']) . ' synchronized';
                }
                if (!empty($result['home_id'])) {
                    $updated[] = 'server home #' . intval($result['home_id']) . ' synchronized';
                }
                if (!empty($result['paid_date'])) {
                    $updated[] = 'paid_date=' . $result['paid_date'];
                }
            }
            $message = 'Invoice #' . $invId . ' updated: ' . implode(', ', $updated) . '.';
        } else {
            $message = 'Invoice #' . $invId . ' was not updated: ' . ($result['error'] ?? 'unknown error') . '.';
            $msgType = 'error';
        }
    }

    if (!headers_sent()) {
        header('Location: ' . $returnUrl . '?msg=' . urlencode($message) . '&type=' . $msgType);
        mysqli_close($db);
        $db = null;
        exit;
    }
}

// Fetch invoices
$invoices = [];
$res = $db->query(
    "SELECT i.*, u.users_login, u.users_email
     FROM `{$prefix}billing_invoices` i
     LEFT JOIN `{$prefix}users` u ON u.user_id = i.user_id
     ORDER BY i.invoice_id DESC
     LIMIT 500"
);
if ($res) $invoices = $res->fetch_all(MYSQLI_ASSOC);
mysqli_close($db);
$db = null;

if (isset($_GET['msg']))  $message = $_GET['msg'];
if (isset($_GET['type'])) $msgType = $_GET['type'];
?>
<?php if (!billing_is_panel_context()): ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Invoices</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/header.css">
  <style>
    .status-badge     { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; }
    .status-paid      { background:#d4edda; color:#155724; }
    .status-unpaid    { background:#fff3cd; color:#856404; }
    .status-cancelled { background:#e2e3e5; color:#383d41; }
    .status-refunded  { background:#f8d7da; color:#721c24; }
    .action-btn { padding:3px 8px; font-size:12px; border:none; border-radius:3px; cursor:pointer; }
	    .btn-pay    { background:#28a745; color:#fff; }
	    .btn-cancel { background:#6c757d; color:#fff; }
	    .btn-refund { background:#dc3545; color:#fff; }
	    .invoice-action-form { display:grid; grid-template-columns: minmax(110px, 1fr) minmax(150px, 1fr) auto; gap:6px; align-items:center; min-width:360px; }
	    .invoice-action-confirm { grid-column:1 / -1; font-size:11px; }
	    .invoice-action-form select,
	    .invoice-action-form input { font-size:12px; padding:3px 5px; }
	  </style>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include __DIR__ . '/includes/top.php'; include __DIR__ . '/includes/menu.php'; ?>
<div class="container-wide panel">
  <h1>Admin — All Invoices</h1>
  <?php if ($message): ?>
    <div style="background:<?= $msgType==='error' ? '#f8d7da' : '#d4edda' ?>;padding:10px;margin-bottom:15px;border-radius:3px;color:<?= $msgType==='error' ? '#721c24' : '#155724' ?>;">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <table class="cart-table">
    <thead>
      <tr>
        <th>#</th><th>User</th><th>Server</th><th>Service</th>
        <th>Rate</th><th>Players</th><th>Period</th>
        <th>Total</th><th>Status</th><th>Method</th><th>Txn ID</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($invoices)): ?>
      <tr><td colspan="12" style="text-align:center">No invoices found.</td></tr>
    <?php else: foreach ($invoices as $inv): ?>
      <tr>
        <td><?= h($inv['invoice_id']) ?></td>
        <td><?= h($inv['users_login'] ?? $inv['user_id']) ?></td>
        <td><?= h($inv['home_id'] ?: '—') ?></td>
        <td><?= h($inv['service_id']) ?></td>
        <td><?= h($inv['rate_type'] ?? '—') ?></td>
        <td><?= h($inv['players'] ?? '—') ?></td>
        <td style="font-size:11px"><?= h(substr($inv['period_start'] ?? '', 0, 10)) ?> – <?= h(substr($inv['period_end'] ?? '', 0, 10)) ?></td>
        <td><?= h(number_format((float)($inv['total_due'] ?? $inv['amount'] ?? 0), 2)) ?></td>
        <td><span class="status-badge status-<?= h(in_array($inv['payment_status'] ?? '', ['unpaid','paid','cancelled','refunded']) ? $inv['payment_status'] : 'unpaid') ?>"><?= h($inv['payment_status'] ?? 'unpaid') ?></span></td>
        <td><?= h($inv['payment_method'] ?? '—') ?></td>
        <td style="font-size:11px;max-width:120px;overflow:hidden"><?= h($inv['payment_txid'] ?? '—') ?></td>
	        <td>
	          <form method="post" class="invoice-action-form" onsubmit="return confirm('Apply this invoice payment change? Linked order and server billing fields may be synchronized.');">
	            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
	            <input type="hidden" name="invoice_id" value="<?= intval($inv['invoice_id']) ?>">
	            <input type="hidden" name="action" value="update_payment">
	            <select name="payment_status" aria-label="Payment status">
	              <?php foreach (['unpaid','paid','cancelled','refunded'] as $statusOption): ?>
	                <option value="<?= h($statusOption) ?>" <?= (($inv['payment_status'] ?? 'unpaid') === $statusOption) ? 'selected' : '' ?>><?= h($statusOption) ?></option>
	              <?php endforeach; ?>
	            </select>
	            <input type="datetime-local" name="paid_date" value="<?= h(billing_admin_datetime_local($inv['paid_date'] ?? '')) ?>" aria-label="Paid date">
	            <button type="submit" class="action-btn btn-pay">Save</button>
	            <label class="invoice-action-confirm">
	              <input type="checkbox" name="confirm_action" value="1" required>
	              Confirm payment-state change for invoice #<?= intval($inv['invoice_id']) ?>
	            </label>
	          </form>
	        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
<?php endif; ?>
