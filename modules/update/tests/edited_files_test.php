<?php
// Edited-file review: detection by sha256, folder keep, defaults, and excluded paths during the copy.
// Run: php modules/update/tests/edited_files_test.php   (exit 0 = all passed)
// Uses only temp directories, removed at the end. No real panel or database is touched.

$work = sys_get_temp_dir() . '/edited_files_test_' . getmypid();
mkdir($work . '/logs', 0777, true);
define('BACKUP_BASE', $work . '/backup-base');
define('UPDATE_LOG', $work . '/logs/update_trace.log');
define('UPDATE_WEB_USER', function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'www-data');
$settings = array();
require_once(dirname(__DIR__, 2) . '/administration/panel_update.php');

$passed = 0;
function ed_assert($condition, $message)
{
	global $passed;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$passed++;
	echo "PASS: {$message}\n";
}

function ed_put($path, $content)
{
	if (!is_dir(dirname($path))) {
		mkdir(dirname($path), 0777, true);
	}
	file_put_contents($path, $content);
}

function ed_remove($dir)
{
	if (is_link($dir) || is_file($dir)) {
		unlink($dir);
		return;
	}
	if (!is_dir($dir)) {
		return;
	}
	foreach (scandir($dir) as $name) {
		if ($name !== '.' && $name !== '..') {
			ed_remove($dir . '/' . $name);
		}
	}
	rmdir($dir);
}

/** A panel as the previous update left it: every file installed and recorded in the manifest. */
function ed_make_panel($panel)
{
	ed_put($panel . '/index.php', 'index v1');
	ed_put($panel . '/modules/forum/a.php', 'forum a v1');
	ed_put($panel . '/modules/forum/b.php', 'forum b v1');
	ed_put($panel . '/modules/forum/sub/c.php', 'forum c v1');
	ed_put($panel . '/modules/shop/s.php', 'shop v1');
	ed_put($panel . '/themes/dark.css', 'css v1');
	$files = array('index.php', 'modules/forum/a.php', 'modules/forum/b.php', 'modules/forum/sub/c.php', 'modules/shop/s.php', 'themes/dark.css');
	um_record_install($panel, $files, '2.6.0');
	return $files;
}

/** The new release: every file changed, plus one new file. */
function ed_make_release($release)
{
	ed_put($release . '/index.php', 'index v2');
	ed_put($release . '/modules/forum/a.php', 'forum a v2');
	ed_put($release . '/modules/forum/b.php', 'forum b v2');
	ed_put($release . '/modules/forum/sub/c.php', 'forum c v2');
	ed_put($release . '/modules/shop/s.php', 'shop v2');
	ed_put($release . '/themes/dark.css', 'css v2');
	ed_put($release . '/brand_new.php', 'new file');
	ed_put($release . '/update_manifest.json', 'release must not replace this');
	ed_put($release . '/update_exclusions.txt', 'release must not replace this');
}

// ---- 1. edited files are found by sha256
$panel = $work . '/panel';
ed_make_panel($panel);
$manifest = um_manifest_load($panel)['manifest'];
ed_assert(um_find_edited($panel, $manifest) === array(), 'an untouched install has no edited files');
ed_put($panel . '/modules/forum/a.php', 'forum a EDITED');
ed_put($panel . '/modules/forum/sub/c.php', 'forum c EDITED');
ed_put($panel . '/themes/dark.css', 'css EDITED');
unlink($panel . '/modules/shop/s.php');
ed_put($panel . '/not_in_manifest.php', 'user file');
$edited = um_find_edited($panel, $manifest);
ed_assert($edited === array('modules/forum/a.php', 'modules/forum/sub/c.php', 'themes/dark.css'), 'only files whose sha256 changed are listed');
ed_assert(!in_array('modules/shop/s.php', $edited, true), 'a deleted file is not an edit');
ed_assert(!in_array('not_in_manifest.php', $edited, true), 'a file the update never installed is not listed');
ed_put($panel . '/modules/forum/a.php', 'forum a v1');
ed_assert(!in_array('modules/forum/a.php', um_find_edited($panel, $manifest), true), 'putting the original content back clears the edit');
ed_put($panel . '/modules/forum/a.php', 'forum a EDITED');

// ---- 2. new files in the update are not listed; excluded paths are not offered for review
ed_assert(!in_array('brand_new.php', $edited, true), 'files only in the update are not listed');
ed_assert(um_find_edited($panel, $manifest, array('themes/')) === array('modules/forum/a.php', 'modules/forum/sub/c.php'), 'an excluded folder is not listed for review');

// ---- 3. grouping: each file is listed once, under its folder
$groups = um_group_by_folder($edited);
ed_assert(array_keys($groups) === array('modules/forum/', 'modules/forum/sub/', 'themes/'), 'edited files are grouped by their own folder');
ed_assert($groups['modules/forum/'] === array('modules/forum/a.php') && $groups['modules/forum/sub/'] === array('modules/forum/sub/c.php'), 'a file appears under one folder only');
ed_assert(um_folder_of('index.php') === './', 'a file in the panel root belongs to the "./" folder');

// ---- 4. default: keep my version when nothing is stored
ed_assert(um_keep_decision('modules/forum/a.php', array()) === true, 'with no stored decision the edited file is kept');
ed_assert(um_keep_decision('anything/at/all.php', array()) === true, 'default keep holds for every path');
ed_assert(um_keep_decision('themes/dark.css', array('themes/dark.css' => 'overwrite')) === false, 'an unchecked file is overwritten');
ed_assert(um_keep_decision('themes/dark.css', array('themes/dark.css' => 'keep')) === true, 'a checked file is kept');

// ---- 5. a folder keep covers every edited file under it
$decisions = array('modules/forum/' => 'keep', 'modules/forum/a.php' => 'overwrite');
ed_assert(um_keep_decision('modules/forum/a.php', $decisions) === true, 'a kept folder wins over an unchecked file in it');
ed_assert(um_keep_decision('modules/forum/sub/c.php', $decisions) === true, 'a kept folder covers files in its sub folders');
ed_assert(um_keep_decision('themes/dark.css', array('themes/' => 'keep', 'themes/dark.css' => 'overwrite')) === true, 'folder keep covers a file at the first level');
ed_assert(um_keep_decision('modules/shop/s.php', $decisions) === true, 'a folder keep does not change other folders (default keep)');
ed_assert(um_keep_decision('modules/shop/s.php', array('modules/shop/s.php' => 'overwrite', 'modules/forum/' => 'keep')) === false, 'a file in another folder can still be overwritten');

// ---- 5b. the review form starts with nothing checked and lists every file and folder
ob_start();
render_edited_review_form($edited, array(), 'token', array(), array());
$review_html = ob_get_clean();
ed_assert(strpos($review_html, "' checked") === false, 'the review form starts with every box unchecked');
ed_assert(substr_count($review_html, "name='keep_file[]'") === count($edited) && substr_count($review_html, "name='keep_folder[]'") === count($groups), 'the review form lists every edited file and folder');

// ---- 6. choices from the review form are stored in the manifest
$manifest = um_store_decisions($manifest, $edited, array('modules/forum/a.php'), array('themes/', 'not/offered/'));
ed_assert($manifest['edited_decisions'] === array(
	'modules/forum/a.php' => 'keep',
	'modules/forum/sub/c.php' => 'overwrite',
	'themes/dark.css' => 'overwrite',
	'themes/' => 'keep',
), 'unchecked files are stored as overwrite, checked folders as keep, folders that were not offered are ignored');
ed_assert(um_manifest_save($panel, $manifest) === '' && um_manifest_load($panel)['manifest']['edited_decisions'] === $manifest['edited_decisions'], 'decisions are saved in update_manifest.json (edited_decisions)');

// ---- 7. the copy: excluded paths and kept files are not written, the log names each one
$release = $work . '/release';
ed_make_release($release);
$exclusion_text = "# never update\nmodules/shop/\nindex.php\n";
file_put_contents(um_exclusions_file($panel), $exclusion_text);
ed_put($panel . '/modules/shop/s.php', 'shop EDITED by user');
$plan = update_install_plan($panel);
ed_assert($plan['error'] === '' && $plan['exclusions'] === array('modules/shop/', 'index.php'), 'the plan reads update_exclusions.txt');
$result = component_copy_tree($release, $panel, 'Panel', $plan['skip']);
ed_assert($result['success'], 'copy succeeds');
ed_assert(file_get_contents($panel . '/index.php') === 'index v1', 'an excluded file is not overwritten');
ed_assert(file_get_contents($panel . '/modules/shop/s.php') === 'shop EDITED by user', 'a file in an excluded folder is not overwritten');
ed_assert(file_get_contents($panel . '/modules/forum/a.php') === 'forum a EDITED', 'an edited file with decision keep is not overwritten');
ed_assert(file_get_contents($panel . '/modules/forum/sub/c.php') === 'forum c v2', 'an edited file with decision overwrite is overwritten');
ed_assert(file_get_contents($panel . '/themes/dark.css') === 'css EDITED', 'an edited file under a kept folder is not overwritten');
ed_assert(file_get_contents($panel . '/modules/forum/b.php') === 'forum b v2', 'an unedited file is updated');
ed_assert(file_get_contents($panel . '/brand_new.php') === 'new file', 'a file only in the update is installed');
ed_assert(file_get_contents($panel . '/update_manifest.json') !== 'release must not replace this' && json_decode(file_get_contents($panel . '/update_manifest.json'), true) !== null, 'a release cannot replace update_manifest.json');
ed_assert(file_get_contents(um_exclusions_file($panel)) === $exclusion_text, 'a release cannot replace update_exclusions.txt');
$log = file_get_contents(UPDATE_LOG);
ed_assert(strpos($log, 'Update skipped index.php (excluded)') !== false && strpos($log, 'Update skipped modules/shop/s.php (excluded)') !== false, 'the update log names each excluded path that was skipped');
ed_assert(strpos($log, 'Update skipped modules/forum/a.php (kept your edited version)') !== false, 'the update log names each edited file that was kept');
ed_assert(!in_array('index.php', $result['installed_files'], true) && in_array('modules/forum/b.php', $result['installed_files'], true) && in_array('brand_new.php', $result['installed_files'], true), 'installed_files lists only what was written');

// ---- 8. recording the install: kept files keep their old hash, overwritten files get the new one
ed_assert(um_record_install($panel, $result['installed_files'], '2.6.1') === '', 'manifest is edited after the install');
$after = um_manifest_load($panel)['manifest'];
ed_assert($after['files']['modules/forum/sub/c.php']['sha256'] === hash('sha256', 'forum c v2') && $after['files']['modules/forum/sub/c.php']['version'] === '2.6.1', 'an overwritten file is recorded with the new hash and version');
ed_assert($after['files']['modules/forum/a.php']['version'] === '2.6.0', 'a kept file stays recorded as the old install');
ed_assert($after['edited_decisions'] === array(), 'the used decisions are cleared after the install');
ed_assert($after['exclusions'] === array('modules/shop/', 'index.php'), 'exclusions stay in the manifest');

// ---- 9. an update with nobody at the Update page keeps every edited file
$panel2 = $work . '/panel2';
ed_make_panel($panel2);
ed_put($panel2 . '/modules/forum/b.php', 'forum b EDITED');
ed_put($panel2 . '/themes/dark.css', 'css EDITED');
$release2 = $work . '/release2';
ed_make_release($release2);
$plan2 = update_install_plan($panel2);
ed_assert($plan2['edited'] === array('modules/forum/b.php', 'themes/dark.css'), 'the plan finds the edited files without any page');
component_copy_tree($release2, $panel2, 'Panel', $plan2['skip']);
ed_assert(file_get_contents($panel2 . '/modules/forum/b.php') === 'forum b EDITED' && file_get_contents($panel2 . '/themes/dark.css') === 'css EDITED', 'non-interactive update keeps edited files by default');
ed_assert(file_get_contents($panel2 . '/modules/forum/a.php') === 'forum a v2', 'non-interactive update still updates files nobody edited');

// ---- 10. the Exclusions section offers only edited files and what is already excluded
$panel3 = $work . '/panel3';
ed_make_panel($panel3);
ed_put($panel3 . '/index.php', 'index EDITED');
file_put_contents(um_exclusions_file($panel3), "modules/shop/\n");
$m3 = um_sync_exclusions($panel3, um_manifest_load($panel3)['manifest']);
$rows = um_exclusion_candidates($panel3, $m3, $m3['exclusions']);
ed_assert($rows === array('index.php' => false, 'modules/shop/' => true), 'candidates are the edited files plus the existing exclusions, nothing else');
ed_assert(um_save_exclusions($panel3, array('index.php')) === '', 'saving the section works');
ed_assert(um_read_exclusions_file($panel3) === array('index.php') && um_manifest_load($panel3)['manifest']['exclusions'] === array('index.php'), 'saving writes update_exclusions.txt and the manifest');

// ---- 11. files the release stopped shipping are not offered as edited again (a deleted module)
$panel5 = $work . '/panel5';
ed_make_panel($panel5);
ed_put($panel5 . '/modules/oldmod/x.php', 'old module x v1');
ed_put($panel5 . '/modules/oldmod/y.php', 'old module y v1');
um_record_install($panel5, array('modules/oldmod/x.php', 'modules/oldmod/y.php'), '2.6.0');
ed_put($panel5 . '/modules/oldmod/x.php', 'old module x changed later');
ed_assert(um_find_edited($panel5, um_manifest_load($panel5)['manifest']) === array('modules/oldmod/x.php'), 'a changed file the release dropped is listed as edited (the bug)');
$release5 = $work . '/release5';
ed_make_release($release5);
$rels5 = um_release_files($release5);
ed_assert(in_array('modules/forum/sub/c.php', $rels5, true) && in_array('brand_new.php', $rels5, true) && !in_array('modules/forum/', $rels5, true) && !in_array('modules/oldmod/x.php', $rels5, true), 'the release file list holds files only, and not the dropped module');
$plan5 = update_install_plan($panel5);
$res5 = component_copy_tree($release5, $panel5, 'Panel', $plan5['skip']);
$dropped5 = array();
ed_assert(um_record_install($panel5, $res5['installed_files'], '2.6.1', $rels5, $dropped5) === '', 'recording the install with the release file list works');
ed_assert($dropped5 === array('modules/oldmod/x.php', 'modules/oldmod/y.php'), 'files the release no longer ships are reported as dropped');
$after5 = um_manifest_load($panel5)['manifest'];
ed_assert(!isset($after5['files']['modules/oldmod/x.php']) && !isset($after5['files']['modules/oldmod/y.php']), 'dropped files are gone from the manifest');
ed_assert(um_find_edited($panel5, $after5) === array(), 'the dropped file is not offered as edited at the next update');
ed_assert(file_get_contents($panel5 . '/modules/oldmod/x.php') === 'old module x changed later', 'a dropped file is left on disk, not deleted');
ed_assert(isset($after5['files']['modules/forum/a.php']) && isset($after5['files']['brand_new.php']) && count($after5['files']) === 7, 'files the release still ships stay tracked');

// a release list far smaller than the manifest drops nothing
$panel6 = $work . '/panel6';
ed_make_panel($panel6);
$dropped6 = array('sentinel');
ed_assert(um_record_install($panel6, array(), '2.6.1', array('index.php'), $dropped6) === '' && $dropped6 === array(), 'a partial release list drops nothing');
ed_assert(count(um_manifest_load($panel6)['manifest']['files']) === 6, 'the manifest keeps every file after a partial release list');
ed_assert(um_record_install($panel6, array(), '2.6.1', array(), $dropped6) === '' && count(um_manifest_load($panel6)['manifest']['files']) === 6, 'an empty release list drops nothing');

ed_remove($work);
ed_assert(!file_exists($work), 'test artifacts are removed');
echo "All {$passed} edited-file checks passed.\n";
exit(0);
