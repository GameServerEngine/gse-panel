<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php
require_once(__DIR__ . '/bootstrap.php');

function gsw_status_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$db = billing_get_db();
$statusRows = [];
$statusError = '';

if ($db instanceof mysqli) {
    $table_check = @mysqli_query($db, "SHOW TABLES LIKE '{$table_prefix}server_status'");
    if (!$table_check || mysqli_num_rows($table_check) === 0) {
        $create_table = "CREATE TABLE IF NOT EXISTS {$table_prefix}server_status (
            status_id INT AUTO_INCREMENT PRIMARY KEY,
            remote_server_id INT NOT NULL,
            server_name VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45),
            status ENUM('online', 'offline', 'maintenance') DEFAULT 'offline',
            cpu_usage DECIMAL(5,2),
            memory_usage DECIMAL(5,2),
            disk_usage DECIMAL(5,2),
            uptime VARCHAR(50),
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            notes TEXT,
            INDEX idx_remote_server (remote_server_id),
            UNIQUE KEY unique_server (remote_server_id)
        )";
        @mysqli_query($db, $create_table);
    }

    $query = "SELECT
                rs.remote_server_id,
                rs.remote_server_name,
                rs.enabled,
                ss.status,
                ss.cpu_usage,
                ss.memory_usage,
                ss.disk_usage,
                ss.uptime,
                ss.last_updated,
                ss.notes
              FROM {$table_prefix}remote_servers rs
              LEFT JOIN {$table_prefix}server_status ss ON rs.remote_server_id = ss.remote_server_id
              ORDER BY rs.remote_server_name";

    $result = @mysqli_query($db, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $statusRows[] = $row;
        }
        mysqli_free_result($result);
    } else {
        $statusError = 'Server status data could not be loaded from the current runtime schema.';
    }
} else {
    $statusError = 'Server status is temporarily unavailable because the storefront database is not configured in this environment.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Status - <?php echo billing_brand_e('name'); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo gsw_status_e(billing_url('images/brand/favicon-32x32.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); ?>
<?php include(__DIR__ . '/includes/menu.php'); ?>

<main class="site-panel">
    <h1 class="site-panel-title">Server Status</h1>
    <div class="text-center mb-20">
        <p class="text-muted">Runtime-backed status for configured <?php echo billing_brand_e('name'); ?> hosting nodes.</p>
    </div>

    <?php if ($statusError !== ''): ?>
        <div class="panel no-data">
            <p><?php echo gsw_status_e($statusError); ?></p>
            <p class="mt-12"><a class="gsw-btn-secondary" href="<?php echo gsw_status_e(billing_url('support.php')); ?>">Contact Support</a></p>
        </div>
    <?php elseif (!empty($statusRows)): ?>
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Server Name</th>
                    <th>Status</th>
                    <th>CPU Usage</th>
                    <th>Memory Usage</th>
                    <th>Disk Usage</th>
                    <th>Uptime</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statusRows as $server): ?>
                    <?php
                    $status = $server['status'] ?? 'unknown';
                    if ((int)($server['enabled'] ?? 0) === 0) {
                        $status = 'maintenance';
                    }
                    $status_class = 'status-unknown';
                    $status_display = 'Unknown';
                    if ($status === 'online') {
                        $status_class = 'status-online';
                        $status_display = 'Online';
                    } elseif ($status === 'offline') {
                        $status_class = 'status-offline';
                        $status_display = 'Offline';
                    } elseif ($status === 'maintenance') {
                        $status_class = 'status-maintenance';
                        $status_display = 'Maintenance';
                    }

                    $last_updated = 'Never';
                    if (!empty($server['last_updated'])) {
                        $timestamp = strtotime((string)$server['last_updated']);
                        $diff = $timestamp ? time() - $timestamp : 0;
                        if ($diff < 60) {
                            $last_updated = 'Just now';
                        } elseif ($diff < 3600) {
                            $last_updated = floor($diff / 60) . ' min ago';
                        } elseif ($diff < 86400) {
                            $last_updated = floor($diff / 3600) . ' hours ago';
                        } else {
                            $last_updated = date('M d, Y', $timestamp);
                        }
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo gsw_status_e($server['remote_server_name'] ?? 'Configured node'); ?></strong></td>
                        <td><span class="status-badge <?php echo gsw_status_e($status_class); ?>"><?php echo gsw_status_e($status_display); ?></span></td>
                        <td><?php echo !empty($server['cpu_usage']) ? gsw_status_e(number_format((float)$server['cpu_usage'], 1) . '%') : 'N/A'; ?></td>
                        <td><?php echo !empty($server['memory_usage']) ? gsw_status_e(number_format((float)$server['memory_usage'], 1) . '%') : 'N/A'; ?></td>
                        <td><?php echo !empty($server['disk_usage']) ? gsw_status_e(number_format((float)$server['disk_usage'], 1) . '%') : 'N/A'; ?></td>
                        <td><?php echo gsw_status_e($server['uptime'] ?? 'N/A'); ?></td>
                        <td><?php echo gsw_status_e($last_updated); ?></td>
                    </tr>
                    <?php if (!empty($server['notes'])): ?>
                    <tr>
                        <td colspan="7" class="server-notes"><em><?php echo gsw_status_e($server['notes']); ?></em></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="panel no-data">
            <p>No server status information is currently published.</p>
        </div>
    <?php endif; ?>

    <div class="text-center text-muted mt-20">
        <p>Status information is generated by the configured runtime environment. If you experience an issue, contact support with your server name and recent changes.</p>
    </div>
</main>

<?php billing_maybe_close_db($db); ?>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
