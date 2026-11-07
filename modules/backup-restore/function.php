<?php
/*
 * Backup/Restore - customer screens and actions (restic).
 *
 * Replaces the old rsync backup buttons. The old rsync code is kept in
 * "Old Stuff/function_rsync_legacy.php" for reference: rsync stays an option
 * for future file management, but it is no longer used for backups.
 *
 * Remote work runs as one restic command on the game server's agent, through
 * GSPRemoteLibrary::exec(). Passwords go in the environment, never in argv.
 * Pruning uses the game home's own REST account (no separate admin credential).
 */
require_once __DIR__ . '/restic_lib.php';
require_once __DIR__ . '/restic_keys.php';
require_once __DIR__ . '/policy.php';

// ---------- small helpers ----------

function restic_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function restic_csrf_token()
{
    if (empty($_SESSION['restic_csrf']))
        $_SESSION['restic_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['restic_csrf'];
}

function restic_csrf_ok()
{
    return isset($_POST['restic_csrf'], $_SESSION['restic_csrf'])
        && hash_equals($_SESSION['restic_csrf'], (string)$_POST['restic_csrf']);
}

function restic_ready()
{
    global $db;
    return (string)$db->getSetting('restic_enabled') === '1' && restic_master_key_exists();
}

// The game home if the logged-in user may use it, otherwise FALSE.
function restic_load_home($home_id)
{
    global $db;
    $home_id = (int)$home_id;
    if ($home_id <= 0)
        return FALSE;
    if ($db->isAdmin($_SESSION['user_id']))
        return $db->getGameHome($home_id);
    return $db->getUserGameHome($_SESSION['user_id'], $home_id);
}

// Limits for restic jobs on the agent. The panel polls every few seconds and stops
// waiting after the wait limit.
const RESTIC_JOB_POLL_SECONDS = 2;
const RESTIC_JOB_WAIT_SECONDS = 600;

// Runs one restic command on the game's agent as a job and waits for it to finish.
// Returns array(exit code, output), or FALSE if the agent did not start the job.
// If the job is still running after the wait limit, returns array(-1, message).
function restic_job_run($cfg, $env, $argv)
{
    include_once __DIR__ . '/../../includes/lib_remote.php';
    set_time_limit(RESTIC_JOB_WAIT_SECONDS + 60);
    $remote = new GSPRemoteLibrary($cfg['agent_ip'], $cfg['agent_port'], $cfg['encryption_key'], $cfg['timeout']);

    $job_id = bin2hex(random_bytes(16));
    if ($remote->restic_job_start($job_id, json_encode($argv), json_encode($env)) !== 'started')
        return FALSE;

    $deadline = time() + RESTIC_JOB_WAIT_SECONDS;
    while (time() < $deadline) {
        sleep(RESTIC_JOB_POLL_SECONDS);
        $status = $remote->restic_job_status($job_id);
        if ($status === FALSE)
            return FALSE;
        if ($status['state'] === 'done')
            return array((int)$status['exit'], $status['output']);
        if ($status['state'] !== 'running')
            return array(-1, 'The game server lost the backup job.');
    }
    return array(-1, 'The job is still running on the game server. Check again later.');
}

// Run a local command with stdin. Used for the ssh call to the DR box.
function restic_pipe_stdin($command, $stdin, &$output)
{
    $spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $proc = proc_open($command, $spec, $pipes);
    if (!is_resource($proc)) {
        $output = '';
        return -1;
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

// ---------- panel database ----------

function restic_home_row($home_id)
{
    global $db;
    $rows = $db->queryRows('SELECT * FROM `GSP_DB_PREFIXrestic_homes` WHERE `home_id` = ' . (int)$home_id);
    return (is_array($rows) && isset($rows[0])) ? $rows[0] : NULL;
}

function restic_home_ensure($home_id)
{
    global $db;
    $row = restic_home_row($home_id);
    if ($row !== NULL)
        return $row;
    $user = 'home-' . (int)$home_id;
    $db->query('INSERT INTO `GSP_DB_PREFIXrestic_homes` (`home_id`, `rest_user`, `repo_state`, `created_at`) VALUES ('
        . (int)$home_id . ", '" . $db->realEscapeSingle($user) . "', 'pending', " . time() . ')');
    return restic_home_row($home_id);
}

function restic_snapshot_rows($home_id)
{
    global $db;
    $rows = $db->queryRows('SELECT * FROM `GSP_DB_PREFIXrestic_snapshots` WHERE `home_id` = ' . (int)$home_id
        . ' ORDER BY `created_at` DESC, `snapshot_row_id` DESC');
    return is_array($rows) ? $rows : array();
}

function restic_snapshot_row_for($home_id, $snapshot_id)
{
    global $db;
    $rows = $db->queryRows('SELECT * FROM `GSP_DB_PREFIXrestic_snapshots` WHERE `home_id` = ' . (int)$home_id
        . " AND `snapshot_id` = '" . $db->realEscapeSingle($snapshot_id) . "'");
    return (is_array($rows) && isset($rows[0])) ? $rows[0] : NULL;
}

function restic_snapshot_forget_row($home_id, $snapshot_id)
{
    global $db;
    $db->query('DELETE FROM `GSP_DB_PREFIXrestic_snapshots` WHERE `home_id` = ' . (int)$home_id
        . " AND `snapshot_id` = '" . $db->realEscapeSingle($snapshot_id) . "'");
}

// ---------- repo access ----------

// Environment for one restic job. The repo URL (with its REST password) and the
// restic password travel here, never in argv.
function restic_home_env($master, $home_id, $rest_user)
{
    return array(
        'RESTIC_REPOSITORY' => restic_home_repo_url($master, $home_id, $rest_user),
        'RESTIC_PASSWORD' => restic_home_repo_password($master, $home_id),
    );
}

// The rest-server base URL from Backup/Restore Admin, or FALSE if it is not set or not valid.
function restic_configured_rest_base()
{
    global $db;
    return restic_rest_base_valid($db->getSetting('restic_rest_url'));
}

function restic_home_repo_url($master, $home_id, $rest_user)
{
    $base = restic_configured_rest_base();
    if ($base === FALSE)
        return FALSE;
    return restic_repo_url_auth($rest_user, restic_home_rest_password($master, $home_id), $base);
}

// Creates or updates this home's REST account on the DR box. The DR helper writes the
// bcrypt htpasswd line and reloads rest-server. Returns TRUE or an error message.
function restic_provision_home($home_id, $master)
{
    global $db;
    $target_host = (string)$db->getSetting('restic_target_host');
    $target_user = (string)$db->getSetting('restic_target_user');
    if ($target_host === '' || $target_user === '')
        return 'The backup target is not set in Backup/Restore Admin.';
    if (restic_configured_rest_base() === FALSE)
        return 'The backup server address is not set in Backup/Restore Admin.';

    $rest_user = 'home-' . (int)$home_id;
    $hash = password_hash(restic_home_rest_password($master, $home_id), PASSWORD_BCRYPT);
    if ($hash === FALSE)
        return 'Could not prepare the backup account.';

    // rest_user is built in code as home-<id> only, so it is sent without quotes.
    $remote_cmd = 'sudo -n /usr/local/sbin/restic-home-admin set ' . $rest_user;
    $ssh = 'ssh -o BatchMode=yes -o ConnectTimeout=10 '
        . escapeshellarg($target_user . '@' . $target_host) . ' ' . escapeshellarg($remote_cmd);
    $rc = restic_pipe_stdin($ssh, $rest_user . ':' . $hash . "\n", $out);
    if ($rc !== 0)
        return 'Could not create the backup account on the backup server (exit ' . $rc . ').';

    $db->query("UPDATE `GSP_DB_PREFIXrestic_homes` SET `repo_state` = 'ready' WHERE `home_id` = " . (int)$home_id);
    return TRUE;
}

// ---------- actions ----------

// Customer backup: needs a comment, runs restic on the agent, records the snapshot, then prunes.
// Returns array(ok, message).
function restic_do_backup($cfg, $home_id)
{
    if (!restic_home_is_windows($cfg))
        return array(FALSE, 'Backups are only available for Windows game servers right now.');
    if (restic_configured_rest_base() === FALSE)
        return array(FALSE, 'The backup server address is not set in Backup/Restore Admin.');
    global $db;
    $comment = trim((string)(isset($_POST['comment']) ? $_POST['comment'] : ''));
    if (!restic_comment_valid($comment))
        return array(FALSE, 'Enter a comment of 1 to 200 characters on one line.');

    $master = restic_master_key_load();
    if ($master === FALSE)
        return array(FALSE, 'Customer backups are not enabled.');

    $row = restic_home_ensure($home_id);
    if ($row === NULL)
        return array(FALSE, 'Could not record the backup account for this game server.');
    if ($row['repo_state'] !== 'ready') {
        $ok = restic_provision_home($home_id, $master);
        if ($ok !== TRUE)
            return array(FALSE, $ok);
        $row = restic_home_row($home_id);
    }

    $env = restic_home_env($master, $home_id, $row['rest_user']);
    // Create the repo the first time only: probe with `cat config`, init when that fails.
    $probe = restic_job_run($cfg, $env, restic_build_probe_argv());
    if ($probe === FALSE)
        return array(FALSE, 'The game server did not answer. Nothing was backed up.');
    if ($probe[0] !== 0) {
        $init = restic_job_run($cfg, $env, restic_build_init_argv());
        if ($init === FALSE || $init[0] !== 0)
            return array(FALSE, 'The backup repository could not be created on the game server.');
    }

    $res = restic_job_run($cfg, $env, restic_build_backup_argv(restic_game_home_path($home_id), $home_id, 'customer'));
    if ($res === FALSE)
        return array(FALSE, 'The game server did not answer. Nothing was backed up.');
    if ($res[0] !== 0)
        return array(FALSE, 'The backup failed on the game server (exit ' . $res[0] . ').');

    $snapshot_id = restic_parse_backup_snapshot_id($res[1]);
    if ($snapshot_id === NULL)
        return array(FALSE, 'The backup finished, but no snapshot id came back.');

    $user = isset($_SESSION['loginuser']) ? (string)$_SESSION['loginuser'] : '';
    $db->query('INSERT INTO `GSP_DB_PREFIXrestic_snapshots` (`home_id`, `snapshot_id`, `kind`, `comment`, `created_by`, `created_at`) VALUES ('
        . (int)$home_id . ", '" . $db->realEscapeSingle($snapshot_id) . "', 'customer', '"
        . $db->realEscapeSingle($comment) . "', '" . $db->realEscapeSingle($user) . "', " . time() . ')');

    $pruned = restic_prune_customers($cfg, $home_id, $master);
    return array(TRUE, 'Backup saved.' . ($pruned !== '' ? ' ' . $pruned : ''));
}

// Keeps the newest RESTIC_CUSTOMER_LIMIT customer snapshots. Baseline snapshots are never listed.
// Returns a message for the user ('' when nothing needed removing).
function restic_prune_customers($cfg, $home_id, $master)
{
    $customers = array();
    foreach (restic_snapshot_rows($home_id) as $row) {
        if ($row['kind'] === 'customer')
            $customers[] = $row;
    }
    $ids = restic_prune_ids_from_rows($customers, RESTIC_CUSTOMER_LIMIT);
    if (empty($ids))
        return '';

    $row = restic_home_row($home_id);
    $env = restic_home_env($master, $home_id, $row['rest_user']);
    $res = restic_job_run($cfg, $env, restic_build_forget_argv($ids));
    if ($res === FALSE || $res[0] !== 0)
        return 'Older snapshots could not be removed yet; the next backup will try again.';

    foreach ($ids as $id)
        restic_snapshot_forget_row($home_id, $id);
    return 'Removed ' . count($ids) . ' older customer snapshot(s).';
}

// Restores one snapshot into place. The game server must be stopped first.
// Returns array(ok, message).
function restic_do_restore($cfg, $home_id)
{
    if (!restic_home_is_windows($cfg))
        return array(FALSE, 'Restores are only available for Windows game servers right now.');
    if (restic_configured_rest_base() === FALSE)
        return array(FALSE, 'The backup server address is not set in Backup/Restore Admin.');
    $snapshot_id = (string)(isset($_POST['snapshot_id']) ? $_POST['snapshot_id'] : '');
    if (!restic_snapshot_id_valid($snapshot_id))
        return array(FALSE, 'Pick a snapshot to restore.');
    if (trim((string)(isset($_POST['confirm']) ? $_POST['confirm'] : '')) !== 'RESTORE')
        return array(FALSE, 'Type RESTORE in the box to confirm.');

    $snap = restic_snapshot_row_for($home_id, $snapshot_id);
    if ($snap === NULL)
        return array(FALSE, 'That snapshot does not belong to this game server.');

    $master = restic_master_key_load();
    if ($master === FALSE)
        return array(FALSE, 'Customer backups are not enabled.');
    $row = restic_home_row($home_id);
    $env = restic_home_env($master, $home_id, $row['rest_user']);
    $res = restic_job_run($cfg, $env, restic_build_restore_argv($snapshot_id, $home_id));
    if ($res === FALSE)
        return array(FALSE, 'The game server did not answer. Nothing was restored.');
    if ($res[0] !== 0)
        return array(FALSE, 'The restore failed on the game server (exit ' . $res[0] . ').');
    return array(TRUE, 'Restore finished.');
}

// Deletes one saved customer backup: forgets it in the repo, then removes its panel row.
// Returns array(ok, message).
function restic_do_delete($cfg, $home_id)
{
    if (restic_configured_rest_base() === FALSE)
        return array(FALSE, 'The backup server address is not set in Backup/Restore Admin.');
    $snapshot_id = (string)(isset($_POST['snapshot_id']) ? $_POST['snapshot_id'] : '');
    if (!restic_snapshot_id_valid($snapshot_id))
        return array(FALSE, 'Pick a backup to delete.');
    if (strcasecmp(trim((string)(isset($_POST['confirm']) ? $_POST['confirm'] : '')), 'DELETE') !== 0)
        return array(FALSE, 'Type delete in the box to confirm.');

    $snap = restic_snapshot_row_for($home_id, $snapshot_id);
    if ($snap === NULL)
        return array(FALSE, 'That backup does not belong to this game server.');
    if ($snap['kind'] === 'baseline')
        return array(FALSE, 'Baseline backups cannot be deleted from this page.');

    $master = restic_master_key_load();
    if ($master === FALSE)
        return array(FALSE, 'Customer backups are not enabled.');
    $row = restic_home_row($home_id);
    $env = restic_home_env($master, $home_id, $row['rest_user']);
    $res = restic_job_run($cfg, $env, restic_build_forget_argv(array($snapshot_id)));
    if ($res === FALSE)
        return array(FALSE, 'The game server did not answer. Nothing was deleted.');
    if ($res[0] !== 0)
        return array(FALSE, 'The delete failed on the game server (exit ' . $res[0] . ').');

    restic_snapshot_forget_row($home_id, $snapshot_id);
    return array(TRUE, 'Backup deleted.');
}

function restic_render_delete_confirm($cfg, $home_id)
{
    $snapshot_id = (string)(isset($_POST['snapshot_id']) ? $_POST['snapshot_id'] : '');
    $snap = restic_snapshot_row_for($home_id, $snapshot_id);
    echo '<center>';
    if ($snap === NULL || !restic_snapshot_id_valid($snapshot_id) || $snap['kind'] === 'baseline') {
        restic_render_notice('That backup cannot be deleted from this page.', FALSE);
        echo '</center>';
        return;
    }
    echo '<h3>Delete backup from ' . restic_h(date('Y-m-d H:i', (int)$snap['created_at'])) . '?</h3>';
    echo '<p>Comment: ' . restic_h($snap['comment']) . '</p>';
    echo '<p><strong>This removes the backup from the backup storage. It cannot be undone.</strong></p>';
    echo '<form method="POST" action="?m=backup-restore&amp;home_id=' . (int)$home_id . '">';
    echo '<input type="hidden" name="home_id" value="' . (int)$home_id . '">';
    echo '<input type="hidden" name="restic_csrf" value="' . restic_h(restic_csrf_token()) . '">';
    echo '<input type="hidden" name="snapshot_id" value="' . restic_h($snapshot_id) . '">';
    echo '<p><label>Type delete to confirm <input type="text" name="confirm" autocomplete="off"></label></p>';
    echo '<button type="submit" name="dothis" value="cancel">Cancel</button>&emsp;';
    echo '<button type="submit" name="dothis" value="deleteNow">Delete this backup</button>';
    echo '</form></center>';
}

// ---------- server deletion and purge ----------

// Called when a game server is deleted: its snapshots stay for 7 days, then the purge removes them.
function restic_mark_home_deleted($home_id, $now = NULL)
{
    global $db;
    $now = ($now === NULL) ? time() : (int)$now;
    $db->query("UPDATE `GSP_DB_PREFIXrestic_homes` SET `repo_state` = 'deleted', `delete_after` = "
        . restic_delete_after_ts($now) . ' WHERE `home_id` = ' . (int)$home_id);
}

// Removes the REST account and repo of every home whose delete delay has passed. Returns the home ids purged.
function restic_purge_due_homes($now = NULL)
{
    global $db;
    $now = ($now === NULL) ? time() : (int)$now;
    $purged = array();
    $rows = $db->queryRows("SELECT * FROM `GSP_DB_PREFIXrestic_homes` WHERE `repo_state` = 'deleted'");
    if (!is_array($rows))
        return $purged;
    foreach ($rows as $row) {
        if (!restic_home_purge_due($row['delete_after'], $now))
            continue;
        if (restic_purge_remote($db, $row['rest_user']) !== TRUE)
            continue;
        $db->query('DELETE FROM `GSP_DB_PREFIXrestic_snapshots` WHERE `home_id` = ' . (int)$row['home_id']);
        $db->query("UPDATE `GSP_DB_PREFIXrestic_homes` SET `repo_state` = 'purged' WHERE `home_id` = " . (int)$row['home_id']);
        $purged[] = (int)$row['home_id'];
    }
    return $purged;
}

function restic_purge_remote($db, $rest_user)
{
    $target_host = (string)$db->getSetting('restic_target_host');
    $target_user = (string)$db->getSetting('restic_target_user');
    if ($target_host === '' || $target_user === '')
        return FALSE;
    $remote_cmd = 'sudo -n /usr/local/sbin/restic-home-admin purge ' . $rest_user;
    $ssh = 'ssh -o BatchMode=yes -o ConnectTimeout=10 '
        . escapeshellarg($target_user . '@' . $target_host) . ' ' . escapeshellarg($remote_cmd);
    return restic_pipe_stdin($ssh, '', $out) === 0;
}

// ---------- screens ----------

function restic_render_notice($text, $ok = TRUE)
{
    $class = $ok ? 'ok' : 'error';
    echo '<center><div class="' . $class . '">' . restic_h($text) . '</div></center>';
}

function restic_render_home($cfg, $home_id, $message = NULL, $ok = TRUE)
{
    echo '<center>';
    if ($message !== NULL)
        restic_render_notice($message, $ok);

    if (!restic_ready()) {
        echo '<h3>Customer backups are not enabled.</h3>';
        echo '<p>Ask an admin to turn them on in Backup/Restore Admin.</p></center>';
        return;
    }

    echo '<h3>Backups for game server ' . restic_h(isset($cfg['home_name']) ? $cfg['home_name'] : $home_id) . '</h3>';
    echo '<p>Keeps the newest ' . RESTIC_CUSTOMER_LIMIT . ' backups. Older ones are removed when a new one is made.<br>'
        . 'Backups of a running server are allowed, but a backup taken while it runs may not be consistent.</p>';

    echo '<form method="POST" action="?m=backup-restore&amp;home_id=' . (int)$home_id . '">';
    echo '<input type="hidden" name="home_id" value="' . (int)$home_id . '">';
    echo '<input type="hidden" name="restic_csrf" value="' . restic_h(restic_csrf_token()) . '">';
    echo '<p><label>Comment (required, up to 200 characters)<br>';
    echo '<input type="text" name="comment" maxlength="200" required size="60"></label></p>';
    echo '<button type="submit" name="dothis" value="backupNow">Create backup</button>';
    echo '</form>';

    echo '<h4>Saved backups</h4>';
    $rows = restic_snapshot_rows($home_id);
    if (empty($rows)) {
        echo '<p>No backups yet.</p>';
    } else {
        echo '<table><tr><th>Date</th><th>Comment</th><th>Made by</th><th></th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . restic_h(date('Y-m-d H:i', (int)$row['created_at'])) . '</td>';
            echo '<td>' . restic_h($row['comment']) . '</td>';
            echo '<td>' . restic_h($row['created_by']) . '</td>';
            echo '<td><form method="POST" action="?m=backup-restore&amp;home_id=' . (int)$home_id . '">';
            echo '<input type="hidden" name="home_id" value="' . (int)$home_id . '">';
            echo '<input type="hidden" name="restic_csrf" value="' . restic_h(restic_csrf_token()) . '">';
            echo '<input type="hidden" name="snapshot_id" value="' . restic_h($row['snapshot_id']) . '">';
            echo '<button type="submit" name="dothis" value="restoreConfirm" style="width:90px">Restore</button>';
            echo '</form>';
            if ($row['kind'] !== 'baseline') {
                echo '<form method="POST" action="?m=backup-restore&amp;home_id=' . (int)$home_id . '" style="display:inline">';
                echo '<input type="hidden" name="home_id" value="' . (int)$home_id . '">';
                echo '<input type="hidden" name="restic_csrf" value="' . restic_h(restic_csrf_token()) . '">';
                echo '<input type="hidden" name="snapshot_id" value="' . restic_h($row['snapshot_id']) . '">';
                echo '<button type="submit" name="dothis" value="deleteConfirm" style="width:90px">Delete</button>';
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</table>';
    }

    echo '<br><form method="POST" action="?m=backup-restore&amp;home_id=' . (int)$home_id . '">';
    echo '<input type="hidden" name="home_id" value="' . (int)$home_id . '">';
    echo '<button type="submit" name="dothis" value="cancel">Go back</button>';
    echo '</form></center>';
}

function restic_render_restore_confirm($cfg, $home_id)
{
    $snapshot_id = (string)(isset($_POST['snapshot_id']) ? $_POST['snapshot_id'] : '');
    $snap = restic_snapshot_row_for($home_id, $snapshot_id);
    echo '<center>';
    if ($snap === NULL || !restic_snapshot_id_valid($snapshot_id)) {
        restic_render_notice('That backup was not found for this game server.', FALSE);
        echo '</center>';
        return;
    }
    echo '<h3>Restore backup from ' . restic_h(date('Y-m-d H:i', (int)$snap['created_at'])) . '?</h3>';
    echo '<p>Comment: ' . restic_h($snap['comment']) . '</p>';
    echo '<p><strong>This overwrites the current server files with this backup.</strong><br>'
        . 'Suggestion: cancel, then stop the game server before restoring. The panel does not block the restore; the choice is yours.</p>';
    echo '<form method="POST" action="?m=backup-restore&amp;home_id=' . (int)$home_id . '">';
    echo '<input type="hidden" name="home_id" value="' . (int)$home_id . '">';
    echo '<input type="hidden" name="restic_csrf" value="' . restic_h(restic_csrf_token()) . '">';
    echo '<input type="hidden" name="snapshot_id" value="' . restic_h($snapshot_id) . '">';
    echo '<p><label>Type RESTORE to confirm <input type="text" name="confirm" autocomplete="off"></label></p>';
    echo '<button type="submit" name="dothis" value="cancel">Cancel</button>&emsp;';
    echo '<button type="submit" name="dothis" value="restoreNow">Restore this backup</button>';
    echo '</form></center>';
}
