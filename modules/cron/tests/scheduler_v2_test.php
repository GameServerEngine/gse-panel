<?php
// Pure-logic tests for scheduler v2 phase P1: catalog validation and conflict
// policy. No DB connection needed, matching the style of
// modules/addonsmanager/tests/*.php. Run with: php modules/cron/tests/scheduler_v2_test.php

require_once(dirname(__DIR__) . '/scheduler_v2_catalog.php');
require_once(dirname(__DIR__) . '/scheduler_v2_lock.php');

function scheduler_v2_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

// --- Catalog validation ---

$result = scheduler_v2_validate_params('server.restart', array());
scheduler_v2_test_assert($result['ok'], 'server.restart with no params is valid (grace_seconds is optional)');
scheduler_v2_test_assert($result['params']['grace_seconds'] === 0, 'server.restart grace_seconds defaults to 0');

$result = scheduler_v2_validate_params('server.restart', array('grace_seconds' => 120));
scheduler_v2_test_assert($result['ok'] && $result['params']['grace_seconds'] === 120, 'server.restart accepts a valid grace_seconds');

$result = scheduler_v2_validate_params('server.restart', array('grace_seconds' => 9999));
scheduler_v2_test_assert(!$result['ok'], 'server.restart rejects grace_seconds above the maximum');

$result = scheduler_v2_validate_params('server.backup', array());
scheduler_v2_test_assert(!$result['ok'], 'server.backup requires target');

$result = scheduler_v2_validate_params('server.backup', array('target' => 'world'));
scheduler_v2_test_assert($result['ok'], 'server.backup accepts an allowlisted target');

$result = scheduler_v2_validate_params('server.backup', array('target' => 'drop_tables'));
scheduler_v2_test_assert(!$result['ok'], 'server.backup rejects a non-allowlisted target');

$result = scheduler_v2_validate_params('server.start', array('raw_command' => 'rm -rf /'));
scheduler_v2_test_assert(!$result['ok'], 'unexpected params are rejected (no raw commands, ever)');

$result = scheduler_v2_validate_params('admin.some_future_action', array());
scheduler_v2_test_assert(!$result['ok'], 'unknown action_key is rejected');

$customerCatalog = scheduler_v2_catalog_for_customer();
scheduler_v2_test_assert(!isset($customerCatalog['admin.wipe_server']), 'admin-only actions are never in the customer-visible catalog');
scheduler_v2_test_assert(isset($customerCatalog['server.restart']), 'server.restart is in the customer-visible catalog');

// --- Conflict policy ---

$now = 1000000;

$decision = scheduler_v2_resolve_conflict('skip', true, null, $now);
scheduler_v2_test_assert($decision['action'] === 'run', 'no running run means run immediately');

$decision = scheduler_v2_resolve_conflict('skip', false, array('is_read_only' => false, 'started_at' => $now - 10), $now);
scheduler_v2_test_assert($decision['action'] === 'run', 'read-only actions never conflict');

$decision = scheduler_v2_resolve_conflict('skip', true, array('is_read_only' => false, 'started_at' => $now - 10), $now);
scheduler_v2_test_assert($decision['action'] === 'skip', 'skip policy skips a mutating action while another mutating action runs');

$decision = scheduler_v2_resolve_conflict('queue', true, array('is_read_only' => false, 'started_at' => $now - 10), $now, 10);
scheduler_v2_test_assert($decision['action'] === 'wait', 'queue policy waits while inside the queue window');

$decision = scheduler_v2_resolve_conflict('queue', true, array('is_read_only' => false, 'started_at' => $now - 700), $now, 10);
scheduler_v2_test_assert($decision['action'] === 'skip', 'queue policy gives up and skips once the queue window elapses');

$decision = scheduler_v2_resolve_conflict('replace', true, array('is_read_only' => true, 'started_at' => $now - 10), $now);
scheduler_v2_test_assert($decision['action'] === 'replace', 'replace policy may cancel a running read-only check');

$decision = scheduler_v2_resolve_conflict('replace', true, array('is_read_only' => false, 'started_at' => $now - 10), $now);
scheduler_v2_test_assert($decision['action'] === 'skip', 'replace policy never cancels a running mutating action');

$decision = scheduler_v2_resolve_conflict('replace', true, array('is_read_only' => false, 'started_at' => $now - 10), $now, 10, true);
scheduler_v2_test_assert($decision['action'] === 'wait', 'restart/stop always waits for an in-progress install, ignoring conflict_policy');

$decision = scheduler_v2_resolve_conflict('replace', true, array('is_read_only' => false, 'started_at' => $now - 700), $now, 10, true);
scheduler_v2_test_assert($decision['action'] === 'skip', 'restart/stop gives up waiting once the window elapses');

scheduler_v2_test_assert(scheduler_v2_is_read_only_action('workshop.check_updates'), 'workshop.check_updates is read-only');
scheduler_v2_test_assert(!scheduler_v2_is_read_only_action('server.restart'), 'server.restart is mutating');
scheduler_v2_test_assert(scheduler_v2_is_restart_or_stop('server.stop'), 'server.stop is flagged restart-or-stop');
scheduler_v2_test_assert(!scheduler_v2_is_restart_or_stop('server.backup'), 'server.backup is not flagged restart-or-stop');

echo "All scheduler v2 P1 tests passed.\n";
