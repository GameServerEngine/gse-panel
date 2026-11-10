<?php
// Panel update safety: preflight, copy restore, files + database rollback, no channels.
// Run: php modules/update/tests/update_safety_test.php   (exit 0 = all passed)
// Uses only temp directories and fake database objects. No real panel, database or network is touched.

$work = sys_get_temp_dir() . '/update_safety_test_' . getmypid();
mkdir($work . '/logs', 0777, true);
define('BACKUP_BASE', $work . '/backup-base');
define('UPDATE_LOG', $work . '/logs/update_trace.log');
// The temp panel is owned by whoever runs the test, so that user is the expected owner here.
define('UPDATE_WEB_USER', function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'www-data');
$settings = array();
require_once(dirname(__DIR__, 2) . '/administration/panel_update.php');

$passed = 0;
function safety_assert($condition, $message)
{
	global $passed;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$passed++;
	echo "PASS: {$message}\n";
}

function safety_is_root()
{
	return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/** Name, size, mtime and hash of every file and directory under $dir. */
function tree_state($dir)
{
	$state = array();
	$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($iter as $item) {
		$state[$item->getPathname()] = $item->isFile() ? md5_file($item->getPathname()) . '@' . $item->getMTime() : 'dir';
	}
	ksort($state);
	return $state;
}

/** A small fake panel with a config file the update can read database settings from. */
function make_panel($path)
{
	mkdir($path . '/includes', 0777, true);
	file_put_contents($path . '/includes/config.inc.php', "<?php\n\$db_user = 'u';\n\$db_name = 'n';\n");
	file_put_contents($path . '/VERSION', "2.5.4\n");
}

function make_cfg($panel)
{
	return array('repo_root' => $panel, 'panel_path' => $panel, 'backup_path' => BACKUP_BASE, 'panel_source_path' => '.');
}

// ---- 1. preflight: a non-writable runtime file stops the update and changes nothing
$panel = $work . '/panel_ok';
make_panel($panel);
$ok = preflight_check(make_cfg($panel), false);
safety_assert($ok['success'], 'preflight passes on a writable panel (' . implode(' | ', $ok['errors']) . ')');
safety_assert(is_dir(BACKUP_BASE) && count(glob(BACKUP_BASE . '/*')) === 0, 'passing preflight creates the empty backup directory and no backup');

if (safety_is_root()) {
	echo "SKIP: running as root, a file cannot be made non-writable; preflight failure test not run\n";
} else {
	$panel = $work . '/panel_locked';
	make_panel($panel);
	chmod($panel . '/includes/config.inc.php', 0444);
	$before = tree_state($panel);
	$result = preflight_check(make_cfg($panel), false);
	$text = implode(' | ', $result['errors']);
	safety_assert(!$result['success'], 'preflight fails when includes/config.inc.php is not writable');
	safety_assert(strpos($text, $panel . '/includes/config.inc.php') !== false, 'preflight error names the path');
	safety_assert(strpos($text, '--repair-permissions --path ' . $panel) !== false && strpos($text, '--group www-data') !== false, 'preflight error gives the repair-permissions fix');
	safety_assert(tree_state($panel) === $before, 'failed preflight changed no file in the panel');
	safety_assert(count(glob(BACKUP_BASE . '/*')) === 0, 'failed preflight did not create a backup');
	chmod($panel . '/includes/config.inc.php', 0644);
}
safety_assert(update_check_free_space($work, PHP_INT_MAX) !== '', 'preflight reports too little free disk space');

// ---- 2. restore is a copy back from the plain backup folder
$src = $work . '/copy_src';
mkdir($src, 0777, true);
file_put_contents($src . '/a.txt', 'hello');
$dest = $work . '/copy_dest';
mkdir($dest);
$restore = update_backup_restore($src, $dest);
safety_assert($restore['success'] && file_get_contents($dest . '/a.txt') === 'hello', 'update_backup_restore copies the backup folder back');
safety_assert(!update_backup_restore($work . '/no_such_backup', $dest)['success'], 'restore from a missing backup folder fails');
// Replaces the old tar --no-same-owner check: the restore copy must never copy owners (it runs as the web user, or root).
safety_assert(strpos(file_get_contents(dirname(__DIR__) . '/update_safety.php'), 'cp -a --no-preserve=ownership') !== false, 'restore copy does not preserve owners (cp --no-preserve=ownership)');

// ---- 3. copy_file reports the failing path; the tree copy stops
$copy = copy_file($work . '/does-not-exist', $work . '/copy_out/x');
safety_assert($copy['success'] === false && strpos($copy['error'], $work . '/copy_out/x') !== false, 'copy_file failure names the path and the system error');
if (!safety_is_root()) {
	$release = $work . '/release';
	mkdir($release);
	file_put_contents($release . '/new.txt', 'n');
	$live = $work . '/live';
	mkdir($live);
	chmod($live, 0555);
	$tree = component_copy_tree($release, $live, 'Panel');
	chmod($live, 0755);
	safety_assert($tree['success'] === false && strpos($tree['error'], $live . '/new.txt') !== false, 'component_copy_tree stops and names the file it cannot write');
}

// ---- 4. a migration failure restores files AND database, then verifies
class FailingDb
{
	private $error = '';
	public function real_escape_string($s) { return addslashes((string)$s); }
	public function getError() { return $this->error; }
	public function resultQuery($sql) { return array(); }
	public function query($sql)
	{
		if (strpos($sql, 'BREAK_ME') !== false) {
			$this->error = 'syntax error near BREAK_ME';
			return false;
		}
		return true;
	}
}
$migrations = $work . '/migrations';
mkdir($migrations);
file_put_contents($migrations . '/0001_ok.sql', 'CREATE TABLE a (id int);');
file_put_contents($migrations . '/0002_bad.sql', 'BREAK_ME;');

function transaction_ops(array &$calls, array $overrides = array())
{
	$ok = function ($name) use (&$calls) {
		return function () use ($name, &$calls) {
			$calls[] = $name;
			return array('success' => true);
		};
	};
	$ops = array();
	foreach (array('copy_files', 'restore_runtime', 'run_migrations', 'write_version', 'verify_update', 'rollback_files', 'rollback_database', 'verify_rollback') as $name) {
		$ops[$name] = $ok($name);
	}
	return array_merge($ops, $overrides);
}

$calls = array();
$ops = transaction_ops($calls, array(
	'run_migrations' => function () use (&$calls, $migrations) {
		$calls[] = 'run_migrations';
		$run = migrations_run_pending(new FailingDb(), $migrations, function ($m) {});
		return array('success' => $run['success'], 'error' => 'Migration failure at ' . $run['failed_migration'] . ': ' . $run['error']);
	},
));
$result = update_run_transaction($ops, '/backups/panel-update_x');
safety_assert($result['success'] === false && $result['rolled_back'] === true, 'migration failure ends in a verified rollback');
safety_assert(in_array('rollback_files', $calls, true) && in_array('rollback_database', $calls, true), 'migration failure restores both the files and the database');
safety_assert(end($calls) === 'verify_rollback', 'rollback is verified after both restores');
safety_assert(!in_array('write_version', $calls, true), 'no later step ran after the migration failed');
safety_assert(strpos($result['error'], '0002_bad') !== false, 'the error names the failed migration');

$calls = array();
$result = update_run_transaction(transaction_ops($calls, array(
	'copy_files' => function () use (&$calls) {
		$calls[] = 'copy_files';
		return array('success' => false, 'error' => 'Cannot write /panel/x.php: Permission denied');
	},
)), '/backups/b1');
safety_assert($result['rolled_back'] === true && in_array('rollback_database', $calls, true) && !in_array('run_migrations', $calls, true), 'a file copy failure rolls back files and database and runs no migration');

$calls = array();
$result = update_run_transaction(transaction_ops($calls), '/backups/b2');
safety_assert($result['success'] === true && !in_array('rollback_files', $calls, true), 'a clean update runs no rollback');

$calls = array();
$result = update_run_transaction(transaction_ops($calls, array(
	'run_migrations' => function () { return array('success' => false, 'error' => 'boom'); },
	'rollback_database' => function () { return array('success' => false, 'error' => 'mysql restore failed'); },
)), '/backups/b3');
safety_assert($result['rolled_back'] === false && strpos($result['error'], 'restore database') !== false && strpos($result['error'], '/backups/b3') !== false, 'failed rollback names the failed step and keeps the backup path in the message');
safety_assert(strpos(file_get_contents(UPDATE_LOG), '/backups/b3') !== false, 'failed rollback leaves the backup path in the log');
safety_assert(!in_array('verify_rollback', $calls, true), 'rollback is not reported verified when a restore step failed');

$calls = array();
$result = update_run_transaction(transaction_ops($calls, array(
	'run_migrations' => function () { return array('success' => false, 'error' => 'boom'); },
	'verify_rollback' => function () { return array('success' => false, 'error' => 'VERSION is 2.5.5 after rollback'); },
)), '/backups/b4');
safety_assert($result['rolled_back'] === false && strpos($result['error'], 'verify restored state') !== false, 'rollback that fails verification is reported as failed');

// ---- 5. verification reads VERSION and schema_migrations
class VerifyDb
{
	public $applied = array();
	public function resultQuery($sql)
	{
		$rows = array();
		foreach ($this->applied as $id) {
			$rows[] = array('migration_id' => $id, 'applied_at' => '2026-01-01 00:00:00');
		}
		return $rows;
	}
}
$panel = $work . '/panel_verify';
make_panel($panel);
$vdb = new VerifyDb();
$vdb->applied = array('0001_ok');
$baseline = array('version_file' => '2.5.4', 'applied_migrations' => array('0001_ok'), 'tables' => array(), 'file_manifest' => update_file_manifest($panel));
safety_assert(update_verify_restored_state($vdb, $panel, $baseline)['success'], 'verification passes when VERSION and schema_migrations match the start');
file_put_contents($panel . '/VERSION', "2.5.5\n");
safety_assert(!update_verify_restored_state($vdb, $panel, $baseline)['success'], 'verification fails when VERSION is not the restored version');
file_put_contents($panel . '/VERSION', "2.5.4\n");
$vdb->applied = array('0001_ok', '0002_new');
safety_assert(!update_verify_restored_state($vdb, $panel, $baseline)['success'], 'verification fails when schema_migrations still has a new migration');

// verification compares runtime files and the panel tree, and names the file that differs
$vdb->applied = array('0001_ok');
mkdir($panel . '/modules/demo', 0777, true);
file_put_contents($panel . '/modules/demo/a.php', "<?php // a\n");
$baseline['file_manifest'] = update_file_manifest($panel);
safety_assert(update_verify_restored_state($vdb, $panel, $baseline)['success'], 'verification passes when every file matches the snapshot');
file_put_contents($panel . '/includes/config.inc.php', "<?php // changed\n");
$result = update_verify_restored_state($vdb, $panel, $baseline);
safety_assert(!$result['success'] && strpos($result['error'], 'changed: includes/config.inc.php') !== false, 'verification fails and names a changed runtime file');
file_put_contents($panel . '/includes/config.inc.php', "<?php\n\$db_user = 'u';\n\$db_name = 'n';\n");
file_put_contents($panel . '/modules/demo/a.php', "<?php // edited\n");
$result = update_verify_restored_state($vdb, $panel, $baseline);
safety_assert(!$result['success'] && strpos($result['error'], 'changed: modules/demo/a.php') !== false, 'verification fails and names a changed panel file');
file_put_contents($panel . '/modules/demo/a.php', "<?php // a\n");
file_put_contents($panel . '/modules/demo/new.php', 'x');
$result = update_verify_restored_state($vdb, $panel, $baseline);
safety_assert(!$result['success'] && strpos($result['error'], 'extra: modules/demo/new.php') !== false, 'verification names a file the failed update left behind');
unlink($panel . '/modules/demo/new.php');
unlink($panel . '/modules/demo/a.php');
$result = update_verify_restored_state($vdb, $panel, $baseline);
safety_assert(!$result['success'] && strpos($result['error'], 'missing: modules/demo/a.php') !== false, 'verification names a file that was not restored');

// ---- 5b. backup directory: created when the parent is writable, refused with the repair command otherwise
$parent = $work . '/backup_parent';
mkdir($parent, 0777, true);
safety_assert(update_ensure_backup_base($parent . '/backups') === '' && is_dir($parent . '/backups'), 'backup directory is created when its parent is writable');
if (!safety_is_root()) {
	$panel = $work . '/panel_nobackup';
	make_panel($panel);
	mkdir($work . '/ro_parent', 0555);
	$errors = update_preflight_errors($panel, $work . '/ro_parent/backups', false);
	$text = implode(' | ', $errors);
	safety_assert(strpos($text, 'Backup directory does not exist') !== false && strpos($text, '--repair-permissions') !== false, 'preflight refuses a missing backup directory and gives the repair command');
	chmod($work . '/ro_parent', 0755);
}

// ---- 6. staging validation
$stage = $work . '/stage';
mkdir($stage . '/sql/migrations', 0777, true);
safety_assert(!validate_staged_release($stage)['success'], 'staged release without a VERSION file is refused');
file_put_contents($stage . '/VERSION', "2.5.5\n");
file_put_contents($stage . '/sql/migrations/0001_ok.sql', 'CREATE TABLE a (id int);');
file_put_contents($stage . '/sql/migrations/2026-01-01-ignored.sql', 'SELECT 1;');
$valid = validate_staged_release($stage);
safety_assert($valid['success'] && $valid['version'] === '2.5.5' && $valid['migrations'] === array('0001_ok'), 'valid staged release gives its version and migrations');
safety_assert($valid['ignored_sql_files'] === array('2026-01-01-ignored.sql'), 'migration files the runner would not run are reported');
file_put_contents($stage . '/sql/migrations/0002_empty.sql', "-- nothing\n");
safety_assert(!validate_staged_release($stage)['success'], 'staged release with an unparseable migration is refused');

// ---- 7. no channels in release selection
$source = file_get_contents(dirname(__DIR__, 2) . '/administration/panel_update.php')
	. file_get_contents(dirname(__DIR__) . '/update_safety.php');
foreach (array('release_channel_url', 'is_valid_release_channel', 'DEFAULT_RELEASE_CHANNEL') as $name) {
	safety_assert(strpos($source, $name) === false, "updater no longer has {$name}");
}
safety_assert(!function_exists('release_channel_url') && !function_exists('is_valid_release_channel'), 'channel functions are gone');
$rows = array(
	array('tag' => 'stable-2.5.4', 'channel' => 'stable', 'version' => '2.5.4', 'created' => '2026-11-01'),
	array('tag' => 'testing-2.5.5', 'channel' => 'testing', 'version' => '2.5.5', 'created' => '2026-11-09'),
);
$html = tag_select('row_tag[panel]', $rows, '2.5.4');
safety_assert(strpos($html, 'optgroup') === false && strpos($html, '>Stable 2.5.4 (2026-11-01)') !== false && strpos($html, '>Testing 2.5.5 (2026-11-09)') !== false, 'release list has no channel groups and labels each release Stable/Testing');
safety_assert(strpos($html, "value='testing-2.5.5'") !== false && strpos($html, 'Stable 2.5.4 (2026-11-01) - installed') !== false, 'release list still offers every release and marks the installed one');
$all_rows = array(
	array('tag' => 'stable-2.6.1', 'channel' => 'stable', 'version' => '2.6.1', 'created' => '2026-11-05'),
	array('tag' => 'testing-2.7.0', 'channel' => 'testing', 'version' => '2.7.0', 'created' => '2026-11-09'),
	array('tag' => 'working-latest', 'channel' => 'working', 'version' => '2.7.1', 'created' => '2026-11-10'),
);
$html = tag_select('row_tag[panel]', $all_rows, '');
safety_assert(strpos($html, "<option value=''>Choose a release</option>") !== false && strpos($html, 'selected') === false, 'dropdown has no default release: the first option is "Choose a release"');
safety_assert(strpos($html, "value='working-latest'>Testing Latest 2.7.1 (2026-11-10)") !== false && strpos($html, "value='stable-2.6.1'>Stable 2.6.1 (2026-11-05)") !== false, 'every option shows its tag, stored values unchanged');
safety_assert(strpos($html, 'Stable 2.6.1') < strpos($html, 'Testing 2.7.0') && strpos($html, 'Testing 2.7.0') < strpos($html, 'Testing Latest 2.7.1'), 'options are ordered Stable, Testing, Testing Latest');
$installer = file_get_contents(dirname(__DIR__, 3) . '/scripts/install-panel.sh');
safety_assert(strpos($installer, 'release_zip_url_for_channel') === false && strpos($installer, 'DEFAULT_RELEASE_CHANNEL') === false, 'installer has no channel mapping');
safety_assert(strpos($installer, 'create_backup_directory "$group"') !== false && strpos($installer, 'chown "$DEFAULT_WEB_USER:$group" "$BACKUP_DIR"') !== false, 'installer repair creates the backup directory owned by the web user');
safety_assert(strpos($installer, '--no-owner --no-group') !== false, 'installer rsync does not copy owners');

exec('rm -rf ' . escapeshellarg($work));
echo "All {$passed} update safety checks passed.\n";
