<?php
/*
 * The Admin assistant is the support team's tool: it reads anything in the GSE ecosystem (panel files,
 * agent machines, the panel database and every MySQL server) and proposes the fix. A fix is a CHANGE
 * SET: any number of steps, each one a file change on the panel or on an agent, an SQL statement on
 * the panel database or a MySQL server, or a command on the panel or an agent. There are no limits
 * on what a change set may touch. The administrator sees the whole thing as a diff and presses
 * Apply; the steps then run in order and stop at the first one that fails. File changes are
 * remembered so the whole set can be undone. PHP files must pass php -l before they are written
 * (a broken panel file would take the panel down, and then nothing could be repaired).
 */

require_once(__DIR__ . '/assistant_files.php');
require_once(__DIR__ . '/assistant_diff.php');
require_once(__DIR__ . '/assistant_admin.php');
require_once(__DIR__ . '/assistant_admin_files.php');

if (!defined('ASSISTANT_CHANGESET_MAX_STEPS')) define('ASSISTANT_CHANGESET_MAX_STEPS', 40);
if (!defined('ASSISTANT_CHANGESET_MAX_BYTES')) define('ASSISTANT_CHANGESET_MAX_BYTES', 4194304);

// ---------------------------------------------------------------------------------------------
// Databases

// A target is 'panel' or 'mysql:<mysql_server_id>' from the panel's MySQL server list.
function assistant_admin_sql_target($input)
{
	$which = isset($input['database']) ? (string)$input['database'] : '';
	if ($which === 'panel') {
		return 'panel';
	}
	if ($which === 'mysql') {
		return 'mysql:' . (int)(isset($input['mysql_server_id']) ? $input['mysql_server_id'] : 0);
	}
	return null;
}

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

// Read a database as the administrator: any SELECT, SHOW, DESCRIBE or EXPLAIN, on the panel database or any
// MySQL server (use schema.table to reach other databases there). Up to 100 rows.
function assistant_tool_db_read($db, $input)
{
	$target = assistant_admin_sql_target($input);
	if ($target === null || !assistant_admin_sql_target_ok($db, $target)) {
		return array('data' => array('error' => 'database must be panel, or mysql with a mysql_server_id from mysql_health.'));
	}
	$sql = trim((string)(isset($input['sql']) ? $input['sql'] : ''));
	if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
		return array('data' => array('error' => 'db_read runs SELECT, SHOW, DESCRIBE or EXPLAIN. Changes go in propose_changes.'));
	}
	$link = assistant_admin_sql_connect($db, $target);
	if (!$link) {
		return array('data' => array('error' => 'Could not log in to that database server.'));
	}
	mysqli_report(MYSQLI_REPORT_OFF);
	$result = mysqli_query($link, $sql);
	if ($result === false) {
		$error = mysqli_error($link);
		mysqli_close($link);
		return array('data' => array('error' => 'MySQL said: ' . $error));
	}
	$lines = array();
	$count = 0;
	if ($result instanceof mysqli_result) {
		while (($row = mysqli_fetch_assoc($result)) !== null) {
			if (++$count > 100) {
				break;
			}
			$lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		mysqli_free_result($result);
	}
	mysqli_close($link);
	$text = implode("\n", $lines);
	if (strlen($text) > 14000) {
		$text = substr($text, 0, 14000) . "\n(more not shown)";
	}
	return array('data' => array('rows_shown' => min($count, 100), 'more_rows' => $count > 100, 'rows' => assistant_wrap('db_data', $text === '' ? '(no rows)' : $text)));
}

// ---------------------------------------------------------------------------------------------
// Where a file lives

// Absolute path for a panel file; a relative one is inside the panel folder. Returns the path or null.
function assistant_admin_panel_full($panel_root, $path)
{
	$path = trim((string)$path);
	$clean = assistant_clean_rel($path);
	if ($path === '' || $clean === false) {
		return null;
	}
	return (isset($path[0]) && $path[0] === '/') ? '/' . $clean : rtrim(realpath($panel_root), '/') . '/' . $clean;
}

// Runs php -l on the text. Returns '' when it passes, otherwise the reason.
function assistant_admin_php_lint($source)
{
	$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
	if (!function_exists('exec') || in_array('exec', $disabled, true)) {
		return '';
	}
	$tmp = tempnam(sys_get_temp_dir(), 'gselint');
	if ($tmp === false) {
		return '';
	}
	file_put_contents($tmp, (string)$source);
	$bin = (defined('PHP_BINARY') && basename(PHP_BINARY) === 'php') ? PHP_BINARY : 'php';
	$out = array();
	$status = 1;
	exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
	unlink($tmp);
	return $status !== 0 ? 'PHP syntax check failed: ' . substr(implode(' ', $out), 0, 300) : '';
}

// Read a file for a step. Returns array(exists, text, error).
function assistant_admin_step_read($db, $panel_root, $where, $server_id, $path)
{
	if ($where === 'panel') {
		$full = assistant_admin_panel_full($panel_root, $path);
		if ($full === null) {
			return array(false, '', 'That path is not valid.');
		}
		if (is_dir($full)) {
			return array(false, '', 'That is a folder.');
		}
		if (!is_file($full)) {
			return array(false, '', '');
		}
		if (filesize($full) > ASSISTANT_FILE_EDIT_MAX) {
			return array(true, '', 'That file is too big to edit here.');
		}
		return array(true, (string)file_get_contents($full), '');
	}
	list($clean, $why) = assistant_agent_path(null, $path);
	if ($clean === null) {
		return array(false, '', $why);
	}
	list($remote, $why) = assistant_agent_open($db, $panel_root, $server_id);
	if ($remote === null) {
		return array(false, '', $why);
	}
	$size = assistant_file_size(array('remote' => $remote), $clean);
	if ($size === null) {
		return array(false, '', '');
	}
	if ($size > ASSISTANT_FILE_EDIT_MAX) {
		return array(true, '', 'That file is too big to edit here.');
	}
	$text = '';
	if ($remote->remote_readfile($clean, $text) !== 1) {
		return array(true, '', 'The file could not be read.');
	}
	return array(true, $text, '');
}

// ---------------------------------------------------------------------------------------------
// Building a change set

function assistant_changeset_build($db, $panel_root, $steps)
{
	if (!is_array($steps) || count($steps) < 1) {
		return array(null, 'Give at least one step.');
	}
	if (count($steps) > ASSISTANT_CHANGESET_MAX_STEPS) {
		return array(null, 'At most ' . ASSISTANT_CHANGESET_MAX_STEPS . ' steps in one change set. Split it.');
	}
	$built = array();
	$bytes = 0;
	foreach (array_values($steps) as $i => $s) {
		$n = $i + 1;
		$kind = is_array($s) && isset($s['kind']) ? (string)$s['kind'] : '';
		$where = isset($s['where']) ? (string)$s['where'] : 'panel';
		$server_id = isset($s['server_id']) ? (int)$s['server_id'] : 0;
		if ($kind === 'file') {
			$path = isset($s['path']) ? trim((string)$s['path']) : '';
			if ($path === '' || !in_array($where, array('panel', 'agent'), true)) {
				return array(null, 'Step ' . $n . ': a file step needs where (panel or agent) and a path.');
			}
			list($exists, $old, $error) = assistant_admin_step_read($db, $panel_root, $where, $server_id, $path);
			if ($error !== '') {
				return array(null, 'Step ' . $n . ': ' . $error);
			}
			if ($exists && assistant_looks_binary($old)) {
				return array(null, 'Step ' . $n . ': that is a binary file.');
			}
			$has_content = isset($s['content']) && is_string($s['content']);
			if ($has_content) {
				$new = $s['content'];
			} elseif ($exists) {
				list($new, $why) = assistant_apply_edits($old, isset($s['edits']) ? $s['edits'] : array());
				if ($new === null) {
					return array(null, 'Step ' . $n . ': ' . $why);
				}
			} else {
				return array(null, 'Step ' . $n . ': that file does not exist. To create it, give its full text in content.');
			}
			if (assistant_looks_binary($new)) {
				return array(null, 'Step ' . $n . ': only text can be written.');
			}
			if ($new === $old && $exists) {
				return array(null, 'Step ' . $n . ': that would not change the file.');
			}
			$bytes += strlen($new) + strlen($old);
			$built[] = array('kind' => 'file', 'where' => $where, 'server_id' => $server_id,
				'path' => $where === 'panel' ? assistant_admin_panel_full($panel_root, $path) : $path,
				'exists' => $exists, 'old' => base64_encode($old), 'new' => base64_encode($new));
		} elseif ($kind === 'sql') {
			$target = assistant_admin_sql_target($s);
			$sql = isset($s['sql']) ? rtrim(trim((string)$s['sql']), ';') : '';
			if ($target === null || !assistant_admin_sql_target_ok($db, $target) || $sql === '') {
				return array(null, 'Step ' . $n . ': an SQL step needs database (panel or mysql), the mysql_server_id from mysql_health when it is mysql, and one statement in sql.');
			}
			$bytes += strlen($sql);
			$built[] = array('kind' => 'sql', 'target' => $target, 'sql' => $sql);
		} elseif ($kind === 'command') {
			$command = isset($s['command']) ? trim((string)$s['command']) : '';
			if ($command === '' || !in_array($where, array('panel', 'agent'), true) || ($where === 'agent' && $server_id < 1)) {
				return array(null, 'Step ' . $n . ': a command step needs where (panel or agent), a server_id for an agent, and the command.');
			}
			$bytes += strlen($command);
			$built[] = array('kind' => 'command', 'where' => $where, 'server_id' => $server_id, 'command' => $command);
		} else {
			return array(null, 'Step ' . $n . ': kind must be file, sql or command.');
		}
	}
	if ($bytes > ASSISTANT_CHANGESET_MAX_BYTES) {
		return array(null, 'The change set is too big. Split it.');
	}
	return array($built, '');
}

function assistant_changeset_where($step)
{
	if ($step['kind'] === 'sql') {
		return $step['target'] === 'panel' ? 'the panel database' : 'MySQL server ' . (int)substr($step['target'], 6);
	}
	return $step['where'] === 'panel' ? 'the panel' : 'agent ' . (int)$step['server_id'];
}

// The text the administrator reviews: file changes as diffs, SQL and commands as written.
function assistant_changeset_text($p)
{
	$steps = json_decode((string)$p['new_content'], true);
	if (!is_array($steps)) {
		return '(unreadable change set)';
	}
	$out = '';
	foreach ($steps as $i => $s) {
		$out .= '=== Step ' . ($i + 1) . ': ';
		if ($s['kind'] === 'file') {
			$out .= ($s['exists'] ? 'change ' : 'create ') . $s['path'] . ' on ' . assistant_changeset_where($s) . "\n";
			$out .= assistant_unified_diff(base64_decode($s['old']), base64_decode($s['new']), $s['path']) . "\n";
		} elseif ($s['kind'] === 'sql') {
			$out .= 'run SQL on ' . assistant_changeset_where($s) . "\n" . $s['sql'] . "\n\n";
		} else {
			$out .= 'run a command on ' . assistant_changeset_where($s) . "\n$ " . $s['command'] . "\n\n";
		}
	}
	return $out;
}

function assistant_tool_propose_changes($db, $user_id, $panel_root, $input)
{
	assistant_expire_proposals($db);
	list($steps, $why) = assistant_changeset_build($db, $panel_root, isset($input['steps']) ? $input['steps'] : array());
	if ($steps === null) {
		return array('data' => array('error' => $why));
	}
	$json = json_encode($steps);
	$note = isset($input['summary']) ? (string)$input['summary'] : '';
	$id = assistant_save_proposal($db, $user_id, 0, 'changeset', count($steps) . ' step(s)', '', $json, $note);
	if ($id < 1) {
		return array('data' => array('error' => 'The change set could not be saved.'));
	}
	$text = assistant_changeset_text(array('new_content' => $json));
	if (strlen($text) > 6000) {
		$text = substr($text, 0, 6000) . "\n(more not shown)";
	}
	return array(
		'data' => array(
			'ok' => true,
			'note' => 'Saved as change set #' . $id . ' with ' . count($steps) . ' step(s). NOTHING has run yet. The administrator reviews the diff and presses Apply; the steps then run in order and stop at the first failure. Explain in plain words what each step does and why.',
			'preview' => assistant_wrap('file_data', $text),
		),
		'button' => array('type' => 'proposal', 'id' => $id, 'label' => 'Review change set #' . $id . ' (' . count($steps) . ' step' . (count($steps) === 1 ? '' : 's') . ')'),
	);
}

// ---------------------------------------------------------------------------------------------
// Applying and undoing

function assistant_changeset_write($db, $panel_root, $step, $text)
{
	if ($step['where'] === 'panel') {
		$dir = dirname($step['path']);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
			return 'could not create the folder ' . $dir;
		}
		return file_put_contents($step['path'], $text, LOCK_EX) === strlen($text) ? '' : 'the write failed';
	}
	list($remote, $why) = assistant_agent_open($db, $panel_root, $step['server_id']);
	if ($remote === null) {
		return $why;
	}
	return $remote->remote_writefile($step['path'], $text) === 1 ? '' : 'the agent refused the write';
}

// Runs one step. Returns array(ok, message).
function assistant_changeset_run_step($db, $panel_root, $step)
{
	if ($step['kind'] === 'file') {
		$old = base64_decode($step['old']);
		$new = base64_decode($step['new']);
		list($exists, $current, $error) = assistant_admin_step_read($db, $panel_root, $step['where'], $step['server_id'], $step['path']);
		if ($error !== '') {
			return array(false, $error);
		}
		if ($step['exists'] ? (!$exists || sha1($current) !== sha1($old)) : $exists) {
			return array(false, $step['path'] . ' changed since this was proposed. Ask the assistant again.');
		}
		if (substr($step['path'], -4) === '.php' && ($lint = assistant_admin_php_lint($new)) !== '') {
			return array(false, $step['path'] . ': ' . $lint);
		}
		$error = assistant_changeset_write($db, $panel_root, $step, $new);
		return $error === '' ? array(true, ($step['exists'] ? 'changed ' : 'created ') . $step['path']) : array(false, $step['path'] . ': ' . $error);
	}
	if ($step['kind'] === 'sql') {
		if (!assistant_admin_sql_target_ok($db, $step['target'])) {
			return array(false, 'that MySQL server is not in the panel any more');
		}
		$link = assistant_admin_sql_connect($db, $step['target']);
		if (!$link) {
			return array(false, 'could not log in to ' . assistant_changeset_where($step));
		}
		mysqli_report(MYSQLI_REPORT_OFF);
		if (mysqli_query($link, $step['sql']) === false) {
			$error = mysqli_error($link);
			mysqli_close($link);
			return array(false, 'MySQL refused the statement: ' . $error);
		}
		$rows = (int)mysqli_affected_rows($link);
		mysqli_close($link);
		return array(true, 'ran SQL on ' . assistant_changeset_where($step) . ' (rows changed: ' . $rows . ')');
	}
	if ($step['where'] === 'panel') {
		$out = array();
		$status = 1;
		@exec('timeout 120 bash -c ' . escapeshellarg($step['command']) . ' 2>&1', $out, $status);
		return array($status === 0, 'command on the panel exited ' . $status . ': ' . substr(implode("\n", $out), -1500));
	}
	list($remote, $why) = assistant_agent_open($db, $panel_root, $step['server_id']);
	if ($remote === null) {
		return array(false, $why);
	}
	$result = $remote->exec($step['command']);
	return array(true, 'command on agent ' . (int)$step['server_id'] . ': ' . substr(is_array($result) ? json_encode($result) : (string)$result, -1500));
}

function assistant_admin_fix_apply($db, $p, $panel_root)
{
	if ($p['status'] !== 'pending') {
		return array('ok' => false, 'message' => 'That change set is not waiting any more (' . $p['status'] . ').');
	}
	$steps = json_decode((string)$p['new_content'], true);
	if (!is_array($steps)) {
		return array('ok' => false, 'message' => 'The change set is unreadable. Nothing was changed.');
	}
	set_time_limit(0);
	$lines = array();
	$done = 0;
	$failed = false;
	foreach ($steps as $i => $step) {
		list($ok, $message) = assistant_changeset_run_step($db, $panel_root, $step);
		if (!$ok) {
			$lines[] = 'Step ' . ($i + 1) . ' FAILED: ' . $message . '. The steps after it did not run.';
			$failed = true;
			break;
		}
		$steps[$i]['applied'] = true;
		$done++;
		$lines[] = 'Step ' . ($i + 1) . ': ' . $message;
	}
	if ($done > 0) {
		$db->query('UPDATE ' . assistant_table('proposals') . ' SET new_content = 0x' . bin2hex(json_encode($steps)) . ", status = '" . ($failed ? 'partial' : 'applied') . "', decided = NOW() WHERE id = " . (int)$p['id']);
	}
	return array('ok' => !$failed, 'message' => ($failed ? ($done > 0 ? 'Stopped part way. ' : 'Nothing was changed. ') : 'Done. ') . implode("\n", $lines) . ($done > 0 ? "\nYou can undo the file changes with the Undo button." : ''), 'undo' => $done > 0, 'id' => (int)$p['id']);
}

function assistant_admin_fix_undo($db, $p, $panel_root)
{
	if ($p['status'] !== 'applied' && $p['status'] !== 'partial') {
		return array('ok' => false, 'message' => 'Only a change set that was applied can be undone.');
	}
	$steps = json_decode((string)$p['new_content'], true);
	if (!is_array($steps)) {
		return array('ok' => false, 'message' => 'The change set is unreadable.');
	}
	$lines = array();
	foreach (array_reverse(array_keys($steps)) as $i) {
		$step = $steps[$i];
		if (empty($step['applied'])) {
			continue;
		}
		if ($step['kind'] !== 'file') {
			$lines[] = 'Step ' . ($i + 1) . ' (' . $step['kind'] . ') cannot be undone automatically.';
			continue;
		}
		list($exists, $current, $error) = assistant_admin_step_read($db, $panel_root, $step['where'], $step['server_id'], $step['path']);
		if ($error !== '' || !$exists || sha1($current) !== sha1(base64_decode($step['new']))) {
			$lines[] = 'Step ' . ($i + 1) . ': ' . $step['path'] . ' has changed since, so it was not touched.';
			continue;
		}
		if (!$step['exists']) {
			if ($step['where'] === 'panel' && @unlink($step['path'])) {
				$lines[] = 'Step ' . ($i + 1) . ': removed the file ' . $step['path'] . ' that was created.';
			} else {
				$lines[] = 'Step ' . ($i + 1) . ': the new file ' . $step['path'] . ' was left in place.';
			}
			continue;
		}
		$error = assistant_changeset_write($db, $panel_root, $step, base64_decode($step['old']));
		$lines[] = 'Step ' . ($i + 1) . ': ' . ($error === '' ? 'put ' . $step['path'] . ' back the way it was.' : $step['path'] . ': ' . $error);
	}
	$db->query('UPDATE ' . assistant_table('proposals') . " SET status = 'undone', decided = NOW() WHERE id = " . (int)$p['id']);
	return array('ok' => true, 'message' => implode("\n", $lines));
}
?>
