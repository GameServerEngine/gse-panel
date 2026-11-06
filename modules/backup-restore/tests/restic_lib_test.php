<?php
// Run with: php modules/backup-restore/tests/restic_lib_test.php
require_once __DIR__ . '/../restic_lib.php';

$failures = 0;
function check($label, $actual, $expected, &$failures)
{
    if ($actual === $expected) { echo "ok   $label\n"; return; }
    $failures++;
    echo "FAIL $label: expected " . var_export($expected, TRUE) . ' got ' . var_export($actual, TRUE) . "\n";
}

check('repo url', restic_repo_url('home-52'), 'rest:http://10.255.0.1:8000/home-52/', $failures);
check('repo url with auth', restic_repo_url_auth('home-52', 'abc123'),
    'rest:http://home-52:abc123@10.255.0.1:8000/home-52/', $failures);
check('repo url auth escapes password', restic_repo_url_auth('home-52', 'p@ss/w'),
    'rest:http://home-52:p%40ss%2Fw@10.255.0.1:8000/home-52/', $failures);

check('comment ok', restic_comment_valid('before the wipe'), TRUE, $failures);
check('comment empty rejected', restic_comment_valid(''), FALSE, $failures);
check('comment 201 chars rejected', restic_comment_valid(str_repeat('a', 201)), FALSE, $failures);
check('comment with newline rejected', restic_comment_valid("a\nb"), FALSE, $failures);

check('snapshot id short ok', restic_snapshot_id_valid('abcd1234'), TRUE, $failures);
check('snapshot id full ok', restic_snapshot_id_valid(str_repeat('f', 64)), TRUE, $failures);
check('snapshot id with shell chars rejected', restic_snapshot_id_valid('abcd;rm'), FALSE, $failures);
check('snapshot id uppercase rejected', restic_snapshot_id_valid('ABCD1234'), FALSE, $failures);

$game52 = 'C:\\GSE\\Agent\\home\\gameserver\\52';
check('game home path', restic_game_home_path(52), $game52, $failures);
check('game home path rejects bad id', restic_game_home_path('52;rm'), FALSE, $failures);
check('backup argv for customer', restic_build_backup_argv($game52, 52, 'customer'),
    array('restic', 'backup', $game52, '--exclude', $game52 . '\\restic-restore', '--tag', 'customer', '--tag', 'home-52', '--json'), $failures);
check('backup rejects other path', restic_build_backup_argv('/', 52, 'customer'), FALSE, $failures);
check('backup rejects other game folder', restic_build_backup_argv($game52, 53, 'customer'), FALSE, $failures);
check('unknown kind rejected', restic_build_backup_argv($game52, 52, 'auto'), FALSE, $failures);

check('windows home', restic_home_is_windows(array('server_os' => 'windows')), TRUE, $failures);
check('linux home not windows', restic_home_is_windows(array('server_os' => 'linux')), FALSE, $failures);
check('any home not windows', restic_home_is_windows(array('server_os' => 'any')), FALSE, $failures);
check('no server_os not windows', restic_home_is_windows(array()), FALSE, $failures);

check('restore argv', restic_build_restore_argv('abcd1234', 52),
    array('restic', 'restore', 'abcd1234', '--target', $game52 . '\\restic-restore'), $failures);
check('restore rejects bad id', restic_build_restore_argv('abcd;ls', 52), FALSE, $failures);
check('restore rejects bad home', restic_build_restore_argv('abcd1234', 'x'), FALSE, $failures);

check('forget argv', restic_build_forget_argv(array('abcd1234', 'ef567890')),
    array('restic', 'forget', '--prune', 'abcd1234', 'ef567890'), $failures);
check('forget rejects empty list', restic_build_forget_argv(array()), FALSE, $failures);
check('no -r in probe argv', in_array('-r', restic_build_probe_argv(), TRUE), FALSE, $failures);
check('forget rejects bad id', restic_build_forget_argv(array('zz')), FALSE, $failures);


$out = "{\"message_type\":\"status\",\"percent_done\":1}\n{\"message_type\":\"summary\",\"snapshot_id\":\"abc123\"}\n";
check('parse snapshot id', restic_parse_backup_snapshot_id($out), 'abc123', $failures);
check('parse snapshot id missing', restic_parse_backup_snapshot_id('garbage'), NULL, $failures);

// Prune: keep the newest 3 customer snapshots, never touch baseline.
$snaps = array(
    array('id' => 'c1', 'time' => '2026-11-01T10:00:00Z', 'tags' => array('customer', 'home-52')),
    array('id' => 'b0', 'time' => '2026-10-01T10:00:00Z', 'tags' => array('baseline', 'home-52')),
    array('id' => 'c2', 'time' => '2026-11-02T10:00:00Z', 'tags' => array('customer', 'home-52')),
    array('id' => 'c3', 'time' => '2026-11-03T10:00:00Z', 'tags' => array('customer', 'home-52')),
    array('id' => 'c4', 'time' => '2026-11-04T10:00:00Z', 'tags' => array('customer', 'home-52')),
);
check('prune keeps newest 3 customers, not baseline', restic_customer_prune_list($snaps), array('c1'), $failures);
check('prune with 3 or fewer customers is empty',
    restic_customer_prune_list(array_slice($snaps, 0, 2)), array(), $failures);

$rows = array(
    array('snapshot_row_id' => 1, 'snapshot_id' => 'r1', 'created_at' => 1000),
    array('snapshot_row_id' => 2, 'snapshot_id' => 'r2', 'created_at' => 2000),
    array('snapshot_row_id' => 3, 'snapshot_id' => 'r3', 'created_at' => 3000),
    array('snapshot_row_id' => 4, 'snapshot_id' => 'r4', 'created_at' => 4000),
);
check('row prune returns oldest beyond 3', restic_prune_ids_from_rows($rows), array('r1'), $failures);
$tied = array(
    array('snapshot_row_id' => 10, 'snapshot_id' => 'a', 'created_at' => 500),
    array('snapshot_row_id' => 11, 'snapshot_id' => 'b', 'created_at' => 500),
    array('snapshot_row_id' => 12, 'snapshot_id' => 'c', 'created_at' => 500),
    array('snapshot_row_id' => 13, 'snapshot_id' => 'd', 'created_at' => 500),
);
check('row prune tie broken by row id', restic_prune_ids_from_rows($tied), array('a'), $failures);

check('delete delay is 7 days', restic_delete_after_ts(1000), 1000 + 7 * 86400, $failures);
check('purge not due before delay', restic_home_purge_due(1000 + 7 * 86400, 1000 + 86400), FALSE, $failures);
check('purge due after delay', restic_home_purge_due(1000 + 7 * 86400, 1000 + 7 * 86400), TRUE, $failures);
check('purge never due when not marked', restic_home_purge_due(0, time()), FALSE, $failures);

echo $failures === 0 ? "\nall passed\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
