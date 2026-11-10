<?php
// One rollback copy: only one backup remains after two updates, and the notice text.
// Uses a temp directory only. Run: php modules/update/tests/rollback_retention_test.php
define('ROOT_DIR', sys_get_temp_dir());
require_once dirname(__DIR__) . '/release_source.php';
require_once dirname(__DIR__) . '/update_safety.php';

function rb_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

// Same steps as create_full_backup: make the folder, verify it, then delete the others.
function rb_make_backup($base, $name, $panel, $from_version, $from_tag)
{
	$dir = $base . '/' . $name;
	mkdir($dir . '/panel', 0777, true);
	file_put_contents($dir . '/database.sql', '-- dump');
	file_put_contents($dir . '/backup.json', json_encode(array('from_version' => $from_version, 'from_tag' => $from_tag)));
	$copy = update_backup_copy($panel, $dir . '/panel', array($base));
	rb_assert($copy['success'], 'backup copy made for ' . $name);
	rb_assert(update_backup_verify($dir)['success'], 'backup verified for ' . $name);
	return update_keep_only_backup($base, $name);
}

$work = sys_get_temp_dir() . '/rollback_retention_' . getmypid();
mkdir($work . '/panel', 0777, true);
file_put_contents($work . '/panel/VERSION', "1.0.0\n");
$base = $work . '/backups';
mkdir($base);
file_put_contents($base . '/keep-me.txt', 'not a backup folder');

rb_assert(update_rollback_record($base) === null, 'no backup folder gives no record');
rb_assert(update_rollback_notice(null) === 'No rollback copy yet.', 'notice with no backup: No rollback copy yet.');

rb_make_backup($base, '2026-11-10_01-00-00-1.0.0', $work . '/panel', '1.0.0', 'Stable');
rb_assert(count(update_backup_folder_names($base)) === 1, 'one backup after the first update');

// A broken new backup must not delete the old one: verify fails, so keep_only is never called.
$bad = $base . '/2026-11-10_02-00-00-1.1.0';
mkdir($bad);
rb_assert(!update_backup_verify($bad)['success'], 'an empty backup folder fails the check');
update_remove_tree($bad);
rb_assert(count(update_backup_folder_names($base)) === 1, 'old backup still there when the new one failed the check');

file_put_contents($work . '/panel/VERSION', "1.1.0\n");
$deleted = rb_make_backup($base, '2026-11-10_03-00-00-1.1.0', $work . '/panel', '1.1.0', 'Testing Latest');
rb_assert(count(update_backup_folder_names($base)) === 1, 'only one backup remains after two updates');
rb_assert($deleted === array('2026-11-10_01-00-00-1.0.0'), 'the older backup was the one deleted');
rb_assert(is_file($base . '/keep-me.txt'), 'files that are not backup folders are left alone');

$record = update_rollback_record($base);
rb_assert($record['name'] === '2026-11-10_03-00-00-1.1.0', 'the record is the newest backup');
rb_assert(trim(file_get_contents($record['path'] . '/panel/VERSION')) === '1.1.0', 'the backup holds the panel as it was before the update');
rb_assert(release_rollback_button_label($record['meta']['from_version'], $record['meta']['from_tag']) === 'Roll back to 1.1.0 (Testing Latest)', 'button text comes from the backup record');
$expected = 'Rollback copy is stored in ' . $base . '/2026-11-10_03-00-00-1.1.0. To restore a single file, copy it from that folder back into the panel folder.';
rb_assert(update_rollback_notice($record) === $expected, 'notice shows the real backup path and the restore sentence');

update_remove_tree($work);
rb_assert(!file_exists($work), 'temp directory removed');
echo "All rollback retention tests passed.\n";
