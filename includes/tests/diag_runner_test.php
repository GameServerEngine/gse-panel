<?php
// Runner tests: permissions, validation, rate limits, concurrency, unsupported agents, agent failures,
// timeouts, audit rows, speed endpoints, ping safety. Fake agents and a fake database; no network.
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/diagnostics/runner.php';

// Agent double. $caps null = old agent without diagnostics. $replies: tool => reply array (success, status, message, result...).
class FakeAgent
{
	public $calls = array();
	public $commands = array();
	public $state = 'ok';
	public $caps;
	public $replies = array();

	public function __construct()
	{
		$this->caps = array('protocol_version' => 1, 'agent_version' => '9.9.9', 'os' => 'linux',
			'tools' => array('tcp_connect', 'udp_exchange', 'traceroute', 'http_download'), 'limits' => array());
	}

	public function diag_probe()
	{
		return array('state' => $this->state, 'caps' => $this->state === 'ok' ? $this->caps : null);
	}

	public function diag_run($tool, $params)
	{
		$this->calls[] = array($tool, $params);
		return isset($this->replies[$tool]) ? $this->replies[$tool] : array('success' => false, 'status' => 'internal_error', 'message' => '', 'result' => array());
	}

	public function what_os()
	{
		return 'Linux';
	}

	public function exec($command)
	{
		$this->commands[] = $command;
		return strpos($command, 'which') === 0 ? '/bin/ping' : "PING ok\n4 packets transmitted";
	}
}

function reply($result, $tool = '')
{
	return array('success' => true, 'status' => 'ok', 'message' => '', 'result' => $result, 'duration_ms' => 5, 'truncated' => false);
}

function make_env($agent, $over = array())
{
	$dns = function ($host) {
		return $host === 'mygame.example.com' ? array('198.51.101.7') : array();
	};
	$store = new DiagAuditMemory();
	return array_merge(array(
		'user_id' => 7, 'role' => 'user', 'client_ip' => '192.0.2.1', 'now' => 1000000, 'store' => $store,
		'limits' => diag_limit_settings(array()),
		'policy' => array('role' => 'user', 'own_ips' => array('198.51.101.7'), 'test_targets' => array('tests.example.org'), 'admin_allow_private' => false, 'resolver' => $dns),
		'agent_ids' => array(3), 'agent' => function ($id, $timeout) use ($agent) { return $agent; },
		'speed_endpoints' => array(),
	), $over);
}

$tcp_ok = reply(array('outcome' => 'connected', 'connect_ms' => 12.34, 'remote_ip' => '198.51.101.7', 'detail' => ''));

// --- TCP success, report fields
$agent = new FakeAgent();
$agent->replies['tcp_connect'] = $tcp_ok;
$env = make_env($agent);
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015', 'timeout_ms' => '2000'), $env);
check('tcp: ok result', $r['ok'] && $r['outcome'] === 'connected');
check('tcp: report names source, target, port, protocol, duration', $r['source']['agent'] === 3 && $r['source']['target'] === '198.51.101.7' && $r['source']['port'] === 27015 && $r['source']['protocol'] === 'tcp' && is_int($r['source']['duration_ms']));
check('tcp: agent got pinned IP and timeout', $agent->calls[0][1]['host_ip'] === '198.51.101.7' && $agent->calls[0][1]['timeout_ms'] === 2000 && $agent->calls[0][1]['allow_private'] === false);
check('tcp: note says TCP does not verify the game protocol', stripos($r['note'], 'does not verify the game protocol') !== false);
check('tcp: audit row finished ok', $env['store']->rows[0]['status'] === 'ok' && $env['store']->rows[0]['user_id'] === 7 && $env['store']->rows[0]['tool'] === 'tcp_test');

foreach (array('refused', 'timeout', 'unreachable') as $outcome) {
	$agent->replies['tcp_connect'] = reply(array('outcome' => $outcome, 'connect_ms' => null));
	$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent));
	check("tcp: $outcome reported", $r['ok'] && $r['outcome'] === $outcome);
}
$agent->replies['tcp_connect'] = reply(array('outcome' => 'timeout'));
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent));
check('tcp timeout text does not claim closed', stripos($r['summary'], 'does not prove') !== false);
$agent->replies['tcp_connect'] = reply(array('outcome' => 'DROP TABLE', 'connect_ms' => 'x'));
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent));
check('tcp: unknown outcome from agent becomes error', $r['outcome'] === 'error');

// --- validation errors are not agent calls
$agent = new FakeAgent();
$env = make_env($agent);
foreach (array(
	array('port', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '0')),
	array('port', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '70000')),
	array('host', array('agent' => 3, 'host' => '198.51.101.7; rm -rf /', 'port' => '80')),
	array('timeout', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80', 'timeout_ms' => '999999')),
	array('timeout', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80', 'timeout_ms' => '0')),
	array('protocol', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80', 'protocol' => 'icmp')),
) as $case) {
	$r = diag_execute('port_check', $case[1], $env);
	check('validation: bad ' . $case[0] . ' refused', !$r['ok'] && $r['code'] === 'bad_input');
}
check('validation: no agent call made', count($agent->calls) === 0);
check('validation: no audit row for form typos', count($env['store']->rows) === 0);

// --- permissions
$env = make_env($agent);
$r = diag_execute('tcp_test', array('agent' => 99, 'host' => '198.51.101.7', 'port' => '80'), $env);
check('permission: agent not assigned to user refused', !$r['ok'] && $r['code'] === 'forbidden');
check('permission: refusal is audited as denied', $env['store']->rows[0]['status'] === 'denied');
$r = diag_execute('traceroute', array('agent' => 3, 'host' => '198.51.101.7'), make_env($agent));
check('permission: traceroute is admin only', !$r['ok'] && $r['code'] === 'forbidden');
$r = diag_execute('speed_test', array('agent' => 3, 'endpoint' => 'x', 'size' => '10'), make_env($agent));
check('permission: speed test is admin only', !$r['ok'] && $r['code'] === 'forbidden');
$r = diag_execute('unknown_tool', array('agent' => 3, 'host' => '198.51.101.7'), make_env($agent));
check('permission: unknown tool refused', !$r['ok'] && $r['code'] === 'forbidden');
check('permission: nothing reached the agent', count($agent->calls) === 0);

// --- target policy through the runner
$agent = new FakeAgent();
$agent->replies['tcp_connect'] = $tcp_ok;
foreach (array('127.0.0.1', '169.254.169.254', '10.0.0.1', '8.8.8.8') as $ip) {
	$env = make_env($agent);
	$r = diag_execute('tcp_test', array('agent' => 3, 'host' => $ip, 'port' => '80'), $env);
	check("policy: $ip refused for a user", !$r['ok'] && $env['store']->rows[0]['status'] === 'denied');
}
check('policy: agent never called for refused targets', count($agent->calls) === 0);

// --- unsupported / offline agent
$agent = new FakeAgent();
$agent->state = 'unsupported';
$env = make_env($agent);
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), $env);
check('old agent: update message with version', !$r['ok'] && $r['code'] === 'agent_needs_update' && $r['message'] === 'This agent needs to be updated to version 0.6.0 to run this test.');
check('old agent: audited as unsupported', $env['store']->rows[0]['status'] === 'unsupported');
$env = make_env($agent, array('os_hint' => 'windows'));
$r = diag_execute('server_query', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015'), $env);
check('old agent: windows version used for windows agents', strpos($r['message'], '1.6.0') !== false);
$agent->state = 'offline';
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent));
check('offline agent: offline message, not the update message', $r['code'] === 'agent_offline');
$agent->state = 'ok';
$agent->caps['tools'] = array('tcp_connect');
$r = diag_execute('server_query', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015'), make_env($agent));
check('partial agent: missing tool gives update message', $r['code'] === 'agent_needs_update');
$agent->caps['protocol_version'] = 0;
$agent->caps['tools'] = array('tcp_connect');
check('old protocol version gives update message', diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent))['code'] === 'agent_needs_update');

// --- agent errors and timeouts
$agent = new FakeAgent();
foreach (array('busy', 'timeout', 'target_blocked', 'bad_params', 'internal_error', 'agent_unreachable') as $status) {
	$agent->replies['tcp_connect'] = array('success' => false, 'status' => $status, 'message' => '', 'result' => array());
	$env = make_env($agent);
	$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), $env);
	check("agent error $status: clear failure and audited", !$r['ok'] && $r['code'] === $status && $r['message'] !== '' && $env['store']->rows[0]['status'] === 'failed');
}

// --- rate limits and concurrency
$agent = new FakeAgent();
$agent->replies['tcp_connect'] = $tcp_ok;
$store = new DiagAuditMemory();
$limits = diag_limit_settings(array('diag_rate_per_user' => '3'));
$last = null;
for ($i = 0; $i < 5; $i++) {
	$last = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent, array('store' => $store, 'limits' => $limits)));
}
check('rate limit: 4th request in the window refused', !$last['ok'] && $last['code'] === 'rate_user');
check('rate limit: agent called only 3 times', count($agent->calls) === 3);
$late = make_env($agent, array('store' => $store, 'limits' => $limits, 'now' => 1000000 + 301));
check('rate limit: allowed again after the window', diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), $late)['ok']);
$other = make_env($agent, array('store' => $store, 'limits' => $limits, 'user_id' => 8));
check('rate limit: another user is not affected', diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), $other)['ok']);

$store = new DiagAuditMemory();
$store->insert(array('created_at' => 1000000 - 5, 'user_id' => 7, 'remote_server_id' => 3, 'status' => 'running'));
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent, array('store' => $store)));
check('concurrency: second job for the same user refused', !$r['ok'] && $r['code'] === 'busy_user');
$store = new DiagAuditMemory();
for ($i = 0; $i < 3; $i++) {
	$store->insert(array('created_at' => 1000000 - 5, 'user_id' => 100 + $i, 'remote_server_id' => 3, 'status' => 'running'));
}
$r = diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent, array('store' => $store)));
check('concurrency: agent cap refuses a 4th running job', !$r['ok'] && $r['code'] === 'busy_agent');
$store = new DiagAuditMemory();
$store->insert(array('created_at' => 1000000 - 500, 'user_id' => 7, 'remote_server_id' => 3, 'status' => 'running'));
check('concurrency: stale running row (crashed request) expires', diag_execute('tcp_test', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent, array('store' => $store)))['ok']);
$store = new DiagAuditMemory();
$limits = diag_limit_settings(array('diag_rate_per_user' => '2'));
for ($i = 0; $i < 4; $i++) {
	diag_execute('tcp_test', array('agent' => 3, 'host' => '8.8.8.8', 'port' => '80'), make_env($agent, array('store' => $store, 'limits' => $limits)));
}
check('rate limit: denied probing attempts count too', $store->rows[3]['status'] === 'rate_limited');

// --- Server query through the runner (UDP, A2S)
$agent = new FakeAgent();
$agent->replies['udp_exchange'] = reply(array('outcome' => 'replied', 'datagrams' => array(array('t_ms' => 18.2, 'data_b64' => base64_encode(test_a2s_info_reply('Test Srv')))), 'bytes' => 100));
$env = make_env($agent);
$r = diag_execute('server_query', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27016', 'adapter' => 'a2s'), $env);
check('query: online with name and latency', $r['ok'] && $r['outcome'] === 'online' && $r['info']['name'] === 'Test Srv');
check('query: query port is used, not a game port', $agent->calls[0][1]['port'] === 27016 && $agent->calls[0][0] === 'udp_exchange');
check('query: payload is real A2S_INFO, not empty', base64_decode($agent->calls[0][1]['payload_b64']) === "\xff\xff\xff\xffTSource Engine Query\0");
$agent->replies['udp_exchange'] = reply(array('outcome' => 'no_reply', 'datagrams' => array()));
$r = diag_execute('server_query', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015'), make_env($agent));
check('query: unanswered is no_response, not offline', $r['outcome'] === 'no_response');
$r = diag_execute('port_check', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015', 'protocol' => 'udp'), make_env($agent));
check('udp port check: timeout never says closed', $r['outcome'] === 'no_response' && stripos($r['note'], 'does not mean the port is closed') !== false && stripos($r['summary'], 'does not prove') !== false);
$agent->replies['udp_exchange'] = array('success' => false, 'status' => 'busy', 'message' => '', 'result' => array());
$r = diag_execute('server_query', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015'), make_env($agent));
check('query: agent busy shows as agent_error status', $r['ok'] && $r['outcome'] === 'agent_error');
$r = diag_execute('server_query', array('agent' => 3, 'host' => '198.51.101.7', 'port' => '27015', 'adapter' => 'nonsense'), make_env($agent));
check('query: unknown protocol refused', !$r['ok'] && $r['code'] === 'bad_input');

// --- traceroute (admin)
$agent = new FakeAgent();
$agent->replies['traceroute'] = reply(array('outcome' => 'completed', 'method' => 'traceroute', 'reached' => true, 'hops' => array(
	array('hop' => 1, 'probes' => array(array('ip' => '10.0.0.1', 'host' => null, 'rtt_ms' => 0.4, 'timeout' => false))),
	array('hop' => 2, 'probes' => array(array('ip' => null, 'host' => null, 'rtt_ms' => null, 'timeout' => true))),
	array('hop' => 3, 'probes' => array(array('ip' => '<script>', 'host' => '<b>x</b>', 'rtt_ms' => 9999999, 'timeout' => false))),
)));
$admin = array('role' => 'admin', 'policy' => array('role' => 'admin', 'own_ips' => array(), 'test_targets' => array(), 'admin_allow_private' => false, 'resolver' => function ($h) { return array(); }));
$r = diag_execute('traceroute', array('agent' => 3, 'host' => '8.8.8.8', 'max_hops' => '2'), make_env($agent, $admin));
check('traceroute: hop limit enforced on the reply', $r['ok'] && count($r['hops']) === 2);
$r = diag_execute('traceroute', array('agent' => 3, 'host' => '8.8.8.8', 'max_hops' => '10'), make_env($agent, $admin));
check('traceroute: timeout hop and rtt kept', $r['hops'][1]['probes'][0]['timeout'] === true && $r['hops'][0]['probes'][0]['rtt_ms'] === 0.4);
check('traceroute: junk ip dropped, rtt capped', $r['hops'][2]['probes'][0]['ip'] === '' && $r['hops'][2]['probes'][0]['rtt_ms'] <= 600000);
check('traceroute: max hops over 30 refused', !diag_execute('traceroute', array('agent' => 3, 'host' => '8.8.8.8', 'max_hops' => '99'), make_env($agent, $admin))['ok']);
check('traceroute: params sent within contract limits', diag_check_params($agent->calls[0][1]) === '');

// --- speed test endpoints
$json = json_encode(array(
	array('id' => 'cdn', 'label' => 'Test CDN', 'sizes' => array(array('id' => '10', 'label' => '10 MB', 'bytes' => 10485760, 'url' => 'https://speed.example.net/10mb.bin'),
		array('id' => '100', 'label' => '100 MB', 'bytes' => 104857600, 'url' => 'https://speed.example.net/100mb.bin'))),
	array('id' => 'evil1', 'sizes' => array(array('id' => '1', 'bytes' => 10485760, 'url' => 'file:///etc/passwd'))),
	array('id' => 'evil2', 'sizes' => array(array('id' => '1', 'bytes' => 10485760, 'url' => 'https://user:pw@speed.example.net/x'))),
	array('id' => 'evil3', 'sizes' => array(array('id' => '1', 'bytes' => 10485760, 'url' => 'https://a.example.net/x'), array('id' => '2', 'bytes' => 10485760, 'url' => 'https://b.example.net/x'))),
	array('id' => 'huge', 'sizes' => array(array('id' => '1', 'bytes' => 99999999999, 'url' => 'https://speed.example.net/x'))),
	array('id' => 'BAD ID', 'sizes' => array(array('id' => '1', 'bytes' => 10485760, 'url' => 'https://speed.example.net/x'))),
));
$endpoints = diag_load_speed_endpoints($json);
check('endpoints: only valid entries kept', count($endpoints) === 2 && $endpoints[0]['id'] === 'cdn' && $endpoints[1]['id'] === 'evil3' && count($endpoints[1]['sizes']) === 1);
check('endpoints: garbage JSON gives none', diag_load_speed_endpoints('not json') === array());

$agent = new FakeAgent();
$agent->replies['http_download'] = reply(array('outcome' => 'ok', 'bytes' => 10485760, 'elapsed_ms' => 1000.0, 'connect_ms' => 20.0, 'mbps' => 99999.0, 'http_status' => 200, 'remote_ip' => '93.184.216.34', 'connections' => 1));
$env = make_env($agent, array_merge($admin, array('speed_endpoints' => $endpoints)));
$r = diag_execute('speed_test', array('agent' => 3, 'endpoint' => 'cdn', 'size' => '10'), $env);
check('speed: ok and Mbps recomputed from bytes and time', $r['ok'] && $r['rows'][4][1] === '83.89 Mbps');
check('speed: URL comes from config, caps are set', $agent->calls[0][1]['url'] === 'https://speed.example.net/10mb.bin' && $agent->calls[0][1]['max_bytes'] <= 268435456 && $agent->calls[0][1]['allow_private'] === false);
check('speed: labelled as agent-to-endpoint throughput', stripos($r['note'], 'agent to the test endpoint') !== false);
$r = diag_execute('speed_test', array('agent' => 3, 'endpoint' => 'cdn', 'size' => '10', 'url' => 'http://169.254.169.254/'), make_env($agent, array_merge($admin, array('speed_endpoints' => $endpoints))));
check('speed: a url field from the user is ignored', $agent->calls[1][1]['url'] === 'https://speed.example.net/10mb.bin');
$r = diag_execute('speed_test', array('agent' => 3, 'endpoint' => 'nope', 'size' => '10'), make_env($agent, array_merge($admin, array('speed_endpoints' => $endpoints))));
check('speed: unknown endpoint refused', !$r['ok'] && $r['code'] === 'bad_input');
$agent->replies['http_download'] = reply(array('outcome' => 'redirect_blocked'));
$r = diag_execute('speed_test', array('agent' => 3, 'endpoint' => 'cdn', 'size' => '10'), make_env($agent, array_merge($admin, array('speed_endpoints' => $endpoints))));
check('speed: redirect refusal reported', $r['ok'] && $r['outcome'] === 'redirect_blocked');
$bad_endpoint = array(array('id' => 'ip', 'label' => 'ip', 'host' => '127.0.0.1', 'port' => 80, 'sizes' => array(array('id' => '1', 'label' => '1', 'bytes' => 10485760, 'url' => 'http://127.0.0.1/x', 'port' => 80))));
$r = diag_execute('speed_test', array('agent' => 3, 'endpoint' => 'ip', 'size' => '1'), make_env($agent, array_merge($admin, array('speed_endpoints' => $bad_endpoint))));
check('speed: loopback endpoint IP refused by the panel too', !$r['ok'] && $r['code'] === 'blocked');

// --- ping: only a validated IP reaches the command
$agent = new FakeAgent();
$env = make_env($agent);
$r = diag_execute('ping', array('agent' => 3, 'host' => '198.51.101.7'), $env);
check('ping: runs with the validated IP', $r['ok'] && $agent->commands[1] === 'ping -c 4 198.51.101.7' && strpos($r['output'], 'PING ok') === 0);
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3, 'host' => '198.51.101.7; cat /etc/passwd'), make_env($agent));
check('ping: injection attempt refused, no command run', !$r['ok'] && count($agent->commands) === 0);
$r = diag_execute('ping', array('agent' => 3, 'host' => '127.0.0.1'), make_env($agent));
check('ping: loopback refused', !$r['ok'] && count($agent->commands) === 0);
$r = diag_execute('ping', array('agent' => 3, 'host' => 'mygame.example.com'), make_env($agent));
check('ping: name is resolved by the panel and the IP is used', $r['ok'] && $agent->commands[1] === 'ping -c 4 198.51.101.7');

// --- guard messages exist for every code
foreach (array('busy_user', 'busy_agent', 'rate_user', 'rate_agent') as $code) {
	check("guard message for $code", diag_guard_message($code) !== 'Not allowed right now.');
}
echo "All runner tests passed\n";
