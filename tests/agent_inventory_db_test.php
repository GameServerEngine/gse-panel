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

    // 8. Refresh scheduling (agent_inventory_refresh_if_due) with a stand-in agent.
    $stateTable = agent_inventory_state_table($prefix);
    $calls = 0;
    $okReply = function () use (&$calls) { $calls++; return array('success' => true, 'status' => 'ok', 'inventory_json' => json_encode(inv())); };
    $t0 = 10000000;

    $r = agent_inventory_refresh_if_due($db, $prefix, 20, $okReply, $t0);
    db_check($r['outcome'] === 'stored' && $calls === 1, 'first check asks the agent and stores the inventory');
    db_check(agent_inventory_state_get($db, $prefix, 20)['state'] === 'ok', 'the check is recorded as ok');

    $r = agent_inventory_refresh_if_due($db, $prefix, 20, $okReply, $t0 + 60);
    db_check($r['outcome'] === 'skipped' && $calls === 1, 'a check one minute later is skipped without asking the agent');

    $r = agent_inventory_refresh_if_due($db, $prefix, 20, $okReply, $t0 + AGENT_INVENTORY_TTL_OK);
    db_check($r['outcome'] === 'unchanged' && $calls === 2, 'after the wait the agent is asked; same hardware stores nothing');
    db_check(agent_inventory_state_get($db, $prefix, 20)['checked_at'] === $t0 + AGENT_INVENTORY_TTL_OK, 'the check time moves forward even when nothing changed');
    db_check(count_rows($db, $table, 20) === 1, 'still one inventory row for the agent');

    $changed = function () use (&$calls) { $calls++; return array('success' => true, 'status' => 'ok', 'inventory_json' => json_encode(inv(array('memory' => array('total_mib' => 32768))))); };
    $r = agent_inventory_refresh_if_due($db, $prefix, 20, $changed, $t0 + 2 * AGENT_INVENTORY_TTL_OK);
    db_check($r['outcome'] === 'stored' && count_rows($db, $table, 20) === 2, 'changed hardware is stored as a new row');

    // An older agent (the wrapper reports an XML-RPC fault): unsupported, nothing stored, rechecked hourly.
    $faultCalls = 0;
    $fault = function () use (&$faultCalls) { $faultCalls++; return array('success' => false, 'status' => 'xmlrpc_fault', 'message' => 'Unknown method'); };
    $r = agent_inventory_refresh_if_due($db, $prefix, 21, $fault, $t0);
    db_check($r['outcome'] === 'unsupported' && agent_inventory_state_get($db, $prefix, 21)['state'] === 'unsupported', 'older agent is recorded as unsupported');
    db_check(agent_inventory_refresh_if_due($db, $prefix, 21, $fault, $t0 + 1800)['outcome'] === 'skipped' && $faultCalls === 1, 'an unsupported agent is not asked again within the hour');
    db_check(agent_inventory_refresh_if_due($db, $prefix, 21, $fault, $t0 + AGENT_INVENTORY_TTL_UNSUPPORTED)['outcome'] === 'unsupported' && $faultCalls === 2, 'an unsupported agent is asked again after an hour');
    db_check(count_rows($db, $table, 21) === 0, 'nothing is stored for an unsupported agent');

    // Agent that has since been updated: the same agent id now answers with an inventory.
    $r = agent_inventory_refresh_if_due($db, $prefix, 21, $okReply, $t0 + 2 * AGENT_INVENTORY_TTL_UNSUPPORTED);
    db_check($r['outcome'] === 'stored' && agent_inventory_state_get($db, $prefix, 21)['state'] === 'ok', 'an agent that is later updated is picked up');

    // Errors are retried after 15 minutes, not on every call.
    $errCalls = 0;
    $err = function () use (&$errCalls) { $errCalls++; return array('success' => false, 'status' => 'bad_encryption', 'message' => 'Bad Encryption Key'); };
    $r = agent_inventory_refresh_if_due($db, $prefix, 22, $err, $t0);
    db_check($r['outcome'] === 'error' && $r['message'] === 'Bad Encryption Key', 'an agent refusal is an error with its message');
    db_check(agent_inventory_refresh_if_due($db, $prefix, 22, $err, $t0 + 899)['outcome'] === 'skipped' && $errCalls === 1, 'an error is not retried before 15 minutes');
    db_check(agent_inventory_refresh_if_due($db, $prefix, 22, $err, $t0 + 900)['outcome'] === 'error' && $errCalls === 2, 'an error is retried at 15 minutes');

    // No reply at all (unreachable) and an unusable reply both count as errors.
    db_check(agent_inventory_refresh_if_due($db, $prefix, 23, function () { return null; }, $t0)['outcome'] === 'error', 'no reply is an error');

    // A fetch that throws never escapes, and is not retried at once.
    $boom = 0;
    $thrower = function () use (&$boom) { $boom++; throw new RuntimeException('network exploded'); };
    $threw = false;
    try { $r = agent_inventory_refresh_if_due($db, $prefix, 24, $thrower, $t0); } catch (\Throwable $e) { $threw = true; }
    db_check(!$threw && $r['outcome'] === 'error', 'an exception from the agent call does not escape');
    db_check(agent_inventory_refresh_if_due($db, $prefix, 24, $thrower, $t0 + 60)['outcome'] === 'skipped' && $boom === 1, 'a failing agent is not asked again on the next page load');

    // Strict error mode (the PHP 8.1+ default): database failures must not escape either.
    // A dropped inventory table is simply recreated when the next inventory is stored.
    $dropper = function () use ($db, $table) {
        $db->query("DROP TABLE {$table}");
        return array('success' => true, 'status' => 'ok', 'inventory_json' => json_encode(inv()));
    };
    $r = agent_inventory_refresh_if_due($db, $prefix, 25, $dropper, $t0);
    db_check($r['outcome'] === 'stored' && count_rows($db, $table, 25) === 1, 'a missing inventory table is recreated and the inventory stored');

    // A table with the right name but the wrong columns makes every query fail. In strict mode those are exceptions.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $breaker = function () use ($db, $table) {
        $db->query("DROP TABLE {$table}");
        $db->query("CREATE TABLE {$table} (junk INT) ENGINE=InnoDB");
        return array('success' => true, 'status' => 'ok', 'inventory_json' => json_encode(inv()));
    };
    $threw = false;
    $r = array('outcome' => 'none');
    try { $r = agent_inventory_refresh_if_due($db, $prefix, 26, $breaker, $t0); } catch (\Throwable $e) { $threw = true; }
    mysqli_report(MYSQLI_REPORT_OFF);
    db_check(!$threw, 'a database exception while storing does not escape (strict mode)');
    db_check($r['outcome'] === 'error', 'a database failure while storing is reported as an error');
    db_check(agent_inventory_refresh_if_due($db, $prefix, 26, $breaker, $t0 + 60)['outcome'] === 'skipped', 'and the broken check is not repeated on the next page load');
} finally {
    $db->query("DROP TABLE IF EXISTS {$table}");
    $db->query("DROP TABLE IF EXISTS " . agent_inventory_state_table($prefix));
    $gone = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->real_escape_string($prefix . 'agent_hardware_inventory') . "'");
    db_check($gone && (int)$gone->fetch_row()[0] === 0, 'test tables dropped afterwards');
}

echo $failures ? "\n$failures FAILED\n" : "\nall agent inventory database tests passed\n";
exit($failures ? 1 : 0);
