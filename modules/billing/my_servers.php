<?php require_once __DIR__ . '/includes/brand.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Servers - <?php echo billing_brand_e('name'); ?></title>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php
// Require login for this page
require_once(__DIR__ . '/includes/login_required.php');

// Include billing bootstrap (loads config and DB helper)
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/action_security.php');
require_once(__DIR__ . '/includes/container_poc.php');
require_once(__DIR__ . '/classes/BillingRepository.php');

// Variables from config.inc.php (helps IDEs understand scope)
/** @var string $db_host Database host */
/** @var string $db_user Database user */
/** @var string $db_pass Database password */
/** @var string $db_name Database name */
/** @var string $table_prefix Table prefix for database tables */

// Create database connection
$db = mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
if (!$db) {
    die("Connection failed: " . mysqli_connect_error());
}
container_poc_ensure_schema($db);

// Include top bar and menu
include(__DIR__ . '/includes/top.php');
include(__DIR__ . '/includes/menu.php');

// Get user ID from session
$user_id = intval($_SESSION['website_user_id']);
$renew_csrf = billing_action_csrf_token('billing_renew_csrf');
$cancel_csrf = billing_action_csrf_token('billing_cancel_csrf');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_cancellation') {
    if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'billing_cancel_csrf') || empty($_POST['confirm'])) {
        $_SESSION['billing_cancel_error'] = 'Please confirm the cancellation and try again.';
    } else {
        $homeId=(int)($_POST['home_id']??0); $orderId=(int)($_POST['order_id']??0); $mode=$_POST['mode']??'';
        if (in_array($mode,['immediate','end_of_term'],true) && $homeId>0 && $orderId>0) {
            $stmt=$db->prepare("SELECT request_id FROM {$table_prefix}billing_cancellation_requests WHERE home_id=? AND user_id=? AND status='pending' LIMIT 1");
            $stmt->bind_param('ii',$homeId,$user_id); $stmt->execute(); $exists=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($exists) $_SESSION['billing_cancel_error']='A cancellation request is already pending.';
            else { $stmt=$db->prepare("INSERT INTO {$table_prefix}billing_cancellation_requests (home_id,order_id,user_id,mode) VALUES (?,?,?,?)"); $stmt->bind_param('iiis',$homeId,$orderId,$user_id,$mode); $stmt->execute(); $stmt->close(); $_SESSION['billing_cancel_message']='Cancellation request submitted for admin approval.'; }
        }
    }
    header('Location: my_servers.php'); exit;
}

// Fetch user's active servers
// We'll look for homes assigned to this user
// The relationship is: {table_prefix}billing_orders -> user_id and contains home_id references
// We need to join with {table_prefix}home to get server details

$query = "SELECT 
            h.home_id,
            h.home_name,
            h.enabled,
            h.billing_status,
            h.server_expiration_date,
            rs.remote_server_name,
            gc.game_name,
            o.order_id,
            o.status,
            o.invoice_duration,
            o.end_date AS expiration_date,
            bs.service_name,
            bs.price_monthly,
            o.price,
            o.discount_amount,
            o.coupon_id,
            bc.code AS coupon_code,
            bc.discount_percent AS coupon_discount_percent
          FROM {$table_prefix}home h
          LEFT JOIN {$table_prefix}remote_servers rs ON h.remote_server_id = rs.remote_server_id
          LEFT JOIN {$table_prefix}game_configs gc ON h.home_cfg_id = gc.home_cfg_id
          LEFT JOIN {$table_prefix}billing_orders o ON h.user_id = o.user_id
          LEFT JOIN {$table_prefix}billing_services bs ON o.service_id = bs.service_id
          LEFT JOIN {$table_prefix}billing_coupons bc ON o.coupon_id = bc.coupon_id
          WHERE h.user_id = $user_id
          ORDER BY h.home_id DESC";

$result = mysqli_query($db, $query);
$pending_cancellations = [];
$pending_result = mysqli_query($db, "SELECT home_id, mode FROM {$table_prefix}billing_cancellation_requests WHERE user_id={$user_id} AND status='pending'");
if ($pending_result) while ($pending = mysqli_fetch_assoc($pending_result)) $pending_cancellations[(int)$pending['home_id']] = $pending['mode'];
$container_csrf = billing_action_csrf_token('container_lifecycle_csrf');
$containers = container_poc_fetch_all($db, "SELECT c.*, a.name AS application_name, ag.name AS agent_name FROM `" . container_poc_table('managed_containers') . "` c LEFT JOIN `" . container_poc_table('container_applications') . "` a ON c.application_id = a.application_id LEFT JOIN `" . container_poc_table('container_agents') . "` ag ON c.agent_id = ag.agent_id WHERE c.user_id = {$user_id} ORDER BY c.container_id DESC");
$access_flash = $_SESSION['container_access_flash'] ?? null;
unset($_SESSION['container_access_flash']);

?>

<div class="site-panel">
    <div class="site-panel-title">My Game Servers</div>
    <?php if (is_array($access_flash)): ?>
        <?php $flash_profile = is_array($access_flash['profile'] ?? null) ? $access_flash['profile'] : []; ?>
        <div class="gsw-alert-success">
            <strong>SSH/SFTP password reset.</strong>
            <div>Host: <?php echo container_poc_e($flash_profile['host'] ?? ''); ?>:<?php echo (int)($flash_profile['port'] ?? 0); ?></div>
            <div>Username: <?php echo container_poc_e($flash_profile['username'] ?? ''); ?></div>
            <div>Password: <code><?php echo container_poc_e($access_flash['password'] ?? ''); ?></code></div>
        </div>
    <?php endif; ?>
    <?php if (!empty($containers)): ?>
        <h2>Container Monitor</h2>
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Application</th>
                    <th>Agent</th>
                    <th>Status</th>
                    <th>Resources</th>
                    <th>Ports</th>
                    <th>SSH/SFTP Access</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($containers as $container): ?>
                    <?php $ports = container_poc_json_decode($container['ports_json'] ?? '[]', []); ?>
                    <?php $access = container_poc_json_decode($container['access_profile_json'] ?? '', []); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($container['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($container['application_name'] ?? 'Empty Container', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($container['agent_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($container['status'] ?? 'unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$container['cpu'], ENT_QUOTES, 'UTF-8'); ?> CPU / <?php echo (int)$container['memory_mb']; ?> MB / <?php echo (int)$container['storage_gb']; ?> GB</td>
                        <td>
                            <?php if ($ports): ?>
                                <?php foreach ($ports as $port): ?>
                                    <div><?php echo htmlspecialchars(($port['label'] ?? 'port') . ': ' . ($port['host_port'] ?? '') . ' -> ' . ($port['container_port'] ?? '') . '/' . ($port['protocol'] ?? 'tcp'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="muted">No ports</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($access['username']) && !empty($access['host']) && !empty($access['port'])): ?>
                                <div>Status: <?php echo empty($access['revoked']) ? 'Enabled' : 'Revoked'; ?></div>
                                <div>Host: <?php echo container_poc_e($access['host']); ?>:<?php echo (int)$access['port']; ?></div>
                                <div>Username: <?php echo container_poc_e($access['username']); ?></div>
                                <div>Protocols: SSH <?php echo !empty($access['ssh_supported']) ? 'on' : 'off'; ?> / SFTP <?php echo !empty($access['sftp_supported']) ? 'on' : 'off'; ?></div>
                                <?php if (!empty($container['access_last_set_at'])): ?>
                                    <small>Last set: <?php echo container_poc_e($container['access_last_set_at']); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">Not provisioned</span>
                            <?php endif; ?>
                        </td>
                        <td class="gsw-inline-actions">
                            <?php foreach (['start' => 'Start', 'stop' => 'Stop', 'restart' => 'Restart', 'reset_access' => 'Reset SSH/SFTP Password', 'delete' => 'Delete'] as $action => $label): ?>
                                <form method="post" action="container_action.php">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($container_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="container_id" value="<?php echo (int)$container['container_id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button class="<?php echo $action === 'delete' ? 'gsw-btn-secondary' : 'gsw-btn'; ?>" type="submit"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <?php if ($result && mysqli_num_rows($result) > 0): ?>
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Server Name</th>
                    <th>Game</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Expiration Date</th>
                    <th>Monthly Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($server = mysqli_fetch_assoc($result)): ?>
                    <?php
                    // Use billing_status from server_homes (set by migration + cron).
                    // Falls back to computing from expiration date for legacy rows.
                    $billing_status = $server['billing_status'] ?? null;
                    if (!$billing_status) {
                        $exp_ts = !empty($server['server_expiration_date']) ? strtotime($server['server_expiration_date']) : false;
                        if ($exp_ts && $exp_ts < time()) {
                            $billing_status = 'Expired';
                        } else {
                            $billing_status = 'Active';
                        }
                    }
                    $status_class_map = [
                        'Active'   => 'text-success',
                        'Invoiced' => 'text-warning',
                        'Expired'  => 'text-danger',
                    ];
                    $status_class = $status_class_map[$billing_status] ?? 'text-secondary';
                    $exp_date     = $server['server_expiration_date'] ?? $server['expiration_date'];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($server['home_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo htmlspecialchars($server['game_name'] ?? $server['service_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo htmlspecialchars($server['remote_server_name'] ?? 'Unknown'); ?></td>
                        <td class="<?php echo $status_class; ?>"><?php echo htmlspecialchars($billing_status); ?></td>
                        <td><?php echo $exp_date ? date('M d, Y', strtotime($exp_date)) : 'N/A'; ?></td>
                        <td>
                            <?php 
                            $price = $server['price'] ?? $server['price_monthly'];
                            $discount = floatval($server['discount_amount'] ?? 0);
                            
                            if ($price) {
                                if ($discount > 0 && $server['coupon_code']) {
                                    echo '<span style="text-decoration: line-through; color: #999;">$' . number_format($price + $discount, 2) . '</span><br>';
                                    echo '<strong>$' . number_format($price, 2) . '</strong>';
                                    echo '<br><small style="color: #28a745;">(' . htmlspecialchars($server['coupon_code']) . ' -' . number_format($server['coupon_discount_percent'], 0) . '%)</small>';
                                } else {
                                    echo '$' . number_format($price, 2);
                                }
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
	                        <td>
	                            <?php if ($server['order_id']): ?>
	                                <form method="post" action="renew_server.php" onsubmit="return confirm('Create a renewal invoice for this server?');" style="margin:0;">
	                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($renew_csrf, ENT_QUOTES, 'UTF-8'); ?>">
	                                    <input type="hidden" name="order_id" value="<?php echo intval($server['order_id']); ?>">
	                                    <button type="submit" class="gsw-btn">Renew</button>
	                                </form>
	                            <?php else: ?>
                                <span class="muted">N/A</span>
                            <?php endif; ?>
                            <?php if (isset($pending_cancellations[(int)$server['home_id']])): ?>
                                <div class="muted">Cancellation pending (<?php echo htmlspecialchars($pending_cancellations[(int)$server['home_id']]); ?>)</div>
                            <?php elseif ($server['order_id'] && in_array($billing_status, ['Active','Invoiced'], true)): ?>
                                <form method="post" style="margin-top:10px;padding-top:8px;border-top:1px solid #ddd">
                                    <strong>Cancel service</strong><br>
                                    <label><input type="radio" name="mode" value="immediate" required> Cancel now and request a refund</label><br>
                                    <label><input type="radio" name="mode" value="end_of_term"> Cancel at the end of the current term</label><br>
                                    <label><input type="checkbox" name="confirm" value="1" required> I confirm this cancellation request</label><br>
                                    <input type="hidden" name="action" value="request_cancellation"><input type="hidden" name="home_id" value="<?php echo (int)$server['home_id']; ?>"><input type="hidden" name="order_id" value="<?php echo (int)$server['order_id']; ?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($cancel_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="gsw-btn">Submit cancellation</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="panel no-data">
            <p style="font-size:1.2rem;margin-bottom:20px;">You don't have any game servers yet.</p>
            <a href="serverlist.php" class="gsw-btn">Browse Game Servers</a>
        </div>
    <?php endif; ?>
</div>

<?php
// Close database connection
    billing_maybe_close_db($db);
?>

</body>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</html>
