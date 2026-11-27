<?php
/**
 * Gathers the public status snapshot (see status_snapshot.php for its shape).
 *
 * Used by tools/refresh_status_snapshot.php (cron) and, when no cron is set up or it has
 * stopped, by the support page itself through billing_status_refresh_if_stale(). Either way
 * the page never needs more than one cached row to render.
 *
 *   agents      every registered agent. An agent is online when the status poller's newest sample for it
 *               (status_sample, written by modules/status_history/poller.php) is ok and recent. If the
 *               status history module has no samples, the cached agent snapshot is used instead
 *               (agent_resource_snapshots: online when it reported within BILLING_STATUS_STALE_AFTER
 *               seconds with no error).
 *   servers     every game server, with Running or Stopped asked of its agent. A server on an agent
 *               that is down is Offline. If a probe fails it is Unknown.
 *   backup_at   newest file or folder in the directory named by site config 'status' => ['backup_path'].
 *
 * Probing stays inside $budget seconds. Servers it did not reach keep their last known state.
 */

require_once __DIR__ . '/status_snapshot.php';

// Pure helpers (no database access) for the status poller's settings. The billing module can run
// without the status history module, so the file is only loaded when it is there.
$billingStatusHistoryLib = dirname(__DIR__, 2) . '/status_history/includes/status_history_lib.php';
if (is_file($billingStatusHistoryLib)) {
    require_once $billingStatusHistoryLib;
}

if (!function_exists('billing_status_latest_samples')) {
    /**
     * The status poller's newest sample for each agent, keyed by remote_server_id.
     * The poller writes these all the time; agent_resource_snapshots only change when an admin
     * opens a server page, so the samples are the better sign that an agent is up.
     * Empty when the status history module is not installed.
     */
    function billing_status_latest_samples(mysqli $db, string $p): array
    {
        $samples = array();
        try {
            $result = $db->query(
                "SELECT s.remote_server_id, s.sampled_at, s.status_code
                   FROM `{$p}status_sample` s
                   JOIN (SELECT remote_server_id, MAX(sampled_at) AS newest
                           FROM `{$p}status_sample`
                          GROUP BY remote_server_id) n
                     ON n.remote_server_id = s.remote_server_id AND n.newest = s.sampled_at"
            );
            while ($result && ($row = $result->fetch_assoc())) {
                $samples[(int)$row['remote_server_id']] = $row;
            }
        } catch (Throwable $e) {
            // The status_sample table does not exist on this panel.
        }
        return $samples;
    }
}

if (!function_exists('billing_status_sample_stale_after')) {
    /**
     * Seconds after which an agent's newest sample no longer counts.
     * The poller asks one agent per interval, so each agent gets a sample once every
     * (agents x interval) seconds. This is the same limit the status history page uses
     * (sv_effective_status in modules/status_history): two full cycles plus a minute.
     */
    function billing_status_sample_stale_after(mysqli $db, string $p, int $agentCount): int
    {
        $interval = 30;
        if (function_exists('sh_settings_from_rows')) {
            $settings = array();
            $result = $db->query("SELECT setting, value FROM `{$p}settings` WHERE setting LIKE 'status_history_%'");
            while ($result && ($row = $result->fetch_assoc())) {
                $settings[$row['setting']] = $row['value'];
            }
            $interval = sh_settings_from_rows($settings)['interval_seconds'];
        }
        return 2 * max(1, $agentCount) * $interval + 60;
    }
}

if (!function_exists('billing_status_agent_online')) {
    /**
     * Online when the poller's newest sample is ok and not stale. With no poller sample for the
     * agent, fall back to its cached resource snapshot.
     */
    function billing_status_agent_online(array $agentRow, ?array $sample, int $now, int $sampleStaleAfter): bool
    {
        if ($sample !== null) {
            return $sample['status_code'] === 'ok'
                && ($now - (int)$sample['sampled_at']) <= $sampleStaleAfter;
        }
        return (int)$agentRow['collected_at'] > 0
            && ($now - (int)$agentRow['collected_at']) <= BILLING_STATUS_STALE_AFTER
            && trim((string)$agentRow['last_error']) === '';
    }
}

if (!function_exists('billing_status_collect')) {
    /**
     * @return array{payload: array, probed: int}
     */
    function billing_status_collect(mysqli $db, string $prefix, int $budget, bool $probe, string $backupPath, ?int $now = null): array
    {
        $now = $now ?? time();
        $started = microtime(true);
        $p = str_replace('`', '', $prefix);

        $previous = billing_status_load($db, $prefix);
        $previousState = array();
        foreach ((array)($previous['servers'] ?? array()) as $server) {
            $previousState[(int)$server['id']] = (string)$server['status'];
        }

        // remote_servers has no enable flag on upgraded panels (see add_remote_server_enabled_column.sql),
        // so every registered agent is listed, as on the status history page.
        $rows = array();
        $result = $db->query(
            "SELECT rs.remote_server_id, rs.remote_server_name, rs.agent_ip, rs.agent_port, rs.encryption_key, rs.timeout,
                    s.collected_at, s.last_error
               FROM `{$p}remote_servers` rs
               LEFT JOIN `{$p}agent_resource_snapshots` s ON s.remote_server_id = rs.remote_server_id
              ORDER BY rs.remote_server_id"
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }

        $samples = billing_status_latest_samples($db, $p);
        $sampleStaleAfter = billing_status_sample_stale_after($db, $p, count($rows));
        $agents = array();
        $agentRows = array();
        foreach ($rows as $row) {
            $id = (int)$row['remote_server_id'];
            $online = billing_status_agent_online($row, $samples[$id] ?? null, $now, $sampleStaleAfter);
            $agents[] = array('id' => $id, 'name' => (string)$row['remote_server_name'], 'online' => $online);
            $agentRows[$id] = $row + array('online' => $online);
        }

        $remotes = array();
        $probeReady = false;
        $cwd = getcwd();
        if ($probe) {
            try {
                $panelRoot = dirname(__DIR__, 3);
                chdir($panelRoot);
                require_once $panelRoot . '/includes/lib_remote.php';
                $probeReady = class_exists('RemoteLibrary');
            } catch (Throwable $e) {
                $probeReady = false;
            }
        }

        $servers = array();
        $probed = 0;
        $result = $db->query(
            "SELECT h.home_id, h.remote_server_id, c.game_name
               FROM `{$p}server_homes` h
               LEFT JOIN `{$p}config_homes` c ON c.home_cfg_id = h.home_cfg_id
              ORDER BY h.home_id"
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $homeId = (int)$row['home_id'];
            $agentId = (int)$row['remote_server_id'];
            $agent = $agentRows[$agentId] ?? null;
            $status = $previousState[$homeId] ?? 'unknown';

            if ($agent === null || !$agent['online']) {
                $status = 'offline';
            } elseif ($probeReady && (microtime(true) - $started) < $budget) {
                try {
                    if (!isset($remotes[$agentId])) {
                        $remotes[$agentId] = new RemoteLibrary($agent['agent_ip'], $agent['agent_port'], $agent['encryption_key'], $agent['timeout']);
                    }
                    $running = $remotes[$agentId]->is_screen_running(SCREEN_TYPE_HOME, $homeId);
                    $status = $running === 1 ? 'running' : ($running === 0 ? 'stopped' : 'unknown');
                    $probed++;
                } catch (Throwable $e) {
                    $status = 'unknown';
                }
            }
            $servers[] = array('id' => $homeId, 'game' => (string)($row['game_name'] ?? ''), 'status' => $status);
        }
        if ($cwd !== false) {
            chdir($cwd);
        }

        $backupAt = null;
        if ($backupPath !== '' && is_dir($backupPath)) {
            foreach (new DirectoryIterator($backupPath) as $entry) {
                if (!$entry->isDot()) {
                    $backupAt = max((int)$backupAt, $entry->getMTime());
                }
            }
        }

        return array(
            'payload' => array('agents' => $agents, 'servers' => $servers, 'backup_at' => $backupAt),
            'probed' => $probed,
        );
    }
}

if (!function_exists('billing_status_refresh_if_stale')) {
    /**
     * Rebuilds the snapshot from a page request when it is older than $maxAge seconds.
     * A database lock keeps simultaneous visitors from each doing the work: whoever does not
     * get the lock just shows the row that is there. Never throws.
     */
    function billing_status_refresh_if_stale(mysqli $db, string $prefix, ?array $snapshot, string $backupPath = '', int $maxAge = 60, int $budget = 4): ?array
    {
        $now = time();
        if ($snapshot !== null && ($now - (int)($snapshot['generated'] ?? 0)) <= $maxAge) {
            return $snapshot;
        }
        try {
            $lockName = 'gse_billing_status_' . md5($prefix);
            $got = $db->query("SELECT GET_LOCK('" . $db->real_escape_string($lockName) . "', 0) AS l");
            $row = $got ? $got->fetch_assoc() : null;
            if (!$row || (int)$row['l'] !== 1) {
                return $snapshot;
            }
            try {
                $built = billing_status_collect($db, $prefix, $budget, true, $backupPath, $now);
                billing_status_store($db, $prefix, $built['payload'], $now);
            } finally {
                $db->query("SELECT RELEASE_LOCK('" . $db->real_escape_string($lockName) . "')");
            }
            return billing_status_load($db, $prefix) ?? $snapshot;
        } catch (Throwable $e) {
            return $snapshot;
        }
    }
}
