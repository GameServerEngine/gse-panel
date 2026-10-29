<?php

if (!defined('GSP_SCREEN_TYPE_HOME')) {
	define('GSP_SCREEN_TYPE_HOME', 'HOME');
}

if (!function_exists('clean_path')) {
	function clean_path($path)
	{
		$path = str_replace('\\', '/', (string)$path);
		$path = preg_replace('#/+#', '/', $path);
		return rtrim($path, '/');
	}
}

$GLOBALS['gsp_lifecycle_test_bot_rows'] = array();

function scm_get_enabled_server_bot_rows($db, $home_id)
{
	return $GLOBALS['gsp_lifecycle_test_bot_rows'];
}

function scm_server_bot_normalize_row(array $row)
{
	return array(
		'name' => isset($row['name']) ? $row['name'] : 'Server Bot',
		'workdir' => isset($row['bot_working_dir']) ? $row['bot_working_dir'] : '',
		'executable' => isset($row['bot_executable']) ? $row['bot_executable'] : '',
		'arguments' => isset($row['bot_arguments']) ? $row['bot_arguments'] : '',
	);
}

require_once(dirname(__DIR__) . '/lifecycle_contract_helpers.php');
require_once(dirname(__DIR__, 2) . '/modules/gamemanager/home_handling_functions.php');

class GspLifecycleFakeRemote
{
	public $files = array();
	public $commands = array();
	public $statusQueue = array();
	public $screenResult = 0;
	public $portListening = false;
	public $stopResult = array('success' => 1, 'status' => 'stopped', 'verified' => 1, 'message' => 'Stopped.');
	public $restartResult = array('success' => 1, 'status' => 'restarted', 'verified' => 1, 'message' => 'Restarted.');
	public $startPayloads = array();
	public $stopPayloads = array();
	public $restartPayloads = array();

	public function remote_readfile($path, &$data)
	{
		if (!array_key_exists($path, $this->files)) {
			return 0;
		}
		$data = $this->files[$path];
		return 1;
	}

	public function remote_writefile($path, $content)
	{
		$this->files[$path] = $content;
		return 1;
	}

	public function exec($command)
	{
		$this->commands[] = $command;
		if (strpos($command, 'GSP_PORT_LISTENING') !== false || strpos($command, 'GSP_PORT_CLOSED') !== false) {
			return $this->portListening ? 'GSP_PORT_LISTENING' : 'GSP_PORT_CLOSED';
		}
		return '';
	}

	public function remote_server_status($home_id, $server_ip, $server_port, $query_port, $rcon_port, $startup_timeout, $state_hint)
	{
		if (!empty($this->statusQueue)) {
			return array_shift($this->statusQueue);
		}
		return array('status' => 'OFFLINE', 'process_running' => 0, 'session_running' => 0, 'game_port_listening' => 0);
	}

	public function is_screen_running($screen_type, $home_id)
	{
		return $this->screenResult;
	}

	public function remote_stop_server_result($home_id, $server_ip, $server_port, $control_protocol, $control_password, $control_type, $home_path = '', $lifecycle_payload = null)
	{
		$this->stopPayloads[] = $lifecycle_payload;
		return $this->stopResult;
	}

	public function remote_restart_server_result($home_id, $server_ip, $server_port, $control_protocol, $control_password, $control_type, $home_path, $server_exe, $run_dir, $cmd, $cpu, $nice, $preStart, $envVars, $game_key, $console_log = '', $restart_reason = '', $lifecycle_payload = null)
	{
		$this->restartPayloads[] = $lifecycle_payload;
		return $this->restartResult;
	}

	public function universal_start_result($home_id, $game_home, $game_binary, $run_dir, $startup_cmd, $server_port, $server_ip, $cpu, $nice, $preStart, $envVars, $game_key, $console_log = '', $lifecycle_payload = null)
	{
		$this->startPayloads[] = $lifecycle_payload;
		return array('success' => 1, 'status' => 'started', 'message' => 'Started.');
	}
}

function gsp_lifecycle_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: $message\n");
		exit(1);
	}
	echo "PASS: $message\n";
}

$serverXml = simplexml_load_string(
	'<server_config>
		<game_key>dayz_win64</game_key>
		<protocol>lgsl</protocol>
		<lgsl_query_name>source</lgsl_query_name>
		<server_exec_name>DayZServer_x64.exe</server_exec_name>
		<exe_location>server</exe_location>
		<control_protocol>rcon</control_protocol>
		<control_protocol_type>tcp</control_protocol_type>
	</server_config>'
);

$homeInfo = array(
	'home_id' => 42,
	'home_path' => '/srv/gse/42',
	'management_home' => '/srv/gse/42',
	'game_key' => 'dayz_win64',
	'game_root' => '/srv/gse/42/dayz_win64',
	'last_param' => json_encode(array('map' => 'chernarusplus', 'players' => '60', 'extra' => '-profiles=profiles')),
	'mods' => array(
		7 => array('mod_id' => 7, 'mod_key' => 'dayz', 'cpu_affinity' => 'NA', 'nice' => '0'),
	),
);

$GLOBALS['gsp_lifecycle_test_bot_rows'] = array(array(
	'name' => 'DZSA',
	'bot_working_dir' => '/srv/gse/42/dayz_win64/dzsa',
	'bot_executable' => 'node-mod-server.exe',
	'bot_arguments' => '"-port=2302" "-mod=@CF"',
));

$remote = new GspLifecycleFakeRemote();
$payload = gsp_lifecycle_start_payload(
	null,
	$remote,
	$homeInfo,
	$serverXml,
	7,
	'192.0.2.10',
	2302,
	'-config=serverDZ.cfg',
	'',
	'ENV_ONE=value',
	array(array('file' => '/srv/gse/42/dayz_win64/serverDZ.cfg', 'write_result' => 1))
);

gsp_lifecycle_test_assert(!isset($payload['error']), 'builds lifecycle start payload');
gsp_lifecycle_test_assert($payload['management_home'] === '/srv/gse/42', 'payload carries management home');
gsp_lifecycle_test_assert($payload['game_root'] === '/srv/gse/42/dayz_win64', 'payload carries game root');
gsp_lifecycle_test_assert($payload['run_directory'] === 'dayz_win64/server', 'payload carries relative game-key run directory');
gsp_lifecycle_test_assert($payload['absolute_run_directory'] === '/srv/gse/42/dayz_win64/server', 'payload carries absolute run directory');
gsp_lifecycle_test_assert($payload['executable'] === 'DayZServer_x64.exe', 'payload carries executable name');
gsp_lifecycle_test_assert($payload['arguments'] === '-config=serverDZ.cfg', 'payload carries resolved startup arguments');
gsp_lifecycle_test_assert($payload['environment'] === 'ENV_ONE=value', 'payload carries environment variables');
gsp_lifecycle_test_assert($payload['port_context']['ip'] === '192.0.2.10' && $payload['port_context']['game_port'] === 2302, 'payload carries assigned IP and game port');
gsp_lifecycle_test_assert($payload['port_context']['protocol'] === 'lgsl' && $payload['port_context']['control_protocol'] === 'rcon', 'payload carries protocol context');
gsp_lifecycle_test_assert($payload['user_options']['players'] === '60', 'payload carries current saved user selections');
gsp_lifecycle_test_assert($payload['replace_text_results'][0]['file'] === '/srv/gse/42/dayz_win64/serverDZ.cfg', 'payload carries replace-text results');
gsp_lifecycle_test_assert($payload['bot']['command'] === 'node-mod-server.exe', 'payload carries one transparent bot command');
gsp_lifecycle_test_assert(isset($remote->files['/srv/gse/42/bot.def']), 'writes bot.def in management home');
gsp_lifecycle_test_assert(strpos($remote->files['/srv/gse/42/bot.def'], 'workdir=/srv/gse/42/dayz_win64/dzsa') !== false, 'bot.def contains simple workdir/executable/arguments keys');
gsp_lifecycle_test_assert(!isset($remote->files['/srv/gse/42/dayz_win64/bot.def']), 'does not write bot.def beneath game root');

$homeInfoChanged = $homeInfo;
$homeInfoChanged['last_param'] = json_encode(array('map' => 'livonia', 'players' => '80', 'extra' => '-profiles=current'));
$payloadChanged = gsp_lifecycle_start_payload(null, $remote, $homeInfoChanged, $serverXml, 7, '192.0.2.10', 2402, '-config=livonia.cfg -profiles=current', '', '', array());
gsp_lifecycle_test_assert($payloadChanged['arguments'] === '-config=livonia.cfg -profiles=current', 'changed user selections regenerate current startup arguments');
gsp_lifecycle_test_assert($payloadChanged['user_options']['players'] === '80', 'changed user selections regenerate current option payload');

$startResult = $remote->universal_start_result(42, '/srv/gse/42', 'DayZServer_x64.exe', 'dayz_win64/server', $payloadChanged['arguments'], 2402, '192.0.2.10', 'NA', '0', '', '', 'dayz_win64', '', $payloadChanged);
gsp_lifecycle_test_assert(!empty($startResult['success']), 'fake Start RPC accepts structured payload');
gsp_lifecycle_test_assert($remote->startPayloads[0]['game_root'] === '/srv/gse/42/dayz_win64', 'fake Start RPC receives game_root');
gsp_lifecycle_test_assert($remote->startPayloads[0]['arguments'] === '-config=livonia.cfg -profiles=current', 'fake Start RPC receives current arguments');

$remote->files['/srv/gse/42/bot.def'] = "workdir=/manual\nexecutable=manual\narguments=\n";
$payload = gsp_lifecycle_start_payload(null, $remote, $homeInfo, $serverXml, 7, '192.0.2.10', 2302, 'cmd', '', '', array());
gsp_lifecycle_test_assert(isset($payload['error']) && strpos($payload['error'], 'non-generated bot.def') !== false, 'refuses to overwrite manual bot.def');

// The real GSE Windows agent's readfile RPC auto-creates a blank file on
// first read of a path that doesn't exist, then reports success (retval 1)
// with empty content - it never returns the "0 = not found" result this
// contract's $read === 0 branch was written for. Model that here (unlike
// the fake's default "return 0 for an untracked path" behavior used by the
// rest of this file) to prove starting a server whose bot.def has never
// been written does not get misreported as a foreign-file conflict.
$remote->files['/srv/gse/42/bot.def'] = '';
$GLOBALS['gsp_lifecycle_test_bot_rows'] = array();
$payload = gsp_lifecycle_start_payload(null, $remote, $homeInfo, $serverXml, 7, '192.0.2.10', 2302, 'cmd', '', '', array());
gsp_lifecycle_test_assert(!isset($payload['error']), 'an auto-created blank bot.def is not treated as a foreign-file conflict');

gsp_lifecycle_test_assert(gsp_lifecycle_status_is_verified_stopped(array('status' => 'OFFLINE', 'process_running' => 0, 'session_running' => 0, 'game_port_listening' => 0)), 'accepts stopped evidence with no runtime');
gsp_lifecycle_test_assert(!gsp_lifecycle_status_is_verified_stopped(array('status' => 'OFFLINE', 'game_port_listening' => 1)), 'rejects stopped status when game port survives');
gsp_lifecycle_test_assert(!gsp_lifecycle_status_is_verified_stopped(array('status' => 'OFFLINE', 'remaining_pids' => array(2222))), 'rejects stopped status when tracked PIDs survive');

$remote = new GspLifecycleFakeRemote();
$remote->statusQueue[] = array('status' => 'OFFLINE', 'process_running' => 0, 'session_running' => 0, 'game_port_listening' => 0);
$stopResult = gsp_lifecycle_remote_stop_verified($remote, $serverXml, $homeInfo, '192.0.2.10', 2302, 'secret', 'tcp');
gsp_lifecycle_test_assert(!empty($stopResult['success']), 'verified Stop succeeds when status evidence is stopped');
gsp_lifecycle_test_assert($remote->stopPayloads[0]['operation'] === 'stop', 'fake Stop RPC receives stop operation');
gsp_lifecycle_test_assert($remote->stopPayloads[0]['management_home'] === '/srv/gse/42', 'fake Stop RPC receives management home');

$remote = new GspLifecycleFakeRemote();
$remote->stopResult = array('success' => 0, 'status' => 'protected_port_owner', 'message' => 'Assigned port is owned by a protected process.');
$remote->statusQueue[] = array(
	'status' => 'STOPPING',
	'process_running' => 0,
	'session_running' => 0,
	'game_port_listening' => 1,
	'port_owners' => array(array('pid' => 4, 'owner' => 'SYSTEM', 'protected' => 1)),
	'last_error' => 'Assigned port is owned by a protected process.',
);
$stopResult = gsp_lifecycle_remote_stop_verified($remote, $serverXml, $homeInfo, '192.0.2.10', 2302, 'secret', 'tcp');
gsp_lifecycle_test_assert(empty($stopResult['success']) && $stopResult['status'] === 'stop_not_verified', 'protected port-owner evidence keeps Stop failed');
gsp_lifecycle_test_assert(strpos($stopResult['message'], 'protected process') !== false, 'protected port-owner message is preserved');

$restartPayload = gsp_lifecycle_start_payload(null, new GspLifecycleFakeRemote(), $homeInfo, $serverXml, 7, '192.0.2.10', 2302, 'restart-current-args', '', '', array(), 'panel_restart');
$remote = new GspLifecycleFakeRemote();
$restartResult = $remote->remote_restart_server_result(42, '192.0.2.10', 2302, 'rcon', 'secret', 'tcp', '/srv/gse/42', 'DayZServer_x64.exe', 'dayz_win64/server', 'restart-current-args', 'NA', '0', '', '', 'dayz_win64', '', 'panel_restart', $restartPayload);
gsp_lifecycle_test_assert(!empty($restartResult['success']), 'fake Restart RPC accepts structured payload');
gsp_lifecycle_test_assert($remote->restartPayloads[0]['operation'] === 'restart_start', 'fake Restart RPC receives restart_start operation');
gsp_lifecycle_test_assert($remote->restartPayloads[0]['restart_reason'] === 'panel_restart', 'fake Restart RPC receives restart reason');

gsp_lifecycle_test_assert(!is_agent_status_online(array('status' => 'ONLINE', 'desired_state' => 'status.running')), 'desired running marker alone is not online proof');
gsp_lifecycle_test_assert(!is_agent_status_active(array('status' => 'STARTING', 'desired_state' => 'status.running')), 'desired state without runtime evidence is not active proof');
gsp_lifecycle_test_assert(is_agent_status_active(array('status' => 'STARTING')), 'legacy active status without desired-state fields remains compatible');
gsp_lifecycle_test_assert(is_agent_status_online(array('status' => 'ONLINE', 'game_port_listening' => 1)), 'observed game port is online proof');

echo "All lifecycle contract helper tests passed.\n";

?>
