<?php
// runPostUpdateOperations() must not fatal when there is no session and no global $db (CLI or broken web call).
// Run: php modules/update/tests/post_update_no_session_test.php   (exit 0 = passed)
// Touches no real panel, database or network.

$passed = 0;
function post_update_assert($condition, $message)
{
	global $passed;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$passed++;
	echo "PASS: {$message}\n";
}

// helpers.php only defines functions. Run from an empty directory so no panel module files are found.
$empty = sys_get_temp_dir() . '/post_update_test_' . getmypid();
mkdir($empty, 0700, true);
chdir($empty);
require_once(dirname(__DIR__, 3) . '/includes/helpers.php');

// 1. no session, no $db: a clear error and no fatal
unset($GLOBALS['db'], $_SESSION);
$result = runPostUpdateOperations();
post_update_assert($result['success'] === false && strpos($result['error'], 'No database connection') !== false, 'no session and no $db gives a clear error, not a fatal');

// 2. a CLI style database object and no session: success, no session key created
class FakeApiDb
{
	public function getApiToken($user_id)
	{
		return 'token-for-' . $user_id;
	}
}
$GLOBALS['db'] = new FakeApiDb();
unset($_SESSION);
$result = runPostUpdateOperations();
post_update_assert($result['success'] === true && !isset($_SESSION), 'database set and no session succeeds without creating a session');

// 3. web style: logged in user without an API key gets one
$_SESSION = array('user_id' => 7);
$result = runPostUpdateOperations();
post_update_assert($result['success'] === true && $_SESSION['users_api_key'] === 'token-for-7', 'logged in web session gets its API key');

rmdir($empty);
echo "All {$passed} post-update checks passed.\n";
