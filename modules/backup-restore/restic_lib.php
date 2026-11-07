<?php
/*
 * Backup/Restore - restic helpers (no database or remote access in this file).
 *
 * Snapshot kinds are restic tags: 'baseline' (install-time, never pruned) and
 * 'customer' (user-made, at most RESTIC_CUSTOMER_LIMIT kept per game home).
 * Comments and display dates live in the panel database, not in restic.
 *
 * Deletes: the REST account of each game home may delete its own snapshots
 * (rest-server runs without --append-only), so prune uses the same account.
 * There is no separate admin credential for pruning.
 */

const RESTIC_CUSTOMER_LIMIT = 3;

// Upload cap in KiB/s for every backup run. 2048 KiB/s is about 2 MB/s (16 Mbit/s).
// The KCWIN agent accepts this flag only before 'backup' and only from 256 to 8192.
const RESTIC_UPLOAD_KBPS = 2048;
const RESTIC_COMMENT_MAX = 200;
const RESTIC_DELETE_DELAY_DAYS = 7;

// Base URL of the rest-server (scheme://host:port), entered in Backup/Restore Admin.
// Nothing is built in. Returns the URL without a trailing slash, or FALSE if it is not
// an http(s) URL with an IP address or host name and an optional port from 1 to 65535.
function restic_rest_base_valid($url)
{
    $url = rtrim(trim((string)$url), '/');
    if (!preg_match('#^https?://(\[[0-9A-Fa-f:]+\]|[A-Za-z0-9.-]+)(:[0-9]{1,5})?$#', $url))
        return FALSE;
    if (preg_match('/:([0-9]{1,5})$/', $url, $m) && (int)$m[1] > 65535)
        return FALSE;
    return $url;
}

// Repo URL for one game home's REST account on the backup server, without credentials.
function restic_repo_url($rest_user, $base)
{
    return 'rest:' . $base . '/' . rawurlencode($rest_user) . '/';
}

// Repo URL with the REST account's credentials. restic's REST backend reads them only from the URL.
function restic_repo_url_auth($rest_user, $rest_password, $base)
{
    $scheme_end = strpos($base, '://') + 3;
    return 'rest:' . substr($base, 0, $scheme_end)
        . rawurlencode($rest_user) . ':' . rawurlencode($rest_password)
        . '@' . substr($base, $scheme_end) . '/' . rawurlencode($rest_user) . '/';
}

// A user comment is required for customer backups: 1..200 chars, one line, no control characters.
function restic_comment_valid($comment)
{
    $comment = (string)$comment;
    if (strlen($comment) < 1 || strlen($comment) > RESTIC_COMMENT_MAX)
        return FALSE;
    return preg_match('/^[^\x00-\x1F\x7F]+$/', $comment) === 1;
}

// restic snapshot ids are lowercase hex: 8 characters (short) or 64 (full).
function restic_snapshot_id_valid($id)
{
    return is_string($id) && preg_match('/^[0-9a-f]{8,64}$/', $id) === 1;
}

// Windows path of one game server's folder on the agent host. Restic only ever touches this folder.
// Returns FALSE for an id that is not a plain positive integer.
function restic_game_home_path($home_id)
{
    if (!preg_match('/^[1-9][0-9]{0,9}$/', (string)$home_id))
        return FALSE;
    return 'C:\\GSE\\Agent\\home\\gameserver\\' . $home_id;
}

// argv for `restic backup` of one game home directory. Passed to the agent as escaped arguments.
// The path must be exactly this game's own folder (restic_game_home_path), nothing else.
function restic_build_backup_argv($home_path, $home_id, $kind, $upload_kbps = RESTIC_UPLOAD_KBPS)
{
    if ($kind !== 'baseline' && $kind !== 'customer')
        return FALSE;
    if ($home_path !== restic_game_home_path($home_id))
        return FALSE;
    // Restore staging lives inside the game folder; keep it out of backups.
    return array('restic', '--limit-upload', (string)(int)$upload_kbps, 'backup', $home_path,
        '--exclude', $home_path . '\\restic-restore',
        '--tag', $kind, '--tag', 'home-' . (int)$home_id, '--json');
}

// True when the game server runs Windows. The Windows restic paths only apply to those servers.
function restic_home_is_windows($cfg)
{
    return is_array($cfg) && isset($cfg['server_os']) && $cfg['server_os'] === 'windows';
}

// argv that creates the repo only when it does not exist yet (checked with `cat config`).
function restic_build_init_argv()
{
    return array('restic', 'init');
}

function restic_build_probe_argv()
{
    return array('restic', 'cat', 'config');
}

// argv for `restic restore` into a staging folder inside the game's own folder.
// Restic recreates the stored absolute path under the target (C:\GSE\... becomes
// <staging>\C\GSE\...), so this never writes outside the game folder and never to C:\ or /.
// Moving files back into place is a separate, reviewed step.
function restic_build_restore_argv($snapshot_id, $home_id)
{
    if (!restic_snapshot_id_valid($snapshot_id))
        return FALSE;
    $home = restic_game_home_path($home_id);
    if ($home === FALSE)
        return FALSE;
    return array('restic', 'restore', $snapshot_id, '--target', $home . '\\restic-restore');
}

// argv for `restic forget --prune` with explicit snapshot ids.
function restic_build_forget_argv($snapshot_ids)
{
    $ids = array();
    foreach ((array)$snapshot_ids as $id) {
        if (!restic_snapshot_id_valid($id))
            return FALSE;
        $ids[] = $id;
    }
    if (empty($ids))
        return FALSE;
    return array_merge(array('restic', 'forget', '--prune'), $ids);
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

// Same rule as restic_customer_prune_list, for the panel's own rows
// (each row has snapshot_id, created_at and snapshot_row_id). Newest first; ties broken by row id.
function restic_prune_ids_from_rows($rows, $limit = RESTIC_CUSTOMER_LIMIT)
{
    usort($rows, function ($a, $b) {
        $by_time = (int)$b['created_at'] <=> (int)$a['created_at'];
        return $by_time !== 0 ? $by_time : (int)$b['snapshot_row_id'] <=> (int)$a['snapshot_row_id'];
    });
    $extra = array_slice($rows, max(0, (int)$limit));
    return array_map(function ($r) { return $r['snapshot_id']; }, $extra);
}

// Unix time when a deleted game server's snapshots may be purged.
function restic_delete_after_ts($deleted_at_ts)
{
    return (int)$deleted_at_ts + RESTIC_DELETE_DELAY_DAYS * 86400;
}

// True once the delete delay has passed for a home that was marked deleted.
function restic_home_purge_due($delete_after_ts, $now_ts)
{
    return (int)$delete_after_ts > 0 && (int)$now_ts >= (int)$delete_after_ts;
}
