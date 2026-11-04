<?php
/*
 * Backup/Restore - restic backup policy rules
 *
 * Pure functions: no database or filesystem access, so they can be tested alone.
 * Settings come from admin.php (restic_* keys). Manual backups are never
 * automatic; they only run when a user or admin asks for one.
 */

// Normalise the raw settings array into a policy array.
function restic_policy_normalize($settings)
{
    $mode = isset($settings['restic_policy_mode']) ? (string)$settings['restic_policy_mode'] : 'manual';
    if (!in_array($mode, array('manual', 'daily', 'every_x_days'), TRUE))
        $mode = 'manual';

    $days = isset($settings['restic_policy_every_days']) ? (int)$settings['restic_policy_every_days'] : 7;
    $days = max(1, min(365, $days));

    return array(
        'mode'           => $mode,
        'every_days'     => $days,
        'initial_backup' => (isset($settings['restic_initial_backup']) && (string)$settings['restic_initial_backup'] === '1'),
    );
}

// Seconds between automatic backups, or 0 when the policy is manual.
function restic_policy_interval_seconds($policy)
{
    if ($policy['mode'] === 'daily')
        return 86400;
    if ($policy['mode'] === 'every_x_days')
        return $policy['every_days'] * 86400;
    return 0;
}

// True when an automatic backup is due for one game home.
// $last_auto_ts is the unix time of that home's last automatic snapshot, or 0 if none exists.
function restic_policy_auto_due($policy, $last_auto_ts, $now_ts)
{
    $interval = restic_policy_interval_seconds($policy);
    if ($interval === 0)
        return FALSE;
    if ($last_auto_ts <= 0)
        return TRUE;
    return ($now_ts - $last_auto_ts) >= $interval;
}

// True when the baseline snapshot should run when a server install completes.
function restic_policy_run_baseline($policy)
{
    return $policy['initial_backup'] === TRUE;
}
