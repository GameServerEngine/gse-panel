<?php
// Database tests for includes/agent_inventory.php (store and latest).
// Needs a scratch database: set GSE_TEST_DB_HOST, GSE_TEST_DB_USER, GSE_TEST_DB_PASS, GSE_TEST_DB_NAME.
// Without them the test skips (exit 0). Never point it at a live panel database: it creates and drops its own table prefix only.
require_once __DIR__ . '/../includes/agent_inventory.php';

$host = getenv('GSE_TEST_DB_HOST');
$user = getenv('GSE_TEST_DB_USER');
$pass = getenv('GSE_TEST_DB_PASS');
$name = getenv('GSE_TEST_DB_NAME');
if (!$host || !$user || $pass === false || !$name) {
    echo "SKIP - set GSE_TEST_DB_HOST/USER/PASS/NAME to run the database tests\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli($host, $user, $pass, $name);
if ($db->connect_errno) {
    echo 'FAIL - cannot connect: ' . $db->connect_error . "\n";
    exit(1);
}
$prefix = 'gse_invtest_' . bin2hex(random_bytes(3)) . '_';
$table = agent_inventory_table($prefix);

$failures = 0;
function db_check($ok, $name)
{
    global $failures;
    echo ($ok ? 'ok' : 'FAIL') . ' - ' . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}
function count_rows(mysqli $db, string $table, int $id): int
{
    $res = $db->query("SELECT COUNT(*) FROM {$table} WHERE remote_server_id = {$id}");
    return $res ? (int)$res->fetch_row()[0] : -1;
}
function inv(array $over = array())
{
    $base = array(
        'schema_version' => 1, 'platform' => 'linux', 'architecture' => 'x86_64',
        'cpu' => array('model' => 'Test CPU', 'logical_cpus_allowed' => 6, 'quota' => array('source' => 'none', 'quota_us' => null, 'period_us' => null, 'unlimited' => null)),
        'memory' => array('total_mib' => 7940, 'available_mib' => 7244, 'cgroup_limit' => array('source' => 'none', 'limit_bytes' => null)),
        'storage' => array('game_volume' => array('total_bytes' => 161061273600, 'available_bytes' => 141733920768), 'block_devices' => array(array('name' => 'vda', 'rotational' => true, 'size_bytes' => 161061273600))),
        'warnings' => array(),
    );
    return array_replace_recursive($base, $over);
}

try {
    // 1. Table creation: InnoDB, and safe to run twice.
    db_check(agent_inventory_ensure_table($db, $prefix), 'creates the table');
    db_check(agent_inventory_ensure_table($db, $prefix), 'creating it again is harmless');
    $eng = $db->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->real_escape_string($prefix . 'agent_hardware_inventory') . "'");
    db_check($eng && $eng->fetch_row()[0] === 'InnoDB', 'table engine is InnoDB');

    // 2. First store writes a row.
    db_check(agent_inventory_store($db, $prefix, 5, inv(), 1000) === 'stored', 'first inventory is stored');
    db_check(count_rows($db, $table, 5) === 1, 'one row after first store');

    // 3. Same hardware with different free RAM and disk is unchanged: no new row.
    $busy = inv(array('memory' => array('available_mib' => 100), 'storage' => array('game_volume' => array('available_bytes' => 7))));
    db_check(agent_inventory_store($db, $prefix, 5, $busy, 2000) === 'unchanged', 'same hardware with different free RAM/disk is unchanged');
    db_check(count_rows($db, $table, 5) === 1, 'still one row after an unchanged store');

    // 4. Real hardware change writes a second row.
    db_check(agent_inventory_store($db, $prefix, 5, inv(array('memory' => array('total_mib' => 16384))), 3000) === 'stored', 'changed RAM total is stored');
    db_check(count_rows($db, $table, 5) === 2, 'two rows after a hardware change');

    // 5. Latest returns the newest row, with nulls preserved.
    $latest = agent_inventory_latest($db, $prefix, 5);
    db_check($latest !== null && $latest['inventory']['memory']['total_mib'] === 16384, 'latest returns the newest inventory');
    db_check($latest['collected_at'] === 3000, 'latest carries its collection time');
    db_check(array_key_exists('limit_bytes', $latest['inventory']['memory']['cgroup_limit']) && $latest['inventory']['memory']['cgroup_limit']['limit_bytes'] === null, 'null values survive storage (not turned into 0)');

    // 6. Reverting hardware to an earlier state is a change from the latest row, so it is stored.
    db_check(agent_inventory_store($db, $prefix, 5, inv(), 4000) === 'stored', 'reverting to earlier hardware is stored as a new row');

    // 7. Agents are independent.
    db_check(agent_inventory_latest($db, $prefix, 99) === null, 'unknown agent has no inventory');
    db_check(agent_inventory_store($db, $prefix, 6, inv(), 5000) === 'stored', 'a second agent with identical hardware gets its own row');
    db_check(count_rows($db, $table, 6) === 1 && count_rows($db, $table, 5) === 3, 'rows are kept per agent');
} finally {
    $db->query("DROP TABLE IF EXISTS {$table}");
    $gone = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->real_escape_string($prefix . 'agent_hardware_inventory') . "'");
    db_check($gone && (int)$gone->fetch_row()[0] === 0, 'test table dropped afterwards');
}

echo $failures ? "\n$failures FAILED\n" : "\nall agent inventory database tests passed\n";
exit($failures ? 1 : 0);
