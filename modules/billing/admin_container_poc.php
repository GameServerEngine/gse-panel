<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/container_poc.php';

$db = billing_get_db();
if (!$db) {
    die('Database connection failed.');
}
container_poc_ensure_schema($db);
$csrf = billing_action_csrf_token('container_admin_csrf');
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'container_admin_csrf')) {
        $errors[] = 'Security token expired.';
    } else {
        $section = (string)($_POST['section'] ?? '');
        if ($section === 'agent') {
            $table = container_poc_table('container_agents');
            $id = (int)($_POST['agent_id'] ?? 0);
            if (($_POST['delete'] ?? '') === '1' && $id > 0) {
                $db->query("DELETE FROM `{$table}` WHERE `agent_id` = {$id}");
                $message = 'Agent removed.';
            } else {
                $data = [
                    'name' => trim((string)($_POST['name'] ?? '')),
                    'base_url' => trim((string)($_POST['base_url'] ?? '')),
                    'api_token' => (string)($_POST['api_token'] ?? ''),
                    'platform' => trim((string)($_POST['platform'] ?? 'linux')),
                    'version' => trim((string)($_POST['version'] ?? '')),
                    'location_id' => (int)($_POST['location_id'] ?? 0),
                    'enabled' => isset($_POST['enabled']) ? 1 : 0,
                    'available_cpu' => (float)($_POST['available_cpu'] ?? 0),
                    'available_memory_mb' => (int)($_POST['available_memory_mb'] ?? 0),
                    'available_storage_gb' => (int)($_POST['available_storage_gb'] ?? 0),
                    'available_ip_addresses_json' => (string)($_POST['available_ip_addresses_json'] ?? '[]'),
                    'capabilities_json' => (string)($_POST['capabilities_json'] ?? '[]'),
                    'max_container_count' => (int)($_POST['max_container_count'] ?? 100),
                ];
                if ($data['name'] === '' || $data['base_url'] === '' || $data['api_token'] === '') {
                    $errors[] = 'Agent name, URL, and token are required.';
                } elseif ($id > 0) {
                    $db->query("UPDATE `{$table}` SET `name`='" . $db->real_escape_string($data['name']) . "',`base_url`='" . $db->real_escape_string($data['base_url']) . "',`api_token`='" . $db->real_escape_string($data['api_token']) . "',`platform`='" . $db->real_escape_string($data['platform']) . "',`version`='" . $db->real_escape_string($data['version']) . "',`location_id`={$data['location_id']},`enabled`={$data['enabled']},`available_cpu`={$data['available_cpu']},`available_memory_mb`={$data['available_memory_mb']},`available_storage_gb`={$data['available_storage_gb']},`available_ip_addresses_json`='" . $db->real_escape_string($data['available_ip_addresses_json']) . "',`capabilities_json`='" . $db->real_escape_string($data['capabilities_json']) . "',`max_container_count`={$data['max_container_count']},`last_heartbeat_at`=NOW(),`updated_at`=NOW() WHERE `agent_id`={$id}");
                    $message = 'Agent updated.';
                } else {
                    $db->query("INSERT INTO `{$table}` (`name`,`base_url`,`api_token`,`platform`,`version`,`location_id`,`enabled`,`available_cpu`,`available_memory_mb`,`available_storage_gb`,`available_ip_addresses_json`,`capabilities_json`,`max_container_count`,`last_heartbeat_at`) VALUES ('" . $db->real_escape_string($data['name']) . "','" . $db->real_escape_string($data['base_url']) . "','" . $db->real_escape_string($data['api_token']) . "','" . $db->real_escape_string($data['platform']) . "','" . $db->real_escape_string($data['version']) . "',{$data['location_id']},{$data['enabled']},{$data['available_cpu']},{$data['available_memory_mb']},{$data['available_storage_gb']},'" . $db->real_escape_string($data['available_ip_addresses_json']) . "','" . $db->real_escape_string($data['capabilities_json']) . "',{$data['max_container_count']},NOW())");
                    $message = 'Agent added.';
                }
            }
        } elseif ($section === 'location') {
            $table = container_poc_table('container_locations');
            $name = trim((string)($_POST['name'] ?? ''));
            $region = trim((string)($_POST['region_code'] ?? ''));
            if ($name !== '' && $region !== '') {
                $db->query("INSERT INTO `{$table}` (`name`,`region_code`,`enabled`) VALUES ('" . $db->real_escape_string($name) . "','" . $db->real_escape_string($region) . "',1) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`enabled`=1,`updated_at`=NOW()");
                $message = 'Location saved.';
            }
        } elseif ($section === 'plan') {
            $table = container_poc_table('container_plans');
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name !== '') {
                $db->query("INSERT INTO `{$table}` (`name`,`cpu`,`memory_mb`,`storage_gb`,`enabled`) VALUES ('" . $db->real_escape_string($name) . "'," . (float)($_POST['cpu'] ?? 1) . "," . (int)($_POST['memory_mb'] ?? 1024) . "," . (int)($_POST['storage_gb'] ?? 10) . ",1) ON DUPLICATE KEY UPDATE `cpu`=VALUES(`cpu`),`memory_mb`=VALUES(`memory_mb`),`storage_gb`=VALUES(`storage_gb`),`enabled`=1");
                $message = 'Plan saved.';
            }
        } elseif ($section === 'port_pool') {
            $table = container_poc_table('container_port_pools');
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name !== '') {
                $db->query("INSERT INTO `{$table}` (`location_id`,`name`,`host_ip`,`range_start`,`range_end`,`enabled`) VALUES (" . (int)($_POST['location_id'] ?? 0) . ",'" . $db->real_escape_string($name) . "','" . $db->real_escape_string((string)($_POST['host_ip'] ?? '0.0.0.0')) . "'," . (int)($_POST['range_start'] ?? 30000) . "," . (int)($_POST['range_end'] ?? 30100) . ",1) ON DUPLICATE KEY UPDATE `location_id`=VALUES(`location_id`),`host_ip`=VALUES(`host_ip`),`range_start`=VALUES(`range_start`),`range_end`=VALUES(`range_end`),`enabled`=1");
                $message = 'Port pool saved.';
            }
        } elseif ($section === 'application') {
            $table = container_poc_table('container_applications');
            $id = (int)($_POST['application_id'] ?? 0);
            $slug = container_poc_normalize_slug((string)($_POST['slug'] ?? $_POST['name'] ?? 'application'));
            if (($_POST['delete'] ?? '') === '1' && $id > 0 && $slug !== 'empty-container') {
                $db->query("DELETE FROM `{$table}` WHERE `application_id` = {$id}");
                $message = 'Application removed.';
            } else {
                $values = [
                    'slug' => $slug,
                    'name' => trim((string)($_POST['name'] ?? '')),
                    'short_description' => trim((string)($_POST['short_description'] ?? '')),
                    'version' => trim((string)($_POST['version'] ?? '')),
                    'supported_platform' => trim((string)($_POST['supported_platform'] ?? 'linux')),
                    'recommended_cpu' => (float)($_POST['recommended_cpu'] ?? 1),
                    'recommended_memory_mb' => (int)($_POST['recommended_memory_mb'] ?? 1024),
                    'recommended_storage_gb' => (int)($_POST['recommended_storage_gb'] ?? 10),
                    'minimum_cpu' => (float)($_POST['minimum_cpu'] ?? 1),
                    'minimum_memory_mb' => (int)($_POST['minimum_memory_mb'] ?? 512),
                    'minimum_storage_gb' => (int)($_POST['minimum_storage_gb'] ?? 5),
                    'required_ports_json' => (string)($_POST['required_ports_json'] ?? '[]'),
                    'container_image' => trim((string)($_POST['container_image'] ?? 'alpine:latest')),
                    'install_interpreter' => (string)($_POST['install_interpreter'] ?? 'none'),
                    'install_script' => (string)($_POST['install_script'] ?? ''),
                    'status' => (string)($_POST['status'] ?? 'active'),
                ];
                if ($values['name'] === '') {
                    $errors[] = 'Application name is required.';
                } elseif ($id > 0) {
                    $db->query("UPDATE `{$table}` SET `slug`='" . $db->real_escape_string($values['slug']) . "',`name`='" . $db->real_escape_string($values['name']) . "',`short_description`='" . $db->real_escape_string($values['short_description']) . "',`version`='" . $db->real_escape_string($values['version']) . "',`supported_platform`='" . $db->real_escape_string($values['supported_platform']) . "',`recommended_cpu`={$values['recommended_cpu']},`recommended_memory_mb`={$values['recommended_memory_mb']},`recommended_storage_gb`={$values['recommended_storage_gb']},`minimum_cpu`={$values['minimum_cpu']},`minimum_memory_mb`={$values['minimum_memory_mb']},`minimum_storage_gb`={$values['minimum_storage_gb']},`required_ports_json`='" . $db->real_escape_string($values['required_ports_json']) . "',`preferred_ports_json`='" . $db->real_escape_string($values['required_ports_json']) . "',`container_image`='" . $db->real_escape_string($values['container_image']) . "',`install_interpreter`='" . $db->real_escape_string($values['install_interpreter']) . "',`install_script`='" . $db->real_escape_string($values['install_script']) . "',`status`='" . $db->real_escape_string($values['status']) . "',`updated_at`=NOW() WHERE `application_id`={$id}");
                    $message = 'Application updated.';
                } else {
                    $db->query("INSERT INTO `{$table}` (`slug`,`name`,`short_description`,`version`,`supported_platform`,`recommended_cpu`,`recommended_memory_mb`,`recommended_storage_gb`,`minimum_cpu`,`minimum_memory_mb`,`minimum_storage_gb`,`required_ports_json`,`preferred_ports_json`,`container_image`,`install_interpreter`,`install_script`,`status`) VALUES ('" . $db->real_escape_string($values['slug']) . "','" . $db->real_escape_string($values['name']) . "','" . $db->real_escape_string($values['short_description']) . "','" . $db->real_escape_string($values['version']) . "','" . $db->real_escape_string($values['supported_platform']) . "',{$values['recommended_cpu']},{$values['recommended_memory_mb']},{$values['recommended_storage_gb']},{$values['minimum_cpu']},{$values['minimum_memory_mb']},{$values['minimum_storage_gb']},'" . $db->real_escape_string($values['required_ports_json']) . "','" . $db->real_escape_string($values['required_ports_json']) . "','" . $db->real_escape_string($values['container_image']) . "','" . $db->real_escape_string($values['install_interpreter']) . "','" . $db->real_escape_string($values['install_script']) . "','" . $db->real_escape_string($values['status']) . "')");
                    $message = 'Application added.';
                }
            }
        }
    }
}

$locations = container_poc_fetch_all($db, "SELECT * FROM `" . container_poc_table('container_locations') . "` ORDER BY `name`");
$agents = container_poc_fetch_all($db, "SELECT a.*, l.name AS location_name FROM `" . container_poc_table('container_agents') . "` a LEFT JOIN `" . container_poc_table('container_locations') . "` l ON a.location_id = l.location_id ORDER BY a.agent_id DESC");
$apps = container_poc_applications($db, false);
$plans = container_poc_fetch_all($db, "SELECT * FROM `" . container_poc_table('container_plans') . "` ORDER BY `name`");
$portPools = container_poc_fetch_all($db, "SELECT p.*, l.name AS location_name FROM `" . container_poc_table('container_port_pools') . "` p LEFT JOIN `" . container_poc_table('container_locations') . "` l ON p.location_id = l.location_id ORDER BY p.name");
$reservedPorts = container_poc_fetch_all($db, "SELECT * FROM `" . container_poc_table('container_reserved_ports') . "` ORDER BY reserved_port_id DESC LIMIT 50");
$jobs = container_poc_fetch_all($db, "SELECT j.*, a.name AS application_name, ag.name AS agent_name FROM `" . container_poc_table('container_provisioning_jobs') . "` j LEFT JOIN `" . container_poc_table('container_applications') . "` a ON j.application_id = a.application_id LEFT JOIN `" . container_poc_table('container_agents') . "` ag ON j.agent_id = ag.agent_id ORDER BY j.job_id DESC LIMIT 20");
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Container POC Admin</title><meta name="viewport" content="width=device-width, initial-scale=1"><?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include __DIR__ . '/includes/top.php'; ?>
<?php include __DIR__ . '/includes/menu.php'; ?>
<main class="container-wide panel">
  <h1>Container POC Administration</h1>
  <?php if ($message): ?><div class="gsw-alert-success"><?php echo container_poc_e($message); ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="gsw-alert-danger"><?php echo container_poc_e($error); ?></div><?php endforeach; ?>

  <section class="gsw-wizard-grid">
    <form method="post" class="gsw-form-card">
      <h2>Add Agent</h2>
      <input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>"><input type="hidden" name="section" value="agent">
      <label>Name <input name="name" required></label>
      <label>Base URL <input name="base_url" placeholder="http://127.0.0.1:12343" required></label>
      <label>API Token <input name="api_token" required></label>
      <label>Location <select name="location_id"><?php foreach ($locations as $location): ?><option value="<?php echo (int)$location['location_id']; ?>"><?php echo container_poc_e($location['name']); ?></option><?php endforeach; ?></select></label>
      <label>Platform <input name="platform" value="linux"></label>
      <label>Version <input name="version" value="0.1"></label>
      <label>Available CPU <input type="number" step="0.1" name="available_cpu" value="4"></label>
      <label>Available Memory MB <input type="number" name="available_memory_mb" value="8192"></label>
      <label>Available Storage GB <input type="number" name="available_storage_gb" value="100"></label>
      <label>IP Addresses JSON <textarea name="available_ip_addresses_json">["127.0.0.1"]</textarea></label>
      <label>Capabilities JSON <textarea name="capabilities_json">["containers","content_jobs","lifecycle"]</textarea></label>
      <label>Max Containers <input type="number" name="max_container_count" value="100"></label>
      <label><input type="checkbox" name="enabled" checked> Enabled</label>
      <button class="gsw-btn" type="submit">Save Agent</button>
    </form>
    <form method="post" class="gsw-form-card">
      <h2>Add Application</h2>
      <input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>"><input type="hidden" name="section" value="application">
      <label>Name <input name="name" required></label>
      <label>Slug <input name="slug"></label>
      <label>Description <textarea name="short_description"></textarea></label>
      <label>Version <input name="version" value="demo"></label>
      <label>Platform <input name="supported_platform" value="linux"></label>
      <label>Recommended CPU <input name="recommended_cpu" type="number" step="0.1" value="1"></label>
      <label>Recommended Memory MB <input name="recommended_memory_mb" type="number" value="1024"></label>
      <label>Recommended Storage GB <input name="recommended_storage_gb" type="number" value="10"></label>
      <label>Minimum CPU <input name="minimum_cpu" type="number" step="0.1" value="1"></label>
      <label>Minimum Memory MB <input name="minimum_memory_mb" type="number" value="512"></label>
      <label>Minimum Storage GB <input name="minimum_storage_gb" type="number" value="5"></label>
      <label>Required Ports JSON <textarea name="required_ports_json">[]</textarea></label>
      <label>Container Image <input name="container_image" value="alpine:latest"></label>
      <label>Install Interpreter <select name="install_interpreter"><option>none</option><option>sh</option><option>bash</option><option>powershell</option><option>cmd</option></select></label>
      <label>Install Script <textarea name="install_script"></textarea></label>
      <label>Status <select name="status"><option>active</option><option>disabled</option><option>draft</option></select></label>
      <button class="gsw-btn" type="submit">Save Application</button>
    </form>
  </section>

  <section class="gsw-wizard-grid">
    <form method="post" class="gsw-form-card">
      <h2>Add Location</h2>
      <input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>"><input type="hidden" name="section" value="location">
      <label>Name <input name="name" required></label>
      <label>Region Code <input name="region_code" required></label>
      <button class="gsw-btn" type="submit">Save Location</button>
    </form>
    <form method="post" class="gsw-form-card">
      <h2>Add Container Plan</h2>
      <input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>"><input type="hidden" name="section" value="plan">
      <label>Name <input name="name" required></label>
      <label>CPU <input type="number" step="0.1" name="cpu" value="1"></label>
      <label>Memory MB <input type="number" name="memory_mb" value="1024"></label>
      <label>Storage GB <input type="number" name="storage_gb" value="10"></label>
      <button class="gsw-btn" type="submit">Save Plan</button>
    </form>
    <form method="post" class="gsw-form-card">
      <h2>Add Port Pool</h2>
      <input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>"><input type="hidden" name="section" value="port_pool">
      <label>Name <input name="name" required></label>
      <label>Location <select name="location_id"><?php foreach ($locations as $location): ?><option value="<?php echo (int)$location['location_id']; ?>"><?php echo container_poc_e($location['name']); ?></option><?php endforeach; ?></select></label>
      <label>Host IP <input name="host_ip" value="0.0.0.0"></label>
      <label>Range Start <input type="number" name="range_start" value="30000"></label>
      <label>Range End <input type="number" name="range_end" value="30100"></label>
      <button class="gsw-btn" type="submit">Save Port Pool</button>
    </form>
  </section>

  <h2>Agents</h2>
  <table class="cart-table"><thead><tr><th>Name</th><th>Status</th><th>Location</th><th>Platform</th><th>Version</th><th>CPU</th><th>Memory</th><th>Storage</th><th>IPs</th><th>Capabilities</th><th>Containers</th><th>Apps</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($agents as $agent): ?><tr>
      <td><?php echo container_poc_e($agent['name']); ?></td><td><?php echo container_poc_e(container_poc_status_label(container_poc_agent_state($agent))); ?></td><td><?php echo container_poc_e($agent['location_name']); ?></td><td><?php echo container_poc_e($agent['platform']); ?></td><td><?php echo container_poc_e($agent['version']); ?></td><td><?php echo container_poc_e($agent['available_cpu']); ?></td><td><?php echo (int)$agent['available_memory_mb']; ?> MB</td><td><?php echo (int)$agent['available_storage_gb']; ?> GB</td><td><code><?php echo container_poc_e($agent['available_ip_addresses_json']); ?></code></td><td><code><?php echo container_poc_e($agent['capabilities_json']); ?></code></td><td><?php echo (int)$agent['current_container_count']; ?></td><td><?php echo (int)$agent['current_application_count']; ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>"><input type="hidden" name="section" value="agent"><input type="hidden" name="agent_id" value="<?php echo (int)$agent['agent_id']; ?>"><input type="hidden" name="delete" value="1"><button class="gsw-btn-secondary" type="submit">Remove</button></form></td>
    </tr><?php endforeach; ?>
  </tbody></table>

  <h2>Application Catalog</h2>
  <table class="cart-table"><thead><tr><th>Name</th><th>Version</th><th>Platform</th><th>Recommended</th><th>Minimum</th><th>Ports</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($apps as $app): ?><tr><td><?php echo container_poc_e($app['name']); ?></td><td><?php echo container_poc_e($app['version']); ?></td><td><?php echo container_poc_e($app['supported_platform']); ?></td><td><?php echo container_poc_e($app['recommended_cpu']); ?> CPU / <?php echo (int)$app['recommended_memory_mb']; ?> MB / <?php echo (int)$app['recommended_storage_gb']; ?> GB</td><td><?php echo container_poc_e($app['minimum_cpu']); ?> CPU / <?php echo (int)$app['minimum_memory_mb']; ?> MB / <?php echo (int)$app['minimum_storage_gb']; ?> GB</td><td><code><?php echo container_poc_e($app['required_ports_json']); ?></code></td><td><?php echo container_poc_e($app['status']); ?></td></tr><?php endforeach; ?>
  </tbody></table>

  <h2>Locations</h2>
  <table class="cart-table"><thead><tr><th>Name</th><th>Region</th><th>Enabled</th></tr></thead><tbody>
    <?php foreach ($locations as $location): ?><tr><td><?php echo container_poc_e($location['name']); ?></td><td><?php echo container_poc_e($location['region_code']); ?></td><td><?php echo (int)$location['enabled'] ? 'Yes' : 'No'; ?></td></tr><?php endforeach; ?>
  </tbody></table>

  <h2>Container Plans</h2>
  <table class="cart-table"><thead><tr><th>Name</th><th>CPU</th><th>Memory</th><th>Storage</th><th>Enabled</th></tr></thead><tbody>
    <?php foreach ($plans as $plan): ?><tr><td><?php echo container_poc_e($plan['name']); ?></td><td><?php echo container_poc_e($plan['cpu']); ?></td><td><?php echo (int)$plan['memory_mb']; ?> MB</td><td><?php echo (int)$plan['storage_gb']; ?> GB</td><td><?php echo (int)$plan['enabled'] ? 'Yes' : 'No'; ?></td></tr><?php endforeach; ?>
  </tbody></table>

  <h2>Port Pools</h2>
  <table class="cart-table"><thead><tr><th>Name</th><th>Location</th><th>Host IP</th><th>Range</th><th>Enabled</th></tr></thead><tbody>
    <?php foreach ($portPools as $pool): ?><tr><td><?php echo container_poc_e($pool['name']); ?></td><td><?php echo container_poc_e($pool['location_name']); ?></td><td><?php echo container_poc_e($pool['host_ip']); ?></td><td><?php echo (int)$pool['range_start']; ?>-<?php echo (int)$pool['range_end']; ?></td><td><?php echo (int)$pool['enabled'] ? 'Yes' : 'No'; ?></td></tr><?php endforeach; ?>
  </tbody></table>

  <h2>Reserved Ports</h2>
  <table class="cart-table"><thead><tr><th>ID</th><th>Job</th><th>Container</th><th>Host</th><th>Container Port</th><th>Protocol</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($reservedPorts as $port): ?><tr><td><?php echo (int)$port['reserved_port_id']; ?></td><td><?php echo (int)$port['job_id']; ?></td><td><?php echo (int)$port['container_id']; ?></td><td><?php echo container_poc_e($port['host_ip']); ?>:<?php echo (int)$port['host_port']; ?></td><td><?php echo (int)$port['container_port']; ?></td><td><?php echo container_poc_e($port['protocol']); ?></td><td><?php echo container_poc_e($port['status']); ?></td></tr><?php endforeach; ?>
  </tbody></table>

  <h2>Recent Provisioning Jobs</h2>
  <table class="cart-table"><thead><tr><th>ID</th><th>User</th><th>Application</th><th>Agent</th><th>Name</th><th>Status</th><th>Error</th><th>Created</th></tr></thead><tbody>
    <?php foreach ($jobs as $job): ?><tr><td><?php echo (int)$job['job_id']; ?></td><td><?php echo (int)$job['user_id']; ?></td><td><?php echo container_poc_e($job['application_name']); ?></td><td><?php echo container_poc_e($job['agent_name']); ?></td><td><?php echo container_poc_e($job['container_name']); ?></td><td><?php echo container_poc_e($job['status']); ?></td><td><?php echo container_poc_e($job['last_error']); ?></td><td><?php echo container_poc_e($job['created_at']); ?></td></tr><?php endforeach; ?>
  </tbody></table>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
