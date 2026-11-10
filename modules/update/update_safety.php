<?php
/*
 * Panel update safety helpers.
 *
 * The update installs one selected release (a version and its ZIP URL). It is
 * one unit of files plus database:
 *   1. preflight  - prove every path can be written, there is disk space, and the
 *                   database dump can be taken. Nothing is changed.
 *   2. stage      - download the ZIP into a temp directory and validate it.
 *   3. backup     - files and database dump.
 *   4. apply      - copy files, run migrations, write the version.
 *   5. rollback   - on any failure restore files AND database, then verify.
 *
 * Every function here is plain and takes its inputs as arguments so the tests
 * can call it without a real database or web server.
 */

// Directories that are never part of a panel release (same list used by the copy and the preflight).
function update_protected_prefixes()
{
	return [
		'.git/', '.github/', '.devplus/', '.vscode/',
		'includes/config.inc.php', 'config.inc.php',
		'Cfg/', 'ServerFiles/', 'Schedule/',
		'logs/', 'log/', 'backups/', 'backup/', 'cache/', 'tmp/', 'temp/',
		'uploads/', 'upload/', 'home/', 'homes/', 'servers/', 'game_servers/',
		'steamcmd/', 'startups/', 'node_modules/', 'vendor/',
	];
}

// True when the update must leave this release-relative path alone.
function update_should_skip($rel)
{
	$base_name = basename($rel);
	if (preg_match('/\.log$/i', $base_name) || preg_match('/\.sql\.(gz|bz2|xz)$/i', $base_name)) {
		return true;
	}
	foreach (update_protected_prefixes() as $prefix) {
		if ($rel === rtrim($prefix, '/') || strpos($rel, $prefix) === 0) {
			return true;
		}
	}
	return false;
}

// ---------------------------------------------------------------- preflight

// The command an admin runs to give the web user write access again.
function update_repair_hint($panel_dir)
{
	return 'run: sudo bash scripts/install-panel.sh --repair-permissions --path ' . $panel_dir
		. ' --owner <user> --group www-data';
}

// Walk up to the nearest directory that exists. Used for paths the update has not created yet.
function update_existing_ancestor($path)
{
	$path = rtrim((string)$path, '/');
	while ($path !== '' && $path !== '/' && !file_exists($path)) {
		$path = dirname($path);
	}
	return $path === '' ? '/' : $path;
}

// Create and delete a temp file in the directory. Returns '' when it works, else the reason.
function update_probe_directory($dir)
{
	$dir = update_existing_ancestor($dir);
	if (!is_dir($dir)) {
		return 'not a directory: ' . $dir;
	}
	$probe = $dir . '/.gse_write_test_' . getmypid() . '_' . mt_rand();
	$written = @file_put_contents($probe, 'x');
	if ($written === false) {
		$last = error_get_last();
		return 'cannot create a file in ' . $dir . (isset($last['message']) ? ' (' . $last['message'] . ')' : '');
	}
	if (!@unlink($probe)) {
		return 'created a test file but cannot delete it in ' . $dir;
	}
	return '';
}

// A runtime file must be writable itself. A file that does not exist yet needs a writable directory.
function update_check_file_writable($path)
{
	if (file_exists($path)) {
		return is_writable($path) ? '' : 'file is not writable: ' . $path;
	}
	return update_probe_directory(dirname($path));
}

function update_check_free_space($path, $needed_bytes)
{
	$free = @disk_free_space(update_existing_ancestor($path));
	if ($free === false) {
		return 'cannot read free disk space for ' . $path;
	}
	if ($free < $needed_bytes) {
		return 'not enough free disk space for ' . $path . ': need ' . round($needed_bytes / 1048576) . ' MB, have ' . round($free / 1048576) . ' MB';
	}
	return '';
}

// Size of the panel tree in bytes, ignoring directories the backup leaves out.
function update_tree_size($dir)
{
	$total = 0;
	if (!is_dir($dir)) {
		return 0;
	}
	$skip = ['backups', 'logs', 'cache', 'tmp', 'node_modules', 'vendor', '.git'];
	$iter = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
			function ($current) use ($skip) {
				return !($current->isDir() && in_array($current->getFilename(), $skip, true));
			}
		)
	);
	foreach ($iter as $item) {
		if ($item->isFile()) {
			$total += $item->getSize();
		}
	}
	return $total;
}

// Free space the update needs: the backup archive, the staged release, plus a margin for the dump.
function update_required_free_bytes($panel_dir)
{
	return (int)(update_tree_size($panel_dir) * 1.2) + 100 * 1048576;
}

// Database dump check. $full takes a real dump into the temp directory and deletes it.
function update_check_database_dump($panel_dir, $full)
{
	foreach (['mysqldump', 'mysql', 'tar'] as $tool) {
		$found = [];
		$ret = 0;
		exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null', $found, $ret);
		if ($ret !== 0 || empty($found[0])) {
			return $tool . ' is not installed, so the update could not back up or restore the database and files';
		}
	}
	$db_config = load_panel_db_config($panel_dir);
	if ($db_config === null) {
		return 'database settings could not be read from ' . $panel_dir . '/includes/config.inc.php';
	}
	if (!$full) {
		return '';
	}
	$tmp = sys_get_temp_dir() . '/dump_check_' . getmypid() . '_' . mt_rand();
	if (!@mkdir($tmp, 0700)) {
		return 'cannot create temp directory for the dump check: ' . $tmp;
	}
	$dump = create_database_backup($tmp, $db_config);
	$error = $dump['success'] ? '' : 'the database dump cannot be taken: ' . $dump['error'];
	@unlink($tmp . '/database.sql');
	@rmdir($tmp);
	return $error;
}

// Every runtime file the update snapshots and restores, as absolute paths.
function update_runtime_file_paths($panel_dir)
{
	$paths = [];
	foreach (runtime_state_paths() as $relative) {
		$paths[] = rtrim($panel_dir, '/') . '/' . $relative;
	}
	return $paths;
}

/**
 * Preflight. Returns a list of error strings, empty when everything passes.
 * It only creates and deletes small temp files. It never touches panel files.
 */
function update_preflight_errors($panel_dir, $backup_base, $full_dump_check)
{
	$panel_dir = rtrim($panel_dir, '/');
	$hint = ' Fix: ' . update_repair_hint($panel_dir);
	$errors = [];

	$directories = [$panel_dir, $panel_dir . '/includes', $backup_base, sys_get_temp_dir(), dirname(UPDATE_LOG)];
	foreach ($directories as $dir) {
		$problem = update_probe_directory($dir);
		if ($problem !== '') {
			$errors[] = 'No write access: ' . $problem . '.' . $hint;
		}
	}
	foreach (update_runtime_file_paths($panel_dir) as $file) {
		$problem = update_check_file_writable($file);
		if ($problem !== '') {
			$errors[] = 'No write access: ' . $problem . '.' . $hint;
		}
	}

	$needed = update_required_free_bytes($panel_dir);
	foreach ([$backup_base, sys_get_temp_dir(), $panel_dir] as $dir) {
		$problem = update_check_free_space($dir, $needed);
		if ($problem !== '') {
			$errors[] = ucfirst($problem) . '. Free some space and run the update again.';
		}
	}

	$problem = update_check_database_dump($panel_dir, $full_dump_check);
	if ($problem !== '') {
		$errors[] = ucfirst($problem) . '.';
	}
	return $errors;
}

/**
 * Second preflight, run after staging: every directory and every existing file
 * the release copy will write must be writable. Returns error strings.
 */
function update_release_target_errors($source_root, $panel_dir)
{
	$source_root = rtrim($source_root, '/');
	$panel_dir = rtrim($panel_dir, '/');
	$hint = ' Fix: ' . update_repair_hint($panel_dir);
	$errors = [];
	$checked_dirs = [];
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source_root, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ($iter as $item) {
		if (count($errors) >= 20) {
			$errors[] = 'More write problems were found. Fix these first and run the update again.';
			break;
		}
		$rel = normalize_rel(substr($item->getPathname(), strlen($source_root) + 1));
		if (update_should_skip($rel)) {
			continue;
		}
		$dest = $panel_dir . '/' . $rel;
		if ($item->isDir()) {
			continue;
		}
		$dest_dir = dirname($dest);
		if (!isset($checked_dirs[$dest_dir])) {
			$checked_dirs[$dest_dir] = true;
			$problem = update_probe_directory($dest_dir);
			if ($problem !== '') {
				$errors[] = 'No write access: ' . $problem . '.' . $hint;
			}
		}
		if (file_exists($dest) && !is_writable($dest)) {
			$errors[] = 'No write access: file is not writable: ' . $dest . '.' . $hint;
		}
	}
	return $errors;
}

// ------------------------------------------------------------------ staging

// A release version such as 2.5.5 or 2.5.5-rc1. Anything else is refused.
function update_is_valid_version($version)
{
	return preg_match('/^\d+\.\d+\.\d+(?:[-+.][A-Za-z0-9.]+)*$/', (string)$version) === 1;
}

/**
 * Validate an extracted release before anything is backed up or changed.
 * Needs: a VERSION file, and migrations that can be found and parsed.
 * Returns success, version, migrations (ids) and ignored_sql_files (files the runner will not run).
 */
function validate_staged_release($payload_root)
{
	$payload_root = rtrim($payload_root, '/');
	$version_file = $payload_root . '/VERSION';
	if (!is_file($version_file)) {
		return ['success' => false, 'error' => 'Release is missing the VERSION file.'];
	}
	$version = trim((string)@file_get_contents($version_file));
	if (!update_is_valid_version($version)) {
		return ['success' => false, 'error' => 'Release VERSION file is not a version number: ' . substr($version, 0, 40)];
	}
	if (!function_exists('migrations_discover') || !function_exists('sql_split_statements')) {
		return ['success' => false, 'error' => 'Migration runner is unavailable, so the release migrations cannot be checked.'];
	}
	$migration_dir = $payload_root . '/sql/migrations';
	$found = migrations_discover($migration_dir);
	$ids = [];
	foreach ($found as $migration) {
		$sql = @file_get_contents($migration['file']);
		if ($sql === false) {
			return ['success' => false, 'error' => 'Release migration cannot be read: ' . $migration['id']];
		}
		if (count(sql_split_statements($sql)) === 0) {
			return ['success' => false, 'error' => 'Release migration has no SQL statements: ' . $migration['id']];
		}
		$ids[] = $migration['id'];
	}
	$ignored = [];
	foreach (glob($migration_dir . '/*.sql') ?: [] as $file) {
		if (!in_array(basename($file, '.sql'), $ids, true)) {
			$ignored[] = basename($file);
		}
	}
	return ['success' => true, 'version' => $version, 'migrations' => $ids, 'ignored_sql_files' => $ignored];
}

// ---------------------------------------------------------------- file copy

// Copy one file. The result names the path and carries the system error text.
function copy_file($src, $dst)
{
	$parent = dirname($dst);
	if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
		$last = error_get_last();
		return ['success' => false, 'path' => $parent, 'error' => 'Cannot create directory ' . $parent . ': ' . (isset($last['message']) ? $last['message'] : 'mkdir failed')];
	}
	if (!@copy($src, $dst)) {
		$last = error_get_last();
		return ['success' => false, 'path' => $dst, 'error' => 'Cannot write ' . $dst . ': ' . (isset($last['message']) ? $last['message'] : 'copy failed')];
	}
	return ['success' => true, 'path' => $dst];
}

// ------------------------------------------------------------------ restore

// Extract a backup. Ownership-blind: files get the owner of the user running this,
// permissions follow the umask, and modification times are set to now.
function restore_archive_command($archive, $target)
{
	return 'tar -xzf ' . escapeshellarg($archive)
		. ' -C ' . escapeshellarg($target)
		. ' --no-same-owner --no-same-permissions --touch 2>&1';
}

function update_read_version_file($panel_dir)
{
	$file = rtrim($panel_dir, '/') . '/VERSION';
	return is_file($file) ? trim((string)@file_get_contents($file)) : '';
}

function update_table_list($db)
{
	$tables = [];
	$rows = $db->resultQuery('SHOW TABLES');
	if (is_array($rows)) {
		foreach ($rows as $row) {
			$tables[] = (string)reset($row);
		}
	}
	sort($tables);
	return $tables;
}

// The state the update starts from. Rollback has to bring the panel back to exactly this.
function update_capture_baseline($db, $panel_dir)
{
	$applied = array_keys(migrations_get_applied($db));
	sort($applied);
	return [
		'version_file' => update_read_version_file($panel_dir),
		'applied_migrations' => $applied,
		'tables' => update_table_list($db),
	];
}

// Tables created during the failed update are not in the dump. Remove them.
function update_drop_new_tables($db, array $baseline)
{
	foreach (update_table_list($db) as $table) {
		if (!in_array($table, $baseline['tables'], true)) {
			update_log('Rollback: dropping table created during the failed update: ' . $table);
			$db->query('DROP TABLE `' . str_replace('`', '', $table) . '`');
			if ((string)$db->getError() !== '') {
				return ['success' => false, 'error' => 'Cannot drop table ' . $table . ': ' . $db->getError()];
			}
		}
	}
	return ['success' => true];
}

// Files the failed update created did not exist before. The archive restore cannot remove them.
function update_remove_created_files($panel_dir, array $created_relative)
{
	foreach ($created_relative as $relative) {
		$path = rtrim($panel_dir, '/') . '/' . $relative;
		if (is_file($path) && !@unlink($path)) {
			return ['success' => false, 'error' => 'Cannot remove file created by the failed update: ' . $path];
		}
	}
	return ['success' => true];
}

// After a rollback: VERSION and schema_migrations must match the state the update began from.
function update_verify_restored_state($db, $panel_dir, array $baseline)
{
	$version = update_read_version_file($panel_dir);
	if ($version !== $baseline['version_file']) {
		return ['success' => false, 'error' => 'VERSION is "' . $version . '" after rollback, expected "' . $baseline['version_file'] . '".'];
	}
	$applied = array_keys(migrations_get_applied($db));
	sort($applied);
	if ($applied !== $baseline['applied_migrations']) {
		return ['success' => false, 'error' => 'schema_migrations does not match the pre-update state after rollback.'];
	}
	return ['success' => true];
}

// After an update: VERSION is the staged version and every staged migration is recorded.
function update_verify_new_state($db, $panel_dir, array $staged)
{
	$version = update_read_version_file($panel_dir);
	if ($version !== $staged['version']) {
		return ['success' => false, 'error' => 'VERSION is "' . $version . '" after the copy, expected "' . $staged['version'] . '".'];
	}
	$applied = migrations_get_applied($db);
	foreach ($staged['migrations'] as $id) {
		if (!isset($applied[$id])) {
			return ['success' => false, 'error' => 'Migration ' . $id . ' is not recorded in schema_migrations.'];
		}
	}
	return ['success' => true];
}

/**
 * Run the update as one unit. $ops are callables returning ['success' => bool, 'error' => string]:
 *   copy_files, restore_runtime, run_migrations, write_version, verify_update   (forward steps, in order)
 *   rollback_files, rollback_database, verify_rollback                           (used only after a failure)
 * On a forward failure BOTH rollback_files and rollback_database run, then verify_rollback.
 * The result says whether the rollback worked: rolled_back = true only after verify_rollback passes.
 */
function update_run_transaction(array $ops, $backup_dir)
{
	foreach (['copy_files', 'restore_runtime', 'run_migrations', 'write_version', 'verify_update'] as $step) {
		$result = $ops[$step]();
		if (!$result['success']) {
			return update_rollback_all($ops, $step, $result['error'], $backup_dir);
		}
	}
	return ['success' => true];
}

function update_rollback_all(array $ops, $failed_step, $error, $backup_dir)
{
	update_log('Update failed at step ' . $failed_step . ': ' . $error . ' Rolling back files and database from ' . $backup_dir);
	$failures = [];
	foreach (['rollback_files' => 'restore files', 'rollback_database' => 'restore database'] as $key => $label) {
		$result = $ops[$key]();
		if (!$result['success']) {
			$failures[] = 'rollback step "' . $label . '" failed: ' . $result['error'];
		}
	}
	if (empty($failures)) {
		$verify = $ops['verify_rollback']();
		if (!$verify['success']) {
			$failures[] = 'rollback step "verify restored state" failed: ' . $verify['error'];
		}
	}
	if (!empty($failures)) {
		$message = 'Update failed at ' . $failed_step . ' (' . $error . '). ' . implode(' ', $failures) . ' Backup is kept at ' . $backup_dir . '.';
		update_log('ROLLBACK FAILED. ' . $message);
		return ['success' => false, 'rolled_back' => false, 'failed_step' => $failed_step, 'error' => $message];
	}
	$message = 'Update failed at ' . $failed_step . ' (' . $error . '). Files and database were restored from ' . $backup_dir . ' and verified.';
	update_log($message);
	return ['success' => false, 'rolled_back' => true, 'failed_step' => $failed_step, 'error' => $message];
}
