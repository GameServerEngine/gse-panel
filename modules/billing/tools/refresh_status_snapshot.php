#!/usr/bin/env php
<?php
/*
 * Builds the public status snapshot the support page shows (see includes/status_snapshot.php).
 *
 * Run it about once a minute from cron, on the host that runs the control panel:
 *
 *   * * * * * php /var/www/html/GSE/Panel/modules/billing/tools/refresh_status_snapshot.php
 *
 * What it collects:
 *   agents      from the panel's cached agent snapshots (agent_resource_snapshots). An agent is online
 *               when it reported within the last 3 minutes with no error.
 *   servers     every game server, with Running or Stopped asked of its agent. A server on an agent that is
 *               down is Offline. If a probe fails it is Unknown.
 *   backup_at   the newest file or folder in the directory named by site config 'status' => ['backup_path'].
 *               Not set means "Not reported" on the page.
 *
 * Probing stays inside --budget seconds (default 40). Servers it did not get to keep their last known
 * state, so a large fleet is covered over a few runs instead of making the run overlong.
 *
 * Options:  --dry-run  print the snapshot and store nothing     --no-probe  skip asking agents
 *           --budget=N seconds for probing
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$probe = !in_array('--no-probe', $argv, true);
$budget = 40;
foreach ($argv as $arg) {
    if (strpos($arg, '--budget=') === 0) {
        $budget = max(5, (int)substr($arg, 9));
    }
}

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/status_snapshot.php';

$db = function_exists('billing_get_db') ? billing_get_db() : null;
if (!($db instanceof mysqli)) {
    fwrite(STDERR, "The database is not reachable.\n");
    exit(1);
}
$prefix = (string)($table_prefix ?? '');
$now = time();
$started = microtime(true);

// Last snapshot, so servers that are not probed this run keep their last known state.
$previous = billing_status_load($db, $prefix);
$previousState = array();
foreach ((array)($previous['servers'] ?? array()) as $server) {
    $previousState[(int)$server['id']] = (string)$server['status'];
}

// Agents.
$agents = array();
$agentRows = array();
$result = $db->query(
    "SELECT rs.remote_server_id, rs.remote_server_name, rs.agent_ip, rs.agent_port, rs.encryption_key, rs.timeout,
            s.collected_at, s.last_error
       FROM `{$prefix}remote_servers` rs
       LEFT JOIN `{$prefix}agent_resource_snapshots` s ON s.remote_server_id = rs.remote_server_id
      WHERE rs.enabled = 1
      ORDER BY rs.remote_server_id"
);
while ($result && ($row = $result->fetch_assoc())) {
    $online = (int)$row['collected_at'] > 0
        && ($now - (int)$row['collected_at']) <= BILLING_STATUS_STALE_AFTER
        && trim((string)$row['last_error']) === '';
    $agents[] = array('id' => (int)$row['remote_server_id'], 'name' => (string)$row['remote_server_name'], 'online' => $online);
    $agentRows[(int)$row['remote_server_id']] = $row + array('online' => $online);
}

// Ask each online agent about its servers, inside the time budget.
$remotes = array();
$probeReady = false;
if ($probe) {
    try {
        $panelRoot = dirname(__DIR__, 3);
        chdir($panelRoot);
        require_once $panelRoot . '/includes/lib_remote.php';
        $probeReady = class_exists('RemoteLibrary');
    } catch (Throwable $e) {
        fwrite(STDERR, 'Agent probing is not available: ' . $e->getMessage() . "\n");
    }
}

$servers = array();
$result = $db->query(
    "SELECT h.home_id, h.remote_server_id, c.game_name
       FROM `{$prefix}server_homes` h
       LEFT JOIN `{$prefix}config_homes` c ON c.home_cfg_id = h.home_cfg_id
      ORDER BY h.home_id"
);
$probed = 0;
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

// Newest backup.
$backupAt = null;
$backupPath = (string)($GLOBALS['billing_runtime_site_config']['status']['backup_path'] ?? '');
if ($backupPath !== '' && is_dir($backupPath)) {
    foreach (new DirectoryIterator($backupPath) as $entry) {
        if (!$entry->isDot()) {
            $backupAt = max((int)$backupAt, $entry->getMTime());
        }
    }
}

$payload = array('agents' => $agents, 'servers' => $servers, 'backup_at' => $backupAt);
if ($dryRun) {
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    fwrite(STDERR, 'Dry run: ' . count($agents) . ' agents, ' . count($servers) . " servers, {$probed} probed. Nothing stored.\n");
    exit(0);
}
if (!billing_status_store($db, $prefix, $payload, $now)) {
    fwrite(STDERR, "The snapshot could not be stored.\n");
    exit(1);
}
echo 'Stored: ' . count($agents) . ' agents, ' . count($servers) . " servers, {$probed} probed.\n";
