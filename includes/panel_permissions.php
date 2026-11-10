<?php
/*
 * The one definition of how the panel tree is owned and what modes it has.
 *
 * Used by:
 *   - tools/install_panel_cli.php --repair-permissions (what scripts/install-panel.sh runs)
 *   - the panel updater, before the files are copied and again after.
 *
 * It only ever touches paths inside the panel root. Symbolic links are left alone
 * because they can point outside the root.
 *
 * The functions that read or change ownership and modes come in through $ops, so
 * a test can run the whole thing without root.
 */

// Directories get setgid + rwxrwxr-x, files rw-rw-r--, secret files rw-rw----.
function perm_dir_mode()
{
	return 02775;
}

function perm_file_mode()
{
	return 0664;
}

function perm_secret_mode()
{
	return 0660;
}

function perm_secret_files()
{
	return array('includes/config.inc.php', 'includes/install.state.json');
}

// The mode a path must have. $rel is the path relative to the panel root.
function perm_wanted_mode($rel, $is_dir)
{
	if ($is_dir) {
		return perm_dir_mode();
	}
	return in_array($rel, perm_secret_files(), true) ? perm_secret_mode() : perm_file_mode();
}

// True when $path is $root or below it. Pure string check, no file access.
function perm_is_inside($root, $path)
{
	$root = rtrim((string)$root, '/');
	$path = (string)$path;
	if ($root === '' || strpos($path, "\0") !== false) {
		return false;
	}
	foreach (explode('/', $path) as $part) {
		if ($part === '..') {
			return false;
		}
	}
	return $path === $root || strpos($path, $root . '/') === 0;
}

// The real functions. Each "change" function returns '' on success, else the reason.
function perm_default_ops()
{
	return array(
		'owner_of' => function ($path) {
			$uid = @fileowner($path);
			if ($uid !== false && function_exists('posix_getpwuid')) {
				$info = posix_getpwuid($uid);
				return is_array($info) ? $info['name'] : (string)$uid;
			}
			return $uid === false ? '' : (string)$uid;
		},
		'group_of' => function ($path) {
			$gid = @filegroup($path);
			if ($gid !== false && function_exists('posix_getgrgid')) {
				$info = posix_getgrgid($gid);
				return is_array($info) ? $info['name'] : (string)$gid;
			}
			return $gid === false ? '' : (string)$gid;
		},
		'mode_of' => function ($path) {
			$perms = @fileperms($path);
			return $perms === false ? -1 : ($perms & 07777);
		},
		'chown' => function ($path, $owner, $group) {
			if (!@chown($path, $owner) || !@chgrp($path, $group)) {
				$last = error_get_last();
				return isset($last['message']) ? $last['message'] : 'chown failed';
			}
			return '';
		},
		'chmod' => function ($path, $mode) {
			if (!@chmod($path, $mode)) {
				$last = error_get_last();
				return isset($last['message']) ? $last['message'] : 'chmod failed';
			}
			return '';
		},
	);
}

// Every path under $root (and $root itself) that is not a symbolic link. $skip(rel) true leaves a path out.
function perm_collect_paths($root, $skip = null)
{
	$root = rtrim((string)$root, '/');
	$paths = array();
	if (!is_dir($root)) {
		return $paths;
	}
	$paths[] = $root;
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ($iter as $item) {
		$path = $item->getPathname();
		if ($item->isLink()) {
			continue;
		}
		$rel = substr($path, strlen($root) + 1);
		if ($skip !== null && call_user_func($skip, $rel)) {
			continue;
		}
		$paths[] = $path;
	}
	return $paths;
}

/**
 * Fix owner, group and mode of the given paths.
 * Returns:
 *   changed - list of "path: what was changed"
 *   failed  - list of "path: why it could not be changed"
 *   outside - paths that were not touched because they are not inside $root
 */
function perm_repair_paths($root, array $paths, $owner, $group, $ops = null)
{
	$root = rtrim((string)$root, '/');
	$ops = $ops === null ? perm_default_ops() : $ops;
	$result = array('changed' => array(), 'failed' => array(), 'outside' => array());
	foreach ($paths as $path) {
		if (!perm_is_inside($root, $path)) {
			$result['outside'][] = $path;
			continue;
		}
		$rel = $path === $root ? '' : substr($path, strlen($root) + 1);
		perm_repair_one($path, $rel, is_dir($path), $owner, $group, $ops, $result);
	}
	return $result;
}

function perm_repair_one($path, $rel, $is_dir, $owner, $group, array $ops, array &$result)
{
	$have_owner = (string)call_user_func($ops['owner_of'], $path);
	$have_group = (string)call_user_func($ops['group_of'], $path);
	if ($have_owner !== $owner || $have_group !== $group) {
		$error = call_user_func($ops['chown'], $path, $owner, $group);
		if ($error !== '') {
			$result['failed'][] = $path . ': cannot set owner ' . $owner . ':' . $group . ' (' . $error . ')';
			return;
		}
		$result['changed'][] = $path . ': owner ' . $have_owner . ':' . $have_group . ' -> ' . $owner . ':' . $group;
	}
	$want_mode = perm_wanted_mode($rel, $is_dir);
	$have_mode = (int)call_user_func($ops['mode_of'], $path);
	if ($have_mode !== $want_mode) {
		$error = call_user_func($ops['chmod'], $path, $want_mode);
		if ($error !== '') {
			$result['failed'][] = $path . ': cannot set mode ' . decoct($want_mode) . ' (' . $error . ')';
			return;
		}
		$result['changed'][] = $path . ': mode ' . decoct($have_mode) . ' -> ' . decoct($want_mode);
	}
}

// Repair the whole panel tree. $skip(rel) true leaves a path alone.
function perm_repair_tree($root, $owner, $group, $ops = null, $skip = null)
{
	return perm_repair_paths($root, perm_collect_paths($root, $skip), $owner, $group, $ops);
}
