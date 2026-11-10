<?php
// Bug report module. Run: php modules/bugreport/tests/bugreport_test.php   (exit 0 = all passed)
// Uses a fake HTTP poster. The relay is never called. No files are written.

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

// Request body and relay answers, with a fake poster
$calls = array();
$poster_ok = function ($url, $headers, $json) use (&$calls) {
	$calls[] = array($url, $headers, $json);
	return array(200, json_encode(array('ok' => true, 'issue_url' => 'https://gitlab.example/issues/9')));
};
list($ok, $text) = bugreport_send_to_relay($clean, '2.7.0', 'admin1', $poster_ok);
br_assert($ok && $text === 'https://gitlab.example/issues/9', 'success returns the issue URL');
br_assert($calls[0][0] === 'https://gameserverengine.com/bugreport/' && BUGREPORT_RELAY_URL === $calls[0][0], 'posts to the relay URL');
$sent = json_decode($calls[0][2], true);
br_assert(array_keys($sent) === array('type', 'title', 'description', 'module', 'version', 'reporter', 'website'), 'body has exactly the relay keys');
br_assert($sent['type'] === 'bug' && $sent['title'] === 'Update fails', 'body has type and title');
br_assert($sent['module'] === 'update' && $sent['version'] === '2.7.0' && $sent['reporter'] === 'admin1', 'body has module, version, reporter');
br_assert($sent['website'] === '', 'honeypot is empty');
br_assert(strpos($sent['description'], '1. Open Update 2. Click install') !== false, 'description has the steps');
br_assert(stripos(implode("\n", $calls[0][1]), 'authorization') === false, 'request carries no token header');
$feature = $clean;
$feature['type'] = 'Feature request';
$sent = json_decode(bugreport_relay_payload($feature, '2.7.0', 'admin1'), true);
br_assert($sent['type'] === 'feature', 'feature request maps to feature');

// Error answers
$cases = array(
	'relay error text' => array(array(400, json_encode(array('ok' => false, 'error' => 'Title too short'))), 'Title too short'),
	'ok without url' => array(array(200, json_encode(array('ok' => true))), 'did not accept'),
	'non-200 with ok' => array(array(500, json_encode(array('ok' => true, 'issue_url' => 'https://x'))), 'HTTP 500'),
	'not json' => array(array(502, '<html>'), 'HTTP 502'),
	'network error' => array(array(0, ''), 'Could not reach'),
);
foreach ($cases as $name => $case) {
	$poster = function ($url, $headers, $json) use ($case) { return $case[0]; };
	list($ok, $text) = bugreport_send_to_relay($clean, '2.7.0', 'admin1', $poster);
	br_assert(!$ok && strpos($text, $case[1]) !== false, "$name: failure message");
}

// The page
$_SESSION = array('users_login' => 'admin1', 'users_role' => 'admin');
require_once(dirname(__DIR__) . '/bugreport.php');

class FakeDb
{
	public $log = array();
	public function logger($m) { $this->log[] = $m; }
}

ob_start();
bugreport_show_form(array(), $modules);
$form = ob_get_clean();
br_assert(strpos($form, 'Do not paste customer data, passwords, keys or tokens') !== false, 'form shows the warning');
br_assert(stripos($form, 'token') === stripos($form, 'tokens into'), 'form has no token field');

$db = new FakeDb();
$_POST = $good;
ob_start();
$kept = bugreport_send_with($db, $modules, $poster_ok);
$out = ob_get_clean();
br_assert(strpos($out, 'issues/9') !== false && $kept === array(), 'success page shows the link and clears the form');
br_assert(strpos(implode("\n", $db->log), 'issues/9') !== false, 'log has the issue URL');

// Failure keeps the admin's text
$db2 = new FakeDb();
$poster_down = function ($url, $headers, $json) { return array(0, ''); };
$_POST = $good;
$_POST['title'] = 'Keep <b>me</b>';
ob_start();
$kept = bugreport_send_with($db2, $modules, $poster_down);
$out = ob_get_clean();
br_assert(strpos($out, 'Could not reach') !== false, 'network error: clear message shown');
br_assert($kept['title'] === 'Keep <b>me</b>', 'network error: form data is kept');
ob_start();
bugreport_show_form($kept, $modules);
$form = ob_get_clean();
br_assert(strpos($form, 'Keep &lt;b&gt;me&lt;/b&gt;') !== false && strpos($form, '<b>me</b>') === false, 'form output is escaped');

// Invalid input: no post
$calls = array();
$_POST = $good;
$_POST['type'] = 'Other';
ob_start();
bugreport_send_with($db2, $modules, $poster_ok);
ob_get_clean();
br_assert(count($calls) === 0, 'invalid input is not posted');

echo "\n{$passed} checks passed\n";
