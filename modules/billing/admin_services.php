<?php
// Admin: service types (what we sell besides game servers) and the requests customers made for them.
require_once(__DIR__ . '/includes/admin_auth.php');
require_once(__DIR__ . '/includes/config_loader.php');
require_once(__DIR__ . '/includes/support_tickets.php');
require_once(__DIR__ . '/includes/services_catalog.php');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['admin_csrf'];
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$db = (isset($db_host, $db_user, $db_pass, $db_name))
    ? @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null)
    : false;
$status = '';
$error = '';
if (!$db) {
    $error = 'Database connection failed.';
} else {
    $db->set_charset('utf8mb4');
    $prefix = (string)$table_prefix;
    if (!billing_service_types_ready($db, $prefix)) {
        $error = 'The services tables are not installed yet. Update the Billing module first (Administration > Modules).';
        $db = false;
    }
}

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save_type') {
            $typeId = (int)($_POST['type_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = ($_POST['kind'] ?? 'paid') === 'support' ? 'support' : 'paid';
            $summary = substr(trim((string)($_POST['summary'] ?? '')), 0, 255);
            $mode = ($_POST['price_mode'] ?? 'quote') === 'fixed' ? 'fixed' : 'quote';
            $price = max(0, round((float)($_POST['price'] ?? 0), 2));
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $sort = (int)($_POST['sort_order'] ?? 0);
            list($stages, $stageError) = billing_service_stages_from_text((string)($_POST['stages'] ?? ''));
            $fields = billing_service_fields_from_text((string)($_POST['fields'] ?? ''));
            $slug = billing_service_slug($typeId > 0 ? (string)($_POST['slug'] ?? $name) : $name);
            if ($name === '' || $slug === '') {
                $error = 'A service needs a name.';
            } elseif ($stageError !== '') {
                $error = $stageError;
            } elseif ($kind === 'paid' && $mode === 'fixed' && $price <= 0) {
                $error = 'A fixed price must be more than 0. Use "Quote" to price each request yourself.';
            } else {
                $stagesJson = json_encode($stages);
                $fieldsJson = json_encode($fields);
                if ($typeId > 0) {
                    $stmt = $db->prepare("UPDATE `{$prefix}billing_service_types` SET name=?, kind=?, summary=?, price_mode=?, price=?, stages=?, fields=?, enabled=?, sort_order=? WHERE type_id=?");
                    $stmt->bind_param('ssssdssiii', $name, $kind, $summary, $mode, $price, $stagesJson, $fieldsJson, $enabled, $sort, $typeId);
                } else {
                    $stmt = $db->prepare("INSERT INTO `{$prefix}billing_service_types` (name, kind, summary, price_mode, price, stages, fields, enabled, sort_order, slug) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('ssssdssiis', $name, $kind, $summary, $mode, $price, $stagesJson, $fieldsJson, $enabled, $sort, $slug);
                }
                try {
                    $stmt->execute();
                    $status = 'Service saved.';
                } catch (Throwable $e) {
                    $error = 'Could not save: ' . ($e->getCode() === 1062 ? 'a service with that name already exists.' : $e->getMessage());
                }
                $stmt->close();
            }
        } elseif ($do === 'quote') {
            $id = billing_service_set_quote($db, $prefix, (int)$_POST['request_id'], round((float)($_POST['total'] ?? 0), 2));
            $status = $id > 0 ? 'Quote saved and invoice #' . $id . ' created.' : '';
            $error = $id > 0 ? '' : 'Nothing was billed. Check the price, and that the request is still open.';
        } elseif ($do === 'bill_next') {
            $id = billing_service_bill_next_stage($db, $prefix, (int)$_POST['request_id']);
            $status = $id > 0 ? 'Invoice #' . $id . ' created.' : '';
            $error = $id > 0 ? '' : 'Nothing to bill: there is no price yet, or every stage already has an invoice.';
        } elseif ($do === 'set_status') {
            $ok = billing_service_set_status($db, $prefix, (int)$_POST['request_id'], (string)($_POST['status'] ?? ''));
            $status = $ok ? 'Status updated.' : '';
            $error = $ok ? '' : 'That status could not be set.';
        }
    }
}

$types = $db ? billing_service_types_list($db, $prefix, false) : array();
$requests = array();
if ($db) {
    $res = $db->query("SELECT r.*, t.name AS type_name, u.users_login AS login,
            (SELECT COUNT(*) FROM `{$prefix}billing_invoices` i WHERE i.request_id = r.request_id AND i.payment_status = 'paid') AS paid_count,
            (SELECT COUNT(*) FROM `{$prefix}billing_invoices` i WHERE i.request_id = r.request_id AND i.payment_status = 'unpaid') AS unpaid_count
        FROM `{$prefix}billing_service_requests` r
        LEFT JOIN `{$prefix}billing_service_types` t ON t.type_id = r.type_id
        LEFT JOIN `{$prefix}users` u ON u.user_id = r.user_id
        ORDER BY r.request_id DESC LIMIT 100");
    while ($res && ($row = $res->fetch_assoc())) {
        $requests[] = $row;
    }
}
$typeForm = function (array $t) use ($csrf) {
    $isNew = empty($t['type_id']);
    ?>
    <form method="POST" class="coupon-form">
      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="do" value="save_type">
      <input type="hidden" name="type_id" value="<?php echo (int)($t['type_id'] ?? 0); ?>">
      <input type="hidden" name="slug" value="<?php echo h($t['slug'] ?? ''); ?>">
      <p><label>Name <input type="text" name="name" maxlength="80" value="<?php echo h($t['name'] ?? ''); ?>" required></label>
         <label>Kind
           <select name="kind">
             <option value="paid"<?php echo ($t['kind'] ?? 'paid') === 'paid' ? ' selected' : ''; ?>>Paid service (order form + invoice + ticket)</option>
             <option value="support"<?php echo ($t['kind'] ?? '') === 'support' ? ' selected' : ''; ?>>Support type (ticket only)</option>
           </select></label>
         <label>Order <input type="number" name="sort_order" value="<?php echo (int)($t['sort_order'] ?? 0); ?>" style="width:5em"></label>
         <label><input type="checkbox" name="enabled" value="1"<?php echo ($isNew || !empty($t['enabled'])) ? ' checked' : ''; ?>> Shown to customers</label></p>
      <p><label>Short description (shown on the Services page)<br>
         <input type="text" name="summary" maxlength="255" style="width:100%" value="<?php echo h($t['summary'] ?? ''); ?>"></label></p>
      <p><label>Pricing
           <select name="price_mode">
             <option value="fixed"<?php echo ($t['price_mode'] ?? '') === 'fixed' ? ' selected' : ''; ?>>Fixed price</option>
             <option value="quote"<?php echo ($t['price_mode'] ?? 'quote') === 'quote' ? ' selected' : ''; ?>>Quote (we set the price per request)</option>
           </select></label>
         <label>Fixed price (USD) <input type="number" step="0.01" min="0" name="price" value="<?php echo h(number_format((float)($t['price'] ?? 0), 2, '.', '')); ?>" style="width:8em"></label></p>
      <p><label>Payment steps, one per line as "Name | percent" (must add up to 100)<br>
         <textarea name="stages" rows="3" style="width:100%"><?php echo h(billing_service_stages_to_text($t['stages_list'] ?? array(array('label' => 'Payment', 'percent' => 100)))); ?></textarea></label></p>
      <p><label>Questions for the customer, one per line as "Question | text, textarea or select | required or optional | options separated by ;"<br>
         <textarea name="fields" rows="4" style="width:100%"><?php echo h(billing_service_fields_to_text($t['fields_list'] ?? array())); ?></textarea></label></p>
      <button type="submit"><?php echo $isNew ? 'Add service' : 'Save'; ?></button>
    </form>
    <?php
};
$statuses = array('in_progress' => 'In progress', 'delivered' => 'Delivered', 'completed' => 'Completed', 'cancelled' => 'Cancelled');
?>
<div class="container-wide panel">
  <h1>Services</h1>
  <p>What customers can order besides game servers. Prices below the starter set are placeholders: set your own.</p>
<?php if ($status): ?><div class="status success"><?php echo h($status); ?></div><?php endif; ?>
<?php if ($error): ?><div class="status error"><?php echo h($error); ?></div><?php endif; ?>

<?php if ($db): ?>
  <h2>Requests</h2>
<?php if (!$requests): ?>
  <p>No one has ordered a service yet.</p>
<?php else: ?>
  <table class="coupon-table">
    <thead><tr><th>#</th><th>Customer</th><th>Service</th><th>Status</th><th>Total</th><th>Invoices</th><th>Ticket</th><th>Actions</th></tr></thead>
    <tbody>
<?php foreach ($requests as $r): $rid = (int)$r['request_id']; ?>
      <tr>
        <td><?php echo $rid; ?></td>
        <td><?php echo h($r['login'] ?? ('#' . $r['user_id'])); ?></td>
        <td><?php echo h($r['type_name'] ?? 'Deleted service'); ?></td>
        <td><?php echo h(billing_service_status_label((string)$r['status'])); ?></td>
        <td><?php echo $r['total_price'] !== null ? h(billing_service_money($r['total_price'])) : '&mdash;'; ?></td>
        <td><?php echo (int)$r['paid_count']; ?> paid, <?php echo (int)$r['unpaid_count']; ?> unpaid</td>
        <td><?php echo (int)$r['tid'] > 0 ? '#' . (int)$r['tid'] : '&mdash;'; ?></td>
        <td>
<?php if ($r['total_price'] === null && !in_array($r['status'], array('cancelled', 'completed'), true)): ?>
          <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="do" value="quote"><input type="hidden" name="request_id" value="<?php echo $rid; ?>">
            <input type="number" step="0.01" min="0.01" name="total" placeholder="Total USD" style="width:8em" required> <button type="submit">Send quote</button></form>
<?php else: ?>
          <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="do" value="bill_next"><input type="hidden" name="request_id" value="<?php echo $rid; ?>">
            <button type="submit">Bill next step</button></form>
<?php endif; ?>
          <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="do" value="set_status"><input type="hidden" name="request_id" value="<?php echo $rid; ?>">
            <select name="status"><?php foreach ($statuses as $k => $label): ?><option value="<?php echo $k; ?>"><?php echo h($label); ?></option><?php endforeach; ?></select>
            <button type="submit">Set</button></form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

  <h2>Service types</h2>
<?php foreach ($types as $t): ?>
  <details style="margin-bottom:10px"><summary><strong><?php echo h($t['name']); ?></strong> &middot; <?php echo $t['kind'] === 'paid' ? 'paid service' : 'support type'; ?><?php echo $t['enabled'] ? '' : ' &middot; hidden'; ?></summary>
<?php $typeForm($t); ?>
  </details>
<?php endforeach; ?>
  <h3>Add a service</h3>
<?php $typeForm(array()); ?>
<?php endif; ?>
</div>
