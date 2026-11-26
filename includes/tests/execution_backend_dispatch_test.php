<?php
// Execution backend dispatch: native homes keep the native RPCs; Docker homes only ever use
// the container RPCs; an unreadable backend refuses instead of guessing.

require_once(dirname(__DIR__) . '/lib_remote.php');

class DispatchFakeDb
{
	public $runtime = null;      // row for home_execution_runtime, null = no row
	public $fail = '';           // error text to simulate a failed read
	public $blocks = array();
	public $queries = array();
	public function queryRows($q)
	{
		if (strpos($q, 'home_execution_runtime') !== false && strpos($q, 'SELECT') === 0) {
			if ($this->fail !== '') { return false; }
			return $this->runtime === null ? array() : array($this->runtime);
		}
		if (strpos($q, 'server_homes') !== false) { return array(array('home_id' => 42, 'remote_server_id' => 3, 'user_id_main' => 7)); }
		if (strpos($q, 'home_port_blocks') !== false) { return $this->blocks; }
		return array();
	}
	public function getError() { return $this->fail; }
	public function realEscapeSingle($s) { return addslashes((string)$s); }
	public function query($q) { $this->queries[] = $q; return true; }
	public function logger($t) {}
}

class DispatchFakeRemote extends RemoteLibrary
{
	public $calls = array();
	public $replies = array();
	public function __construct() { parent::__construct('192.0.2.1', 12679, 'testkey'); }
	protected function postRequest($request)
	{
		$method = null;
		xmlrpc_decode_request($request, $method);
		$this->calls[] = $method;
		return isset($this->replies[$method]) ? array_shift($this->replies[$method]) : 1;
	}
}

function t($cond, $msg) { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "PASS: $msg\n"; }
function docker_row() {
	return array('home_id' => 42, 'execution_backend' => 'docker', 'runtime_image_id' => 1, 'cpu_cores' => '2.00', 'memory_mb' => 2048,
		'memory_swap_mb' => null, 'pids_limit' => 512, 'stop_command' => 'stop', 'stop_timeout_seconds' => 60,
		'image_reference' => 'eclipse-temurin:25-jre', 'image_digest' => 'sha256:' . str_repeat('a', 64), 'image_enabled' => 1);
}
function start($remote) {
	return $remote->universal_start_result(42, '/users/gameserver/42', 'server.jar', '', 'java -jar server.jar nogui', 25565, '203.0.113.10', 'NA', 0, '', '', 'minecraft', 'logs/latest.log');
}

$GLOBALS['db'] = $db = new DispatchFakeDb();

// Native home: unchanged.
$remote = new DispatchFakeRemote();
start($remote);
t($remote->calls === array('universal_start'), 'native home starts with universal_start');

// Docker home: container RPC only, never universal_start.
$db->runtime = docker_row();
$db->blocks = array(array('block_start' => 25565, 'block_end' => 25570));
gse_execution_runtime(42, true);
$remote = new DispatchFakeRemote();
$remote->replies['container_game_start'] = array(json_encode(array('success' => true, 'status' => 'started', 'state' => 'starting', 'target' => array('container_name' => 'gse-h42-a1'), 'result' => array())));
$r = start($remote);
t($remote->calls === array('container_game_start'), 'docker home starts with container_game_start only');
t(!empty($r['success']) && $r['status'] === 'started', 'container start success maps to started');

$remote = new DispatchFakeRemote();
$remote->replies['container_game_start'] = array(json_encode(array('success' => false, 'status' => 'failed', 'error' => array('code' => 'image_missing', 'message' => 'Image missing'))));
$r = start($remote);
t(empty($r['success']) && $r['status'] === 'start_failed', 'container start failure is reported as start_failed');
t(!in_array('universal_start', $remote->calls, true), 'failed container start never falls back to native');

$remote = new DispatchFakeRemote();
$remote->replies['container_game_start'] = array(NULL);
$r = start($remote);
t(empty($r['success']) && !in_array('universal_start', $remote->calls, true), 'unreachable agent never falls back to native');

// Request contents.
list($req, $err) = gse_container_request(42, '203.0.113.10', 25565);
t($req !== null && $req['target']['allocation_id'] === 1 && $req['target']['owner_user_id'] === 7, 'request targets the home owner with a stable allocation');
t(count($req['payload']['ports']['publish']) === 2 && $req['payload']['ports']['publish'][0]['host_start'] === 25565 && $req['payload']['ports']['publish'][1]['protocol'] === 'udp', 'request publishes the home port block on tcp and udp');
t($req['payload']['image']['digest'] === 'sha256:' . str_repeat('a', 64), 'request pins the image digest');
t(!isset($req['payload']['resources']['memory_burst_mb']), 'no swap unless set');

// Stop, force stop, restart.
$remote = new DispatchFakeRemote();
$remote->replies['container_game_stop'] = array(json_encode(array('success' => true, 'status' => 'stopped', 'state' => 'stopped')));
$r = $remote->remote_stop_server_result(42, '203.0.113.10', 25565, 'rcon', 'pw', 'tcp', '/users/gameserver/42');
t($remote->calls === array('container_game_stop') && !empty($r['success']), 'docker stop uses container_game_stop');

$remote = new DispatchFakeRemote();
$remote->replies['container_game_stop'] = array(json_encode(array('success' => true, 'status' => 'stopped')));
$remote->replies['container_game_start'] = array(json_encode(array('success' => true, 'status' => 'started')));
$r = $remote->remote_restart_server_result(42, '203.0.113.10', 25565, 'rcon', 'pw', 'tcp', '/users/gameserver/42', 'server.jar', '', 'java -jar server.jar', 'NA', 0, '', '', 'minecraft');
t($remote->calls === array('container_game_stop', 'container_game_start') && $r['status'] === 'restarted', 'docker restart is stop then start in the container');

$remote = new DispatchFakeRemote();
$remote->replies['container_game_stop'] = array(json_encode(array('success' => false, 'status' => 'failed', 'error' => array('code' => 'timeout'))));
$r = $remote->remote_restart_server_result(42, '203.0.113.10', 25565, 'rcon', 'pw', 'tcp', '/users/gameserver/42', 'server.jar', '', 'java', 'NA', 0, '', '', 'minecraft');
t($remote->calls === array('container_game_stop') && $r['raw_response'] === -2, 'restart does not start when the stop failed');

// Status and screen check.
$remote = new DispatchFakeRemote();
$remote->replies['container_game_status'] = array(json_encode(array('success' => true, 'state' => 'running', 'result' => array('game_state' => 'ONLINE', 'stats' => array('cpu_percent' => '3%')))));
$s = $remote->remote_server_status(42, '203.0.113.10', 25565);
t($s['status'] === 'ONLINE' && $s['ready'] === 1 && $s['execution_backend'] === 'docker', 'ONLINE container maps to ONLINE status');

$remote = new DispatchFakeRemote();
$remote->replies['container_game_status'] = array(json_encode(array('success' => false, 'result' => array('game_state' => 'RUNTIME_UNAVAILABLE'))));
$s = $remote->remote_server_status(42, '203.0.113.10', 25565);
t($s['status'] === 'UNKNOWN' && $s['process_running'] === 0 && !lifecycle_status_is_verified_stopped_safe($s), 'Docker unavailable is UNKNOWN, never verified stopped');

$remote = new DispatchFakeRemote();
$remote->replies['container_game_status'] = array(json_encode(array('success' => true, 'result' => array('game_state' => 'STOPPED'))), NULL);
t($remote->is_screen_running(SCREEN_TYPE_HOME, 42) === 0, 'stopped container: screen check 0');
t($remote->is_screen_running(SCREEN_TYPE_HOME, 42) === -1, 'unreachable agent: screen check -1 (not stopped)');

// Console line.
$remote = new DispatchFakeRemote();
$remote->replies['container_game_command'] = array(json_encode(array('success' => true, 'status' => 'sent')));
$data = '';
t($remote->remote_send_rcon_command(42, '203.0.113.10', 25565, 'rcon', 'pw', 'tcp', 'say hi', $data) === 1 && $remote->calls === array('container_game_command'), 'console input goes to container_game_command');

// Unreadable backend: refuse, send nothing.
$db->fail = 'Lost connection to MySQL server';
gse_execution_runtime(42, true);
$remote = new DispatchFakeRemote();
$r = start($remote);
t(empty($r['success']) && $r['status'] === 'backend_unknown' && $remote->calls === array(), 'unreadable backend refuses start and sends nothing');

// Table not created yet: native.
$db->fail = "Table 'panel.home_execution_runtime' doesn't exist";
gse_execution_runtime(42, true);
$remote = new DispatchFakeRemote();
start($remote);
t($remote->calls === array('universal_start'), 'before the migration every home is native');

function lifecycle_status_is_verified_stopped_safe($s) {
	$status = strtoupper($s['status']);
	return in_array($status, array('OFFLINE', 'STOPPED', 'STOPPING'), true);
}

echo "All execution backend dispatch tests passed.\n";
