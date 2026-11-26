<?php
// The panel (diag_execute + RemoteLibrary, real XML-RPC and XXTEA) talks to a fake agent process over a real socket.
// The runner asks the agent's probe first, so these tests cover what the runner needs from the wire:
// a working agent is seen, a wrong key or a stopped agent is "offline" for both tools, and nothing is run on it.
// Run: php includes/tests/diag_agent_integration_test.php   (skips with exit 0 if there is no xmlrpc extension)
require_once __DIR__ . '/diag_test_helper.php';
if (!function_exists('xmlrpc_encode_request')) {
	echo "SKIP: needs the xmlrpc extension\n";
	exit(0);
}
require_once dirname(__DIR__) . '/lib_remote.php';
require_once dirname(__DIR__) . '/diagnostics/runner.php';

// A port nobody listens on. Never returns the same port twice in one run.
function free_port()
{
	static $given = array();
	do {
		$s = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
		fclose($s);
	} while (in_array($port, $given, true));
	$given[] = $port;
	return $port;
}

$key = 'integration-key!'; // XXTEA keys are at most 16 bytes
$router = __DIR__ . '/fixtures/fake_diag_agent.php';
$port = free_port();
$proc = proc_open('php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router), array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a')), $pipes, null,
	array_merge($_ENV, array('FAKE_AGENT_KEY' => $key, 'PATH' => getenv('PATH'))));
register_shutdown_function(function () use ($proc) {
	proc_terminate($proc);
});
usleep(800000);

function env_for($port, $key)
{
	$rows = array(array('remote_server_id' => 3, 'ip' => '198.51.101.7', 'port' => 2302, 'home_cfg_file' => 'x.xml', 'server_os' => 'linux'));
	return array('user_id' => 7, 'role' => 'user', 'client_ip' => '198.51.101.50', 'now' => time(), 'store' => new DiagAuditMemory(), 'limits' => diag_limit_settings(array()),
		'policy' => array('role' => 'user', 'own_ips' => array('198.51.101.7'), 'admin_allow_private' => false),
		'agent_ids' => array(3), 'rows' => $rows, 'server_ports' => function ($row) { return array(array('port' => 2302, 'label' => 'Game port')); },
		'agent' => function ($id, $timeout) use ($port, $key) { return new RemoteLibrary('127.0.0.1', $port, $key, $timeout); });
}

$remote = new RemoteLibrary('127.0.0.1', $port, $key, 5);
$probe = $remote->diag_probe();
check('wire: probe sees a working agent', $probe['state'] === 'ok');
check('wire: wrong key is not usable', (new RemoteLibrary('127.0.0.1', $port, 'wrong-key', 5))->diag_probe()['state'] === 'offline');
check('wire: stopped agent is offline', (new RemoteLibrary('127.0.0.1', free_port(), $key, 2))->diag_probe()['state'] === 'offline');

$ping = array('agent' => 3, 'host' => '8.8.8.8');
$check = array('agent' => 3, 'server_ip' => '198.51.101.7', 'server_port' => '2302');
foreach (array('ping' => $ping, 'port_check' => $check) as $tool => $input) {
	$env = env_for(free_port(), $key);
	$r = diag_execute($tool, $input, $env);
	check("stopped agent: $tool says offline", !$r['ok'] && $r['code'] === 'agent_offline' && $env['store']->rows[0]['status'] === 'failed');
	$r = diag_execute($tool, $input, env_for($port, 'wrong-key'));
	check("wrong agent key: $tool says offline (agent rejects the key)", !$r['ok'] && $r['code'] === 'agent_offline');
}
echo "All integration tests passed\n";
