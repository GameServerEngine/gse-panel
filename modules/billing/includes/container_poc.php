<?php

require_once __DIR__ . '/../bootstrap.php';

if (!function_exists('container_poc_e')) {
    function container_poc_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function container_poc_table(string $name): string
{
    global $table_prefix;
    return ($table_prefix ?? '') . $name;
}

function container_poc_json_decode($value, $fallback)
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function container_poc_normalize_slug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9_.-]+/', '-', $slug);
    $slug = trim((string)$slug, '-.');
    return $slug !== '' ? substr($slug, 0, 80) : 'container';
}

function container_poc_status_label(?string $status): string
{
    $status = strtolower((string)$status);
    if ($status === 'disabled') {
        return 'Disabled';
    }
    if ($status === 'busy') {
        return 'Busy';
    }
    if ($status === 'online') {
        return 'Online';
    }
    return 'Offline';
}

function container_poc_agent_state(array $agent): string
{
    if ((int)($agent['enabled'] ?? 0) !== 1) {
        return 'disabled';
    }
    $heartbeat = strtotime((string)($agent['last_heartbeat_at'] ?? ''));
    if (!$heartbeat || $heartbeat < time() - 180) {
        return 'offline';
    }
    if ((int)($agent['current_container_count'] ?? 0) >= (int)($agent['max_container_count'] ?? 999999)) {
        return 'busy';
    }
    return 'online';
}

function container_poc_ensure_schema(mysqli $db): bool
{
    $tables = [
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_locations') . "` (
            `location_id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(128) NOT NULL,
            `region_code` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`location_id`),
            UNIQUE KEY `uq_container_locations_region` (`region_code`),
            KEY `idx_container_locations_enabled` (`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_agents') . "` (
            `agent_id` int(11) NOT NULL AUTO_INCREMENT,
            `location_id` int(11) DEFAULT NULL,
            `name` varchar(128) NOT NULL,
            `base_url` varchar(512) NOT NULL,
            `api_token` varchar(512) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `platform` varchar(64) NOT NULL DEFAULT 'linux',
            `version` varchar(64) NOT NULL DEFAULT '',
            `available_cpu` decimal(8,2) NOT NULL DEFAULT 0,
            `available_memory_mb` int(11) NOT NULL DEFAULT 0,
            `available_storage_gb` int(11) NOT NULL DEFAULT 0,
            `available_ip_addresses_json` mediumtext,
            `capabilities_json` mediumtext,
            `current_container_count` int(11) NOT NULL DEFAULT 0,
            `current_application_count` int(11) NOT NULL DEFAULT 0,
            `max_container_count` int(11) NOT NULL DEFAULT 100,
            `last_heartbeat_at` datetime DEFAULT NULL,
            `last_error` text,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`agent_id`),
            KEY `idx_container_agents_enabled_location` (`enabled`,`location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_applications') . "` (
            `application_id` int(11) NOT NULL AUTO_INCREMENT,
            `slug` varchar(80) NOT NULL,
            `name` varchar(160) NOT NULL,
            `icon` varchar(512) NOT NULL DEFAULT '',
            `short_description` varchar(512) NOT NULL DEFAULT '',
            `version` varchar(80) NOT NULL DEFAULT '',
            `supported_platform` varchar(64) NOT NULL DEFAULT 'linux',
            `recommended_cpu` decimal(8,2) NOT NULL DEFAULT 1,
            `recommended_memory_mb` int(11) NOT NULL DEFAULT 1024,
            `recommended_storage_gb` int(11) NOT NULL DEFAULT 10,
            `minimum_cpu` decimal(8,2) NOT NULL DEFAULT 1,
            `minimum_memory_mb` int(11) NOT NULL DEFAULT 1024,
            `minimum_storage_gb` int(11) NOT NULL DEFAULT 10,
            `required_ports_json` mediumtext,
            `preferred_ports_json` mediumtext,
            `default_port_block_size` int(11) NOT NULL DEFAULT 0,
            `compatibility_notes` text,
            `container_image` varchar(512) NOT NULL DEFAULT 'alpine:latest',
            `install_interpreter` enum('none','sh','bash','powershell','cmd') NOT NULL DEFAULT 'none',
            `install_script` mediumtext,
            `status` enum('active','disabled','draft') NOT NULL DEFAULT 'active',
            `sort_order` int(11) NOT NULL DEFAULT 100,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`application_id`),
            UNIQUE KEY `uq_container_applications_slug` (`slug`),
            KEY `idx_container_applications_status_sort` (`status`,`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_plans') . "` (
            `plan_id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(128) NOT NULL,
            `cpu` decimal(8,2) NOT NULL,
            `memory_mb` int(11) NOT NULL,
            `storage_gb` int(11) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`plan_id`),
            UNIQUE KEY `uq_container_plans_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_port_pools') . "` (
            `port_pool_id` int(11) NOT NULL AUTO_INCREMENT,
            `location_id` int(11) DEFAULT NULL,
            `name` varchar(128) NOT NULL,
            `host_ip` varchar(64) NOT NULL DEFAULT '0.0.0.0',
            `range_start` int(11) NOT NULL,
            `range_end` int(11) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`port_pool_id`),
            UNIQUE KEY `uq_container_port_pools_name` (`name`),
            KEY `idx_container_port_pools_range` (`enabled`,`range_start`,`range_end`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_reserved_ports') . "` (
            `reserved_port_id` int(11) NOT NULL AUTO_INCREMENT,
            `port_pool_id` int(11) DEFAULT NULL,
            `container_id` int(11) DEFAULT NULL,
            `job_id` int(11) DEFAULT NULL,
            `host_ip` varchar(64) NOT NULL DEFAULT '0.0.0.0',
            `host_port` int(11) NOT NULL,
            `container_port` int(11) NOT NULL,
            `protocol` enum('tcp','udp') NOT NULL DEFAULT 'tcp',
            `status` enum('reserved','active','released') NOT NULL DEFAULT 'reserved',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`reserved_port_id`),
            UNIQUE KEY `uq_container_reserved_port` (`host_ip`,`host_port`,`protocol`,`status`),
            KEY `idx_container_reserved_job` (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('container_provisioning_jobs') . "` (
            `job_id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `application_id` int(11) DEFAULT NULL,
            `agent_id` int(11) DEFAULT NULL,
            `location_id` int(11) DEFAULT NULL,
            `container_name` varchar(128) NOT NULL,
            `platform` varchar(64) NOT NULL DEFAULT 'linux',
            `cpu` decimal(8,2) NOT NULL,
            `memory_mb` int(11) NOT NULL,
            `storage_gb` int(11) NOT NULL,
            `port_mode` enum('none','automatic','block') NOT NULL DEFAULT 'none',
            `requested_port_block_size` int(11) NOT NULL DEFAULT 0,
            `status` enum('queued','provisioning','installing','running','failed','cancelled','deleted') NOT NULL DEFAULT 'queued',
            `agent_container_id` varchar(160) DEFAULT NULL,
            `last_result_json` mediumtext,
            `last_error` text,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL,
            `completed_at` datetime DEFAULT NULL,
            PRIMARY KEY (`job_id`),
            KEY `idx_container_jobs_user_status` (`user_id`,`status`),
            KEY `idx_container_jobs_agent` (`agent_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `" . container_poc_table('managed_containers') . "` (
            `container_id` int(11) NOT NULL AUTO_INCREMENT,
            `job_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `application_id` int(11) DEFAULT NULL,
            `agent_id` int(11) NOT NULL,
            `name` varchar(128) NOT NULL,
            `agent_container_id` varchar(160) NOT NULL,
            `status` enum('created','running','stopped','failed','deleted') NOT NULL DEFAULT 'created',
            `platform` varchar(64) NOT NULL DEFAULT 'linux',
            `cpu` decimal(8,2) NOT NULL,
            `memory_mb` int(11) NOT NULL,
            `storage_gb` int(11) NOT NULL,
            `ports_json` mediumtext,
            `access_profile_json` mediumtext,
            `access_last_set_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`container_id`),
            UNIQUE KEY `uq_managed_containers_agent_runtime` (`agent_id`,`agent_container_id`),
            KEY `idx_managed_containers_user_status` (`user_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        if (!$db->query($sql)) {
            return false;
        }
    }
    container_poc_ensure_column($db, container_poc_table('managed_containers'), 'access_profile_json', "`access_profile_json` mediumtext");
    container_poc_ensure_column($db, container_poc_table('managed_containers'), 'access_last_set_at', "`access_last_set_at` datetime DEFAULT NULL");
    return container_poc_seed_defaults($db);
}

function container_poc_ensure_column(mysqli $db, string $table, string $column, string $definition): bool
{
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $db->real_escape_string($column);
    $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if ($result && $result->num_rows > 0) {
        return true;
    }
    return (bool)$db->query("ALTER TABLE `{$safeTable}` ADD COLUMN {$definition}");
}

function container_poc_seed_defaults(mysqli $db): bool
{
    $apps = container_poc_table('container_applications');
    $locations = container_poc_table('container_locations');
    $plans = container_poc_table('container_plans');
    $pools = container_poc_table('container_port_pools');
    $db->query("INSERT IGNORE INTO `{$locations}` (`name`,`region_code`,`enabled`) VALUES ('Kansas City','us-central',1)");
    $db->query("INSERT IGNORE INTO `{$plans}` (`name`,`cpu`,`memory_mb`,`storage_gb`,`enabled`) VALUES ('POC Small',1,1024,10,1),('POC Medium',2,4096,30,1)");
    $db->query("INSERT IGNORE INTO `{$pools}` (`name`,`host_ip`,`range_start`,`range_end`,`enabled`) VALUES ('POC Default Ports','0.0.0.0',30000,30100,1)");
    $emptyPorts = $db->real_escape_string('[]');
    $minecraftPorts = $db->real_escape_string(json_encode([
        ['label' => 'Game', 'protocol' => 'tcp', 'container_port' => 25565, 'preferred_host_port' => 25565],
    ]));
    $installScript = $db->real_escape_string("mkdir -p /srv/server-content\nprintf 'Minecraft POC installed\n' > /srv/server-content/install.txt\n");
    $db->query("INSERT IGNORE INTO `{$apps}`
        (`slug`,`name`,`icon`,`short_description`,`version`,`supported_platform`,`recommended_cpu`,`recommended_memory_mb`,`recommended_storage_gb`,`minimum_cpu`,`minimum_memory_mb`,`minimum_storage_gb`,`required_ports_json`,`preferred_ports_json`,`default_port_block_size`,`compatibility_notes`,`container_image`,`install_interpreter`,`install_script`,`status`,`sort_order`)
        VALUES
        ('empty-container','None / Empty Container','','Blank managed container. Install applications later.','0.1','linux',1,1024,10,1,512,5,'{$emptyPorts}','{$emptyPorts}',0,'No server content is installed. Ports are optional.','alpine:latest','none',NULL,'active',0),
        ('minecraft-poc','Minecraft POC','','Java server content proof of concept.','demo','linux',2,4096,20,1,2048,10,'{$minecraftPorts}','{$minecraftPorts}',1,'Requires TCP 25565 or an allocated replacement.','eclipse-temurin:21-jre','sh','{$installScript}','active',10)");
    return true;
}

function container_poc_fetch_all(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function container_poc_applications(mysqli $db, bool $activeOnly = true): array
{
    $table = container_poc_table('container_applications');
    $where = $activeOnly ? "WHERE `status` = 'active'" : '';
    return container_poc_fetch_all($db, "SELECT * FROM `{$table}` {$where} ORDER BY `sort_order`, `name`");
}

function container_poc_validate_request(array $application, array $input): array
{
    $errors = [];
    $cpu = (float)($input['cpu'] ?? 0);
    $memory = (int)($input['memory_mb'] ?? 0);
    $storage = (int)($input['storage_gb'] ?? 0);
    $name = trim((string)($input['container_name'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_. -]{1,126}$/', $name)) {
        $errors[] = 'Container name must be 2-127 characters and use letters, numbers, spaces, dots, underscores, or dashes.';
    }
    if ($cpu < (float)$application['minimum_cpu']) {
        $errors[] = 'CPU is below the selected application minimum.';
    }
    if ($memory < (int)$application['minimum_memory_mb']) {
        $errors[] = 'Memory is below the selected application minimum.';
    }
    if ($storage < (int)$application['minimum_storage_gb']) {
        $errors[] = 'Storage is below the selected application minimum.';
    }
    if (!in_array((string)($input['port_mode'] ?? 'none'), ['none', 'automatic', 'block'], true)) {
        $errors[] = 'Port mode is invalid.';
    }
    if (($application['slug'] ?? '') !== 'empty-container' && (string)($input['port_mode'] ?? 'none') === 'none') {
        $errors[] = 'This application requires ports; choose automatic allocation or a port block.';
    }
    return $errors;
}

function container_poc_select_agent(mysqli $db, int $locationId): ?array
{
    $table = container_poc_table('container_agents');
    $locationFilter = $locationId > 0 ? "AND (`location_id` = {$locationId} OR `location_id` IS NULL)" : '';
    $rows = container_poc_fetch_all($db, "SELECT * FROM `{$table}` WHERE `enabled` = 1 {$locationFilter} ORDER BY `current_container_count`, `agent_id` LIMIT 10");
    foreach ($rows as $row) {
        if (container_poc_agent_state($row) === 'online') {
            return $row;
        }
    }
    return $rows[0] ?? null;
}

function container_poc_allocate_ports(mysqli $db, int $jobId, array $application, string $mode): array
{
    $required = container_poc_json_decode($application['required_ports_json'] ?? '[]', []);
    if ($mode === 'none' && empty($required)) {
        return [];
    }
    $blockSize = max(count($required), (int)($application['default_port_block_size'] ?? 0), $mode === 'block' ? 1 : 0);
    if ($blockSize < 1) {
        return [];
    }
    $poolTable = container_poc_table('container_port_pools');
    $reservedTable = container_poc_table('container_reserved_ports');
    $pools = container_poc_fetch_all($db, "SELECT * FROM `{$poolTable}` WHERE `enabled` = 1 ORDER BY `port_pool_id`");
    foreach ($pools as $pool) {
        for ($candidate = (int)$pool['range_start']; $candidate <= (int)$pool['range_end'] - $blockSize + 1; $candidate++) {
            $end = $candidate + $blockSize - 1;
            $check = $db->query("SELECT 1 FROM `{$reservedTable}` WHERE `status` IN ('reserved','active') AND `host_ip` = '" . $db->real_escape_string($pool['host_ip']) . "' AND `host_port` BETWEEN {$candidate} AND {$end} LIMIT 1");
            if ($check && $check->num_rows > 0) {
                continue;
            }
            $ports = [];
            for ($i = 0; $i < $blockSize; $i++) {
                $spec = $required[$i] ?? ['label' => 'Port ' . ($i + 1), 'protocol' => 'tcp', 'container_port' => $candidate + $i];
                $hostPort = $candidate + $i;
                $containerPort = (int)($spec['container_port'] ?? $hostPort);
                $protocol = strtolower((string)($spec['protocol'] ?? 'tcp')) === 'udp' ? 'udp' : 'tcp';
                $label = (string)($spec['label'] ?? 'port');
                $db->query("INSERT INTO `{$reservedTable}` (`port_pool_id`,`job_id`,`host_ip`,`host_port`,`container_port`,`protocol`,`status`) VALUES (" . (int)$pool['port_pool_id'] . ",{$jobId},'" . $db->real_escape_string($pool['host_ip']) . "',{$hostPort},{$containerPort},'{$protocol}','reserved')");
                $ports[] = [
                    'protocol' => $protocol,
                    'label' => $label,
                    'host_ip' => (string)$pool['host_ip'],
                    'host_port' => $hostPort,
                    'container_port' => $containerPort,
                    'expected_listener' => true,
                ];
            }
            return $ports;
        }
    }
    throw new RuntimeException('No available port block could satisfy the request.');
}

function container_poc_agent_request(array $agent, string $method, string $path, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'status_code' => 0, 'error' => 'PHP curl extension is not available.', 'body' => null];
    }
    $base = rtrim((string)$agent['base_url'], '/');
    $url = $base . $path;
    $headers = [
        'Authorization: Bearer ' . (string)$agent['api_token'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        return ['success' => false, 'status_code' => 0, 'error' => $error, 'body' => null];
    }
    $json = json_decode((string)$body, true);
    return ['success' => $status >= 200 && $status < 300, 'status_code' => $status, 'error' => null, 'body' => $json ?: $body];
}

function container_poc_access_profile_from_response(array $response): array
{
    $data = $response['body']['data'] ?? $response['body'] ?? [];
    $profile = $data['profile'] ?? $data;
    return is_array($profile) ? $profile : [];
}

function container_poc_access_password_from_response(array $response): string
{
    $data = $response['body']['data'] ?? $response['body'] ?? [];
    return is_array($data) ? (string)($data['one_time_password'] ?? '') : '';
}

function container_poc_provision_access_profile(mysqli $db, int $containerId, array $agent, string $runtimeId, string $idempotencyKey, ?callable $transport = null): array
{
    $transport = $transport ?: 'container_poc_agent_request';
    $result = $transport($agent, 'POST', '/v1/containers/' . rawurlencode($runtimeId) . '/access', ['idempotency_key' => $idempotencyKey]);
    if (empty($result['success'])) {
        throw new RuntimeException('Agent access provisioning failed: ' . json_encode($result));
    }
    $profile = container_poc_access_profile_from_response($result);
    if (empty($profile['username']) || empty($profile['host']) || empty($profile['port'])) {
        throw new RuntimeException('Agent access provisioning did not return connection metadata.');
    }
    unset($profile['password'], $profile['password_hash'], $profile['password_salt']);
    $profileJson = $db->real_escape_string(json_encode($profile));
    $containers = container_poc_table('managed_containers');
    $db->query("UPDATE `{$containers}` SET `access_profile_json` = '{$profileJson}', `access_last_set_at` = NOW(), `updated_at` = NOW() WHERE `container_id` = {$containerId}");
    return ['profile' => $profile, 'password' => container_poc_access_password_from_response($result)];
}

function container_poc_build_container_request(int $jobId, array $application, array $input, array $ports): array
{
    $name = 'gse-' . $jobId . '-' . container_poc_normalize_slug((string)$input['container_name']);
    return [
        'name' => $name,
        'image' => ['reference' => (string)$application['container_image'], 'pull_policy' => 'if_missing'],
        'idempotency_key' => 'gse-container-job-' . $jobId,
        'ports' => $ports,
        'resources' => [
            'cpu_limit' => (float)$input['cpu'],
            'memory_limit_mb' => (int)$input['memory_mb'],
            'memory_reservation_mb' => (int)$input['memory_mb'],
        ],
        'labels' => [
            'com.gameserverengine.job_id' => (string)$jobId,
            'com.gameserverengine.application' => (string)$application['slug'],
        ],
        'platform' => (string)$input['platform'],
    ];
}

function container_poc_run_agent_workflow(mysqli $db, int $jobId, array $agent, array $application, array $input, array $ports, ?callable $transport = null): array
{
    $transport = $transport ?: 'container_poc_agent_request';
    $jobs = container_poc_table('container_provisioning_jobs');
    $containers = container_poc_table('managed_containers');
    $reserved = container_poc_table('container_reserved_ports');
    $db->query("UPDATE `{$jobs}` SET `status` = 'provisioning', `agent_id` = " . (int)$agent['agent_id'] . ", `updated_at` = NOW() WHERE `job_id` = {$jobId}");
    $create = $transport($agent, 'POST', '/v1/containers', container_poc_build_container_request($jobId, $application, $input, $ports));
    if (empty($create['success'])) {
        throw new RuntimeException('Agent container creation failed: ' . json_encode($create));
    }
    $detail = $create['body']['data'] ?? $create['body'];
    $runtimeId = (string)($detail['container_id'] ?? $detail['managed_id'] ?? '');
    if ($runtimeId === '') {
        throw new RuntimeException('Agent did not return a container ID.');
    }
    $portsJson = $db->real_escape_string(json_encode($ports));
    $db->query("INSERT INTO `{$containers}` (`job_id`,`user_id`,`application_id`,`agent_id`,`name`,`agent_container_id`,`status`,`platform`,`cpu`,`memory_mb`,`storage_gb`,`ports_json`) VALUES ({$jobId}," . (int)$input['user_id'] . "," . (int)$application['application_id'] . "," . (int)$agent['agent_id'] . ",'" . $db->real_escape_string((string)$input['container_name']) . "','" . $db->real_escape_string($runtimeId) . "','created','" . $db->real_escape_string((string)$input['platform']) . "'," . (float)$input['cpu'] . "," . (int)$input['memory_mb'] . "," . (int)$input['storage_gb'] . ",'{$portsJson}')");
    $localContainerId = (int)$db->insert_id;
    $access = container_poc_provision_access_profile($db, $localContainerId, $agent, $runtimeId, 'gse-access-job-' . $jobId, $transport);
    $db->query("UPDATE `{$reserved}` SET `container_id` = {$localContainerId}, `status` = 'active' WHERE `job_id` = {$jobId}");
    $db->query("UPDATE `{$jobs}` SET `agent_container_id` = '" . $db->real_escape_string($runtimeId) . "', `updated_at` = NOW() WHERE `job_id` = {$jobId}");
    if (($application['install_interpreter'] ?? 'none') !== 'none' && trim((string)($application['install_script'] ?? '')) !== '') {
        $db->query("UPDATE `{$jobs}` SET `status` = 'installing', `updated_at` = NOW() WHERE `job_id` = {$jobId}");
        $script = (string)$application['install_script'];
        $content = [
            'content_id' => (string)$application['slug'],
            'content_version' => (string)$application['version'],
            'interpreter' => (string)$application['install_interpreter'],
            'script' => $script,
            'sha256' => hash('sha256', $script),
            'arguments' => [],
            'environment' => (object)[],
            'timeout_seconds' => 300,
            'idempotency_key' => 'gse-content-job-' . $jobId,
        ];
        $contentResult = $transport($agent, 'POST', '/v1/containers/' . rawurlencode($runtimeId) . '/content-jobs', $content);
        if (empty($contentResult['success'])) {
            throw new RuntimeException('Agent content installation failed: ' . json_encode($contentResult));
        }
    }
    $start = $transport($agent, 'POST', '/v1/containers/' . rawurlencode($runtimeId) . '/start', ['idempotency_key' => 'gse-start-job-' . $jobId, 'timeout_seconds' => 30]);
    if (empty($start['success'])) {
        throw new RuntimeException('Agent container start failed: ' . json_encode($start));
    }
    $resultJson = $db->real_escape_string(json_encode(['create' => $create['body'], 'start' => $start['body'], 'access_profile' => $access['profile']]));
    $db->query("UPDATE `{$containers}` SET `status` = 'running', `updated_at` = NOW() WHERE `container_id` = {$localContainerId}");
    $db->query("UPDATE `{$jobs}` SET `status` = 'running', `last_result_json` = '{$resultJson}', `updated_at` = NOW(), `completed_at` = NOW() WHERE `job_id` = {$jobId}");
    $db->query("UPDATE `" . container_poc_table('container_agents') . "` SET `current_container_count` = `current_container_count` + 1 WHERE `agent_id` = " . (int)$agent['agent_id']);
    return ['success' => true, 'job_id' => $jobId, 'container_id' => $localContainerId, 'agent_container_id' => $runtimeId, 'access_profile' => $access['profile'], 'access_password' => $access['password']];
}

function container_poc_provision(mysqli $db, int $userId, array $application, array $input, ?callable $transport = null): array
{
    $input['user_id'] = $userId;
    $errors = container_poc_validate_request($application, $input);
    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }
    $agent = container_poc_select_agent($db, (int)($input['location_id'] ?? 0));
    if (!$agent) {
        return ['success' => false, 'errors' => ['No enabled agent is registered for this location.']];
    }
    $jobs = container_poc_table('container_provisioning_jobs');
    $db->begin_transaction();
    try {
        $db->query("INSERT INTO `{$jobs}` (`user_id`,`application_id`,`agent_id`,`location_id`,`container_name`,`platform`,`cpu`,`memory_mb`,`storage_gb`,`port_mode`,`requested_port_block_size`,`status`) VALUES ({$userId}," . (int)$application['application_id'] . "," . (int)$agent['agent_id'] . "," . (int)($input['location_id'] ?? 0) . ",'" . $db->real_escape_string((string)$input['container_name']) . "','" . $db->real_escape_string((string)$input['platform']) . "'," . (float)$input['cpu'] . "," . (int)$input['memory_mb'] . "," . (int)$input['storage_gb'] . ",'" . $db->real_escape_string((string)$input['port_mode']) . "'," . (int)($input['requested_port_block_size'] ?? 0) . ",'queued')");
        $jobId = (int)$db->insert_id;
        $ports = container_poc_allocate_ports($db, $jobId, $application, (string)$input['port_mode']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
    try {
        return container_poc_run_agent_workflow($db, $jobId, $agent, $application, $input, $ports, $transport);
    } catch (Throwable $e) {
        $db->query("UPDATE `{$jobs}` SET `status` = 'failed', `last_error` = '" . $db->real_escape_string($e->getMessage()) . "', `updated_at` = NOW() WHERE `job_id` = {$jobId}");
        $db->query("UPDATE `" . container_poc_table('container_reserved_ports') . "` SET `status` = 'released' WHERE `job_id` = {$jobId} AND `status` = 'reserved'");
        return ['success' => false, 'job_id' => $jobId, 'errors' => [$e->getMessage()]];
    }
}
