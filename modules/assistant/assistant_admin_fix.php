<?php
/*
 * Repairs the Admin assistant may PROPOSE, and that run only when the administrator presses Apply:
 *  - one SQL statement, on the panel database or on a MySQL server in the panel's list;
 *  - one text file inside the panel folder (a new file, or small exact edits to an existing one).
 * The model never runs anything. The panel checks the rights again when Apply is pressed. A panel
 * file gets a backup first, and a PHP file must pass php -l before it is written. The panel config,
 * secrets and the .git folder can never be changed from here.
 */

require_once(__DIR__ . '/assistant_files.php');
require_once(__DIR__ . '/assistant_diff.php');
require_once(__DIR__ . '/assistant_admin.php');
require_once(__DIR__ . '/assistant_admin_files.php');

// ---------------------------------------------------------------------------------------------
// SQL

// Returns '' when the statement may be proposed, otherwise the reason.
function assistant_admin_sql_problem($sql)
{
	$sql = rtrim(trim((string)$sql), "; \t\r\n");
	if ($sql === '') {
		return 'Give one SQL statement.';
	}
	if (strlen($sql) > 20000) {
		return 'The statement is too long.';
	}
	if (strpos($sql, ';') !== false) {
		return 'Only one statement at a time. Remove the semicolon.';
	}
	if (preg_match('/(--|#|\/\*)/', $sql)) {
		return 'Comments are not allowed in the statement.';
	}
	if (preg_match('/\b(DROP|TRUNCATE|RENAME)\b/i', $sql)) {
		return 'DROP, TRUNCATE and RENAME are not allowed here. Do those by hand.';
	}
	$parts = preg_split('/\s+/', $sql);
	$first = strtoupper($parts[0]);
	$allowed = array('UPDATE', 'INSERT', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'GRANT', 'REVOKE', 'FLUSH');
	if (!in_array($first, $allowed, true)) {
		return 'Only UPDATE, INSERT, DELETE, REPLACE, CREATE, ALTER, GRANT, REVOKE or FLUSH can be proposed.';
	}
	if (($first === 'UPDATE' || $first === 'DELETE') && !preg_match('/\bWHERE\b/i', $sql)) {
		return 'UPDATE and DELETE need a WHERE clause.';
	}
	return '';
}

// A target is 'panel' or 'mysql:<mysql_server_id>' from the panel's MySQL server list.
function assistant_admin_sql_target_ok($db, $target)
{
	if ($target === 'panel') {
		return true;
	}
	if (!preg_match('/^mysql:(\d+)$/', (string)$target, $m)) {
		return false;
	}
	foreach (assistant_admin_mysql_servers($db) as $s) {
		if ((int)$s['mysql_server_id'] === (int)$m[1]) {
			return true;
		}
	}
	return false;
}

function assistant_admin_sql_connect($db, $target)
{
	global $db_host, $db_user, $db_pass, $db_name, $db_port;
	if ($target === 'panel') {
		return assistant_mysql_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? $db_port : 3306);
	}
	$id = (int)substr($target, 6);
	foreach (assistant_admin_mysql_servers($db) as $s) {
		if ((int)$s['mysql_server_id'] === $id) {
			return assistant_mysql_connect($s['mysql_ip'], !empty($s['mysql_admin_user']) ? $s['mysql_admin_user'] : 'root', $s['mysql_root_passwd'], '', $s['mysql_port']);
		}
	}
	return false;
}

function assistant_tool_propose_sql($db, $user_id, $input)
{
	assistant_expire_proposals($db);
	$which = isset($input['database']) ? (string)$input['database'] : '';
	if ($which === 'panel') {
		$target = 'panel';
	} elseif ($which === 'mysql') {
		$target = 'mysql:' . (int)(isset($input['mysql_server_id']) ? $input['mysql_server_id'] : 0);
	} else {
		return array('data' => array('error' => 'database must be panel or mysql.'));
	}
	if (!assistant_admin_sql_target_ok($db, $target)) {
		return array('data' => array('error' => 'No MySQL server has that id. mysql_health lists them with their id.'));
	}
	$sql = rtrim(trim((string)(isset($input['sql']) ? $input['sql'] : '')), "; \t\r\n");
	$problem = assistant_admin_sql_problem($sql);
	if ($problem !== '') {
		return array('data' => array('error' => $problem));
	}
	$note = isset($input['summary']) ? (string)$input['summary'] : '';
	$id = assistant_save_proposal($db, $user_id, 0, 'sql', $target, '', $sql, $note);
	if ($id < 1) {
		return array('data' => array('error' => 'The statement could not be saved.'));
	}
	return array(
		'data' => array('ok' => true, 'note' => 'Saved as proposal #' . $id . '. It is NOT run. The administrator reviews the exact statement and presses Apply. Say what it changes and what it does not.'),
		'button' => array('type' => 'proposal', 'id' => $id, 'label' => 'Review SQL on ' . ($target === 'panel' ? 'the panel database' : 'MySQL server ' . (int)substr($target, 6))),
	);
}

function assistant_admin_sql_apply($db, $p)
{
	$sql = (string)$p['new_content'];
	$problem = assistant_admin_sql_problem($sql);
	if ($problem !== '') {
		return array('ok' => false, 'message' => $problem);
	}
	$target = (string)$p['target'];
	if (!assistant_admin_sql_target_ok($db, $target)) {
		return array('ok' => false, 'message' => 'That MySQL server is no longer in the panel. Nothing was changed.');
	}
	$link = assistant_admin_sql_connect($db, $target);
	if (!$link) {
		return array('ok' => false, 'message' => 'Could not log in to that database server. Nothing was changed.');
	}
	mysqli_report(MYSQLI_REPORT_OFF);
	$ok = mysqli_query($link, $sql);
	if ($ok === false) {
		$error = mysqli_error($link);
		mysqli_close($link);
		return array('ok' => false, 'message' => 'MySQL refused the statement: ' . $error);
	}
	$rows = (int)mysqli_affected_rows($link);
	mysqli_close($link);
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'applied', decided = NOW() WHERE id = " . (int)$p['id'] . " AND status = 'pending'");
	return array('ok' => true, 'message' => 'Ran it on ' . ($target === 'panel' ? 'the panel database' : 'MySQL server ' . (int)substr($target, 6)) . '. Rows changed: ' . $rows . '.');
}

// ---------------------------------------------------------------------------------------------
// Panel files

// A path relative to the panel folder, cleaned. Returns the string, or false when it is not allowed.
function assistant_admin_panel_rel($path)
{
	$path = trim((string)$path);
	if ($path === '' || $path[0] === '/') {
		return false;
	}
	return assistant_clean_rel($path);
}

// The real path of a file or folder inside the panel folder, or null (outside, a symlink, or missing parent).
function assistant_admin_panel_full($panel_root, $rel)
{
	$root = realpath($panel_root);
	if ($root === false || $rel === false || $rel === '') {
		return null;
	}
	$full = $root . '/' . $rel;
	if (is_link($full)) {
		return null;
	}
	$parent = realpath(dirname($full));
	if ($parent === false || ($parent !== $root && strpos($parent, $root . '/') !== 0)) {
		return null;
	}
	if (is_file($full)) {
		$real = realpath($full);
		if ($real === false || strpos($real, $root . '/') !== 0) {
			return null;
		}
	}
	return $full;
}

// '' when the file may be changed from here, otherwise the reason.
function assistant_admin_panel_forbidden($rel)
{
	if (assistant_secret_filename('/' . $rel)) {
		return 'That file holds secrets or the panel configuration and cannot be changed from here.';
	}
	return '';
}

// Runs php -l on the text. Returns '' when it passes, otherwise the reason.
function assistant_admin_php_lint($source)
{
	$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
	if (!function_exists('exec') || in_array('exec', $disabled, true)) {
		return 'PHP files cannot be syntax-checked on this server, so they cannot be changed from here.';
	}
	$tmp = tempnam(sys_get_temp_dir(), 'gselint');
	if ($tmp === false) {
		return 'The syntax check could not start.';
	}
	file_put_contents($tmp, (string)$source);
	$bin = (defined('PHP_BINARY') && basename(PHP_BINARY) === 'php') ? PHP_BINARY : 'php';
	$out = array();
	$status = 1;
	exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
	unlink($tmp);
	if ($status !== 0) {
		return 'PHP syntax check failed: ' . substr(implode(' ', $out), 0, 300);
	}
	return '';
}

function assistant_tool_propose_panel_edit($db, $user_id, $panel_root, $cfg, $input)
{
	assistant_expire_proposals($db);
	$rel = assistant_admin_panel_rel(isset($input['path']) ? $input['path'] : '');
	if ($rel === false || $rel === '') {
		return array('data' => array('error' => 'Give a path relative to the panel folder, like includes/functions.php.'));
	}
	$full = assistant_admin_panel_full($panel_root, $rel);
	if ($full === null) {
		return array('data' => array('error' => 'That path is outside the panel folder, or its folder does not exist.'));
	}
	$why = assistant_admin_panel_forbidden($rel);
	if ($why !== '') {
		return array('data' => array('error' => $why));
	}
	if (is_dir($full)) {
		return array('data' => array('error' => 'That is a folder.'));
	}
	if (assistant_binary_extension($rel)) {
		return array('data' => array('error' => 'Only text files can be changed.'));
	}
	$note = isset($input['summary']) ? (string)$input['summary'] : '';
	$has_content = isset($input['content']) && is_string($input['content']);
	$old = '';
	if (is_file($full)) {
		if ($has_content) {
			return array('data' => array('error' => 'That file exists. Change it with edits (old_text and new_text), not content.'));
		}
		if (filesize($full) > ASSISTANT_FILE_EDIT_MAX) {
			return array('data' => array('error' => 'That file is too big to edit here.'));
		}
		$old = (string)file_get_contents($full);
		if (assistant_looks_binary($old)) {
			return array('data' => array('error' => 'That is a binary file.'));
		}
		list($new, $why) = assistant_apply_edits($old, isset($input['edits']) ? $input['edits'] : array());
		if ($new === null) {
			return array('data' => array('error' => $why));
		}
	} else {
		if (!$has_content || $input['content'] === '') {
			return array('data' => array('error' => 'That file does not exist. To create it, give its full text in content.'));
		}
		if (assistant_looks_binary($input['content'])) {
			return array('data' => array('error' => 'Only text files can be created.'));
		}
		$new = $input['content'];
	}
	if (strlen($new) > ASSISTANT_FILE_EDIT_MAX) {
		return array('data' => array('error' => 'The result would be too big.'));
	}
	if ($new === $old) {
		return array('data' => array('error' => 'That change would not alter the file.'));
	}
	$id = assistant_save_proposal($db, $user_id, 0, 'panel_file', 'panel:' . $rel, $old, $new, $note);
	if ($id < 1) {
		return array('data' => array('error' => 'The change could not be saved.'));
	}
	$diff = assistant_redact(assistant_unified_diff($old, $new, $rel));
	if (strlen($diff) > 3000) {
		$diff = substr($diff, 0, 3000) . "\n(more not shown)";
	}
	return array(
		'data' => array(
			'ok' => true,
			'note' => 'Saved as proposal #' . $id . '. Nothing has been written yet. The administrator presses Apply; the panel keeps a backup and checks PHP syntax first. Tell them what the change does in plain words.',
			'diff_preview' => assistant_wrap('file_data', $diff),
		),
		'button' => array('type' => 'proposal', 'id' => $id, 'label' => ($old === '' ? 'Review new panel file ' : 'Review change to panel file ') . $rel),
	);
}

// Applies a panel file proposal. Called only after the caller checked the proposal is visible to the admin.
function assistant_admin_panel_apply($db, $p, $panel_root)
{
	$rel = substr((string)$p['target'], 6);
	$full = assistant_admin_panel_full($panel_root, $rel);
	if ($full === null || assistant_admin_panel_forbidden($rel) !== '') {
		return array('ok' => false, 'message' => 'That path is not allowed. Nothing was changed.');
	}
	$is_new = (string)$p['old_content'] === '';
	$exists = is_file($full);
	if ($is_new && $exists) {
		return array('ok' => false, 'message' => 'The file exists now. Nothing was changed. Ask the assistant again.');
	}
	if (!$is_new) {
		$current = $exists ? file_get_contents($full) : false;
		if ($current === false || sha1($current) !== sha1((string)$p['old_content'])) {
			return array('ok' => false, 'message' => 'The file changed since this was proposed. Nothing was changed. Ask the assistant again.');
		}
	}
	$new = (string)$p['new_content'];
	if (substr($rel, -4) === '.php') {
		$lint = assistant_admin_php_lint($new);
		if ($lint !== '') {
			return array('ok' => false, 'message' => $lint . ' Nothing was changed.');
		}
	}
	$backup = '';
	if (!$is_new) {
		$backup = $full . '.bak-' . date('Ymd-His');
		if (!copy($full, $backup)) {
			return array('ok' => false, 'message' => 'The backup copy could not be made. Nothing was changed.');
		}
	}
	if (file_put_contents($full, $new, LOCK_EX) !== strlen($new)) {
		if ($backup !== '') {
			copy($backup, $full);
		}
		return array('ok' => false, 'message' => 'The write failed. The file was put back from its backup where one was made.');
	}
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'applied', decided = NOW() WHERE id = " . (int)$p['id'] . " AND status = 'pending'");
	return array(
		'ok' => true,
		'message' => ($is_new ? 'Created ' : 'Changed ') . $rel . '.' . ($backup !== '' ? ' The old version is saved as ' . basename($backup) . '.' : ''),
		'undo' => !$is_new,
		'id' => (int)$p['id'],
	);
}

function assistant_admin_panel_undo($db, $p, $panel_root)
{
	if ($p['status'] !== 'applied' || (string)$p['old_content'] === '') {
		return array('ok' => false, 'message' => 'Only a change to an existing file that was applied can be undone.');
	}
	$rel = substr((string)$p['target'], 6);
	$full = assistant_admin_panel_full($panel_root, $rel);
	if ($full === null || !is_file($full)) {
		return array('ok' => false, 'message' => 'That path is not allowed.');
	}
	$current = file_get_contents($full);
	if ($current === false || sha1($current) !== sha1((string)$p['new_content'])) {
		return array('ok' => false, 'message' => 'The file has changed since. It was not touched.');
	}
	if (file_put_contents($full, (string)$p['old_content'], LOCK_EX) !== strlen((string)$p['old_content'])) {
		return array('ok' => false, 'message' => 'The machine refused the write. Nothing was changed.');
	}
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'undone', decided = NOW() WHERE id = " . (int)$p['id']);
	return array('ok' => true, 'message' => 'Put ' . $rel . ' back the way it was.');
}

// Entry points used by confirm.php and assistant_files.php for the two admin kinds.
function assistant_admin_fix_apply($db, $p, $panel_root)
{
	if ($p['status'] !== 'pending') {
		return array('ok' => false, 'message' => 'That change is not waiting any more (' . $p['status'] . ').');
	}
	if ($p['kind'] === 'sql') {
		return assistant_admin_sql_apply($db, $p);
	}
	return assistant_admin_panel_apply($db, $p, $panel_root);
}

function assistant_admin_fix_undo($db, $p, $panel_root)
{
	if ($p['kind'] !== 'panel_file') {
		return array('ok' => false, 'message' => 'That change cannot be undone. Run a corrective statement instead.');
	}
	return assistant_admin_panel_undo($db, $p, $panel_root);
}
?>
