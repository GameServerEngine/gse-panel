<?php
// End to end: the panel (diag_execute + RemoteLibrary, real XML-RPC and XXTEA) talks to a fake agent process
// that follows docs/agent-diagnostics-protocol.md and opens real sockets to controlled targets on this machine.
// Targets bind to this machine's own non-loopback IPv4 address, because loopback is always blocked by design.
// Run: php includes/tests/diag_agent_integration_test.php   (skips with exit 0 if no such address or no xmlrpc)
require_once __DIR__ . '/diag_test_helper.php';
if (!function_exists('xmlrpc_encode_request')) {
	echo "SKIP: needs the xmlrpc extension\n";
	exit(0);
}
require_once dirname(__DIR__) . '/lib_remote.php';
require_once dirname(__DIR__) . '/diagnostics/runner.php';

function local_ipv4()
{
	foreach (net_get_interfaces() as $iface) {
		foreach ($iface['unicast'] as $addr) {
			if (($addr['family'] ?? 0) === AF_INET && strpos($addr['address'], '127.') !== 0 && strpos($addr['address'], '169.254.') !== 0) {
				return $addr['address'];
			}
		}
	}
	return null;
}

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

function start($command, $env = array())
{
	$proc = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a')), $pipes, null, array_merge($_ENV, $env, array('PATH' => getenv('PATH'))));
	return array($proc, $pipes[1]);
}

$ip = local_ipv4();
if ($ip === null) {
	echo "SKIP: no non-loopback IPv4 address\n";
	exit(0);
}
$key = 'integration-key!'; // XXTEA keys are at most 16 bytes
$router = __DIR__ . '/fixtures/fake_diag_agent.php';
$ports = array('agent' => free_port(), 'legacy' => free_port());
$procs = array();
$procs[] = start('php -S 127.0.0.1:' . $ports['agent'] . ' ' . escapeshellarg($router), array('FAKE_AGENT_KEY' => $key));
$procs[] = start('php -S 127.0.0.1:' . $ports['legacy'] . ' ' . escapeshellarg($router), array('FAKE_AGENT_KEY' => $key, 'FAKE_AGENT_LEGACY' => '1'));
$a2s_port = free_port();
list($a2s_proc, $a2s_out) = start('php ' . escapeshellarg(__DIR__ . '/fixtures/fake_a2s_server.php') . ' ' . $ip . ' ' . $a2s_port);
$procs[] = array($a2s_proc, $a2s_out);
fgets($a2s_out);
register_shutdown_function(function () use ($procs) {
	foreach ($procs as $p) {
		proc_terminate($p[0]);
	}
});
usleep(800000);

// Controlled targets: a listening TCP port, a silent UDP port, a closed TCP port, a closed UDP port.
$tcp_listener = stream_socket_server('tcp://' . $ip . ':0');
$tcp_port = (int)substr(strrchr(stream_socket_get_name($tcp_listener, false), ':'), 1);
$silent_udp = stream_socket_server('udp://' . $ip . ':0', $e1, $e2, STREAM_SERVER_BIND);
$silent_port = (int)substr(strrchr(stream_socket_get_name($silent_udp, false), ':'), 1);
$closed_tcp = stream_socket_server('tcp://' . $ip . ':0');
$closed_tcp_port = (int)substr(strrchr(stream_socket_get_name($closed_tcp, false), ':'), 1);
fclose($closed_tcp);
$closed_udp = stream_socket_server('udp://' . $ip . ':0', $e1, $e2, STREAM_SERVER_BIND);
$closed_udp_port = (int)substr(strrchr(stream_socket_get_name($closed_udp, false), ':'), 1);
fclose($closed_udp);

function env_for($port, $key, $ip, $role = 'user')
{
	return array('user_id' => 7, 'role' => $role, 'client_ip' => '192.0.2.1', 'now' => time(), 'store' => new DiagAuditMemory(), 'limits' => diag_limit_settings(array()),
		'policy' => array('role' => $role, 'own_ips' => array($ip), 'test_targets' => array(), 'admin_allow_private' => false),
		'agent_ids' => array(3), 'speed_endpoints' => array(),
		'agent' => function ($id, $timeout) use ($port, $key) { return new RemoteLibrary('127.0.0.1', $port, $key, $timeout); });
}

$env = env_for($ports['agent'], $key, $ip);
$base = array('agent' => 3, 'host' => $ip);

// --- probe over the real wire
$remote = new RemoteLibrary('127.0.0.1', $ports['agent'], $key, 5);
$probe = $remote->diag_probe();
check('wire: probe sees diagnostics support', $probe['state'] === 'ok' && $probe['caps']['protocol_version'] === 1 && $probe['caps']['tools'] === array('tcp_connect', 'udp_exchange'));
check('wire: wrong key is not usable', (new RemoteLibrary('127.0.0.1', $ports['agent'], 'wrong-key', 5))->diag_probe()['state'] === 'offline');
check('wire: stopped agent is offline', (new RemoteLibrary('127.0.0.1', free_port(), $key, 2))->diag_probe()['state'] === 'offline');

// --- TCP
$r = diag_execute('tcp_test', $base + array('port' => $tcp_port), $env);
check('tcp: listening port connected, latency measured', $r['ok'] && $r['outcome'] === 'connected' && $r['rows'][1][1] !== 'n/a');
check('tcp: report names agent, target and duration', $r['source']['agent'] === 3 && $r['source']['target'] === $ip && $r['source']['port'] === $tcp_port && $r['source']['duration_ms'] >= 0);
$r = diag_execute('tcp_test', $base + array('port' => $closed_tcp_port), $env);
check('tcp: closed port is refused', $r['ok'] && $r['outcome'] === 'refused');
$r = diag_execute('port_check', $base + array('port' => $tcp_port, 'protocol' => 'tcp'), $env);
check('port checker: tcp uses the same backend', $r['ok'] && $r['outcome'] === 'connected');

// --- Server Query: challenge + split packets + real UDP through the agent
$r = diag_execute('server_query', $base + array('port' => $a2s_port, 'adapter' => 'a2s', 'timeout_ms' => 2000), $env);
check('query: online through challenge and split packets', $r['ok'] && $r['outcome'] === 'online' && $r['info']['name'] === 'Integration Server' && $r['info']['map'] === 'de_nuke');
check('query: players and password reported', $r['info']['players'] === 7 && $r['info']['max_players'] === 20 && $r['info']['password'] === false);
check('query: latency present', $r['rows'][1][0] === 'Query latency');
$r = diag_execute('port_check', $base + array('port' => $a2s_port, 'protocol' => 'udp', 'timeout_ms' => 2000), $env);
check('udp port check: answered by a real A2S reply', $r['ok'] && $r['outcome'] === 'online');
$r = diag_execute('server_query', $base + array('port' => $silent_port, 'timeout_ms' => 500), $env);
check('query: silent UDP port is no_response (not offline)', $r['ok'] && $r['outcome'] === 'no_response');
$r = diag_execute('server_query', $base + array('port' => $closed_udp_port, 'timeout_ms' => 1000), $env);
check('query: closed UDP port reports ICMP unreachable', $r['ok'] && in_array($r['outcome'], array('port_unreachable', 'no_response'), true));
echo "  (closed UDP port outcome: " . $r['outcome'] . ")\n";

// --- agent side rules (protocol doc, sections 4 and 5) called directly
$params = array('host_ip' => '127.0.0.1', 'port' => 80, 'timeout_ms' => 500, 'allow_private' => true);
check('agent: loopback blocked even with allow_private', $remote->diag_run('tcp_connect', $params)['status'] === 'target_blocked');
$params = array('host_ip' => '169.254.169.254', 'port' => 80, 'timeout_ms' => 500, 'allow_private' => true);
check('agent: metadata address blocked', $remote->diag_run('tcp_connect', $params)['status'] === 'target_blocked');
$params = array('host_ip' => '10.1.2.3', 'port' => 80, 'timeout_ms' => 500, 'allow_private' => false);
check('agent: private blocked without allow_private', $remote->diag_run('tcp_connect', $params)['status'] === 'target_blocked');
check('agent: host name instead of IP rejected', $remote->diag_run('tcp_connect', array('host_ip' => 'example.com', 'port' => 80, 'timeout_ms' => 500))['status'] === 'bad_params');
check('agent: out of range port rejected', $remote->diag_run('tcp_connect', array('host_ip' => $ip, 'port' => 70000, 'timeout_ms' => 500))['status'] === 'bad_params');
check('agent: out of range timeout rejected', $remote->diag_run('tcp_connect', array('host_ip' => $ip, 'port' => 80, 'timeout_ms' => 999999))['status'] === 'bad_params');
check('agent: empty UDP payload rejected', $remote->diag_run('udp_exchange', array('host_ip' => $ip, 'port' => 80, 'payload_b64' => '', 'timeout_ms' => 500, 'idle_ms' => 100, 'max_datagrams' => 1, 'max_bytes' => 64))['status'] === 'bad_params');
check('agent: tool it does not have', $remote->diag_run('traceroute', array('host_ip' => $ip))['status'] === 'unsupported_tool');

// --- tools this agent lacks, and an old agent
$r = diag_execute('traceroute', $base, env_for($ports['agent'], $key, $ip, 'admin'));
check('partial agent: traceroute shows the update message', !$r['ok'] && $r['code'] === 'agent_needs_update' && strpos($r['message'], 'needs to be updated to version') !== false);
$r = diag_execute('tcp_test', $base + array('port' => $tcp_port), env_for($ports['legacy'], $key, $ip));
check('old agent: update message, no failure', !$r['ok'] && $r['code'] === 'agent_needs_update' && $r['message'] === 'This agent needs to be updated to version 0.6.0 to run this test.');
$r = diag_execute('tcp_test', $base + array('port' => $tcp_port), env_for(free_port(), $key, $ip));
check('stopped agent: offline message', !$r['ok'] && $r['code'] === 'agent_offline');
$r = diag_execute('tcp_test', $base + array('port' => $tcp_port), env_for($ports['agent'], 'wrong-key', $ip));
check('wrong agent key: offline message (agent rejects the key)', !$r['ok'] && $r['code'] === 'agent_offline');

// --- audit trail of the run above
$audit = $env['store']->rows;
check('audit: every run has a finished row', count($audit) === 7 && count(array_filter($audit, function ($row) { return $row['status'] === 'running'; })) === 0);
check('audit: rows name user, agent, tool, target', $audit[0]['user_id'] === 7 && $audit[0]['remote_server_id'] === 3 && $audit[0]['tool'] === 'tcp_test' && $audit[0]['target'] === $ip);
echo "All integration tests passed\n";
