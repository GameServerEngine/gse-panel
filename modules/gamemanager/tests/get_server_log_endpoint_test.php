<?php

define('GSP_GET_SERVER_LOG_SKIP_MAIN', true);
define('SERVER_CONFIG_LOCATION', 'modules/config_games/server_configs/');
define('GSP_SCREEN_TYPE_HOME', 'HOME');

if (!function_exists('clean_path')) {
	function clean_path($path)
	{
		$path = str_replace('\\', '/', (string)$path);
		$path = preg_replace('#/+#', '/', $path);
		return rtrim($path, '/');
	}
}

function read_server_config($filename)
{
	if (!empty($GLOBALS['gsp_test_missing_xml'])) {
		return false;
	}
	return simplexml_load_string($GLOBALS['gsp_test_xml']);
}

require_once(dirname(__DIR__) . '/get_server_log.php');

class GspGetServerLogFakeDb
{
	public $adminUsers = array();
	public $homes = array();
	public $assignments = array();

	public function isAdmin($user_id)
	{
		return !empty($this->adminUsers[$user_id]);
	}

	public function getGameHome($home_id)
	{
		return isset($this->homes[$home_id]) ? $this->homes[$home_id] : false;
	}

	public function getUserGameHome($user_id, $home_id)
	{
		if (empty($this->assignments[$user_id]) || empty($this->assignments[$user_id][$home_id])) {
			return false;
		}
		return $this->getGameHome($home_id);
	}
}

class GspGetServerLogFakeRemote
{
	public static $retval = 1;
	public static $log = '';
	public static $lastArgs = array();

	public function get_log($screen_type, $home_id, $home_path, &$data, $nb_of_lines = 100, $console_log = false)
	{
		self::$lastArgs = array($screen_type, $home_id, $home_path, $nb_of_lines, $console_log);
		$data = self::$log;
		return self::$retval;
	}
}

function gsp_get_server_log_test_remote_factory($home_info)
{
	return new GspGetServerLogFakeRemote();
}

function gsp_get_server_log_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

function gsp_get_server_log_test_db()
{
	$db = new GspGetServerLogFakeDb();
	$db->adminUsers[1] = true;
	$db->assignments[2] = array(10 => true);
	$db->homes[10] = array(
		'home_id' => 10,
		'home_cfg_file' => 'fake.xml',
		'agent_ip' => '127.0.0.1',
		'agent_port' => 12679,
		'encryption_key' => 'secret',
		'timeout' => 5,
		'home_path' => '/srv/game',
		'mods' => array(
			20 => array('mod_key' => 'default'),
		),
	);
	return $db;
}

function gsp_get_server_log_test_response($db, $home_id, $mod_id, $user_id)
{
	return gsp_get_server_log_response($db, $home_id, $mod_id, $user_id, dirname(dirname(dirname(__DIR__))), 'gsp_get_server_log_test_remote_factory');
}

$endpointSource = file_get_contents(dirname(__DIR__) . '/get_server_log.php');
gsp_get_server_log_test_assert(strpos($endpointSource, "includes/helpers.php") !== false, 'endpoint bootstrap loads includes/helpers.php');
gsp_get_server_log_test_assert(strpos($endpointSource, "createDatabaseConnection") !== false, 'endpoint still uses createDatabaseConnection');
gsp_get_server_log_test_assert(strpos($endpointSource, "includes/functions.php") < strpos($endpointSource, "includes/helpers.php"), 'endpoint loads helpers after functions like panel entry points');

gsp_get_server_log_test_assert(gsp_get_server_log_authenticated_user_id(array()) === false, 'unauthenticated request is rejected');
gsp_get_server_log_test_assert(gsp_get_server_log_authenticated_user_id(array('users_login' => 'user', 'user_id' => 2)) === 2, 'authenticated session user is accepted');

$GLOBALS['gsp_test_xml'] = '<game_config><game_key>test_game</game_key><console_log>logs/%mod%/console.log</console_log><exe_location>bin</exe_location></game_config>';
$GLOBALS['gsp_test_missing_xml'] = false;
GspGetServerLogFakeRemote::$retval = 1;
GspGetServerLogFakeRemote::$log = "ready\n";

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), null, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 400 && strpos($response['body'], 'home_id') !== false, 'missing home_id is rejected');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, null, 1);
gsp_get_server_log_test_assert($response['status'] === 400 && strpos($response['body'], 'mod_id') !== false, 'missing mod_id is rejected');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), '10 OR 1=1', 20, 1);
gsp_get_server_log_test_assert($response['status'] === 400, 'invalid identifier input is rejected');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 3);
gsp_get_server_log_test_assert($response['status'] === 403, 'unauthorized non-admin request is rejected');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 200 && strpos($response['body'], 'ready') !== false, 'authorized admin request returns log');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 2);
gsp_get_server_log_test_assert($response['status'] === 200 && strpos($response['body'], 'ready') !== false, 'authorized assigned user request returns log');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 999, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 404, 'missing home is handled safely');

$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 999, 1);
gsp_get_server_log_test_assert($response['status'] === 404, 'missing mod is handled safely');

$GLOBALS['gsp_test_missing_xml'] = true;
$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 500 && strpos($response['body'], 'invalid') !== false, 'missing server XML is handled safely');
$GLOBALS['gsp_test_missing_xml'] = false;

$GLOBALS['gsp_test_xml'] = '<game_config><game_key>test_game</game_key></game_config>';
$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 500 && strpos($response['body'], 'log configuration') !== false, 'missing log configuration is handled safely');

$GLOBALS['gsp_test_xml'] = '<game_config><game_key>test_game</game_key><console_log>logs/%mod%/console.log</console_log></game_config>';
$lines = array();
for ($i = 1; $i <= 45; $i++) {
	$lines[] = sprintf('line%03d', $i);
}
GspGetServerLogFakeRemote::$retval = 1;
GspGetServerLogFakeRemote::$log = implode("\n", $lines);
$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 200 && strpos($response['body'], 'line006') !== false && strpos($response['body'], 'line005') === false, 'successful log retrieval returns the last 40 lines');
gsp_get_server_log_test_assert(GspGetServerLogFakeRemote::$lastArgs[2] === '/srv/game/test_game' && GspGetServerLogFakeRemote::$lastArgs[4] === 'logs/default/console.log', 'console_log path substitution and clean game-root base path are preserved');

$GLOBALS['gsp_test_xml'] = '<game_config><game_key>test_game</game_key><console_log>logs/*.log</console_log></game_config>';
GspGetServerLogFakeRemote::$retval = -8;
GspGetServerLogFakeRemote::$log = '';
$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 200 && strpos($response['body'], 'No log files matched pattern') !== false, 'wildcard no-match response is safe');

GspGetServerLogFakeRemote::$retval = -5;
$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert($response['status'] === 502 && strpos($response['body'], 'Unable to get log: -5') !== false, 'remote log failure is safe');

GspGetServerLogFakeRemote::$retval = 1;
GspGetServerLogFakeRemote::$log = '<script>alert(1)</script>';
$GLOBALS['gsp_test_xml'] = '<game_config><game_key>test_game</game_key><console_log>logs/console.log</console_log></game_config>';
$response = gsp_get_server_log_test_response(gsp_get_server_log_test_db(), 10, 20, 1);
gsp_get_server_log_test_assert(strpos($response['body'], '&lt;script&gt;') !== false && strpos($response['body'], '<script>alert') === false, 'log output containing HTML is escaped');
gsp_get_server_log_test_assert(strpos($response['body'], 'Fatal error') === false && strpos($response['body'], 'Stack trace') === false, 'safe responses do not expose fatal errors or stack traces');

$startServerSource = file_get_contents(dirname(__DIR__) . '/start_server.php');
gsp_get_server_log_test_assert(strpos($startServerSource, 'modules/gamemanager/get_server_log.php?home_id=') !== false, 'start_server polling URL remains valid');
gsp_get_server_log_test_assert(strpos($startServerSource, ".done(function(html)") !== false, 'startup log polling updates the pane only on successful AJAX responses');

echo "All get_server_log endpoint tests passed.\n";

?>
