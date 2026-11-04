<?php
// Run with: php modules/backup-restore/tests/policy_test.php
require_once __DIR__ . '/../policy.php';

$failures = 0;
function check($label, $actual, $expected, &$failures)
{
    if ($actual === $expected) { echo "ok   $label\n"; return; }
    $failures++;
    echo "FAIL $label: expected " . var_export($expected, TRUE) . ' got ' . var_export($actual, TRUE) . "\n";
}

const DAY = 86400;
$now = 1800000000;

$p = restic_policy_normalize(array('restic_policy_mode' => 'manual'));
check('manual never auto-due', restic_policy_auto_due($p, 0, $now), FALSE, $failures);
check('manual never auto-due (old snapshot)', restic_policy_auto_due($p, $now - 999 * DAY, $now), FALSE, $failures);

$p = restic_policy_normalize(array('restic_policy_mode' => 'daily'));
check('daily, no snapshot yet: due', restic_policy_auto_due($p, 0, $now), TRUE, $failures);
check('daily, 23h ago: not due', restic_policy_auto_due($p, $now - (DAY - 3600), $now), FALSE, $failures);
check('daily, 24h ago: due', restic_policy_auto_due($p, $now - DAY, $now), TRUE, $failures);

$p = restic_policy_normalize(array('restic_policy_mode' => 'every_x_days', 'restic_policy_every_days' => '3'));
check('every 3 days, 2 days ago: not due', restic_policy_auto_due($p, $now - 2 * DAY, $now), FALSE, $failures);
check('every 3 days, 3 days ago: due', restic_policy_auto_due($p, $now - 3 * DAY, $now), TRUE, $failures);

$p = restic_policy_normalize(array('restic_policy_mode' => 'every_x_days', 'restic_policy_every_days' => '0'));
check('days clamped to minimum 1', $p['every_days'], 1, $failures);
$p = restic_policy_normalize(array('restic_policy_mode' => 'every_x_days', 'restic_policy_every_days' => '9999'));
check('days clamped to maximum 365', $p['every_days'], 365, $failures);

$p = restic_policy_normalize(array('restic_policy_mode' => 'bogus'));
check('unknown mode falls back to manual', $p['mode'], 'manual', $failures);

$p = restic_policy_normalize(array('restic_initial_backup' => '1'));
check('initial backup on when toggled', restic_policy_run_baseline($p), TRUE, $failures);
$p = restic_policy_normalize(array('restic_initial_backup' => '0'));
check('initial backup off by default', restic_policy_run_baseline($p), FALSE, $failures);

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
