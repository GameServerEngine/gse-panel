<?php
// Update manifest, exclusions file, copy backup and backup retention.
// Run: php modules/update/tests/update_manifest_test.php   (exit 0 = all passed)
// Uses only temp directories, removed at the end. No real panel, backup folder or database is touched.

$work = sys_get_temp_dir() . '/update_manifest_test_' . getmypid();
mkdir($work . '/logs', 0777, true);
define('BACKUP_BASE', $work . '/backup-base');
define('UPDATE_LOG', $work . '/logs/update_trace.log');
define('UPDATE_WEB_USER', function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'www-data');
$settings = array();
require_once(dirname(__DIR__, 2) . '/administration/panel_update.php');

$passed = 0;
function mf_assert($condition, $message)
{
	global $passed;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$passed++;
	echo "PASS: {$message}\n";
}

function mf_put($path, $content)
{
	if (!is_dir(dirname($path))) {
		mkdir(dirname($path), 0777, true);
	}
	file_put_contents($path, $content);
}

function mf_remove($dir)
{
	if (is_link($dir) || is_file($dir)) {
		unlink($dir);
		return;
	}
	if (!is_dir($dir)) {
		return;
	}
	chmod($dir, 0777);
	foreach (scandir($dir) as $name) {
		if ($name !== '.' && $name !== '..') {
			mf_remove($dir . '/' . $name);
		}
	}
	rmdir($dir);
}

// ---- 1. the manifest is created on the first run
$panel = $work . '/panel';
mf_put($panel . '/index.php', 'one');
mf_put($panel . '/modules/a/a.php', 'aaa');
mf_assert(!is_file(um_manifest_file($panel)), 'no manifest before the first update');
mf_assert(um_record_install($panel, array('index.php', 'modules/a/a.php'), '2.6.0') === '', 'first update writes the manifest');
$loaded = um_manifest_load($panel);
$files = $loaded['manifest']['files'];
mf_assert($loaded['exists'] && isset($files['index.php']) && isset($files['modules/a/a.php']), 'manifest lists every installed file');
mf_assert($files['index.php']['sha256'] === hash('sha256', 'one'), 'manifest records the sha256 of the installed content');
mf_assert($files['index.php']['version'] === '2.6.0', 'manifest records the release version that installed the file');
mf_assert(is_file(um_exclusions_file($panel)), 'update_exclusions.txt is created on the first run');
$raw = file_get_contents(um_manifest_file($panel));
mf_assert(strpos($raw, "\"exclusions\": [") !== false && json_decode($raw, true) !== null, 'manifest is valid JSON with an exclusions list');

// ---- 2. later runs edit the manifest, they do not replace it
$user_exclusion = "# my own note\nmodules/keepme/\n\nconfig/site.ini\n";
file_put_contents(um_exclusions_file($panel), $user_exclusion);
mf_put($panel . '/new.php', 'new');
$inode_before = fileinode(um_manifest_file($panel));
mf_assert(um_record_install($panel, array('new.php'), '2.6.1') === '', 'second update edits the manifest');
$after = um_manifest_load($panel)['manifest'];
mf_assert($after['files']['index.php']['version'] === '2.6.0' && $after['files']['new.php']['version'] === '2.6.1', 'earlier entries keep their version, the new file is added with the new version');
mf_assert(count($after['files']) === 3, 'manifest now lists three files');
mf_assert($after['exclusions'] === array('modules/keepme/', 'config/site.ini'), 'exclusions from update_exclusions.txt are merged into the manifest');
mf_assert(file_get_contents(um_exclusions_file($panel)) === $user_exclusion, 'update_exclusions.txt is not overwritten by the update');
mf_put($panel . '/index.php', 'one, installed again');
um_record_install($panel, array('index.php'), '2.6.2');
$after = um_manifest_load($panel)['manifest'];
mf_assert($after['files']['index.php']['version'] === '2.6.2' && $after['files']['index.php']['sha256'] === hash('sha256', 'one, installed again'), 'a file installed again gets the new hash and version');

// ---- 3. a person can edit update_exclusions.txt by hand
file_put_contents(um_exclusions_file($panel), "# only one now\nmodules/keepme/\n");
$synced = um_sync_exclusions($panel, $after);
mf_assert($synced['exclusions'] === array('modules/keepme/'), 'a line deleted from the text file is removed from the manifest');
mf_assert(um_read_exclusions_file($panel) === array('modules/keepme/'), 'comments and blank lines are ignored');
file_put_contents(um_exclusions_file($panel), "../etc/passwd\n/abs/path.php\n./rel/x.php\n");
mf_assert(um_read_exclusions_file($panel) === array('abs/path.php', 'rel/x.php'), 'paths with .. are dropped, leading / and ./ are removed');
unlink(um_exclusions_file($panel));
$synced = um_sync_exclusions($panel, array('files' => array(), 'exclusions' => array('x/'), 'edited_decisions' => array()));
mf_assert(um_read_exclusions_file($panel) === array('x/'), 'a missing text file is created from the manifest list');

// ---- 4. a broken manifest is never replaced
file_put_contents(um_manifest_file($panel), '{not json');
$error = um_record_install($panel, array('index.php'), '2.6.3');
mf_assert($error !== '' && file_get_contents(um_manifest_file($panel)) === '{not json', 'a manifest that is not valid JSON is reported and left as it is');

// ---- 5. the backup is a plain folder copy, no archive
$live = $work . '/live';
mf_put($live . '/index.php', '<?php // index');
mf_put($live . '/modules/a/a.php', 'aaa');
mf_put($live . '/logs/big.log', 'log');
mf_put($live . '/.hidden', 'dot');
$backups = $work . '/backups-area';
mkdir($backups);
$name = update_backup_folder_name('2026-11-10_04-12-33', '2.5.10');
mf_assert($name === '2026-11-10_04-12-33-2.5.10', 'backup folder is named <timestamp>-<from_version>');
$made = update_backup_copy($live, $backups . '/' . $name . '/panel', update_backup_excluded_roots($backups));
mf_assert($made['success'], 'backup copy succeeds (' . $made['error'] . ')');
$copy = $backups . '/' . $name . '/panel';
mf_assert(is_dir($copy) && is_file($copy . '/modules/a/a.php') && file_get_contents($copy . '/modules/a/a.php') === 'aaa', 'backup is a plain folder with the files in it');
mf_assert(is_file($copy . '/.hidden') && is_file($copy . '/logs/big.log'), 'hidden files and logs are in the full copy');
$archives = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backups, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
	if (preg_match('/\.(tar|tgz|gz|zip|bz2|xz)$/i', $f->getFilename())) {
		$archives[] = $f->getPathname();
	}
}
mf_assert(empty($archives), 'no archive file is made by the backup');
// copy a file back by hand
unlink($live . '/modules/a/a.php');
copy($copy . '/modules/a/a.php', $live . '/modules/a/a.php');
mf_assert(file_get_contents($live . '/modules/a/a.php') === 'aaa', 'a single file can be copied back by hand');
// restore is a copy back
file_put_contents($live . '/index.php', 'broken');
mf_put($live . '/created_by_failed_update.php', 'x');
mf_assert(update_backup_restore($copy, $live)['success'] && file_get_contents($live . '/index.php') === '<?php // index', 'restore copies the files back from the backup folder');

// ---- 6. the backup folder is never copied into itself
$inside = $work . '/inside';
mf_put($inside . '/a.php', 'a');
mkdir($inside . '/backup-area');
mf_put($inside . '/backup-area/old/file.txt', 'old');
$made = update_backup_copy($inside, $work . '/inside-copy', array($inside . '/backup-area'));
mf_assert($made['success'] && is_file($work . '/inside-copy/a.php') && !file_exists($work . '/inside-copy/backup-area'), 'a backup folder inside the panel is left out of the copy');
$made = update_backup_copy($inside, $inside . '/backup-area/new', array($inside . '/backup-area'));
mf_assert($made['success'] && !file_exists($inside . '/backup-area/new/backup-area'), 'the copy does not contain the folder it is written into');
$made = update_backup_copy($inside, $work . '/x-copy', array($inside . '/backup-area/old'));
mf_assert($made['success'] === false && strpos($made['error'], 'is inside') !== false, 'a backup folder inside a top-level entry stops the copy with a clear message');
mf_assert(in_array('/var/lib/gse-panel', update_backup_excluded_roots($backups), true), '/var/lib/gse-panel is always excluded from the copy');

// ---- 7. retention: only the newest backup is kept (more in rollback_retention_test.php)
$base = $work . '/retention';
mkdir($base);
foreach (array('2026-11-01_10-00-00-2.5.7', '2026-11-03_10-00-00-2.5.8', '2026-11-05_10-00-00-2.5.9', '2026-11-10_04-12-33-2.5.10') as $dir) {
	mf_put($base . '/' . $dir . '/panel/VERSION', $dir);
}
mkdir($base . '/not-a-backup');
$deleted = update_keep_only_backup($base, '2026-11-10_04-12-33-2.5.10');
$left = array_values(array_diff(scandir($base), array('.', '..')));
sort($left);
mf_assert($left === array('2026-11-10_04-12-33-2.5.10', 'not-a-backup'), 'only the new backup is kept');
mf_assert(count($deleted) === 3, 'three older backups were deleted');
mf_assert(is_safe_backup_name('2026-11-10_04-12-33-2.5.10') && !is_safe_backup_name('../2026-11-10_04-12-33-2.5.10'), 'new backup folder names are accepted, unsafe ones are not');

// ---- 8. the update never overwrites its own records
mf_assert(update_should_skip('update_manifest.json') && update_should_skip('update_exclusions.txt'), 'release files cannot replace update_manifest.json or update_exclusions.txt');
mf_assert(!isset(update_file_manifest($panel)['update_manifest.json']), 'the rollback check ignores the update records');

// ---- 9. the repair only touches paths inside the panel root
$fake_touched = array();
$ops = array(
	'owner_of' => function ($p) use (&$fake_touched) { $fake_touched[] = $p; return 'root'; },
	'group_of' => function ($p) { return 'root'; },
	'mode_of' => function ($p) { return 0600; },
	'chown' => function ($p, $o, $g) { return ''; },
	'chmod' => function ($p, $m) { return ''; },
);
$result = perm_repair_paths($panel, array($panel . '/index.php', '/etc/shadow', $work . '/live/index.php'), 'www-data', 'www-data', $ops);
mf_assert($fake_touched === array($panel . '/index.php') && count($result['outside']) === 2, 'repair reads and changes only the path inside the panel root');

mf_remove($work);
mf_assert(!file_exists($work), 'test artifacts are removed');
echo "All {$passed} manifest checks passed.\n";
exit(0);
