<?php
// Page and endpoint tests with a fake database (page_harness.php). Run: php modules/util/tests/util_pages_test.php
require_once dirname(__DIR__, 3) . '/includes/tests/diag_test_helper.php';

function page($spec)
{
	$cmd = 'php ' . escapeshellarg(__DIR__ . '/page_harness.php') . ' ' . escapeshellarg(json_encode($spec)) . ' 2>/dev/null';
	$raw = shell_exec($cmd);
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		fwrite(STDERR, "Harness output was not JSON:\n$raw\n");
		exit(1);
	}
	return $data;
}

// --- the page for each role
$admin = page(array('page' => 'util', 'role' => 'admin'))['out'];
$user = page(array('page' => 'util', 'role' => 'user'))['out'];
$sub = page(array('page' => 'util', 'role' => 'subuser'))['out'];
foreach (array('ping', 'port_check', 'sourcemod', 'amx', 'steamid') as $tool) {
	check("admin sees tool $tool", strpos($admin, 'data-tool="' . $tool . '"') !== false);
	check("user keeps $tool", strpos($user, 'id="tool-' . $tool . '"') !== false && strpos($sub, 'id="tool-' . $tool . '"') !== false);
}
foreach (array('tcp_test', 'traceroute', 'speed_test', 'server_query') as $gone) {
	check("tool $gone is gone from the page", strpos($admin, 'tool-' . $gone) === false && strpos($admin, 'value="' . $gone . '"') === false);
}
check('two categories shown to admin', substr_count($admin, 'class="util-category"') === 2);
check('category names', strpos($admin, 'Network Tools') !== false && strpos($admin, 'Game Tools') === false && strpos($admin, 'Steam &amp; Admins') !== false);
check('only the selected tool is visible by default', substr_count($admin, ' hidden>') === 4);
$q = page(array('page' => 'util', 'role' => 'user', 'get' => array('tool' => 'steamid')))['out'];
check('?tool=steamid selects that tool', strpos($q, 'id="tool-steamid" data-tool="steamid">') !== false && strpos($q, 'aria-current="page" class="active">SteamID Converter') !== false);
$q = page(array('page' => 'util', 'role' => 'user', 'get' => array('tool' => 'traceroute')))['out'];
check('?tool=traceroute falls back to ping', strpos($q, 'id="tool-ping" data-tool="ping">') !== false);
check('admin link to policy page only for admins', strpos($admin, 'p=diag_settings') !== false && strpos($user, 'p=diag_settings') === false);
check('legacy form ids kept (sourcemod, amx, steam)', strpos($user, 'id="addadmin_form"') !== false && strpos($user, 'id="amx_addadmin_form"') !== false && strpos($user, 'id="steam_converter"') !== false);
check('labels linked to inputs', strpos($admin, 'for="ping-host"') !== false && strpos($admin, 'id="ping-host"') !== false);
check('ping target is filled with my IP and may be changed', strpos($user, 'id="ping-host" name="host"') !== false && strpos($user, 'value="192.0.2.50"') !== false && strpos($user, 'id="ping-host" name="host" required') === false);
check('port check asks for one of my servers, extra ports only for admins', strpos($user, 'class="util-server-ip"') !== false && strpos($user, 'name="extra_ports"') === false && strpos($admin, 'name="extra_ports"') !== false);
check('no agent secrets in the page', strpos($admin, 'SECRETKEY') === false);

// --- agents list: only authorized agents, no keys
$agents = json_decode(page(array('page' => 'agents', 'role' => 'user'))['out'], true);
check('user sees only the agent hosting their server', count($agents) === 1 && $agents[0]['id'] == 3);
$agents = json_decode(page(array('page' => 'agents', 'role' => 'admin'))['out'], true);
check('admin sees all agents', count($agents) === 2);
check('agents list is offline-aware and has no keys', $agents[0]['status'] === 0 && strpos(json_encode($agents), 'SECRETKEY') === false);

// --- config endpoint
$cfg = json_decode(page(array('page' => 'diag', 'role' => 'user', 'token' => true))['out'], true);
check('config: token, tools for a user', $cfg['csrf'] === 'tok123' && $cfg['tools'] === array('ping', 'port_check') && $cfg['is_admin'] === false);
check('config: own server listed with agent', $cfg['servers'][0]['ip'] === '198.51.101.7' && $cfg['servers'][0]['agent'] === 3 && $cfg['servers'][0]['port'] === 27015);
check('config: my IP for the ping form', $cfg['my_ip'] === '192.0.2.50');
check('config: nothing about the removed tools, no agent keys', !isset($cfg['presets']) && !isset($cfg['speed_endpoints']) && !isset($cfg['adapters']) && strpos(json_encode($cfg), 'SECRETKEY') === false);
check('config: admin is flagged', json_decode(page(array('page' => 'diag', 'role' => 'admin', 'token' => true))['out'], true)['is_admin'] === true);

// --- run endpoint: CSRF, method, auth
$run = array('tool' => 'ping', 'agent' => '3', 'host' => '198.51.101.7');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'get' => array('action' => 'run'), 'post' => $run));
check('run: refused without CSRF token', json_decode($r['out'], true)['code'] === 'csrf' && $r['audit'] === array());
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'wrong', 'get' => array('action' => 'run'), 'post' => $run));
check('run: refused with a wrong token', json_decode($r['out'], true)['code'] === 'csrf');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'GET', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => $run));
check('run: GET refused even with a token', json_decode($r['out'], true)['code'] === 'csrf');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => $run));
$res = json_decode($r['out'], true);
check('run: with token and an unreachable agent, a clear offline message (no hang)', $res['ok'] === false && $res['code'] === 'agent_offline');
check('run: audit row written and finished as failed', $r['audit'][0]['tool'] === 'ping' && $r['audit'][0]['user_id'] === 5 && $r['audit'][0]['status'] === 'failed' && $r['audit'][0]['target'] === '198.51.101.7');
check('run: also written to the panel log', count($r['logs']) === 1 && strpos($r['logs'][0], 'ping') !== false);
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'post' => $run + array('csrf' => 'tok123'), 'get' => array('action' => 'run')));
check('run: token as a form field works too', json_decode($r['out'], true)['code'] === 'agent_offline');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => array('tool' => 'ping', 'agent' => '4', 'host' => '198.51.101.7')));
check('run: agent 4 not allowed for this user', json_decode($r['out'], true)['code'] === 'forbidden' && $r['audit'][0]['status'] === 'denied');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => array('tool' => 'ping', 'agent' => '3', 'host' => '127.0.0.1')));
check('run: loopback target denied by policy', json_decode($r['out'], true)['code'] === 'blocked' && $r['audit'][0]['status'] === 'denied');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => array('tool' => 'ping', 'agent' => '3', 'host' => '')));
check('run: empty ping target pings my IP (here a documentation address, which is refused)', json_decode($r['out'], true)['code'] === 'blocked' && $r['audit'][0]['target'] === '192.0.2.50');
$check = array('tool' => 'port_check', 'agent' => '3', 'server_ip' => '198.51.101.7', 'server_port' => '27015');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => $check));
check('run: port check of my own server reaches the offline agent', json_decode($r['out'], true)['code'] === 'agent_offline' && $r['audit'][0]['tool'] === 'port_check' && $r['audit'][0]['port'] === 27015);
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => array('server_port' => '27016') + $check));
check('run: port check of a server that is not mine is refused before any agent call', json_decode($r['out'], true)['code'] === 'bad_input' && $r['audit'] === array());

// --- legacy network_tools endpoint
$r = page(array('page' => 'network_tools', 'role' => 'user', 'method' => 'POST', 'post' => array('command' => 'ping', 'agent' => '3', 'remote_target' => '198.51.101.7')));
check('legacy endpoint: needs the CSRF token', strpos($r['out'], 'expired') !== false);
$r = page(array('page' => 'network_tools', 'role' => 'admin', 'method' => 'POST', 'token' => true, 'post' => array('csrf' => 'tok123', 'command' => 'traceroute', 'agent' => '3', 'remote_target' => '198.51.101.7')));
check('legacy endpoint: traceroute is gone, even for admins', strpos($r['out'], 'No command selected') !== false);
$r = page(array('page' => 'network_tools', 'role' => 'user', 'method' => 'POST', 'token' => true, 'post' => array('csrf' => 'tok123', 'command' => 'ping', 'agent' => '3', 'remote_target' => '8.8.8.8; id')));
check('legacy endpoint: injection text refused', strpos($r['out'], 'Not a valid host') !== false);

// --- admin settings page
$r = page(array('page' => 'diag_settings', 'role' => 'admin', 'token' => true, 'method' => 'POST', 'post' => array('csrf' => 'tok123',
	'diag_admin_allow_private' => '1', 'diag_rate_per_user' => 'abc')));
check('settings: private override and limits saved, bad limit falls back to default', $r['saved']['diag_rate_per_user'] === '12' && $r['saved']['diag_admin_allow_private'] === '1');
check('settings: no test target or speed endpoint settings any more', !isset($r['saved']['diag_test_targets']) && !isset($r['saved']['diag_speed_endpoints']) && stripos($r['out'], 'speed test') === false && stripos($r['out'], 'Test targets') === false);
$r = page(array('page' => 'diag_settings', 'role' => 'admin', 'token' => true, 'method' => 'POST', 'post' => array('diag_test_targets' => 'x.example.org')));
check('settings: refused without CSRF token', $r['saved'] === array() && strpos($r['out'], 'expired') !== false);
echo "All page tests passed\n";
