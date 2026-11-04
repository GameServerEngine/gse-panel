<?php
// Run with: php modules/backup-restore/tests/restic_keys_test.php
require_once __DIR__ . '/../restic_keys.php';

$failures = 0;
function check($label, $actual, $expected, &$failures)
{
    if ($actual === $expected) { echo "ok   $label\n"; return; }
    $failures++;
    echo "FAIL $label: expected " . var_export($expected, TRUE) . ' got ' . var_export($actual, TRUE) . "\n";
}

$dir = sys_get_temp_dir() . '/restic_keys_test_' . getmypid();
$master = NULL;
check('no key before enable', restic_master_key_exists($dir), FALSE, $failures);
check('first enable creates key', restic_enable_create_master_key($dir), 'created', $failures);
check('key exists after enable', restic_master_key_exists($dir), TRUE, $failures);
check('second enable refuses to overwrite', restic_enable_create_master_key($dir), 'exists', $failures);
$master = restic_master_key_load($dir);
check('key loads as 64 hex chars', is_string($master) && preg_match('/^[0-9a-f]{64}$/', $master) === 1, TRUE, $failures);
check('key file mode is 0600', fileperms(restic_master_key_path($dir)) & 0777, 0600, $failures);

$p1 = restic_home_repo_password($master, 52);
check('repo password is deterministic', restic_home_repo_password($master, 52) === $p1, TRUE, $failures);
check('repo password differs per home', restic_home_repo_password($master, 53) !== $p1, TRUE, $failures);
check('REST password differs from repo password', restic_home_rest_password($master, 52) !== $p1, TRUE, $failures);
check('derived password is 64 hex chars', preg_match('/^[0-9a-f]{64}$/', $p1) === 1, TRUE, $failures);

unlink(restic_master_key_path($dir));
rmdir($dir);

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
