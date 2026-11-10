<?php
// Ownership and write-access check used by the panel update.
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
$me = update_path_owner(sys_get_temp_dir()) === '' ? 'me' : (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'me');
$as_me = function ($path) use ($me) {
	return $me;
};

// 1. All owned by the expected user and writable.
own_make_tree($work . '/ok', 3);
own_assert(update_ownership_errors($work . '/ok', $me, $as_me) === [], 'owned and writable tree passes');

// 2. One path with a different owner is listed.
$one_root = function ($path) use ($me) {
	return substr($path, -6) === 'f2.php' ? 'root' : $me;
};
$errors = update_ownership_errors($work . '/ok', $me, $one_root);
own_assert(count($errors) === 1 && strpos($errors[0], '1 paths not owned by ' . $me . ' (owner root)') !== false, 'wrong owner fails with owner name and count');
own_assert(strpos($errors[0], $work . '/ok/modules/f2.php') !== false, 'wrong owner path is listed');
own_assert(strpos($errors[0], 'not run by the updater') !== false, 'fix command is only a suggestion');

// 3. A path that is not writable fails (skipped when root, which can write anything).
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	echo "SKIP: non-writable test needs a non-root user\n";
} else {
	chmod($work . '/ok/modules/f1.php', 0444);
	$errors = update_ownership_errors($work . '/ok', $me, $as_me);
	own_assert(count($errors) === 1 && strpos($errors[0], $work . '/ok/modules/f1.php') !== false && strpos($errors[0], 'not writable') !== false, 'non-writable path fails and is listed');
}

// 4. More than 20 failures: list is capped and the rest is counted.
own_make_tree($work . '/many', 30);
$all_root = function ($path) {
	return 'root';
};
$errors = update_ownership_errors($work . '/many', $me, $all_root);
own_assert(strpos($errors[0], '32 paths not owned by ' . $me . ' (owner root)') !== false, 'count covers every failing path');
own_assert(strpos($errors[0], ' and 12 more') !== false, 'list is capped at 20 with the rest counted');
own_assert(substr_count($errors[0], $work . '/many') === 21, 'only 20 paths are listed (plus the suggested fix path)');

// 5. Skipped directories are not checked.
mkdir($work . '/ok/logs');
file_put_contents($work . '/ok/logs/a.txt', 'x');
own_assert(!in_array($work . '/ok/logs/a.txt', update_owned_paths($work . '/ok'), true), 'protected directories are not checked');

// 6. The check changes nothing: no chown anywhere in the update code.
$code = file_get_contents(dirname(__DIR__) . '/update_safety.php') . file_get_contents(dirname(__DIR__, 2) . '/administration/panel_update.php');
own_assert(!preg_match('/\bchown\s*\(|exec\s*\([^)]*chown|system\s*\([^)]*chown/', $code), 'updater code never calls chown');

own_remove($work . '/ok');
own_remove($work . '/many');
rmdir($work);
echo "{$passed} passed\n";
exit(0);
