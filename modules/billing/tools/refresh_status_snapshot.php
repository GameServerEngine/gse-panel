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
require_once dirname(__DIR__) . '/includes/status_collect.php';

$db = function_exists('billing_get_db') ? billing_get_db() : null;
if (!($db instanceof mysqli)) {
    fwrite(STDERR, "The database is not reachable.\n");
    exit(1);
}
$prefix = (string)($table_prefix ?? '');
$backupPath = (string)($GLOBALS['billing_runtime_site_config']['status']['backup_path'] ?? '');
$built = billing_status_collect($db, $prefix, $budget, $probe, $backupPath);
$payload = $built['payload'];
$probed = $built['probed'];
$agents = $payload['agents'];
$servers = $payload['servers'];
if ($dryRun) {
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    fwrite(STDERR, 'Dry run: ' . count($agents) . ' agents, ' . count($servers) . " servers, {$probed} probed. Nothing stored.\n");
    exit(0);
}
if (!billing_status_store($db, $prefix, $payload, time())) {
    fwrite(STDERR, "The snapshot could not be stored.\n");
    exit(1);
}
echo 'Stored: ' . count($agents) . ' agents, ' . count($servers) . " servers, {$probed} probed.\n";
