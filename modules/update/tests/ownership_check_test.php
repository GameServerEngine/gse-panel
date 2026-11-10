<?php
// Permission repair used by the panel update (shared with install-panel.sh --repair-permissions).
// Run: php modules/update/tests/ownership_check_test.php   (exit 0 = all passed)
// Uses a temp tree owned by the current user. Owners are faked with a callable, so no chown is needed.

require_once(dirname(__DIR__) . '/update_safety.php');
if (!function_exists('update_log')) {
	function update_log($message)
	{
	}
}

$passed = 0;
function own_assert($condition, $message)
{
	global $passed;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$passed++;
	echo "PASS: {$message}\n";
}

function own_make_tree($root, $file_count)
{
	mkdir($root . '/modules', 0775, true);
	for ($i = 1; $i <= $file_count; $i++) {
		file_put_contents($root . '/modules/f' . $i . '.php', '<?php');
	}
}

function own_remove($dir)
{
	chmod($dir, 0775);
	foreach (scandir($dir) as $name) {
		if ($name === '.' || $name === '..') {
			continue;
		}
		$path = $dir . '/' . $name;
		is_dir($path) ? own_remove($path) : unlink($path);
	}
	rmdir($dir);
}

$work = sys_get_temp_dir() . '/ownership_check_test_' . getmypid();

// Fake owner/chown functions: the whole repair runs without root.
// $fake holds path => ['owner','group','mode']. $touched records every path the ops were called on.
function own_fake_ops(array &$fake, array &$touched)
{
	return array(
		'owner_of' => function ($path) use (&$fake, &$touched) {
			$touched[] = $path;
			return isset($fake[$path]) ? $fake[$path]['owner'] : 'someone';
		},
		'group_of' => function ($path) use (&$fake) {
			return isset($fake[$path]) ? $fake[$path]['group'] : 'someone';
		},
		'mode_of' => function ($path) use (&$fake) {
			return isset($fake[$path]) ? $fake[$path]['mode'] : 0600;
		},
		'chown' => function ($path, $owner, $group) use (&$fake, &$touched) {
			$touched[] = $path;
			if (!empty($fake[$path]['immutable'])) {
				return 'Operation not permitted';
			}
			$fake[$path]['owner'] = $owner;
			$fake[$path]['group'] = $group;
			return '';
		},
		'chmod' => function ($path, $mode) use (&$fake) {
			$fake[$path]['mode'] = $mode;
			return '';
		},
	);
}

function own_fake_state(array $paths, $owner, $group, $mode)
{
	$fake = array();
	foreach ($paths as $path) {
		$fake[$path] = array('owner' => $owner, 'group' => $group, 'mode' => $mode);
	}
	return $fake;
}

$panel = $work . '/panel';
own_make_tree($panel, 3);
mkdir($panel . '/includes');
file_put_contents($panel . '/includes/config.inc.php', '<?php');
mkdir($panel . '/logs');
file_put_contents($panel . '/logs/a.log', 'x');
mkdir($work . '/outside');
file_put_contents($work . '/outside/secret.txt', 'x');
symlink($work . '/outside', $panel . '/link_to_outside');

// 1. The repair changes owner, group and mode, and says which path changed.
$paths = perm_collect_paths($panel, 'update_repair_skip');
$fake = own_fake_state($paths, 'root', 'root', 0600);
$touched = array();
$result = perm_repair_paths($panel, $paths, 'www-data', 'www-data', own_fake_ops($fake, $touched));
own_assert(empty($result['failed']) && count($result['changed']) > 0, 'repair changes wrong owner and mode without failures');
own_assert($fake[$panel]['owner'] === 'www-data' && $fake[$panel]['group'] === 'www-data' && $fake[$panel]['mode'] === 02775, 'panel root gets www-data:www-data and directory mode 2775');
own_assert($fake[$panel . '/modules/f1.php']['mode'] === 0664, 'files get mode 0664');
own_assert($fake[$panel . '/includes/config.inc.php']['mode'] === 0660, 'config file gets mode 0660');
own_assert(strpos(implode("\n", $result['changed']), $panel . '/modules/f1.php: owner root:root -> www-data:www-data') !== false, 'each changed path is named with what changed');

// 2. A second run changes nothing.
$touched = array();
$again = perm_repair_paths($panel, $paths, 'www-data', 'www-data', own_fake_ops($fake, $touched));
own_assert(empty($again['changed']) && empty($again['failed']), 'a second repair changes nothing');

// 3. Only paths inside the panel root are touched: the symlink target and protected folders are not.
own_assert(!in_array($panel . '/link_to_outside', $paths, true), 'symbolic links are not collected');
own_assert(!in_array($panel . '/logs/a.log', $paths, true), 'protected folders (logs) are not repaired');
$fake = own_fake_state(array($panel, $work . '/outside/secret.txt', '/etc/passwd', $panel . '/../outside'), 'root', 'root', 0600);
$touched = array();
$result = perm_repair_paths($panel, array($panel, $work . '/outside/secret.txt', '/etc/passwd', $panel . '/../outside', $panel . '2'), 'www-data', 'www-data', own_fake_ops($fake, $touched));
own_assert(count($result['outside']) === 4, 'paths outside the panel root (including ../ and a sibling with the same prefix) are refused');
own_assert($touched === array($panel, $panel), 'owner was read and changed only for the path inside the panel root');
own_assert($fake[$work . '/outside/secret.txt']['owner'] === 'root', 'a path outside the panel root keeps its owner');

// 4. A path that cannot be repaired is reported as failed, with the reason.
$paths = perm_collect_paths($panel, 'update_repair_skip');
$fake = own_fake_state($paths, 'root', 'root', 0600);
$fake[$panel . '/modules/f2.php']['immutable'] = true;
$touched = array();
$result = perm_repair_paths($panel, $paths, 'www-data', 'www-data', own_fake_ops($fake, $touched));
own_assert(count($result['failed']) === 1 && strpos($result['failed'][0], $panel . '/modules/f2.php') !== false && strpos($result['failed'][0], 'Operation not permitted') !== false, 'an immutable file is reported as failed with the reason');

// 5. update_repair_panel_tree stops with a message naming the failed paths, and offers the installer command.
$fake = own_fake_state($paths, 'root', 'root', 0600);
$fake[$panel . '/modules/f2.php']['immutable'] = true;
$touched = array();
$repair = update_repair_panel_tree($panel, 'before the copy', own_fake_ops($fake, $touched));
own_assert($repair['success'] === false && strpos($repair['message'], $panel . '/modules/f2.php') !== false && strpos($repair['message'], '--repair-permissions') !== false, 'failed repair gives a message naming the path and the fix');
$fake = own_fake_state($paths, 'www-data', 'www-data', 0600);
$repair = update_repair_panel_tree($panel, 'after the copy', own_fake_ops($fake, $touched));
own_assert($repair['success'] === true && count($repair['changed']) > 0, 'repair reports the paths it changed');

// 6. The installer and the updater share one definition.
$cli = file_get_contents(dirname(__DIR__, 3) . '/tools/install_panel_cli.php');
own_assert(strpos($cli, 'perm_repair_tree(') !== false && strpos($cli, 'chown -R') === false, 'install-panel.sh repair runs the shared perm_repair_tree');

unlink($panel . '/link_to_outside');
own_remove($panel);
own_remove($work . '/outside');
rmdir($work);
echo "{$passed} passed\n";
exit(0);
