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

if (!function_exists('agent_inventory_prefix_ok')) {
    /**
     * A table prefix is empty or letters, digits and underscores. The literal placeholder DB_PREFIX is refused:
     * it is not a prefix, and this code talks to the raw connection, which would create a table with that name.
     */
    function agent_inventory_prefix_ok(string $prefix): bool
    {
        return $prefix !== 'DB_PREFIX' && preg_match('/^[A-Za-z0-9_]*$/', $prefix) === 1;
    }
}

if (!function_exists('agent_inventory_table')) {
    function agent_inventory_table(string $prefix): string
    {
        return '`' . $prefix . 'agent_hardware_inventory`';
    }
}

if (!function_exists('agent_inventory_ensure_table')) {
    function agent_inventory_ensure_table(mysqli $db, string $prefix): bool
    {
        if (!agent_inventory_prefix_ok($prefix)) {
            return false;
        }
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

// ---------------------------------------------------------------------------------------------
// Refresh scheduling: ask an agent for its inventory only when a check is due.
// ---------------------------------------------------------------------------------------------
if (!defined('AGENT_INVENTORY_TTL_OK')) {
    define('AGENT_INVENTORY_TTL_OK', 21600);          // 6 hours: hardware rarely changes
}
if (!defined('AGENT_INVENTORY_TTL_UNSUPPORTED')) {
    define('AGENT_INVENTORY_TTL_UNSUPPORTED', 3600);  // 1 hour: an agent updated later is noticed soon
}
if (!defined('AGENT_INVENTORY_TTL_ERROR')) {
    define('AGENT_INVENTORY_TTL_ERROR', 900);         // 15 minutes
}

if (!function_exists('agent_inventory_state_table')) {
    function agent_inventory_state_table(string $prefix): string
    {
        return '`' . $prefix . 'agent_inventory_state`';
    }
}

if (!function_exists('agent_inventory_ensure_state_table')) {
    function agent_inventory_ensure_state_table(mysqli $db, string $prefix): bool
    {
        if (!agent_inventory_prefix_ok($prefix)) {
            return false;
        }
        return (bool)$db->query(
            'CREATE TABLE IF NOT EXISTS ' . agent_inventory_state_table($prefix) . ' (
                remote_server_id INT NOT NULL PRIMARY KEY,
                checked_at INT UNSIGNED NOT NULL,
                state VARCHAR(16) NOT NULL,
                message VARCHAR(255) NOT NULL DEFAULT \'\'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

if (!function_exists('agent_inventory_ttl_for_state')) {
    /** Seconds until the next check, by the last recorded state. Unknown states are treated like errors. */
    function agent_inventory_ttl_for_state(string $state): int
    {
        if ($state === 'ok') {
            return AGENT_INVENTORY_TTL_OK;
        }
        if ($state === 'unsupported') {
            return AGENT_INVENTORY_TTL_UNSUPPORTED;
        }
        return AGENT_INVENTORY_TTL_ERROR;
    }
}

if (!function_exists('agent_inventory_is_due')) {
    /** True when no check is recorded, the record is unusable, the clock moved back, or the wait is over. */
    function agent_inventory_is_due(?array $state, int $now): bool
    {
        if ($state === null) {
            return true;
        }
        $checked = (int)($state['checked_at'] ?? 0);
        $elapsed = $now - $checked;
        if ($checked <= 0 || $elapsed < 0) {
            return true;
        }
        return $elapsed >= agent_inventory_ttl_for_state((string)($state['state'] ?? ''));
    }
}

if (!function_exists('agent_inventory_state_get')) {
    function agent_inventory_state_get(mysqli $db, string $prefix, int $remoteId): ?array
    {
        $table = agent_inventory_state_table($prefix);
        $stmt = $db->prepare("SELECT checked_at, state, message FROM {$table} WHERE remote_server_id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $remoteId);
        $stmt->execute();
        $stmt->bind_result($checkedAt, $state, $message);
        $row = $stmt->fetch() ? array('checked_at' => (int)$checkedAt, 'state' => $state, 'message' => $message) : null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('agent_inventory_state_set')) {
    function agent_inventory_state_set(mysqli $db, string $prefix, int $remoteId, string $state, string $message, int $now): bool
    {
        $table = agent_inventory_state_table($prefix);
        $message = substr($message, 0, 255);
        $stmt = $db->prepare("INSERT INTO {$table} (remote_server_id, checked_at, state, message) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), state = VALUES(state), message = VALUES(message)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iiss', $remoteId, $now, $state, $message);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('agent_inventory_refresh_if_due')) {
    /**
     * Asks an agent for its inventory when a check is due, and stores it when the hardware changed.
     * $fetch returns the decoded agent reply (RemoteLibrary::agent_hardware_inventory()).
     * Returns array('outcome' => skipped|stored|unchanged|unsupported|error, 'message' => string).
     * Never throws: a failure here must not affect the caller (the resource snapshot refresh).
     * Two requests due at the same moment may both ask; the store keeps only changes, so the
     * worst case is one repeated row.
     */
    function agent_inventory_refresh_if_due(mysqli $db, string $prefix, int $remoteId, callable $fetch, int $now): array
    {
        try {
            if (!agent_inventory_prefix_ok($prefix)) {
                return array('outcome' => 'error', 'message' => 'Not a usable table prefix.');
            }
            if (!agent_inventory_ensure_state_table($db, $prefix)) {
                return array('outcome' => 'error', 'message' => 'Inventory state table unavailable.');
            }
            if (!agent_inventory_is_due(agent_inventory_state_get($db, $prefix, $remoteId), $now)) {
                return array('outcome' => 'skipped', 'message' => '');
            }
            try {
                $reply = $fetch();
                if (!is_array($reply)) {
                    $reply = array('success' => false, 'status' => 'unexpected_response', 'message' => 'No response from agent.');
                }
                $result = agent_inventory_from_reply($reply);
                if ($result['state'] === 'ok') {
                    $stored = agent_inventory_store($db, $prefix, $remoteId, $result['inventory'], $now);
                    if ($stored === 'error') {
                        agent_inventory_state_set($db, $prefix, $remoteId, 'error', 'Could not store the inventory.', $now);
                        return array('outcome' => 'error', 'message' => 'Could not store the inventory.');
                    }
                    agent_inventory_state_set($db, $prefix, $remoteId, 'ok', '', $now);
                    return array('outcome' => $stored, 'message' => '');
                }
                agent_inventory_state_set($db, $prefix, $remoteId, $result['state'], $result['message'], $now);
                return array('outcome' => $result['state'], 'message' => $result['message']);
            } catch (\Throwable $e) {
                // Record the failure so a broken agent is not asked again on every page load.
                agent_inventory_state_set($db, $prefix, $remoteId, 'error', 'Inventory check failed.', $now);
                return array('outcome' => 'error', 'message' => 'Inventory check failed.');
            }
        } catch (\Throwable $e) {
            return array('outcome' => 'error', 'message' => 'Inventory check failed.');
        }
    }
}
