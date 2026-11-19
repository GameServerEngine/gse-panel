<?php
/*
 * Admin-only tools for reading the panel's own files, the files on agent machines (in folders the
 * admin allowed), and for saving PATCHES. A patch is never written anywhere by the assistant:
 * it is stored, shown on the Assistant page and downloaded, then applied through git and the
 * normal working -> testing -> stable flow. Panel file changes are a separate proposal with an Apply button (assistant_admin_fix.php).
 */

require_once(__DIR__ . '/assistant_files.php');

if (!defined('ASSISTANT_PATCH_PENDING_MAX')) define('ASSISTANT_PATCH_PENDING_MAX', 50);

// Splits a path and refuses anything with . or .. or a NUL. Returns the clean string or false.
function assistant_clean_rel($path)
{
	$path = str_replace('\\', '/', (string)$path);
	if (strpos($path, "\0") !== false) {
		return false;
	}
	$parts = array();
	foreach (explode('/', $path) as $part) {
		if ($part === '' ) {
			continue;
		}
		if ($part === '.' || $part === '..') {
			return false;
		}
		$parts[] = $part;
	}
	return implode('/', $parts);
}

// A path the admin gave -> a real path on the panel server. Absolute paths are taken as they are; a relative path is
// inside the panel folder. Nothing is off limits. $new is true for a file that may not exist yet.
// Returns array(full, label, '') or array(null, null, 'reason').
function assistant_panel_resolve($panel_root, $cfg, $path, $new = false)
{
	$path = trim((string)$path);
	$root = realpath($panel_root);
	$clean = assistant_clean_rel($path);
	if ($clean === false) {
		return array(null, null, 'Do not use . or .. in a path. Give the full path instead.');
	}
	$full = (isset($path[0]) && $path[0] === '/') ? '/' . $clean : ($clean === '' ? $root : $root . '/' . $clean);
	if (!$new && !file_exists($full)) {
		return array(null, null, 'No such file or folder.');
	}
	$label = $full === $root ? '' : (strpos($full, $root . '/') === 0 ? substr($full, strlen($root) + 1) : $full);
	return array($full, $label, '');
}

// Admin reads are not redacted: the support team needs the real values.
function assistant_admin_plain($text)
{
	return $text;
}

function assistant_tool_panel_list($panel_root, $cfg, $input)
{
	list($full, $label, $why) = assistant_panel_resolve($panel_root, $cfg, isset($input['path']) ? $input['path'] : '');
	if ($full === null || !is_dir($full)) {
		return array('data' => array('error' => $full === null ? $why : 'That is not a folder.'));
	}
	$entries = array();
	foreach ((array)scandir($full) as $name) {
		if ($name === '.' || $name === '..' || $name === '.git') {
			continue;
		}
		$entries[] = is_dir($full . '/' . $name) ? $name . '/' : $name . ' (' . (int)@filesize($full . '/' . $name) . ' bytes)';
	}
	sort($entries);
	return array('data' => array('folder' => $label === '' ? '(panel folder)' : $label, 'entries' => assistant_wrap('file_data', implode("\n", array_slice($entries, 0, 200))), 'more_not_shown' => count($entries) > 200));
}

function assistant_tool_panel_read($panel_root, $cfg, $input)
{
	list($full, $label, $why) = assistant_panel_resolve($panel_root, $cfg, isset($input['path']) ? $input['path'] : '');
	if ($full === null || !is_file($full)) {
		return array('data' => array('error' => $full === null ? $why : 'That is not a file.'));
	}
	$size = (int)filesize($full);
	if ($size > ASSISTANT_FILE_READ_MAX) {
		return array('data' => array('error' => 'That file is too big (' . $size . ' bytes). Use contains to search it, or read a smaller file.'));
	}
	$data = (string)file_get_contents($full);
	if (assistant_looks_binary($data)) {
		return array('data' => array('error' => 'That is a binary file.'));
	}
	$filter = isset($input['filter']) ? (string)$input['filter'] : '';
	$pattern = isset($input['contains']) ? substr((string)$input['contains'], 0, 100) : '';
	$from = isset($input['from']) && $input['from'] === 'end' ? 'end' : 'start';
	$picked = assistant_pick_lines($data, $filter, $pattern, $from, isset($input['lines']) ? $input['lines'] : 120);
	return array('data' => array('path' => $label, 'size_bytes' => $size, 'content' => assistant_wrap('file_data', assistant_admin_plain($picked))));
}

function assistant_tool_panel_search($panel_root, $cfg, $input)
{
	$query = isset($input['query']) ? substr(trim((string)$input['query']), 0, 100) : '';
	if (strlen($query) < 3) {
		return array('data' => array('error' => 'Search for at least 3 characters.'));
	}
	list($full, $label, $why) = assistant_panel_resolve($panel_root, $cfg, isset($input['path']) ? $input['path'] : '');
	if ($full === null || !is_dir($full)) {
		return array('data' => array('error' => $full === null ? $why : 'Search in a folder.'));
	}
	$exts = array('php', 'js', 'xml', 'css', 'html', 'txt', 'md', 'json', 'sql', 'log', 'pl', 'pm', 'sh', 'ini', 'cfg', 'conf', 'yml', 'yaml', 'inc');
	$hits = array();
	$visited = 0;
	$stack = array($full);
	$root = realpath($panel_root);
	while ($stack && count($hits) < 40 && $visited < 4000) {
		$dir = array_pop($stack);
		foreach ((array)@scandir($dir) as $name) {
			if ($name === '.' || $name === '..' || $name === '.git' || $name === 'node_modules') {
				continue;
			}
			$path = $dir . '/' . $name;
			if (is_link($path)) {
				continue;
			}
			if (is_dir($path)) {
				$stack[] = $path;
				continue;
			}
			if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $exts, true) || (int)@filesize($path) > 1048576) {
				continue;
			}
			$visited++;
			$lines = @file($path, FILE_IGNORE_NEW_LINES);
			foreach ((array)$lines as $n => $line) {
				if (stripos($line, $query) !== false) {
					$short = strpos($path, $root . '/') === 0 ? substr($path, strlen($root) + 1) : $path;
					$hits[] = $short . ':' . ($n + 1) . ': ' . trim(substr($line, 0, 200));
					if (count($hits) >= 40) {
						break 2;
					}
				}
			}
		}
	}
	return array('data' => array('matches' => assistant_wrap('file_data', assistant_admin_plain($hits ? implode("\n", $hits) : '(no matches)')), 'files_searched' => $visited));
}

// ---- agent machines -------------------------------------------------------------------------

// Absolute path on an agent -> clean path. Any folder on the machine. Returns array(path, '') or array(null, reason).
function assistant_agent_path($cfg, $path)
{
	$clean = assistant_clean_rel($path);
	if ($clean === false || $clean === '' || (string)$path === '' || $path[0] !== '/') {
		return array(null, 'Give an absolute path, like /home/gameserver/GSP/Cfg.');
	}
	return array('/' . $clean, '');
}

function assistant_agent_open($db, $panel_root, $server_id)
{
	require_once($panel_root . '/includes/lib_remote.php');
	foreach ((array)$db->getRemoteServers() as $r) {
		if ((int)$r['remote_server_id'] === (int)$server_id) {
			$remote = new RemoteLibrary($r['agent_ip'], $r['agent_port'], $r['encryption_key'], 8);
			return $remote->status_chk() === 1 ? array($remote, '') : array(null, 'That agent is not answering.');
		}
	}
	return array(null, 'No such agent machine. Use agents_health to see the ids.');
}

function assistant_tool_agent_list($db, $panel_root, $cfg, $input)
{
	list($path, $why) = assistant_agent_path($cfg, isset($input['path']) ? $input['path'] : '');
	if ($path === null) {
		return array('data' => array('error' => $why));
	}
	list($remote, $why) = assistant_agent_open($db, $panel_root, isset($input['server_id']) ? $input['server_id'] : 0);
	if ($remote === null) {
		return array('data' => array('error' => $why));
	}
	$list = $remote->remote_dirlistfm($path);
	if (!is_array($list)) {
		return array('data' => array('error' => 'That folder could not be opened.'));
	}
	$names = array();
	foreach ((isset($list['directorys']) ? (array)$list['directorys'] : array()) as $d) {
		if (isset($d['filename'])) {
			$names[] = $d['filename'] . '/';
		}
	}
	foreach ((isset($list['files']) ? (array)$list['files'] : array()) as $f) {
		if (isset($f['filename'])) {
			$names[] = $f['filename'] . ' (' . (int)$f['size'] . ' bytes)';
		}
	}
	sort($names);
	return array('data' => array('folder' => $path, 'entries' => assistant_wrap('file_data', implode("\n", array_slice($names, 0, 200))), 'more_not_shown' => count($names) > 200));
}

function assistant_tool_agent_read($db, $panel_root, $cfg, $input)
{
	list($path, $why) = assistant_agent_path($cfg, isset($input['path']) ? $input['path'] : '');
	if ($path === null) {
		return array('data' => array('error' => $why));
	}
	list($remote, $why) = assistant_agent_open($db, $panel_root, isset($input['server_id']) ? $input['server_id'] : 0);
	if ($remote === null) {
		return array('data' => array('error' => $why));
	}
	$ctx = array('remote' => $remote);
	$size = assistant_file_size($ctx, $path);
	if ($size === null) {
		return array('data' => array('error' => 'No such file.'));
	}
	if ($size > ASSISTANT_FILE_READ_MAX) {
		return array('data' => array('error' => 'That file is too big (' . $size . ' bytes). Use contains or filter, or read a smaller file.'));
	}
	$data = '';
	if ($remote->remote_readfile($path, $data) !== 1 || assistant_looks_binary($data)) {
		return array('data' => array('error' => 'The file could not be read as text.'));
	}
	$filter = isset($input['filter']) ? (string)$input['filter'] : '';
	$pattern = isset($input['contains']) ? substr((string)$input['contains'], 0, 100) : '';
	$from = isset($input['from']) && $input['from'] === 'start' ? 'start' : 'end';
	$picked = assistant_pick_lines($data, $filter, $pattern, $from, isset($input['lines']) ? $input['lines'] : 100);
	return array('data' => array('path' => $path, 'size_bytes' => $size, 'content' => assistant_wrap('file_data', assistant_admin_plain($picked))));
}

// ---- patches --------------------------------------------------------------------------------

// Saves a patch for the panel (path inside the panel folder) or for an agent file. Never writes it.
function assistant_tool_propose_patch($db, $user_id, $panel_root, $cfg, $input)
{
	assistant_expire_proposals($db);
	$rows = $db->resultQuery('SELECT COUNT(*) AS n FROM ' . assistant_table('proposals') . " WHERE kind = 'patch' AND status = 'pending'");
	if (isset($rows[0]['n']) && (int)$rows[0]['n'] >= ASSISTANT_PATCH_PENDING_MAX) {
		return array('data' => array('error' => 'There are ' . ASSISTANT_PATCH_PENDING_MAX . ' patches waiting on the Assistant page. Ask the admin to review or discard some first.'));
	}
	$target = isset($input['target']) ? (string)$input['target'] : 'panel';
	$has_content = isset($input['content']) && is_string($input['content']);
	$old = '';
	$exists = false;
	if ($target === 'panel') {
		list($full, $label, $why) = assistant_panel_resolve($panel_root, $cfg, isset($input['path']) ? $input['path'] : '', true);
		if ($full === null) {
			return array('data' => array('error' => $why));
		}
		if (isset($input['path']) && $input['path'] !== '' && $input['path'][0] === '/' && $label === $full) {
			return array('data' => array('error' => 'Patches are only for files inside the panel folder. Use a path relative to it.'));
		}
		if (is_dir($full)) {
			return array('data' => array('error' => 'That is a folder.'));
		}
		$exists = is_file($full);
		if ($exists) {
			if ((int)filesize($full) > ASSISTANT_FILE_EDIT_MAX) {
				return array('data' => array('error' => 'That file is too big to patch here.'));
			}
			$old = (string)file_get_contents($full);
		}
		$store_target = 'panel:' . $label;
	} elseif ($target === 'agent') {
		list($path, $why) = assistant_agent_path($cfg, isset($input['path']) ? $input['path'] : '');
		if ($path === null) {
			return array('data' => array('error' => $why));
		}
		$server_id = isset($input['server_id']) ? (int)$input['server_id'] : 0;
		list($remote, $why) = assistant_agent_open($db, $panel_root, $server_id);
		if ($remote === null) {
			return array('data' => array('error' => $why));
		}
		$size = assistant_file_size(array('remote' => $remote), $path);
		$exists = $size !== null;
		if ($exists) {
			if ($size > ASSISTANT_FILE_EDIT_MAX || $remote->remote_readfile($path, $old) !== 1) {
				return array('data' => array('error' => 'That file could not be read for patching.'));
			}
		}
		$store_target = 'agent' . $server_id . ':' . $path;
	} else {
		return array('data' => array('error' => 'target must be panel or agent.'));
	}
	if (assistant_binary_extension($store_target) || assistant_looks_binary($old)) {
		return array('data' => array('error' => 'Only text files can be patched.'));
	}
	if ($exists) {
		if ($has_content) {
			return array('data' => array('error' => 'That file exists. Change it with edits (old_text and new_text), not content.'));
		}
		list($new, $why) = assistant_apply_edits($old, isset($input['edits']) ? $input['edits'] : array());
		if ($new === null) {
			return array('data' => array('error' => $why));
		}
	} else {
		if (!$has_content || $input['content'] === '' || assistant_looks_binary($input['content'])) {
			return array('data' => array('error' => 'That file does not exist. To add it, give its full text in content.'));
		}
		$new = $input['content'];
	}
	if (strlen($new) > ASSISTANT_FILE_EDIT_MAX || $new === $old) {
		return array('data' => array('error' => $new === $old ? 'That would not change the file.' : 'The result would be too big.'));
	}
	$id = assistant_save_proposal($db, $user_id, 0, 'patch', $store_target, $old, $new, isset($input['summary']) ? $input['summary'] : '');
	if ($id < 1) {
		return array('data' => array('error' => 'The patch could not be saved.'));
	}
	return array(
		'data' => array('ok' => true, 'patch_id' => $id, 'note' => 'Saved as patch #' . $id . '. It is NOT applied. The admin downloads it from the Assistant page and applies it through git (working, then testing). Say which files a change touches and why.'),
		'button' => array('type' => 'patch', 'id' => $id, 'label' => 'Download patch #' . $id . ' (' . $store_target . ')'),
	);
}

// The text of a patch file for the proposals the admin picked (one id, or every pending patch).
function assistant_patch_text($db, $ids)
{
	$out = '';
	foreach ($ids as $id) {
		$p = assistant_load_proposal($db, $id);
		if ($p === null || $p['kind'] !== 'patch') {
			continue;
		}
		list($where, $path) = explode(':', $p['target'], 2);
		$label = $where === 'panel' ? $path : $where . $path;
		$out .= '# Patch #' . (int)$p['id'] . ' (' . $p['status'] . ', ' . $p['created'] . ') for ' . $p['target'] . "\n";
		if ((string)$p['note'] !== '') {
			$out .= '# ' . str_replace(array("\r", "\n"), ' ', $p['note']) . "\n";
		}
		if ($where !== 'panel') {
			$out .= "# This one is for an agent file. The path below is agent<id> + the file's path; apply it in the agent repository.\n";
		}
		$out .= assistant_unified_diff((string)$p['old_content'], (string)$p['new_content'], $label) . "\n";
	}
	return $out;
}
?>
