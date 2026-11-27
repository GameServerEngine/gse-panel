<?php
/**
 * Hardware inventory storage for GSE Resource Intelligence (Phase 2).
 *
 * The agent reports what the machine has (see GSE::Resource::Inventory on the Linux agent).
 * Unknown values arrive as null and stay null: never convert them to 0.
 *
 * A new row is stored only when the hardware fingerprint changes, so polling does not grow the table.
 * Fingerprints leave out values that change constantly (available memory, free disk space).
 */

if (!function_exists('agent_inventory_table')) {
    function agent_inventory_table(string $prefix): string
    {
        return '`' . $prefix . 'agent_hardware_inventory`';
    }
}

if (!function_exists('agent_inventory_ensure_table')) {
    function agent_inventory_ensure_table(mysqli $db, string $prefix): bool
    {
        return (bool)$db->query(
            'CREATE TABLE IF NOT EXISTS ' . agent_inventory_table($prefix) . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                remote_server_id INT NOT NULL,
                collected_at INT UNSIGNED NOT NULL,
                schema_version SMALLINT UNSIGNED NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                inventory_json MEDIUMTEXT NOT NULL,
                KEY remote_collected (remote_server_id, collected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

if (!function_exists('agent_inventory_parse')) {
    /** Decodes an inventory JSON string. Returns NULL unless it is a known schema with a platform. */
    function agent_inventory_parse($json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            return null;
        }
        if (($data['platform'] ?? '') === '') {
            return null;
        }
        return $data;
    }
}

if (!function_exists('agent_inventory_ksort_recursive')) {
    function agent_inventory_ksort_recursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = agent_inventory_ksort_recursive($v);
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return $value;
    }
}

if (!function_exists('agent_inventory_fingerprint')) {
    /** SHA-256 over the stable hardware fields only. Order-independent. Excludes volatile values. */
    function agent_inventory_fingerprint(array $inv): string
    {
        $stable = array(
            'platform' => $inv['platform'] ?? null,
            'architecture' => $inv['architecture'] ?? null,
            'cpu_model' => $inv['cpu']['model'] ?? null,
            'cpu_allowed' => $inv['cpu']['logical_cpus_allowed'] ?? null,
            'cpu_quota' => $inv['cpu']['quota'] ?? null,
            'memory_total_mib' => $inv['memory']['total_mib'] ?? null,
            'memory_cgroup_limit' => $inv['memory']['cgroup_limit'] ?? null,
            'block_devices' => $inv['storage']['block_devices'] ?? null,
            'game_volume_total_bytes' => $inv['storage']['game_volume']['total_bytes'] ?? null,
        );
        return hash('sha256', json_encode(agent_inventory_ksort_recursive($stable)));
    }
}

if (!function_exists('agent_inventory_from_reply')) {
    /**
     * Maps a remote reply (already decoded by lib_remote) to one of:
     *   state 'ok'          with inventory (array) and its JSON
     *   state 'unsupported' when the agent predates the inventory call (an XML-RPC fault)
     *   state 'error'       for anything else
     */
    function agent_inventory_from_reply(array $reply): array
    {
        $status = (string)($reply['status'] ?? '');
        if ($status === 'xmlrpc_fault') {
            return array('state' => 'unsupported', 'inventory' => null, 'message' => 'Agent does not report hardware inventory yet.');
        }
        if (empty($reply['success'])) {
            return array('state' => 'error', 'inventory' => null, 'message' => (string)($reply['message'] ?? 'Inventory request failed.'));
        }
        $inventory = agent_inventory_parse($reply['inventory_json'] ?? null);
        if ($inventory === null) {
            return array('state' => 'error', 'inventory' => null, 'message' => 'Agent returned an unknown inventory format.');
        }
        return array('state' => 'ok', 'inventory' => $inventory, 'message' => '');
    }
}

if (!function_exists('agent_inventory_store')) {
    /** Stores the inventory when the fingerprint differs from the latest stored row. Returns stored | unchanged | error. */
    function agent_inventory_store(mysqli $db, string $prefix, int $remoteId, array $inventory, int $now): string
    {
        if (!agent_inventory_ensure_table($db, $prefix)) {
            return 'error';
        }
        $table = agent_inventory_table($prefix);
        $fingerprint = agent_inventory_fingerprint($inventory);

        $latest = $db->prepare("SELECT fingerprint FROM {$table} WHERE remote_server_id = ? ORDER BY id DESC LIMIT 1");
        if (!$latest) {
            return 'error';
        }
        $latest->bind_param('i', $remoteId);
        $latest->execute();
        $latest->bind_result($previous);
        $hasPrevious = $latest->fetch();
        $latest->close();
        if ($hasPrevious && $previous === $fingerprint) {
            return 'unchanged';
        }

        $json = json_encode($inventory);
        $schema = (int)$inventory['schema_version'];
        $insert = $db->prepare("INSERT INTO {$table} (remote_server_id, collected_at, schema_version, fingerprint, inventory_json) VALUES (?, ?, ?, ?, ?)");
        if (!$insert) {
            return 'error';
        }
        $insert->bind_param('iiiss', $remoteId, $now, $schema, $fingerprint, $json);
        $ok = $insert->execute();
        $insert->close();
        return $ok ? 'stored' : 'error';
    }
}

if (!function_exists('agent_inventory_latest')) {
    /** Latest stored inventory for an agent, or NULL. */
    function agent_inventory_latest(mysqli $db, string $prefix, int $remoteId): ?array
    {
        $table = agent_inventory_table($prefix);
        $stmt = $db->prepare("SELECT collected_at, fingerprint, inventory_json FROM {$table} WHERE remote_server_id = ? ORDER BY id DESC LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $remoteId);
        $stmt->execute();
        $stmt->bind_result($collectedAt, $fingerprint, $json);
        $row = $stmt->fetch() ? array('collected_at' => (int)$collectedAt, 'fingerprint' => $fingerprint, 'inventory' => agent_inventory_parse($json)) : null;
        $stmt->close();
        return $row;
    }
}
