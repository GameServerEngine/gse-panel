<?php
// Bug report module. Run: php modules/bugreport/tests/bugreport_test.php   (exit 0 = all passed)
// Uses a fake HTTP poster. GitHub is never called. No files are written.

require_once(dirname(__DIR__) . '/bugreport_lib.php');

$passed = 0;
function br_assert($condition, $message)
{
	global $passed;
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	$passed++;
	echo "PASS: {$message}\n";
}

$modules = array('update', 'settings', 'billing');
$secret = 'ghp_SECRET_TOKEN_VALUE_123';
$good = array(
	'module' => 'update', 'type' => 'Bug', 'title' => 'Update fails',
	'bug' => 'It stops at step 3.', 'steps' => '1. Open Update 2. Click install',
	'expected' => 'It should finish.',
);

// Validation
list($clean, $errors) = bugreport_validate($good, $modules);
br_assert(count($errors) === 0, 'good input has no errors');

foreach (array('title', 'bug', 'steps', 'expected') as $field) {
	$input = $good;
	$input[$field] = '   ';
	list(, $errors) = bugreport_validate($input, $modules);
	br_assert(count($errors) === 1, "empty $field is rejected");
}

$input = $good;
$input['module'] = 'not_a_module';
list(, $errors) = bugreport_validate($input, $modules);
br_assert(count($errors) === 1, 'unknown module is rejected');

$input = $good;
$input['type'] = 'Other';
list(, $errors) = bugreport_validate($input, $modules);
br_assert(count($errors) === 1, 'unknown type is rejected');

$input = $good;
$input['title'] = str_repeat('a', 151);
list(, $errors) = bugreport_validate($input, $modules);
br_assert(count($errors) === 1, 'title over 150 characters is rejected');

$input = $good;
$input['bug'] = array('x');
list(, $errors) = bugreport_validate($input, $modules);
br_assert(count($errors) === 1, 'non-string field is rejected');

// Module list
$list = bugreport_module_list(dirname(__DIR__, 2));
br_assert(in_array('update', $list, true) && in_array('bugreport', $list, true), 'module list holds real modules');
br_assert(!in_array('tests', $list, true) && !in_array('vendor', $list, true), 'module list skips tests and vendor');

// Body
$body = bugreport_build_body($clean, '2.7.0', 'admin1');
br_assert(strpos($body, 'update') !== false, 'body names the module');
br_assert(strpos($body, 'Bug') !== false, 'body names the type');
br_assert(strpos($body, '1. Open Update 2. Click install') !== false, 'body has the steps');
br_assert(strpos($body, 'It should finish.') !== false, 'body has the expected fix');
br_assert(strpos($body, '2.7.0') !== false && strpos($body, 'admin1') !== false, 'body has version and user');
br_assert(strpos($body, '@') === false, 'body has no email');

// Posting with a fake poster
$calls = array();
$poster_ok = function ($url, $headers, $json) use (&$calls) {
	$calls[] = array($url, $headers, $json);
	return array(201, json_encode(array('html_url' => 'https://github.com/GameServerEngine/gse-panel/issues/9')));
};
list($ok, $text) = bugreport_create_issue($clean, '2.7.0', 'admin1', $secret, $poster_ok);
br_assert($ok && $text === 'https://github.com/GameServerEngine/gse-panel/issues/9', 'success returns the issue URL');
br_assert($calls[0][0] === 'https://api.github.com/repos/GameServerEngine/gse-panel/issues', 'posts to the issues endpoint');
$sent = json_decode($calls[0][2], true);
br_assert($sent['title'] === '[Bug] Update fails', 'issue title has the type prefix');
br_assert(strpos($text, $secret) === false, 'result does not contain the token');

// No token: no post
$calls = array();
foreach (array('', false) as $empty) {
	list($ok, $text) = bugreport_create_issue($clean, '2.7.0', 'admin1', $empty, $poster_ok);
	br_assert(!$ok && count($calls) === 0 && strpos($text, 'No GitHub token') !== false, 'no token does not post');
}

// GitHub error: message is returned, token is not
$poster_fail = function ($url, $headers, $json) {
	return array(401, json_encode(array('message' => 'Bad credentials')));
};
list($ok, $text) = bugreport_create_issue($clean, '2.7.0', 'admin1', $secret, $poster_fail);
br_assert(!$ok && strpos($text, 'Bad credentials') !== false && strpos($text, $secret) === false, 'error result has no token');
$poster_down = function ($url, $headers, $json) {
	return array(0, '');
};
list($ok, $text) = bugreport_create_issue($clean, '2.7.0', 'admin1', $secret, $poster_down);
br_assert(!$ok && strpos($text, $secret) === false, 'network failure result has no token');

// The page never prints the token and never logs it
$_SESSION = array('users_login' => 'admin1', 'users_role' => 'admin');
require_once(dirname(__DIR__) . '/bugreport.php');

class FakeDb
{
	public $settings = array();
	public $log = array();
	public function setSettings($s) { $this->settings = array_merge($this->settings, $s); }
	public function getSetting($n) { return isset($this->settings[$n]) ? $this->settings[$n] : false; }
	public function logger($m) { $this->log[] = $m; }
}

$db = new FakeDb();
$_POST = array('github_token' => $secret);
ob_start();
bugreport_save_token($db);
$saved_out = ob_get_clean();
br_assert($db->getSetting(BUGREPORT_TOKEN_SETTING) === $secret, 'token is saved in the settings table');
br_assert(strpos($saved_out, $secret) === false, 'save message does not echo the token');

ob_start();
bugreport_show_form(array(), $modules, true);
$form = ob_get_clean();
br_assert(strpos($form, $secret) === false, 'form does not contain the saved token');
br_assert(strpos($form, '<strong>set</strong>') !== false, 'form shows "set" when a token exists');
ob_start();
bugreport_show_form(array(), $modules, false);
$form = ob_get_clean();
br_assert(strpos($form, '<strong>not set</strong>') !== false && strpos($form, 'No GitHub token is set') !== false, 'form shows "not set" with a message');

// Sending with a token: log has the URL, not the token
$_POST = $good;
ob_start();
bugreport_send_with($db, $modules, $poster_ok);
$out = ob_get_clean();
br_assert(strpos($out, 'issues/9') !== false, 'success page shows the issue URL');
br_assert(strpos(implode("\n", $db->log), 'issues/9') !== false, 'log has the issue URL');
br_assert(strpos(implode("\n", $db->log), $secret) === false && strpos($out, $secret) === false, 'log and page never hold the token');

// Sending without a token: no post, form data kept, error logged
$db2 = new FakeDb();
$calls = array();
$_POST = $good;
$_POST['title'] = 'Keep <b>me</b>';
ob_start();
$kept = bugreport_send_with($db2, $modules, $poster_ok);
$out = ob_get_clean();
br_assert(count($calls) === 0, 'no token: nothing was posted');
br_assert($kept['title'] === 'Keep <b>me</b>', 'no token: form data is kept');
br_assert(strpos($out, 'No GitHub token') !== false, 'no token: clear message shown');
ob_start();
bugreport_show_form($kept, $modules, false);
$form = ob_get_clean();
br_assert(strpos($form, 'Keep &lt;b&gt;me&lt;/b&gt;') !== false && strpos($form, '<b>me</b>') === false, 'form output is escaped');

echo "\n{$passed} checks passed\n";
