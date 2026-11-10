<?php
/*
 * Update manifest, exclusions and edited-file decisions.
 *
 * Two files live in the panel root. Neither is in any release ZIP and the update never replaces them:
 *   update_manifest.json  - every file the update installed (path, sha256, release version),
 *                           the exclusions list and the edited_decisions map.
 *   update_exclusions.txt - one path per line, "#" starts a comment, a trailing "/" marks a folder.
 *                           A person can edit it by hand. It is read on every run.
 *
 * Every function takes the panel root as an argument so the tests can use a temp directory.
 */

function um_manifest_file($root)
{
	return rtrim($root, '/') . '/update_manifest.json';
}

function um_exclusions_file($root)
{
	return rtrim($root, '/') . '/update_exclusions.txt';
}

// The two files the update must never overwrite or hash as release files.
function um_own_files()
{
	return array('update_manifest.json', 'update_exclusions.txt');
}

function um_sha256($path)
{
	$hash = @hash_file('sha256', $path);
	return $hash === false ? '' : $hash;
}

function um_empty_manifest()
{
	return array('files' => array(), 'exclusions' => array(), 'edited_decisions' => array());
}

// A clean relative path with "/" separators. Returns '' for anything unsafe (.. or empty).
// A trailing "/" is kept: it marks a folder.
function um_clean_path($path)
{
	$path = trim(str_replace('\\', '/', (string)$path));
	$is_folder = substr($path, -1) === '/';
	$path = trim($path, '/');
	while (strpos($path, './') === 0) {
		$path = substr($path, 2);
	}
	if ($path === '' || $path === '.' || strpos($path, "\0") !== false) {
		return '';
	}
	foreach (explode('/', $path) as $part) {
		if ($part === '..') {
			return '';
		}
	}
	return $is_folder ? $path . '/' : $path;
}

/**
 * Read the manifest. A missing file is not an error: the result is an empty manifest and exists = false.
 * A file that is not valid JSON is an error and is left exactly as it is.
 * Returns: manifest, exists, error.
 */
function um_manifest_load($root)
{
	$file = um_manifest_file($root);
	if (!is_file($file)) {
		return array('manifest' => um_empty_manifest(), 'exists' => false, 'error' => '');
	}
	$data = json_decode((string)@file_get_contents($file), true);
	if (!is_array($data)) {
		return array('manifest' => um_empty_manifest(), 'exists' => true, 'error' => 'update_manifest.json is not valid JSON. Fix or delete it by hand: ' . $file);
	}
	$manifest = um_empty_manifest();
	foreach (array('files', 'exclusions', 'edited_decisions') as $key) {
		if (isset($data[$key]) && is_array($data[$key])) {
			$manifest[$key] = $data[$key];
		}
	}
	$manifest['exclusions'] = array_values($manifest['exclusions']);
	return array('manifest' => $manifest, 'exists' => true, 'error' => '');
}

// Write the manifest through a temp file so a crash never leaves half a file. Returns '' or the reason.
function um_manifest_save($root, array $manifest)
{
	ksort($manifest['files']);
	$manifest['exclusions'] = array_values(array_unique($manifest['exclusions']));
	$json = um_manifest_json($manifest);
	$file = um_manifest_file($root);
	$tmp = $file . '.tmp' . getmypid();
	if (@file_put_contents($tmp, $json . "\n") === false) {
		return 'Cannot write ' . $tmp;
	}
	@chmod($tmp, 0664);
	if (!@rename($tmp, $file)) {
		@unlink($tmp);
		return 'Cannot replace ' . $file;
	}
	return '';
}

// JSON with the exclusions as a list (one per line) and the other two parts as objects.
function um_manifest_json(array $manifest)
{
	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
	$parts = array(
		'files' => empty($manifest['files']) ? new stdClass() : $manifest['files'],
		'exclusions' => array_values($manifest['exclusions']),
		'edited_decisions' => empty($manifest['edited_decisions']) ? new stdClass() : $manifest['edited_decisions'],
	);
	return json_encode($parts, $flags);
}

// ---------------------------------------------------------------- exclusions

// Read update_exclusions.txt. Returns null when the file does not exist.
function um_read_exclusions_file($root)
{
	$file = um_exclusions_file($root);
	if (!is_file($file)) {
		return null;
	}
	$list = array();
	foreach (preg_split('/\r?\n/', (string)@file_get_contents($file)) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		$path = um_clean_path($line);
		if ($path !== '') {
			$list[] = $path;
		}
	}
	return array_values(array_unique($list));
}

// Write update_exclusions.txt: a short help comment, then one path per line. Returns '' or the reason.
function um_write_exclusions_file($root, array $list)
{
	$text = "# Files and folders the update must never overwrite or delete.\n"
		. "# One path per line, relative to the panel folder. A trailing / marks a folder.\n"
		. "# Lines starting with # are comments. Edit by hand or from the Update page.\n";
	foreach (array_values(array_unique($list)) as $path) {
		$text .= $path . "\n";
	}
	$file = um_exclusions_file($root);
	if (@file_put_contents($file, $text) === false) {
		return 'Cannot write ' . $file;
	}
	@chmod($file, 0664);
	return '';
}

/**
 * Merge update_exclusions.txt into the manifest. The text file is the one a person edits, so when it
 * exists its list becomes the manifest list (a line deleted by hand is really removed).
 * When it does not exist yet it is created from the manifest list.
 * Returns the manifest. The caller saves it.
 */
function um_sync_exclusions($root, array $manifest)
{
	$from_file = um_read_exclusions_file($root);
	if ($from_file === null) {
		um_write_exclusions_file($root, $manifest['exclusions']);
		return $manifest;
	}
	$manifest['exclusions'] = $from_file;
	return $manifest;
}

// True when $rel is one of the exclusions: the same file, or anywhere under an excluded folder.
function um_is_excluded($rel, array $exclusions)
{
	foreach ($exclusions as $entry) {
		if (substr($entry, -1) === '/') {
			if ($rel === rtrim($entry, '/') || strpos($rel, $entry) === 0) {
				return true;
			}
		} elseif ($rel === $entry) {
			return true;
		}
	}
	return false;
}

// ----------------------------------------------------------- edited files

/**
 * Files in the manifest whose sha256 on disk is no longer the recorded one (the user edited them).
 * A file that is gone is not an edit. Excluded paths are left out when $exclusions is given.
 * Returns a sorted list of relative paths.
 */
function um_find_edited($root, array $manifest, array $exclusions = array())
{
	$root = rtrim($root, '/');
	$edited = array();
	foreach ($manifest['files'] as $rel => $info) {
		if (um_is_excluded($rel, $exclusions)) {
			continue;
		}
		$path = $root . '/' . $rel;
		if (!is_file($path)) {
			continue;
		}
		$recorded = is_array($info) && isset($info['sha256']) ? $info['sha256'] : '';
		if (um_sha256($path) !== $recorded) {
			$edited[] = $rel;
		}
	}
	sort($edited);
	return $edited;
}

// Folder key of a file for the review page: "modules/foo/" or "./" for a file in the panel root.
function um_folder_of($rel)
{
	$dir = dirname($rel);
	return $dir === '.' ? './' : $dir . '/';
}

// Every folder above a file, nearest last: "a/", "a/b/". The root "./" comes first.
function um_folders_above($rel)
{
	$folders = array('./');
	$parts = explode('/', $rel);
	array_pop($parts);
	$prefix = '';
	foreach ($parts as $part) {
		$prefix .= $part . '/';
		$folders[] = $prefix;
	}
	return $folders;
}

// Edited files grouped by their own folder: folder => list of files.
function um_group_by_folder(array $edited)
{
	$groups = array();
	foreach ($edited as $rel) {
		$groups[um_folder_of($rel)][] = $rel;
	}
	ksort($groups);
	return $groups;
}

/**
 * Keep the user's version of this file? Yes unless the user unchecked it.
 * A kept folder covers every file under it. With no stored decision the answer is yes.
 */
function um_keep_decision($rel, array $decisions)
{
	foreach (um_folders_above($rel) as $folder) {
		if (isset($decisions[$folder]) && $decisions[$folder] === 'keep') {
			return true;
		}
	}
	return !(isset($decisions[$rel]) && $decisions[$rel] === 'overwrite');
}

/**
 * Store the choices from the review form in the manifest. Starts from nothing, so an old choice never lingers.
 * $keep_files and $keep_folders are the checked boxes. Only paths that were really offered are accepted.
 * Returns the manifest. The caller saves it.
 */
function um_store_decisions(array $manifest, array $edited, array $keep_files, array $keep_folders)
{
	$offered_folders = array_keys(um_group_by_folder($edited));
	$decisions = array();
	foreach ($edited as $rel) {
		$decisions[$rel] = in_array($rel, $keep_files, true) ? 'keep' : 'overwrite';
	}
	foreach ($keep_folders as $folder) {
		if (in_array($folder, $offered_folders, true)) {
			$decisions[$folder] = 'keep';
		}
	}
	$manifest['edited_decisions'] = $decisions;
	return $manifest;
}

/**
 * Why the update must leave this path alone, or '' when it may be written.
 * $edited is the list found before the update started.
 */
function um_skip_reason($rel, array $exclusions, array $edited, array $decisions)
{
	if (um_is_excluded($rel, $exclusions)) {
		return 'excluded';
	}
	if (in_array($rel, $edited, true) && um_keep_decision($rel, $decisions)) {
		return 'kept your edited version';
	}
	return '';
}

// ------------------------------------------------------ after the install

/**
 * Record the files an update installed. The manifest is created on the first run and edited after that.
 * Every installed file gets its sha256 and the release version. The review choices are used up, so they are cleared.
 * Returns '' or the reason it failed.
 */
function um_record_install($root, array $installed_rels, $version)
{
	$root = rtrim($root, '/');
	$loaded = um_manifest_load($root);
	if ($loaded['error'] !== '') {
		return $loaded['error'];
	}
	$manifest = um_sync_exclusions($root, $loaded['manifest']);
	foreach ($installed_rels as $rel) {
		$manifest['files'][$rel] = array('sha256' => um_sha256($root . '/' . $rel), 'version' => (string)$version);
	}
	$manifest['edited_decisions'] = array();
	return um_manifest_save($root, $manifest);
}

// Save a new exclusions list from the Update page to both files. Returns '' or the reason.
function um_save_exclusions($root, array $list)
{
	$loaded = um_manifest_load($root);
	if ($loaded['error'] !== '') {
		return $loaded['error'];
	}
	$manifest = $loaded['manifest'];
	$manifest['exclusions'] = array_values(array_unique($list));
	$error = um_write_exclusions_file($root, $manifest['exclusions']);
	if ($error !== '') {
		return $error;
	}
	return um_manifest_save($root, $manifest);
}

// What the Exclusions section offers: files edited since the last install, plus what is already excluded.
// Returns rows of path => true when it is excluded now.
function um_exclusion_candidates($root, array $manifest, array $exclusions)
{
	$rows = array();
	foreach (um_find_edited($root, $manifest) as $rel) {
		$rows[$rel] = in_array($rel, $exclusions, true);
	}
	foreach ($exclusions as $entry) {
		$rows[$entry] = true;
	}
	ksort($rows);
	return $rows;
}
