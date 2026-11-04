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
check('comment ok', restic_comment_valid('before the wipe'), TRUE, $failures);
check('comment empty rejected', restic_comment_valid(''), FALSE, $failures);
check('comment 201 chars rejected', restic_comment_valid(str_repeat('a', 201)), FALSE, $failures);
check('comment with newline rejected', restic_comment_valid("a\nb"), FALSE, $failures);

check('backup argv for customer', restic_build_backup_argv('rest:x', '/p', 52, 'customer'),
    array('restic', '-r', 'rest:x', 'backup', '/p', '--tag', 'customer', '--tag', 'home-52', '--json'), $failures);
check('unknown kind rejected', restic_build_backup_argv('rest:x', '/p', 52, 'auto'), FALSE, $failures);

$out = "{\"message_type\":\"status\",\"percent_done\":1}\n{\"message_type\":\"summary\",\"snapshot_id\":\"abc123\"}\n";
check('parse snapshot id', restic_parse_backup_snapshot_id($out), 'abc123', $failures);
check('parse snapshot id missing', restic_parse_backup_snapshot_id('garbage'), NULL, $failures);

$snaps = array(
    array('id' => 'b1', 'time' => '2026-10-01T10:00:00Z', 'tags' => array('baseline', 'home-52')),
    array('id' => 'c1', 'time' => '2026-10-02T10:00:00Z', 'tags' => array('customer', 'home-52')),
    array('id' => 'c2', 'time' => '2026-10-03T10:00:00Z', 'tags' => array('customer', 'home-52')),
    array('id' => 'c3', 'time' => '2026-10-04T10:00:00Z', 'tags' => array('customer', 'home-52')),
    array('id' => 'c4', 'time' => '2026-10-05T10:00:00Z', 'tags' => array('customer', 'home-52')),
);
check('prune oldest customer beyond 3, keep baseline', restic_customer_prune_list($snaps), array('c1'), $failures);
check('nothing to prune at or under limit', restic_customer_prune_list(array_slice($snaps, 0, 3)), array(), $failures);

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
