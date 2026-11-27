<?php
// Tests for includes/agent_inventory.php (pure functions; no database needed).
require_once __DIR__ . '/../includes/agent_inventory.php';

$failures = 0;
function inv_check($ok, $name)
{
    global $failures;
    echo ($ok ? 'ok' : 'FAIL') . ' - ' . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

function sample_inventory(array $overrides = array())
{
    $base = array(
        'schema_version' => 1,
        'platform' => 'linux',
        'kernel' => '6.8.0-test',
        'architecture' => 'x86_64',
        'cpu' => array(
            'model' => 'Intel(R) Xeon(R) CPU E5-2683 v4 @ 2.10GHz',
            'logical_cpus_allowed' => 20,
            'cpus_allowed_list' => '0-19',
            'quota' => array('source' => 'none', 'quota_us' => null, 'period_us' => null, 'unlimited' => null),
            'hypervisor_flag' => true,
        ),
        'memory' => array(
            'total_mib' => 23960,
            'available_mib' => 20757,
            'cgroup_limit' => array('source' => 'none', 'limit_bytes' => null),
        ),
        'storage' => array(
            'game_volume' => array('path' => '/home/dev', 'total_bytes' => 207549751296, 'available_bytes' => 122163961856),
            'block_devices' => array(array('name' => 'sda', 'rotational' => true, 'size_bytes' => 214748364800)),
        ),
        'warnings' => array(),
    );
    return array_replace_recursive($base, $overrides);
}

// 1. Parsing accepts the agent's format and rejects unknown schemas.
$json = json_encode(sample_inventory());
inv_check(is_array(agent_inventory_parse($json)), 'parses a valid schema 1 inventory');
inv_check(agent_inventory_parse(json_encode(array_merge(sample_inventory(), array('schema_version' => 2)))) === null, 'rejects an unknown schema version');
inv_check(agent_inventory_parse('not json') === null, 'rejects invalid JSON');
inv_check(agent_inventory_parse(null) === null, 'rejects a missing payload');
inv_check(agent_inventory_parse(json_encode(array_merge(sample_inventory(), array('platform' => '')))) === null, 'rejects an inventory with no platform');

// 2. Unknown values stay null, never 0.
$sparse = sample_inventory(array('cpu' => array('logical_cpus_allowed' => null), 'memory' => array('available_mib' => null)));
$parsed = agent_inventory_parse(json_encode($sparse));
inv_check($parsed['cpu']['logical_cpus_allowed'] === null, 'missing CPU count stays null');
inv_check($parsed['memory']['available_mib'] === null, 'missing available memory stays null');

// 3. Fingerprint: same hardware gives the same fingerprint, regardless of key order.
$a = sample_inventory();
$b = sample_inventory();
$reordered = array_reverse($b, true);
inv_check(agent_inventory_fingerprint($a) === agent_inventory_fingerprint($reordered), 'fingerprint ignores key order');

// 4. Fingerprint ignores volatile values (available memory, free disk space).
$busy = sample_inventory(array('memory' => array('available_mib' => 100), 'storage' => array('game_volume' => array('available_bytes' => 5))));
inv_check(agent_inventory_fingerprint($a) === agent_inventory_fingerprint($busy), 'fingerprint ignores available memory and free disk');

// 5. Fingerprint changes when real hardware changes.
inv_check(agent_inventory_fingerprint($a) !== agent_inventory_fingerprint(sample_inventory(array('cpu' => array('logical_cpus_allowed' => 6)))), 'fingerprint changes when CPU count changes');
inv_check(agent_inventory_fingerprint($a) !== agent_inventory_fingerprint(sample_inventory(array('memory' => array('total_mib' => 8192)))), 'fingerprint changes when RAM changes');
inv_check(agent_inventory_fingerprint($a) !== agent_inventory_fingerprint(sample_inventory(array('cpu' => array('quota' => array('source' => 'cgroup_v2', 'quota_us' => 200000, 'period_us' => 100000, 'unlimited' => false))))), 'fingerprint changes when a CPU quota is applied');

// 6. Reply mapping: fault means an older agent (unsupported), not an error.
$r = agent_inventory_from_reply(array('success' => false, 'status' => 'xmlrpc_fault', 'message' => 'method not found'));
inv_check($r['state'] === 'unsupported', 'XML-RPC fault maps to unsupported');

// 7. Reply mapping: successful reply carries a parsed inventory.
$r = agent_inventory_from_reply(array('success' => true, 'status' => 'ok', 'inventory_json' => $json));
inv_check($r['state'] === 'ok' && $r['inventory']['platform'] === 'linux', 'successful reply maps to ok with inventory');

// 8. Reply mapping: failure and bad payloads are errors, not silent success.
inv_check(agent_inventory_from_reply(array('success' => false, 'status' => 'bad_encryption', 'message' => 'Bad Encryption Key'))['state'] === 'error', 'agent refusal maps to error');
inv_check(agent_inventory_from_reply(array('success' => true, 'status' => 'ok', 'inventory_json' => '{"schema_version":9}'))['state'] === 'error', 'unknown inventory format maps to error');
inv_check(agent_inventory_from_reply(array('success' => false, 'status' => 'agent_unreachable', 'message' => 'No response from agent.'))['state'] === 'error', 'unreachable agent maps to error');

// 9. When is a check due? (no database)
$now = 1000000;
inv_check(agent_inventory_is_due(null, $now), 'no record: due');
inv_check(!agent_inventory_is_due(array('checked_at' => $now - 100, 'state' => 'ok'), $now), 'ok 100 s ago: not due');
inv_check(!agent_inventory_is_due(array('checked_at' => $now - (AGENT_INVENTORY_TTL_OK - 1), 'state' => 'ok'), $now), 'ok one second before the wait ends: not due');
inv_check(agent_inventory_is_due(array('checked_at' => $now - AGENT_INVENTORY_TTL_OK, 'state' => 'ok'), $now), 'ok exactly at the wait: due');
inv_check(!agent_inventory_is_due(array('checked_at' => $now - 3599, 'state' => 'unsupported'), $now), 'unsupported 3599 s ago: not due');
inv_check(agent_inventory_is_due(array('checked_at' => $now - 3600, 'state' => 'unsupported'), $now), 'unsupported 3600 s ago: due (an updated agent is noticed within an hour)');
inv_check(!agent_inventory_is_due(array('checked_at' => $now - 899, 'state' => 'error'), $now), 'error 899 s ago: not due');
inv_check(agent_inventory_is_due(array('checked_at' => $now - 900, 'state' => 'error'), $now), 'error 900 s ago: due');
inv_check(!agent_inventory_is_due(array('checked_at' => $now - 899, 'state' => 'something-new'), $now), 'an unknown state waits like an error');
inv_check(agent_inventory_is_due(array('checked_at' => $now + 500, 'state' => 'ok'), $now), 'a check from the future (clock moved back): due');
inv_check(agent_inventory_is_due(array('checked_at' => 0, 'state' => 'ok'), $now), 'an unusable record (time 0): due');
inv_check(AGENT_INVENTORY_TTL_OK > AGENT_INVENTORY_TTL_UNSUPPORTED && AGENT_INVENTORY_TTL_UNSUPPORTED > AGENT_INVENTORY_TTL_ERROR, 'waits order: healthy > unsupported > error');

// 10. Table prefix guard: the DB_PREFIX constant is a placeholder, never a real prefix.
inv_check(agent_inventory_prefix_ok(''), 'an empty prefix is allowed (core uses one)');
inv_check(agent_inventory_prefix_ok('ogp_') && agent_inventory_prefix_ok('gse_x1_'), 'letters, digits and underscores are allowed');
inv_check(!agent_inventory_prefix_ok('DB_PREFIX'), 'the placeholder DB_PREFIX is refused');
inv_check(!agent_inventory_prefix_ok('bad prefix') && !agent_inventory_prefix_ok('a-b') && !agent_inventory_prefix_ok("x`; DROP TABLE y; -- "), 'spaces, dashes, quotes and SQL are refused');

echo $failures ? "\n$failures FAILED\n" : "\nall agent inventory tests passed\n";
exit($failures ? 1 : 0);
