<?php
/*
 * Backup/Restore - restic helpers (no database or remote access in this file).
 *
 * Snapshot kinds are restic tags: 'baseline' (install-time, never pruned) and
 * 'customer' (user-made, at most RESTIC_CUSTOMER_LIMIT kept per game home).
 * Comments and display dates live in the panel database, not in restic.
 */

const RESTIC_CUSTOMER_LIMIT = 3;
const RESTIC_COMMENT_MAX = 200;

// Repo URL for one game home's REST account on the DR box (WireGuard address).
function restic_repo_url($rest_user)
{
    return 'rest:http://10.255.0.1:8000/' . rawurlencode($rest_user) . '/';
}

// A user comment is required for customer backups: 1..200 chars, one line, no control characters.
function restic_comment_valid($comment)
{
    $comment = (string)$comment;
    if (strlen($comment) < 1 || strlen($comment) > RESTIC_COMMENT_MAX)
        return FALSE;
    return preg_match('/^[^\x00-\x1F\x7F]+$/', $comment) === 1;
}

// argv for `restic backup` of one home directory. Passed to the agent as escaped arguments.
function restic_build_backup_argv($repo_url, $home_path, $home_id, $kind)
{
    if ($kind !== 'baseline' && $kind !== 'customer')
        return FALSE;
    return array('restic', '-r', $repo_url, 'backup', $home_path,
        '--tag', $kind, '--tag', 'home-' . (int)$home_id, '--json');
}

// Pull the new snapshot id out of `restic backup --json` output, or NULL.
function restic_parse_backup_snapshot_id($output)
{
    foreach (preg_split('/\R/', (string)$output) as $line) {
        $obj = json_decode($line, TRUE);
        if (is_array($obj) && isset($obj['message_type']) && $obj['message_type'] === 'summary' && !empty($obj['snapshot_id']))
            return $obj['snapshot_id'];
    }
    return NULL;
}

// Given `restic snapshots --json` entries (each with id, time, tags), return the ids of
// customer snapshots beyond the newest RESTIC_CUSTOMER_LIMIT. Baseline snapshots are never returned.
function restic_customer_prune_list($snapshots, $limit = RESTIC_CUSTOMER_LIMIT)
{
    $customers = array();
    foreach ((array)$snapshots as $snap) {
        if (!isset($snap['id'], $snap['time'], $snap['tags']))
            continue;
        if (!in_array('customer', (array)$snap['tags'], TRUE))
            continue;
        $customers[] = array('id' => $snap['id'], 'time' => strtotime($snap['time']));
    }
    usort($customers, function ($a, $b) { return $b['time'] <=> $a['time']; });
    $extra = array_slice($customers, max(0, (int)$limit));
    return array_map(function ($c) { return $c['id']; }, $extra);
}
