<?php

/**
 * Normalizes a Panel/agent path for prefix checks without resolving it on the
 * local filesystem. Agent paths may be remote Linux or Cygwin paths.
 */
function normalize_remote_path($path)
{
	$path = str_replace('\\', '/', trim((string)$path));
	$path = preg_replace('#/+#', '/', $path);
	if ($path !== '/' && preg_match('#^/[A-Za-z]$#', $path) !== 1) {
		$path = rtrim($path, '/');
	}
	return $path;
}

/**
 * Detects parent-directory path segments in remote paths without using local
 * filesystem resolution.
 */
function remote_path_has_parent_segment($path)
{
	$path = normalize_remote_path($path);
	foreach (explode('/', $path) as $segment) {
		if ($segment === '..') {
			return true;
		}
	}
	return false;
}

/**
 * Validates an XML game_key as one safe directory component for game_root.
 */
function validate_game_key($game_key, &$error = '')
{
	$error = '';
	$game_key = (string)$game_key;
	if ($game_key === '' || trim($game_key) !== $game_key) {
		$error = 'Game key is empty or contains surrounding whitespace.';
		return false;
	}
	if (preg_match('/[\x00-\x1F\x7F]/', $game_key)) {
		$error = 'Game key contains a control character.';
		return false;
	}
	if (strpos($game_key, '/') !== false || strpos($game_key, '\\') !== false) {
		$error = 'Game key must be a single directory name, not a path.';
		return false;
	}
	if (preg_match('/^[A-Za-z]:/', $game_key) || strpos($game_key, ':') !== false) {
		$error = 'Game key must not contain a drive prefix.';
		return false;
	}
	if ($game_key === '.' || $game_key === '..' || strpos($game_key, '..') !== false) {
		$error = 'Game key must not contain traversal segments.';
		return false;
	}
	if (!preg_match('/^[A-Za-z0-9._-]+$/', $game_key)) {
		$error = 'Game key contains characters that are not safe for a directory name.';
		return false;
	}
	if (substr($game_key, -1) === '.') {
		$error = 'Game key must not end with a dot.';
		return false;
	}
	$name_part = strtoupper(strtok($game_key, '.'));
	$reserved = array('CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$');
	for ($i = 1; $i <= 9; $i++) {
		$reserved[] = 'COM' . $i;
		$reserved[] = 'LPT' . $i;
	}
	if (in_array($name_part, $reserved, true)) {
		$error = 'Game key uses a reserved device name.';
		return false;
	}
	return true;
}

/**
 * Returns true when a candidate remote path is inside a management home.
 */
function remote_path_is_child_of($management_home, $candidate_path)
{
	$management_home = normalize_remote_path($management_home);
	$candidate_path = normalize_remote_path($candidate_path);
	if ($management_home === '' || $candidate_path === '') {
		return false;
	}
	if (remote_path_has_parent_segment($management_home) || remote_path_has_parent_segment($candidate_path)) {
		return false;
	}
	return strpos($candidate_path . '/', $management_home . '/') === 0 && $candidate_path !== $management_home;
}

/**
 * Derives game_root as management_home/<validated game_key>.
 */
function resolve_game_root($management_home, $game_key, &$error = '')
{
	$error = '';
	$management_home = normalize_remote_path($management_home);
	if ($management_home === '') {
		$error = 'Management home is empty.';
		return false;
	}
	if (!validate_game_key($game_key, $error)) {
		return false;
	}
	$game_root = normalize_remote_path($management_home . '/gamefiles');
	if (!remote_path_is_child_of($management_home, $game_root)) {
		$error = 'Resolved game root escapes the management home.';
		return false;
	}
	return $game_root;
}

/**
 * Resolves the authoritative game_root for a DB home row and XML config.
 */
function resolve_game_root_for_home(array $home_info, $server_xml = null, &$error = '')
{
	$game_key = '';
	if ($server_xml !== null && isset($server_xml->game_key)) {
		$game_key = (string)$server_xml->game_key;
	} elseif (isset($home_info['game_key'])) {
		$game_key = (string)$home_info['game_key'];
	}
	return resolve_game_root(isset($home_info['home_path']) ? $home_info['home_path'] : '', $game_key, $error);
}

/**
 * Adds management_home, game_key, and game_root to a home_info array.
 */
function with_game_root(array $home_info, $server_xml = null, &$error = '')
{
	$game_key = ($server_xml !== null && isset($server_xml->game_key)) ? (string)$server_xml->game_key : (string)(isset($home_info['game_key']) ? $home_info['game_key'] : '');
	$game_root = resolve_game_root(isset($home_info['home_path']) ? $home_info['home_path'] : '', $game_key, $error);
	if ($game_root === false) {
		return false;
	}
	$home_info['management_home'] = normalize_remote_path($home_info['home_path']);
	$home_info['game_key'] = $game_key;
	$home_info['game_root'] = $game_root;
	return $home_info;
}

/**
 * Resolves a caller-supplied payload path without allowing home escape.
 */
function resolve_game_payload_path(array $home_info, $path, &$error = '', $allow_absolute_in_management_home = false)
{
	$error = '';
	$game_root = isset($home_info['game_root']) ? normalize_remote_path($home_info['game_root']) : '';
	$management_home = isset($home_info['management_home']) ? normalize_remote_path($home_info['management_home']) : (isset($home_info['home_path']) ? normalize_remote_path($home_info['home_path']) : '');
	$path = str_replace('\\', '/', trim((string)$path));
	$path = preg_replace('#/+#', '/', $path);
	if ($game_root === '' || $path === '' || strpos($path, "\0") !== false) {
		$error = 'Payload path is empty or invalid.';
		return false;
	}
	if (remote_path_has_parent_segment($path)) {
		$error = 'Payload path must not contain parent-directory segments.';
		return false;
	}
	if (strpos($path, '/') === 0) {
		$resolved = normalize_remote_path($path);
		if (!$allow_absolute_in_management_home || !remote_path_is_child_of($management_home, $resolved)) {
			$error = 'Absolute payload path is outside the server management home.';
			return false;
		}
		return $resolved;
	}
	$resolved = normalize_remote_path(rtrim($game_root, '/') . '/' . ltrim($path, '/'));
	if ($resolved !== $game_root && !remote_path_is_child_of($game_root, $resolved)) {
		$error = 'Payload path escapes the game directory.';
		return false;
	}
	return $resolved;
}

?>
