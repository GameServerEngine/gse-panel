<?php
/*
 * File and database tools for one game server, and the proposals for changing its files.
 *
 * Reading: the assistant sees only files inside the server's own folder (the same jail the File
 * Manager uses). Secrets are blanked and the text is marked as data.
 * Changing: the assistant only PROPOSES. A proposal is saved with the exact old and new file, the
 * person sees the difference and presses Apply (confirm.php). Nothing is written before that.
 */

require_once(__DIR__ . '/assistant_lib.php');
require_once(__DIR__ . '/assistant_tools.php');
require_once(__DIR__ . '/assistant_diff.php');

if (!defined('ASSISTANT_FILE_READ_MAX'))  define('ASSISTANT_FILE_READ_MAX', 3145728); // biggest file it will fetch
if (!defined('ASSISTANT_FILE_EDIT_MAX'))  define('ASSISTANT_FILE_EDIT_MAX', 204800);  // biggest file it will edit or create
if (!defined('ASSISTANT_FILE_SHOW_CHARS')) define('ASSISTANT_FILE_SHOW_CHARS', 9000); // most text given to the model per read
if (!defined('ASSISTANT_LIST_MAX'))       define('ASSISTANT_LIST_MAX', 150);
if (!defined('ASSISTANT_PENDING_MAX'))    define('ASSISTANT_PENDING_MAX', 20);        // waiting proposals per server

// Opens the server's folder. Returns array(ctx, '') or array(null, 'reason').
// ctx = array('home' => ..., 'remote' => RemoteLibrary, 'root' => the folder the file manager is limited to)
function assistant_jail($db, $user_id, $home_id, $panel_root)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array(null, $why);
	}
	require_once($panel_root . '/includes/lib_remote.php');
	require_once($panel_root . '/includes/lifecycle_contract_helpers.php');
	require_once($panel_root . '/modules/config_games/server_config_parser.php');
	require_once($panel_root . '/includes/game_path_helpers.php');
	require_once($panel_root . '/modules/litefm/litefm.php');

	$error = '';
	$attached = litefm_attach_game_root($home, $error);
	if ($attached === false) {
		return array(null, $error !== '' ? $error : 'The server folder is not available.');
	}
	$remote = new RemoteLibrary($home['agent_ip'], $home['agent_port'], $home['encryption_key'], $home['timeout']);
	if ($remote->status_chk() !== 1) {
		return array(null, 'The machine this server runs on is not answering.');
	}
	return array(array('home' => $home, 'remote' => $remote, 'root' => litefm_payload_root($attached)), '');
}

// Relative path as the model gave it -> clean relative path and the full path on the machine.
// Returns array(rel, full, '') or array(null, null, 'reason').
function assistant_jail_path($ctx, $path)
{
	$rel = litefm_normalize_relative_path((string)$path);
	if ($rel === false || assistant_secret_filename($rel)) {
		return array(null, null, 'That path is not allowed.');
	}
	$full = litefm_safe_join_home_path($ctx['root'], $rel);
	if ($full === false) {
		return array(null, null, 'That path is outside the server folder.');
	}
	return array($rel, $full, '');
}

// Size of one file, or null when it is not there. Looks it up in its folder's listing.
function assistant_file_size($ctx, $full)
{
	$dir = dirname($full);
	$name = basename($full);
	$list = $ctx['remote']->remote_dirlistfm($dir);
	if (!is_array($list)) {
		return null;
	}
	if (isset($list['files']) && is_array($list['files'])) {
		foreach ($list['files'] as $f) {
			if (isset($f['filename']) && $f['filename'] === $name) {
				return (int)$f['size'];
			}
		}
	}
	return null;
}

function assistant_tool_list_files($db, $user_id, $home_id, $path, $panel_root)
{
	list($ctx, $why) = assistant_jail($db, $user_id, $home_id, $panel_root);
	if ($ctx === null) {
		return array('data' => array('error' => $why));
	}
	list($rel, $full, $why) = assistant_jail_path($ctx, $path);
	if ($rel === null) {
		return array('data' => array('error' => $why));
	}
	$list = $ctx['remote']->remote_dirlistfm($full);
	if (!is_array($list)) {
		return array('data' => array('error' => 'That folder could not be opened.'));
	}
	$dirs = array();
	$files = array();
	foreach ((isset($list['directorys']) ? (array)$list['directorys'] : array()) as $d) {
		if (isset($d['filename']) && !assistant_secret_filename($rel . '/' . $d['filename'])) {
			$dirs[] = $d['filename'] . '/';
		}
	}
	foreach ((isset($list['files']) ? (array)$list['files'] : array()) as $f) {
		if (isset($f['filename']) && !assistant_secret_filename($rel . '/' . $f['filename'])) {
			$files[] = $f['filename'] . ' (' . (int)$f['size'] . ' bytes)';
		}
	}
	sort($dirs);
	sort($files);
	$all = array_merge($dirs, $files);
	$more = count($all) > ASSISTANT_LIST_MAX;
	return array('data' => array(
		'folder' => $rel === '' ? '(server folder)' : $rel,
		'entries' => assistant_wrap('file_data', implode("\n", array_slice($all, 0, ASSISTANT_LIST_MAX))),
		'more_not_shown' => $more,
	));
}

// Keeps the parts of $text the question needs. Returns the text for the model.
function assistant_pick_lines($text, $filter, $pattern, $from, $lines)
{
	$lines = max(5, min(200, (int)$lines));
	if ($pattern !== '') {
		$kept = array();
		foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $n => $line) {
			if (stripos($line, $pattern) !== false) {
				$kept[] = ($n + 1) . ': ' . substr($line, 0, ASSISTANT_LOG_LINE_CHARS);
			}
		}
		$kept = $from === 'start' ? array_slice($kept, 0, $lines) : array_slice($kept, -$lines);
		$result = implode("\n", $kept);
	} elseif ($filter !== '' && $filter !== 'recent') {
		$result = assistant_filter_log($text, $filter);
	} else {
		$all = array();
		foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
			if (trim($line) !== '') {
				$all[] = substr(preg_replace('/[^\x09\x20-\x7E\x80-\xFF]/', '', $line), 0, ASSISTANT_LOG_LINE_CHARS);
			}
		}
		$result = implode("\n", $from === 'start' ? array_slice($all, 0, $lines) : array_slice($all, -$lines));
	}
	if (strlen($result) > ASSISTANT_FILE_SHOW_CHARS) {
		$result = $from === 'start' ? substr($result, 0, ASSISTANT_FILE_SHOW_CHARS) : substr($result, -ASSISTANT_FILE_SHOW_CHARS);
	}
	return $result === '' ? '(nothing matched)' : $result;
}

function assistant_tool_read_file($db, $user_id, $home_id, $input, $panel_root)
{
	list($ctx, $why) = assistant_jail($db, $user_id, $home_id, $panel_root);
	if ($ctx === null) {
		return array('data' => array('error' => $why));
	}
	list($rel, $full, $why) = assistant_jail_path($ctx, isset($input['path']) ? $input['path'] : '');
	if ($rel === null || $rel === '') {
		return array('data' => array('error' => $rel === '' ? 'Give the path of a file.' : $why));
	}
	$size = assistant_file_size($ctx, $full);
	if ($size === null) {
		return array('data' => array('error' => 'No such file. Use list_files to see the folder.'));
	}
	if ($size > ASSISTANT_FILE_READ_MAX) {
		return array('data' => array('error' => 'That file is too big to read here (' . $size . ' bytes). Use server_log for the main log.'));
	}
	$data = '';
	$status = $ctx['remote']->remote_readfile($full, $data);
	if ($status !== 1) {
		return array('data' => array('error' => 'The file could not be read.'));
	}
	if (assistant_looks_binary($data)) {
		return array('data' => array('error' => 'That is a binary file, not text.'));
	}
	$filter = isset($input['filter']) ? (string)$input['filter'] : '';
	$pattern = isset($input['contains']) ? substr((string)$input['contains'], 0, 100) : '';
	$from = isset($input['from']) && $input['from'] === 'start' ? 'start' : 'end';
	$picked = assistant_pick_lines($data, $filter, $pattern, $from, isset($input['lines']) ? $input['lines'] : 80);
	return array('data' => array(
		'path' => $rel,
		'size_bytes' => $size,
		'content' => assistant_wrap('file_data', assistant_redact($picked)),
		'note' => 'Passwords and keys are blanked in what you see. When you propose an edit, old_text must match the real file, so choose lines that contain no [hidden] value.',
	));
}

// ---------------------------------------------------------------------------------------------
// Proposals

function assistant_expire_proposals($db)
{
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'expired' WHERE status = 'pending' AND created < (NOW() - INTERVAL 7 DAY)");
}

// Saves a proposal and returns its id (0 on failure).
function assistant_save_proposal($db, $user_id, $home_id, $kind, $target, $old, $new, $note)
{
	assistant_expire_proposals($db);
	// File text goes in as hex so any byte (and any connection character set) is stored exactly.
	$hex = function ($data) {
		return $data === '' ? "''" : '0x' . bin2hex($data);
	};
	$db->query('INSERT INTO ' . assistant_table('proposals') . ' (user_id, home_id, kind, target, old_content, new_content, note, status, created) VALUES ('
		. (int)$user_id . ', ' . (int)$home_id . ", '" . $db->real_escape_string($kind) . "', '" . $db->real_escape_string($target) . "', "
		. $hex($old) . ', ' . $hex($new) . ", '" . $db->real_escape_string(substr(preg_replace('/[^\x20-\x7E]/', ' ', (string)$note), 0, 300)) . "', 'pending', NOW())");
	$rows = $db->resultQuery('SELECT LAST_INSERT_ID() AS id');
	return isset($rows[0]['id']) ? (int)$rows[0]['id'] : 0;
}

function assistant_pending_count($db, $home_id)
{
	$rows = $db->resultQuery('SELECT COUNT(*) AS n FROM ' . assistant_table('proposals') . ' WHERE home_id = ' . (int)$home_id . " AND status = 'pending'");
	return isset($rows[0]['n']) ? (int)$rows[0]['n'] : 0;
}

function assistant_load_proposal($db, $id)
{
	$rows = $db->resultQuery('SELECT * FROM ' . assistant_table('proposals') . ' WHERE id = ' . (int)$id);
	return isset($rows[0]) ? $rows[0] : null;
}

// Applies edits to the real file text. Each old_text must be found exactly once.
// Returns array(new_text, '') or array(null, 'reason').
function assistant_apply_edits($current, $edits)
{
	if (!is_array($edits) || count($edits) < 1 || count($edits) > 10) {
		return array(null, 'Give between 1 and 10 edits.');
	}
	$text = (string)$current;
	foreach ($edits as $i => $edit) {
		$old = is_array($edit) && isset($edit['old_text']) ? (string)$edit['old_text'] : '';
		$new = is_array($edit) && isset($edit['new_text']) ? (string)$edit['new_text'] : '';
		if ($old === '') {
			return array(null, 'Edit ' . ($i + 1) . ' has an empty old_text.');
		}
		$found = substr_count($text, $old);
		if ($found !== 1) {
			return array(null, 'Edit ' . ($i + 1) . ': old_text was found ' . $found . ' times in the file. It must match exactly once (include more lines around it). Lines with [hidden] values will not match.');
		}
		$text = str_replace($old, $new, $text);
	}
	return array($text, '');
}

// The model's request to change or create one file in the server's folder.
function assistant_tool_propose_file_edit($db, $user_id, $home_id, $input, $panel_root)
{
	list($ctx, $why) = assistant_jail($db, $user_id, $home_id, $panel_root);
	if ($ctx === null) {
		return array('data' => array('error' => $why));
	}
	list($rel, $full, $why) = assistant_jail_path($ctx, isset($input['path']) ? $input['path'] : '');
	if ($rel === null || $rel === '') {
		return array('data' => array('error' => $rel === '' ? 'Give the path of the file.' : $why));
	}
	if (assistant_binary_extension($rel)) {
		return array('data' => array('error' => 'Only text files can be changed.'));
	}
	if (assistant_pending_count($db, (int)$ctx['home']['home_id']) >= ASSISTANT_PENDING_MAX) {
		return array('data' => array('error' => 'There are already ' . ASSISTANT_PENDING_MAX . ' changes waiting for review on this server. Ask the customer to apply or discard some first.'));
	}
	$note = isset($input['summary']) ? (string)$input['summary'] : '';
	$size = assistant_file_size($ctx, $full);
	$has_content = isset($input['content']) && is_string($input['content']);

	if ($size === null) {
		// A new file: the full text comes from the model.
		if (!$has_content || $input['content'] === '') {
			return array('data' => array('error' => 'That file does not exist. To create it, give its full text in content.'));
		}
		$old = '';
		$new = $input['content'];
		if (assistant_looks_binary($new)) {
			return array('data' => array('error' => 'Only text files can be created.'));
		}
	} else {
		if ($has_content) {
			return array('data' => array('error' => 'That file exists. Change it with edits (old_text and new_text), not content.'));
		}
		if ($size > ASSISTANT_FILE_EDIT_MAX) {
			return array('data' => array('error' => 'That file is too big to edit here.'));
		}
		$old = '';
		if ($ctx['remote']->remote_readfile($full, $old) !== 1) {
			return array('data' => array('error' => 'The file could not be read.'));
		}
		if (assistant_looks_binary($old)) {
			return array('data' => array('error' => 'That is a binary file.'));
		}
		list($new, $why) = assistant_apply_edits($old, isset($input['edits']) ? $input['edits'] : array());
		if ($new === null) {
			return array('data' => array('error' => $why));
		}
	}
	if (strlen($new) > ASSISTANT_FILE_EDIT_MAX) {
		return array('data' => array('error' => 'The result would be too big.'));
	}
	if ($new === $old) {
		return array('data' => array('error' => 'That change would not alter the file.'));
	}
	$id = assistant_save_proposal($db, $user_id, (int)$ctx['home']['home_id'], 'file', $rel, $old, $new, $note);
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
			'note' => 'Saved as a proposal. The customer now sees a "Review change" button and must press Apply. Nothing has been written yet. Tell them what the change does in plain words.',
			'diff_preview' => assistant_wrap('file_data', $diff),
		),
		'button' => array('type' => 'proposal', 'id' => $id, 'label' => ($size === null ? 'Review new file ' : 'Review change to ') . $rel),
	);
}

// What the person sees before pressing Apply: the real change, not redacted (it is their own file).
function assistant_proposal_view($db, $user_id, $scope, $id)
{
	$p = assistant_load_proposal($db, $id);
	if ($p === null || !assistant_proposal_visible($db, $user_id, $scope, $p)) {
		return array('ok' => false, 'message' => 'That change was not found.');
	}
	$diff = assistant_unified_diff((string)$p['old_content'], (string)$p['new_content'], $p['target']);
	if (strlen($diff) > 40000) {
		$diff = substr($diff, 0, 40000) . "\n(more not shown)";
	}
	return array('ok' => true, 'id' => (int)$p['id'], 'target' => $p['target'], 'status' => $p['status'], 'is_new' => (string)$p['old_content'] === '',
		'note' => (string)$p['note'], 'diff' => $diff);
}

// A proposal can be seen by the server's owner (via the chat's scope) and by administrators.
function assistant_proposal_visible($db, $user_id, $scope, $p)
{
	if ($p['kind'] !== 'file') {
		return $scope['type'] === 'admin' && $db->isAdmin($user_id);
	}
	if ($scope['type'] === 'server') {
		return (int)$p['home_id'] === (int)$scope['home_id'];
	}
	return $db->isAdmin($user_id);
}

// Writes the new file. Called only from confirm.php after the person pressed Apply.
function assistant_proposal_apply($db, $user_id, $scope, $id, $panel_root)
{
	$p = assistant_load_proposal($db, $id);
	if ($p !== null && in_array($p['kind'], array('sql', 'panel_file'), true)) {
		if (!assistant_proposal_visible($db, $user_id, $scope, $p)) {
			return array('ok' => false, 'message' => 'That change was not found.');
		}
		return assistant_admin_fix_apply($db, $p, $panel_root);
	}
	if ($p === null || $p['kind'] !== 'file' || !assistant_proposal_visible($db, $user_id, $scope, $p)) {
		return array('ok' => false, 'message' => 'That change was not found.');
	}
	if ($p['status'] !== 'pending') {
		return array('ok' => false, 'message' => 'That change is not waiting any more (' . $p['status'] . ').');
	}
	list($ctx, $why) = assistant_jail($db, $user_id, (int)$p['home_id'], $panel_root);
	if ($ctx === null) {
		return array('ok' => false, 'message' => $why);
	}
	list($rel, $full, $why) = assistant_jail_path($ctx, $p['target']);
	if ($rel === null || $rel === '' || assistant_binary_extension($rel)) {
		return array('ok' => false, 'message' => 'That path is not allowed.');
	}
	$size = assistant_file_size($ctx, $full);
	$is_new = (string)$p['old_content'] === '';
	if ($is_new && $size !== null) {
		return array('ok' => false, 'message' => 'The file exists now. Nothing was changed. Ask the assistant again.');
	}
	if (!$is_new) {
		$current = '';
		if ($size === null || $ctx['remote']->remote_readfile($full, $current) !== 1) {
			return array('ok' => false, 'message' => 'The file could not be read. Nothing was changed.');
		}
		if (sha1($current) !== sha1((string)$p['old_content'])) {
			return array('ok' => false, 'message' => 'The file changed since this was proposed. Nothing was changed. Ask the assistant again.');
		}
	}
	if ($ctx['remote']->remote_writefile($full, (string)$p['new_content']) !== 1) {
		return array('ok' => false, 'message' => 'The machine refused the write. Nothing was changed.');
	}
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'applied', decided = NOW() WHERE id = " . (int)$p['id'] . " AND status = 'pending'");
	return array('ok' => true, 'message' => ($is_new ? 'Created ' : 'Changed ') . $rel . '.' . ($is_new ? '' : ' You can undo it with the Undo button.'), 'undo' => !$is_new, 'id' => (int)$p['id']);
}

function assistant_proposal_discard($db, $user_id, $scope, $id)
{
	$p = assistant_load_proposal($db, $id);
	if ($p === null || !assistant_proposal_visible($db, $user_id, $scope, $p) || $p['status'] !== 'pending') {
		return array('ok' => false, 'message' => 'That change is not waiting.');
	}
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'discarded', decided = NOW() WHERE id = " . (int)$p['id'] . " AND status = 'pending'");
	return array('ok' => true, 'message' => 'Discarded. Nothing was changed.');
}

// Puts the old text back, but only if the file is still exactly what Apply wrote.
function assistant_proposal_undo($db, $user_id, $scope, $id, $panel_root)
{
	$p = assistant_load_proposal($db, $id);
	if ($p !== null && in_array($p['kind'], array('sql', 'panel_file'), true)) {
		if (!assistant_proposal_visible($db, $user_id, $scope, $p)) {
			return array('ok' => false, 'message' => 'That change was not found.');
		}
		return assistant_admin_fix_undo($db, $p, $panel_root);
	}
	if ($p === null || $p['kind'] !== 'file' || !assistant_proposal_visible($db, $user_id, $scope, $p)) {
		return array('ok' => false, 'message' => 'That change was not found.');
	}
	if ($p['status'] !== 'applied' || (string)$p['old_content'] === '') {
		return array('ok' => false, 'message' => 'Only a change to an existing file that was applied can be undone.');
	}
	list($ctx, $why) = assistant_jail($db, $user_id, (int)$p['home_id'], $panel_root);
	if ($ctx === null) {
		return array('ok' => false, 'message' => $why);
	}
	list($rel, $full, $why) = assistant_jail_path($ctx, $p['target']);
	if ($rel === null || $rel === '') {
		return array('ok' => false, 'message' => 'That path is not allowed.');
	}
	$current = '';
	if ($ctx['remote']->remote_readfile($full, $current) !== 1 || sha1($current) !== sha1((string)$p['new_content'])) {
		return array('ok' => false, 'message' => 'The file has changed since. It was not touched.');
	}
	if ($ctx['remote']->remote_writefile($full, (string)$p['old_content']) !== 1) {
		return array('ok' => false, 'message' => 'The machine refused the write. Nothing was changed.');
	}
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'undone', decided = NOW() WHERE id = " . (int)$p['id']);
	return array('ok' => true, 'message' => 'Put ' . $rel . ' back the way it was.');
}

// ---------------------------------------------------------------------------------------------
// Read-only database queries

// Returns '' when the statement is acceptable, otherwise the reason.
function assistant_sql_problem($sql)
{
	$sql = trim((string)$sql);
	if ($sql === '' || strlen($sql) > 2000) {
		return 'Give one short SELECT, SHOW, DESCRIBE or EXPLAIN statement.';
	}
	if (preg_match('/(--|#|\/\*|\*\/|`\s*;)/', $sql)) {
		return 'Comments are not allowed.';
	}
	$sql = rtrim($sql, "; \t\r\n");
	if (strpos($sql, ';') !== false) {
		return 'Only one statement.';
	}
	if (!preg_match('/^\s*(select|show|describe|desc|explain)\b/i', $sql)) {
		return 'Only SELECT, SHOW, DESCRIBE and EXPLAIN are allowed.';
	}
	if (preg_match('/\b(into|load_file|sleep|benchmark|get_lock|release_lock|for\s+update|lock\s+in\s+share|handler|call|set|do|outfile|dumpfile)\b/i', $sql)) {
		return 'That statement uses something that is not allowed.';
	}
	return '';
}

function assistant_sensitive_column($name)
{
	return (bool)preg_match('/pass|pwd|hash|secret|token|salt|api_?key|private/i', (string)$name);
}

function assistant_tool_db_query($db, $user_id, $home_id, $input, $panel_root)
{
	require_once(__DIR__ . '/assistant_manage.php');
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	$problem = assistant_sql_problem(isset($input['sql']) ? $input['sql'] : '');
	if ($problem !== '') {
		return array('data' => array('error' => $problem));
	}
	$sql = rtrim(trim($input['sql']), "; \t\r\n");
	$databases = assistant_home_databases($db, $home['home_id']);
	if (!$databases) {
		return array('data' => array('error' => 'This server has no database.'));
	}
	$wanted = isset($input['db_id']) ? (int)$input['db_id'] : 0;
	$row = null;
	foreach ($databases as $candidate) {
		if ($wanted === (int)$candidate['db_id'] || ($wanted === 0 && count($databases) === 1)) {
			$row = $candidate;
		}
	}
	if ($row === null) {
		return array('data' => array('error' => 'Say which database with db_id.', 'databases' => array_map(function ($d) {
			return array('db_id' => (int)$d['db_id'], 'database' => $d['db_name']);
		}, $databases)));
	}
	// The server's own database login, so it can only see what that login can see.
	$link = assistant_mysql_connect($row['mysql_ip'], $row['db_user'], $row['db_passwd'], $row['db_name'], $row['mysql_port']);
	if ($link === false) {
		return array('data' => array('error' => 'The panel could not log in to the database.'));
	}
	mysqli_options($link, MYSQLI_OPT_READ_TIMEOUT, 10);
	@mysqli_query($link, 'SET SESSION TRANSACTION READ ONLY');
	@mysqli_query($link, 'SET SESSION max_execution_time = 5000');
	$res = @mysqli_query($link, $sql);
	if (!$res || !($res instanceof mysqli_result)) {
		$message = $res ? 'No rows.' : 'The database said: ' . substr((string)mysqli_error($link), 0, 200);
		mysqli_close($link);
		return array('data' => array('error' => $message));
	}
	$fields = array();
	$hide = array();
	foreach (mysqli_fetch_fields($res) as $i => $f) {
		$fields[] = $f->name;
		$hide[$i] = assistant_sensitive_column($f->name);
	}
	$lines = array(implode("\t", $fields));
	$count = 0;
	$total = mysqli_num_rows($res);
	while ($count < 30 && ($r = mysqli_fetch_row($res))) {
		$cells = array();
		foreach ($r as $i => $cell) {
			$cells[] = $hide[$i] ? '[hidden]' : str_replace(array("\t", "\r", "\n"), ' ', substr((string)$cell, 0, 150));
		}
		$lines[] = implode("\t", $cells);
		$count++;
	}
	mysqli_free_result($res);
	mysqli_close($link);
	$text = implode("\n", $lines);
	if (strlen($text) > ASSISTANT_FILE_SHOW_CHARS) {
		$text = substr($text, 0, ASSISTANT_FILE_SHOW_CHARS);
	}
	return array('data' => array('database' => $row['db_name'], 'rows_total' => $total, 'rows_shown' => $count, 'result' => assistant_wrap('db_data', $text)));
}
?>
