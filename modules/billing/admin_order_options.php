<?php
// Admin: one-time options a customer can add to a game server order (Server Setup and similar).
require_once(__DIR__ . '/includes/admin_auth.php');
require_once(__DIR__ . '/includes/config_loader.php');
require_once(__DIR__ . '/includes/order_options.php');

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
$prefix = (string)$table_prefix;
$status = '';
$error = '';
if (!$db) {
    $error = 'Database connection failed.';
} else {
    $db->set_charset('utf8mb4');
    // A panel that never ran the Billing database step gets the tables here, on first use.
    if (!order_options_table_ready($db, $prefix)) {
        $installError = order_options_install($db, $prefix);
        if ($installError !== '') {
            $error = 'The order options tables are missing and could not be created: ' . $installError;
            $db = false;
        }
    }
}

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        try {
            $do = (string)($_POST['do'] ?? '');
            if ($do === 'save_option') {
                $id = (int)($_POST['option_id'] ?? 0);
                $title = substr(trim((string)($_POST['title'] ?? '')), 0, 120);
                $description = substr(trim((string)($_POST['description'] ?? '')), 0, 1000);
                $price = max(0, round((float)($_POST['price'] ?? 0), 2));
                $enabled = isset($_POST['enabled']) ? 1 : 0;
                $sort = (int)($_POST['sort_order'] ?? 0);
                if ($title === '') {
                    $error = 'An option needs a title.';
                } elseif ($id > 0) {
                    $stmt = $db->prepare("UPDATE `{$prefix}billing_order_options` SET title=?, description=?, price=?, enabled=?, sort_order=? WHERE option_id=?");
                    $stmt->bind_param('ssdiii', $title, $description, $price, $enabled, $sort, $id);
                    $stmt->execute();
                    $stmt->close();
                    $status = 'Option saved.';
                } else {
                    $stmt = $db->prepare("INSERT INTO `{$prefix}billing_order_options` (title, description, price, enabled, sort_order) VALUES (?,?,?,?,?)");
                    $stmt->bind_param('ssdii', $title, $description, $price, $enabled, $sort);
                    $stmt->execute();
                    $stmt->close();
                    $status = 'Option added.';
                }
            } elseif ($do === 'delete_option') {
                $id = (int)($_POST['option_id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM `{$prefix}billing_order_options` WHERE option_id=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $status = 'Option deleted.';
            }
        } catch (Throwable $e) {
            $error = 'Could not save: ' . $e->getMessage();
        }
    }
}

$options = array();
if ($db) {
    $res = $db->query("SELECT * FROM `{$prefix}billing_order_options` ORDER BY sort_order, option_id");
    while ($res && ($row = $res->fetch_assoc())) {
        $options[] = $row;
    }
}

$optionForm = function (array $o) use ($csrf) { ?>
<form method="POST" class="coupon-form" style="margin-bottom:14px;">
  <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
  <input type="hidden" name="do" value="save_option">
  <input type="hidden" name="option_id" value="<?php echo (int)($o['option_id'] ?? 0); ?>">
  <label>Title <input type="text" name="title" maxlength="120" required value="<?php echo h($o['title'] ?? ''); ?>"></label>
  <label>Price (USD) <input type="number" name="price" step="0.01" min="0" required value="<?php echo h(number_format((float)($o['price'] ?? 5), 2, '.', '')); ?>" style="width:90px;"></label>
  <label>Sort <input type="number" name="sort_order" value="<?php echo (int)($o['sort_order'] ?? 0); ?>" style="width:70px;"></label>
  <label><input type="checkbox" name="enabled" value="1" <?php echo ((int)($o['enabled'] ?? 1) === 1) ? 'checked' : ''; ?>> Offer on order page</label>
  <label style="display:block;">Description <textarea name="description" rows="2" maxlength="1000" style="width:100%;"><?php echo h($o['description'] ?? ''); ?></textarea></label>
  <button type="submit">Save</button>
</form>
<?php };
?>
<?php if ($error !== ''): ?><div class="failure"><?php echo h($error); ?></div><?php endif; ?>
<?php if ($status !== ''): ?><div class="success"><?php echo h($status); ?></div><?php endif; ?>

<h3>Order options</h3>
<p class="muted">One-time extras a customer can tick while ordering a game server, such as Server Setup. Each ticked option is added to that order's first invoice. Renewals do not repeat it. Things a customer asks for separately, such as a support request or custom work, are on the Services tab.</p>

<?php foreach ($options as $o): ?>
  <h4><?php echo h($o['title']); ?><?php echo ((int)$o['enabled'] === 1) ? '' : ' <span class="muted">(hidden from customers)</span>'; ?></h4>
  <?php $optionForm($o); ?>
  <form method="POST" style="margin-bottom:18px;" onsubmit="return confirm('Delete this option? Customers will no longer see it.');">
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="do" value="delete_option">
    <input type="hidden" name="option_id" value="<?php echo (int)$o['option_id']; ?>">
    <button type="submit">Delete option</button>
  </form>
<?php endforeach; ?>

<h4>Add an option</h4>
<?php $optionForm(array('price' => 5, 'enabled' => 1)); ?>
