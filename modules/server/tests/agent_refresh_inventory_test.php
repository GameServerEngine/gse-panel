<?php
// agent_refresh_snapshot() with the hardware inventory hook: fake agent, fake panel database object.
// The storage part needs a scratch database (GSE_TEST_DB_HOST/USER/PASS/NAME); without it only the no-database cases run.
// Run from the repository root: php modules/server/tests/agent_refresh_inventory_test.php
chdir(dirname(__DIR__, 3));
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('GSE_TEST_DB_HOST'); $user = getenv('GSE_TEST_DB_USER'); $pass = getenv('GSE_TEST_DB_PASS'); $name = getenv('GSE_TEST_DB_NAME');
$mysqli = ($host && $user && $pass !== false && $name) ? new mysqli($host, $user, $pass, $name) : null;
if ($mysqli && $mysqli->connect_errno) { echo "FAIL - cannot connect: {$mysqli->connect_error}\n"; exit(1); }
$prefix = 'gse_invtest_' . bin2hex(random_bytes(3)) . '_';
// As in production (includes/database.php): DB_PREFIX is only a placeholder string. The real prefix comes from the db object.
define('DB_PREFIX', 'DB_PREFIX');
ini_set('error_log', '/dev/null');

require_once 'modules/server/agent_helpers.php';

class FakeSnapshotDb
{
    public $upserts = array();
    private $mysqli;
    private $prefix;
    public function __construct($mysqli, $prefix) { $this->mysqli = $mysqli; $this->prefix = $prefix; }
    public function getTablePrefix() { return $this->prefix; }
    public function query($sql) { return true; }
    public function upsertAgentResourceSnapshot($id, $snapshot) { $this->upserts[] = array($id, $snapshot); }
    public function getMysqli() { return $this->mysqli; }
}

class FakeDbWithoutPrefix
{
    private $mysqli;
    public function __construct($mysqli) { $this->mysqli = $mysqli; }
    public function query($sql) { return true; }
    public function upsertAgentResourceSnapshot($id, $snapshot) {}
    public function getMysqli() { return $this->mysqli; }
}

class FakeAgentRemote
{
    public $snapshotCalls = 0;
    public $inventoryCalls = 0;
    public $snapshotReply;
    public $inventoryReply;
    public $inventoryThrows = false;
    public function agent_resource_snapshot() { $this->snapshotCalls++; return $this->snapshotReply; }
    public function agent_hardware_inventory()
    {
        $this->inventoryCalls++;
        if ($this->inventoryThrows) { throw new RuntimeException('network exploded'); }
        return $this->inventoryReply;
    }
}

$failures = 0;
function rc_check($ok, $label) { global $failures; echo ($ok ? 'ok' : 'FAIL') . " - $label\n"; if (!$ok) { $failures++; } }
function good_snapshot() { return array('success' => true, 'status' => 'ok', 'os' => 'Linux', 'cpu_percent' => '12', 'ram_percent' => '34', 'disk_percent' => '56'); }
function good_inventory() { return array('success' => true, 'status' => 'ok', 'inventory_json' => json_encode(array('schema_version' => 1, 'platform' => 'linux', 'cpu' => array('logical_cpus_allowed' => 6), 'memory' => array('total_mib' => 7940), 'storage' => array('game_volume' => array('total_bytes' => 1), 'block_devices' => array())))); }
function server_row($id) { return array('remote_server_id' => $id, 'agent_ip' => '127.0.0.1', 'agent_port' => 12679); }

// 1. No database handle: the snapshot works exactly as before and the agent is not asked for inventory.
$db = new FakeSnapshotDb(null, $prefix);
$remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot(); $remote->inventoryReply = good_inventory();
$r = agent_refresh_snapshot($db, server_row(31), $remote);
rc_check(!empty($r['success']) && $r['cpu_percent'] === '12' && count($db->upserts) === 1, 'no database handle: snapshot saved and returned as before');
rc_check($remote->inventoryCalls === 0, 'no database handle: the agent is not asked for inventory');

// 2. A failed snapshot never asks for inventory.
$db = new FakeSnapshotDb($mysqli, $prefix);
$remote = new FakeAgentRemote(); $remote->snapshotReply = array('success' => false, 'status' => 'agent_unreachable', 'message' => 'No response from agent.'); $remote->inventoryReply = good_inventory();
$r = agent_refresh_snapshot($db, server_row(32), $remote);
rc_check(empty($r['success']) && count($db->upserts) === 1 && $remote->inventoryCalls === 0, 'failed snapshot: failure saved, inventory not requested');

// 3. A row without an id never reaches the inventory code.
$remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot();
$r = agent_refresh_snapshot(new FakeSnapshotDb($mysqli, $prefix), array('agent_ip' => 'x', 'agent_port' => 1), $remote);
rc_check(empty($r['success']) && $remote->snapshotCalls === 0 && $remote->inventoryCalls === 0, 'row without an id: refused before any agent call');

if (!$mysqli) {
    echo "SKIP - set GSE_TEST_DB_* to run the storage cases\n";
    echo $failures ? "\n$failures FAILED\n" : "\nall agent refresh inventory tests passed (no-database cases only)\n";
    exit($failures ? 1 : 0);
}

try {
    // 4. Good snapshot with a database: inventory requested once and stored; the snapshot result is untouched.
    $db = new FakeSnapshotDb($mysqli, $prefix);
    $remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot(); $remote->inventoryReply = good_inventory();
    $r = agent_refresh_snapshot($db, server_row(41), $remote);
    rc_check(!empty($r['success']) && $r['cpu_percent'] === '12' && $r['ram_percent'] === '34' && $r['disk_percent'] === '56', 'good snapshot: result unchanged by the inventory hook');
    rc_check($remote->inventoryCalls === 1, 'good snapshot: agent asked for inventory once');
    $latest = agent_inventory_latest($mysqli, $prefix, 41);
    rc_check($latest !== null && $latest['inventory']['cpu']['logical_cpus_allowed'] === 6, 'the inventory was stored');

    // 5. A second refresh straight away does not ask again.
    agent_refresh_snapshot($db, server_row(41), $remote);
    rc_check($remote->snapshotCalls === 2 && $remote->inventoryCalls === 1, 'a second refresh refreshes the snapshot but does not ask for inventory again');

    // 6. An agent that throws on the inventory call: the snapshot still comes back normally.
    $remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot(); $remote->inventoryThrows = true;
    $threw = false;
    try { $r = agent_refresh_snapshot($db, server_row(42), $remote); } catch (\Throwable $e) { $threw = true; }
    rc_check(!$threw && !empty($r['success']) && $r['cpu_percent'] === '12', 'inventory call throws: snapshot still returned, nothing escapes');

    // 7. An older agent: snapshot fine, nothing stored, recorded as unsupported.
    $remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot(); $remote->inventoryReply = array('success' => false, 'status' => 'xmlrpc_fault', 'message' => 'Unknown method');
    $r = agent_refresh_snapshot($db, server_row(43), $remote);
    rc_check(!empty($r['success']) && agent_inventory_latest($mysqli, $prefix, 43) === null, 'older agent: snapshot fine, no inventory stored');
    rc_check(agent_inventory_state_get($mysqli, $prefix, 43)['state'] === 'unsupported', 'older agent: recorded as unsupported');

    // 8. Snapshot saved even if the inventory tables cannot be used at all.
    $mysqli->query('DROP TABLE IF EXISTS `' . $prefix . 'agent_inventory_state`');
    $mysqli->query('CREATE TABLE `' . $prefix . 'agent_inventory_state` (junk INT) ENGINE=InnoDB');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot(); $remote->inventoryReply = good_inventory();
    $threw = false;
    try { $r = agent_refresh_snapshot($db, server_row(44), $remote); } catch (\Throwable $e) { $threw = true; }
    mysqli_report(MYSQLI_REPORT_OFF);
    rc_check(!$threw && !empty($r['success']), 'broken inventory tables (strict mode): snapshot still returned, nothing escapes');

    // 9. A db object that cannot give its table prefix: the snapshot works and the inventory hook does nothing.
    $remote = new FakeAgentRemote(); $remote->snapshotReply = good_snapshot(); $remote->inventoryReply = good_inventory();
    $r = agent_refresh_snapshot(new FakeDbWithoutPrefix($mysqli), server_row(45), $remote);
    rc_check(!empty($r['success']) && $remote->inventoryCalls === 0, 'db object without a table prefix: snapshot fine, inventory hook skipped');

    // 10. Production defines DB_PREFIX as a placeholder; no table named after it may exist, and the real prefix is used.
    $stray = $mysqli->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'DB\\_PREFIX%'");
    rc_check($stray && (int)$stray->fetch_row()[0] === 0, 'no table named DB_PREFIX... was created');
    $real = $mysqli->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $mysqli->real_escape_string($prefix . 'agent_hardware_inventory') . "'");
    rc_check($real && (int)$real->fetch_row()[0] === 1, 'the inventory table was created with the real prefix');
} finally {
    $mysqli->query('DROP TABLE IF EXISTS `' . $prefix . 'agent_hardware_inventory`');
    $mysqli->query('DROP TABLE IF EXISTS `' . $prefix . 'agent_inventory_state`');
}

echo $failures ? "\n$failures FAILED\n" : "\nall agent refresh inventory tests passed\n";
exit($failures ? 1 : 0);
