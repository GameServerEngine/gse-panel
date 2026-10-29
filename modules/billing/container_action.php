<?php
require_once __DIR__ . '/includes/login_required.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/container_poc.php';

$db = billing_get_db();
if (!$db) {
    die('Database connection failed.');
}
container_poc_ensure_schema($db);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !billing_action_validate_csrf($_POST['csrf'] ?? '', 'container_lifecycle_csrf')) {
    header('Location: my_servers.php');
    exit;
}

$userId = (int)$_SESSION['website_user_id'];
$containerId = (int)($_POST['container_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$allowed = ['start', 'stop', 'restart', 'delete', 'reset_access'];
if (!in_array($action, $allowed, true)) {
    header('Location: my_servers.php?container_error=invalid_action');
    exit;
}

$containers = container_poc_table('managed_containers');
$agents = container_poc_table('container_agents');
$rows = container_poc_fetch_all($db, "SELECT c.*, a.base_url, a.api_token, a.agent_id FROM `{$containers}` c JOIN `{$agents}` a ON c.agent_id = a.agent_id WHERE c.container_id = {$containerId} AND c.user_id = {$userId} LIMIT 1");
if (!$rows) {
    header('Location: my_servers.php?container_error=not_found');
    exit;
}
$row = $rows[0];
$agent = ['base_url' => $row['base_url'], 'api_token' => $row['api_token']];
$runtimeId = (string)$row['agent_container_id'];
if ($action === 'reset_access') {
    try {
        $access = container_poc_provision_access_profile($db, $containerId, $agent + ['agent_id' => (int)$row['agent_id']], $runtimeId, 'gse-access-reset-' . $containerId . '-' . time());
        $_SESSION['container_access_flash'] = [
            'container_id' => $containerId,
            'profile' => $access['profile'],
            'password' => $access['password'],
        ];
        header('Location: my_servers.php?container_access=reset');
        exit;
    } catch (Throwable $e) {
        header('Location: my_servers.php?container_error=access_failed');
        exit;
    }
}
if ($action === 'delete') {
    $result = container_poc_agent_request($agent, 'DELETE', '/v1/containers/' . rawurlencode($runtimeId), ['idempotency_key' => 'gse-delete-' . $containerId, 'remove_volumes' => false, 'allow_absent' => true]);
    $newStatus = 'deleted';
} else {
    $result = container_poc_agent_request($agent, 'POST', '/v1/containers/' . rawurlencode($runtimeId) . '/' . $action, ['idempotency_key' => 'gse-' . $action . '-' . $containerId, 'timeout_seconds' => 30]);
    $newStatus = $action === 'stop' ? 'stopped' : 'running';
}
if (!empty($result['success'])) {
    $db->query("UPDATE `{$containers}` SET `status` = '{$newStatus}', `updated_at` = NOW() WHERE `container_id` = {$containerId}");
    header('Location: my_servers.php?container_action=ok');
} else {
    header('Location: my_servers.php?container_error=agent_failed');
}
exit;
