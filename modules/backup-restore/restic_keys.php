<?php
/*
 * Backup/Restore - key handling for restic repos.
 *
 * One master key lives in secrets/restic-master.key (never committed, never shown
 * in the UI). Each game home's restic repo password and REST account password are
 * derived from it with HMAC, so nothing per-server is stored.
 *
 * Losing or regenerating the master key makes every customer backup unreadable,
 * so enabling never overwrites an existing key.
 */

const RESTIC_MASTER_KEY_FILE = 'restic-master.key';

function restic_secrets_dir()
{
    return __DIR__ . '/secrets';
}

function restic_master_key_path($dir = NULL)
{
    return ($dir ? $dir : restic_secrets_dir()) . '/' . RESTIC_MASTER_KEY_FILE;
}

function restic_master_key_exists($dir = NULL)
{
    return is_file(restic_master_key_path($dir));
}

// Creates the master key once. Returns 'created', 'exists', or an error string.
function restic_enable_create_master_key($dir = NULL)
{
    $dir = $dir ? $dir : restic_secrets_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, TRUE))
        return 'cannot create secrets directory';

    $path = restic_master_key_path($dir);
    if (is_file($path))
        return 'exists';

    $key = bin2hex(random_bytes(32));
    $old = umask(0077);
    $written = file_put_contents($path, $key . "\n", LOCK_EX);
    umask($old);
    if ($written === FALSE)
        return 'write failed';
    chmod($path, 0600);
    return 'created';
}

// Returns the master key (64 hex chars) or FALSE.
function restic_master_key_load($dir = NULL)
{
    $path = restic_master_key_path($dir);
    if (!is_file($path))
        return FALSE;
    $key = trim((string)file_get_contents($path));
    return preg_match('/^[0-9a-f]{64}$/', $key) === 1 ? $key : FALSE;
}

// Restic repository password for one game home (derived, never stored).
function restic_home_repo_password($master_key, $home_id)
{
    return hash_hmac('sha256', 'restic-repo:home-' . (int)$home_id, $master_key);
}

// REST account password for one game home (derived with a different label).
function restic_home_rest_password($master_key, $home_id)
{
    return hash_hmac('sha256', 'restic-rest:home-' . (int)$home_id, $master_key);
}
