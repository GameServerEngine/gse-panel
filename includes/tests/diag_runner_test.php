<?php
// Runner tests for the two tools (ping, port check): permissions, validation, target policy, offline agents,
// agent failures, rate limits, concurrency, audit rows. Fake agents and a fake database; no network.
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/diagnostics/runner.php';

// Agent double. $outputs: exact command => text the agent's exec() returns. Every command it was asked to run is kept.
class FakeAgent
{
	public $commands = array();
	public $state = 'ok';
	public $os = 'Linux';
	public $outputs = array();

	public function diag_probe()
	{
		return array('state' => $this->state, 'caps' => null);
	}

	public function what_os()
	{
		return $this->os;
	}

	public function exec($command)
	{
		$this->commands[] = $command;
		if ($command === 'which ping') {
			return isset($this->outputs[$command]) ? $this->outputs[$command] : '/bin/ping';
		}
		if (strpos($command, 'ping') === 0) {
			return array_key_exists($command, $this->outputs) ? $this->outputs[$command] : "PING ok\n4 packets transmitted";
		}
		return isset($this->outputs[$command]) ? $this->outputs[$command] : '';
	}
}

$SS_GAME = "udp   UNCONN 0      0                  *:2302            *:*    users:((\"arma2oaserver\",pid=4242,fd=7))\n"
	. "tcp   LISTEN 0      128          0.0.0.0:22         0.0.0.0:*    users:((\"sshd\",pid=811,fd=3))\n";
$NETSTAT = "  Proto  Local Address          Foreign Address        State           PID\r\n"
	. "  UDP    0.0.0.0:2302           *:*                                    2200\r\n"
	. "  UDP    0.0.0.0:2303           *:*                                    2200\r\n"
	. "  TCP    0.0.0.0:135            0.0.0.0:0              LISTENING       1012\r\n";

function make_env($agent, $over = array())
{
	$dns = function ($host) {
		return $host === 'mygame.example.com' ? array('198.51.101.7') : array();
	};
	$rows = array(array('remote_server_id' => 3, 'ip' => '198.51.101.7', 'port' => 2302, 'home_id' => 1, 'home_name' => 'Arma', 'home_cfg_file' => 'arma2oa.xml', 'server_os' => 'linux'));
	return array_merge(array(
		'user_id' => 7, 'role' => 'user', 'client_ip' => '198.51.101.50', 'now' => 1000000, 'store' => new DiagAuditMemory(),
		'limits' => diag_limit_settings(array()),
		'policy' => array('role' => 'user', 'own_ips' => array('198.51.101.7'), 'admin_allow_private' => false, 'resolver' => $dns),
		'agent_ids' => array(3), 'agent' => function ($id, $timeout) use ($agent) { return $agent; },
		'rows' => $rows,
		'server_ports' => function ($row) { return array(array('port' => 2302, 'label' => 'Game port'), array('port' => 2303, 'label' => 'Query port')); },
	), $over);
}

function pc($extra = array())
{
	return array_merge(array('agent' => 3, 'server_ip' => '198.51.101.7', 'server_port' => '2302'), $extra);
}

// --- ping
$agent = new FakeAgent();
$env = make_env($agent);
$r = diag_execute('ping', array('agent' => 3, 'host' => '198.51.101.7'), $env);
check('ping: runs with the validated IP', $r['ok'] && $agent->commands[1] === 'ping -c 4 198.51.101.7' && strpos($r['output'], 'PING ok') === 0);
check('ping: report names source agent and target', $r['source']['agent'] === 3 && $r['source']['target'] === '198.51.101.7');
check('ping: audit row finished ok', $env['store']->rows[0]['status'] === 'ok' && $env['store']->rows[0]['tool'] === 'ping' && $env['store']->rows[0]['user_id'] === 7);
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3, 'host' => ''), make_env($agent));
check('ping: empty target means my IP', $r['ok'] && $agent->commands[1] === 'ping -c 4 198.51.101.50');
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3), make_env($agent));
check('ping: missing target field means my IP', $r['ok'] && $agent->commands[1] === 'ping -c 4 198.51.101.50');
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3, 'host' => '8.8.8.8'), make_env($agent));
check('ping: a user may ping any public IP', $r['ok'] && $agent->commands[1] === 'ping -c 4 8.8.8.8');
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3, 'host' => 'mygame.example.com'), make_env($agent));
check('ping: name is resolved by the panel and the IP is used', $r['ok'] && $agent->commands[1] === 'ping -c 4 198.51.101.7');
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3, 'host' => '198.51.101.7; cat /etc/passwd'), make_env($agent));
check('ping: injection attempt refused, no command run', !$r['ok'] && $r['code'] === 'bad_input' && count($agent->commands) === 0);
foreach (array('127.0.0.1', '169.254.169.254', '10.0.0.1') as $ip) {
	$agent = new FakeAgent();
	$env = make_env($agent);
	$r = diag_execute('ping', array('agent' => 3, 'host' => $ip), $env);
	check("ping: $ip refused for a user", !$r['ok'] && $env['store']->rows[0]['status'] === 'denied' && count($agent->commands) === 0);
}
$agent = new FakeAgent();
$r = diag_execute('ping', array('agent' => 3, 'host' => ''), make_env($agent, array('client_ip' => '10.1.2.3')));
check('ping: my IP behind a private address is refused, not sent', !$r['ok'] && $r['code'] === 'private_denied' && count($agent->commands) === 0);
$agent = new FakeAgent();
$agent->os = 'CYGWIN_NT-10.0';
$r = diag_execute('ping', array('agent' => 3, 'host' => '8.8.8.8'), make_env($agent));
check('ping: Windows agent uses the Windows ping form', $r['ok'] && $agent->commands[1] === 'ping 8.8.8.8');
$agent = new FakeAgent();
$agent->outputs['which ping'] = '';
check('ping: machine without ping gives a clear failure', diag_execute('ping', array('agent' => 3, 'host' => '8.8.8.8'), make_env($agent))['code'] === 'tool_missing');
$agent = new FakeAgent();
$agent->outputs['ping -c 4 8.8.8.8'] = null;
check('ping: failed exec gives a clear failure', diag_execute('ping', array('agent' => 3, 'host' => '8.8.8.8'), make_env($agent))['code'] === 'exec_failed');

// --- permissions
$agent = new FakeAgent();
$env = make_env($agent);
$r = diag_execute('ping', array('agent' => 99, 'host' => '8.8.8.8'), $env);
check('permission: agent not assigned to user refused', !$r['ok'] && $r['code'] === 'forbidden' && $env['store']->rows[0]['status'] === 'denied');
foreach (array('tcp_test', 'traceroute', 'speed_test', 'server_query', 'unknown_tool') as $gone) {
	$r = diag_execute($gone, array('agent' => 3, 'host' => '198.51.101.7', 'port' => '80'), make_env($agent));
	check("permission: $gone is not a tool any more", !$r['ok'] && $r['code'] === 'forbidden');
}
$r = diag_execute('port_check', pc(), make_env($agent, array('tool_access' => array('ping' => array('user')))));
check('permission: tool access list can switch a tool off', !$r['ok'] && $r['code'] === 'forbidden');
check('permission: nothing reached the agent', count($agent->commands) === 0);
check('access: ping and port check for every role', diag_default_tool_access() === array('ping' => array('admin', 'user', 'subuser'), 'port_check' => array('admin', 'user', 'subuser')));

// --- offline agent
$agent = new FakeAgent();
$agent->state = 'offline';
foreach (array('ping' => array('agent' => 3, 'host' => '8.8.8.8'), 'port_check' => pc()) as $tool => $input) {
	$r = diag_execute($tool, $input, make_env($agent));
	check("offline agent: $tool says offline", !$r['ok'] && $r['code'] === 'agent_offline');
}
check('offline agent: no command sent', count($agent->commands) === 0);

// --- port check
$agent = new FakeAgent();
$agent->outputs['ss -H -lntup'] = $SS_GAME;
$env = make_env($agent);
$r = diag_execute('port_check', pc(), $env);
check('port check: ran the one fixed command', $r['ok'] && $agent->commands === array('ss -H -lntup'));
check('port check: partial when only the game port listens', $r['outcome'] === 'partial' && strpos($r['summary'], '1 of 2') === 0);
check('port check: rows per port', $r['rows'][0][0] === 'Game port 2302' && strpos($r['rows'][0][1], 'UDP listening; TCP not listening') === 0 && $r['rows'][1][0] === 'Query port 2303');
check('port check: a user sees no PID or program', strpos(json_encode($r), 'arma2oaserver') === false && strpos(json_encode($r), 'PID') === false);
check('port check: says it is not a firewall test', stripos($r['note'], 'does not test the firewall') !== false);
check('port check: report names agent, server address and port', $r['source']['agent'] === 3 && $r['source']['target'] === '198.51.101.7' && $r['source']['port'] === 2302);
check('port check: audit row finished ok', $env['store']->rows[0]['tool'] === 'port_check' && $env['store']->rows[0]['status'] === 'ok' && $env['store']->rows[0]['outcome'] === 'partial');

$agent = new FakeAgent();
$agent->outputs['ss -H -lntup'] = $SS_GAME . "udp   UNCONN 0      0                  *:2303            *:*\n";
check('port check: ok when every port listens', diag_execute('port_check', pc(), make_env($agent))['outcome'] === 'ok');
$agent = new FakeAgent();
$agent->outputs['ss -H -lntup'] = "tcp   LISTEN 0      128          0.0.0.0:22         0.0.0.0:*\n";
$r = diag_execute('port_check', pc(), make_env($agent));
check('port check: nothing listening is reported, not failed', $r['ok'] && $r['outcome'] === 'not_listening');
$agent = new FakeAgent();
check('port check: empty answer is a failure, not "nothing listens"', diag_execute('port_check', pc(), make_env($agent))['code'] === 'exec_failed');

$agent = new FakeAgent();
$agent->outputs['ss -H -lntup'] = $SS_GAME;
$r = diag_execute('port_check', pc(), make_env($agent, array('role' => 'admin')));
check('port check: admin sees PID and program', strpos(json_encode($r['rows']), 'PID 4242') !== false && strpos(json_encode($r['rows']), 'arma2oaserver') !== false);

$agent = new FakeAgent();
$agent->os = 'CYGWIN_NT-10.0';
$agent->outputs['netstat -ano'] = $NETSTAT;
$agent->outputs['tasklist /FO CSV /NH'] = "\"arma2oaserver.exe\",\"2200\",\"Console\",\"1\",\"212,332 K\"\r\n";
$r = diag_execute('port_check', pc(), make_env($agent));
check('port check: Windows agent uses netstat, user does not run tasklist', $r['ok'] && $r['outcome'] === 'ok' && $agent->commands === array('netstat -ano'));
$agent->commands = array();
$r = diag_execute('port_check', pc(), make_env($agent, array('role' => 'admin')));
check('port check: admin on Windows also gets program names', $agent->commands === array('netstat -ano', 'tasklist /FO CSV /NH') && strpos(json_encode($r['rows']), 'arma2oaserver.exe') !== false);

// Which server and which ports
$agent = new FakeAgent();
$agent->outputs['ss -H -lntup'] = $SS_GAME;
$env = make_env($agent);
foreach (array(
	'unknown server address' => pc(array('server_ip' => '198.51.101.8')),
	'unknown server port' => pc(array('server_port' => '2400')),
	'bad server port' => pc(array('server_port' => 'abc')),
	'server of another agent' => pc(array('agent' => 4)),
	'no server chosen' => array('agent' => 3),
) as $what => $input) {
	$r = diag_execute('port_check', $input, make_env($agent, array('agent_ids' => array(3, 4))));
	check("port check: $what refused", !$r['ok'] && $r['code'] === 'bad_input');
}
check('port check: no command for refused servers', count($agent->commands) === 0);
$r = diag_execute('port_check', pc(array('extra_ports' => '22')), make_env($agent));
check('port check: a user cannot add ports', $r['ok'] && count($r['rows']) === 2);
$r = diag_execute('port_check', pc(array('extra_ports' => '22, 2302 27015')), make_env($agent, array('role' => 'admin')));
check('port check: admin extra ports are added once, duplicates dropped', $r['ok'] && count($r['rows']) === 4 && $r['rows'][2][0] === 'Extra port 22' && strpos($r['rows'][2][1], 'TCP listening') !== false);
foreach (array('0', '70000', 'abc', '1,2,3,4,5,6,7,8,9,10,11') as $bad) {
	$r = diag_execute('port_check', pc(array('extra_ports' => $bad)), make_env($agent, array('role' => 'admin')));
	check('port check: bad extra ports ' . json_encode($bad) . ' refused', !$r['ok'] && $r['code'] === 'bad_input');
}
check('extra ports: empty means none', diag_parse_extra_ports('') === array() && diag_parse_extra_ports(' , ') === array());

// --- validation errors are not audited
$env = make_env(new FakeAgent());
diag_execute('ping', array('agent' => 3, 'host' => 'a b'), $env);
diag_execute('port_check', array('agent' => 3), $env);
check('validation: no audit row for form typos', count($env['store']->rows) === 0);

// --- rate limits and concurrency (ping is the example tool)
$ping = array('agent' => 3, 'host' => '8.8.8.8');
$agent = new FakeAgent();
$store = new DiagAuditMemory();
$limits = diag_limit_settings(array('diag_rate_per_user' => '3'));
$last = null;
for ($i = 0; $i < 5; $i++) {
	$last = diag_execute('ping', $ping, make_env($agent, array('store' => $store, 'limits' => $limits)));
}
check('rate limit: 4th request in the window refused', !$last['ok'] && $last['code'] === 'rate_user');
check('rate limit: agent ran ping only 3 times', count(array_filter($agent->commands, function ($c) { return strpos($c, 'ping') === 0; })) === 3);
check('rate limit: allowed again after the window', diag_execute('ping', $ping, make_env($agent, array('store' => $store, 'limits' => $limits, 'now' => 1000000 + 301)))['ok']);
check('rate limit: another user is not affected', diag_execute('ping', $ping, make_env($agent, array('store' => $store, 'limits' => $limits, 'user_id' => 8)))['ok']);
check('rate limit: port check counts too', diag_execute('port_check', pc(), make_env($agent, array('store' => $store, 'limits' => $limits)))['code'] === 'rate_user');

$store = new DiagAuditMemory();
$store->insert(array('created_at' => 1000000 - 5, 'user_id' => 7, 'remote_server_id' => 3, 'status' => 'running'));
$r = diag_execute('ping', $ping, make_env($agent, array('store' => $store)));
check('concurrency: second job for the same user refused', !$r['ok'] && $r['code'] === 'busy_user');
$store = new DiagAuditMemory();
for ($i = 0; $i < 3; $i++) {
	$store->insert(array('created_at' => 1000000 - 5, 'user_id' => 100 + $i, 'remote_server_id' => 3, 'status' => 'running'));
}
check('concurrency: agent cap refuses a 4th running job', diag_execute('ping', $ping, make_env($agent, array('store' => $store)))['code'] === 'busy_agent');
$store = new DiagAuditMemory();
$store->insert(array('created_at' => 1000000 - 500, 'user_id' => 7, 'remote_server_id' => 3, 'status' => 'running'));
check('concurrency: stale running row (crashed request) expires', diag_execute('ping', $ping, make_env($agent, array('store' => $store)))['ok']);
$store = new DiagAuditMemory();
$limits = diag_limit_settings(array('diag_rate_per_user' => '2'));
for ($i = 0; $i < 4; $i++) {
	diag_execute('ping', array('agent' => 3, 'host' => '127.0.0.1'), make_env($agent, array('store' => $store, 'limits' => $limits)));
}
check('rate limit: denied probing attempts count too', $store->rows[3]['status'] === 'rate_limited');

echo "All runner tests passed\n";
