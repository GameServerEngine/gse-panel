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
foreach (array('ping', 'port_check', 'tcp_test', 'traceroute', 'speed_test', 'server_query', 'sourcemod', 'amx', 'steamid') as $tool) {
	check("admin sees tool $tool", strpos($admin, 'data-tool="' . $tool . '"') !== false);
}
foreach (array('traceroute', 'speed_test') as $tool) {
	check("user does not get $tool", strpos($user, 'id="tool-' . $tool . '"') === false && strpos($sub, 'id="tool-' . $tool . '"') === false);
}
foreach (array('ping', 'port_check', 'tcp_test', 'server_query', 'sourcemod', 'amx', 'steamid') as $tool) {
	check("user keeps $tool", strpos($user, 'id="tool-' . $tool . '"') !== false);
}
check('three categories shown to admin', substr_count($admin, 'class="util-category"') === 3);
check('category names', strpos($admin, 'Network Tools') !== false && strpos($admin, 'Game Tools') !== false && strpos($admin, 'Steam &amp; Admins') !== false);
check('only the selected tool is visible by default', substr_count($admin, ' hidden>') === 8);
$q = page(array('page' => 'util', 'role' => 'user', 'get' => array('tool' => 'steamid')))['out'];
check('?tool=steamid selects that tool', strpos($q, 'id="tool-steamid" data-tool="steamid">') !== false && strpos($q, 'aria-current="page" class="active">SteamID Converter') !== false);
$q = page(array('page' => 'util', 'role' => 'user', 'get' => array('tool' => 'traceroute')))['out'];
check('?tool=traceroute falls back for a user', strpos($q, 'id="tool-ping" data-tool="ping">') !== false);
check('admin link to policy page only for admins', strpos($admin, 'p=diag_settings') !== false && strpos($user, 'p=diag_settings') === false);
check('legacy form ids kept (sourcemod, amx, steam)', strpos($user, 'id="addadmin_form"') !== false && strpos($user, 'id="amx_addadmin_form"') !== false && strpos($user, 'id="steam_converter"') !== false);
check('labels linked to inputs', strpos($admin, 'for="ping-host"') !== false && strpos($admin, 'id="ping-host"') !== false);
check('no agent secrets in the page', strpos($admin, 'SECRETKEY') === false);
check('page does not leak endpoint URLs', strpos($admin, 'secret-speed') === false);

// --- agents list: only authorized agents, no keys
$agents = json_decode(page(array('page' => 'agents', 'role' => 'user'))['out'], true);
check('user sees only the agent hosting their server', count($agents) === 1 && $agents[0]['id'] == 3);
$agents = json_decode(page(array('page' => 'agents', 'role' => 'admin'))['out'], true);
check('admin sees all agents', count($agents) === 2);
check('agents list is offline-aware and has no keys', $agents[0]['status'] === 0 && strpos(json_encode($agents), 'SECRETKEY') === false);

// --- config endpoint
$cfg = json_decode(page(array('page' => 'diag', 'role' => 'user', 'token' => true))['out'], true);
check('config: token, tools for a user', $cfg['csrf'] === 'tok123' && $cfg['tools'] === array('ping', 'port_check', 'tcp_test', 'server_query'));
check('config: own server listed with agent', $cfg['servers'][0]['ip'] === '198.51.101.7' && $cfg['servers'][0]['agent'] === 3);
check('config: speed endpoint labels but never URLs', $cfg['speed_endpoints'][0]['id'] === 'cdn' && strpos(json_encode($cfg), 'secret-speed') === false && strpos(json_encode($cfg), 'SECRETKEY') === false);
check('config: presets carry games', count($cfg['presets']) >= 9);

// --- run endpoint: CSRF, method, auth
$run = array('tool' => 'tcp_test', 'agent' => '3', 'host' => '198.51.101.7', 'port' => '27015');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'get' => array('action' => 'run'), 'post' => $run));
check('run: refused without CSRF token', json_decode($r['out'], true)['code'] === 'csrf' && $r['audit'] === array());
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'wrong', 'get' => array('action' => 'run'), 'post' => $run));
check('run: refused with a wrong token', json_decode($r['out'], true)['code'] === 'csrf');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'GET', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => $run));
check('run: GET refused even with a token', json_decode($r['out'], true)['code'] === 'csrf');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => $run));
$res = json_decode($r['out'], true);
check('run: with token and an unreachable agent, a clear offline message (no hang)', $res['ok'] === false && $res['code'] === 'agent_offline');
check('run: audit row written and finished as failed', $r['audit'][0]['tool'] === 'tcp_test' && $r['audit'][0]['user_id'] === 5 && $r['audit'][0]['status'] === 'failed' && $r['audit'][0]['target'] === '198.51.101.7');
check('run: also written to the panel log', count($r['logs']) === 1 && strpos($r['logs'][0], 'tcp_test') !== false);
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'post' => $run + array('csrf' => 'tok123'), 'get' => array('action' => 'run')));
check('run: token as a form field works too', json_decode($r['out'], true)['code'] === 'agent_offline');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => array('tool' => 'tcp_test', 'agent' => '4', 'host' => '198.51.101.7', 'port' => '80')));
check('run: agent 4 not allowed for this user', json_decode($r['out'], true)['code'] === 'forbidden' && $r['audit'][0]['status'] === 'denied');
$r = page(array('page' => 'diag', 'role' => 'user', 'method' => 'POST', 'token' => true, 'header_token' => 'tok123', 'get' => array('action' => 'run'), 'post' => array('tool' => 'tcp_test', 'agent' => '3', 'host' => '127.0.0.1', 'port' => '80')));
check('run: loopback target denied by policy', json_decode($r['out'], true)['code'] === 'blocked' && $r['audit'][0]['status'] === 'denied');

// --- legacy network_tools endpoint
$r = page(array('page' => 'network_tools', 'role' => 'user', 'method' => 'POST', 'post' => array('command' => 'ping', 'agent' => '3', 'remote_target' => '198.51.101.7')));
check('legacy endpoint: needs the CSRF token', strpos($r['out'], 'expired') !== false);
$r = page(array('page' => 'network_tools', 'role' => 'user', 'method' => 'POST', 'token' => true, 'post' => array('csrf' => 'tok123', 'command' => 'traceroute', 'agent' => '3', 'remote_target' => '198.51.101.7')));
check('legacy endpoint: traceroute still admin only', strpos($r['out'], 'may not use this tool') !== false);
$r = page(array('page' => 'network_tools', 'role' => 'user', 'method' => 'POST', 'token' => true, 'post' => array('csrf' => 'tok123', 'command' => 'ping', 'agent' => '3', 'remote_target' => '8.8.8.8; id')));
check('legacy endpoint: injection text refused', strpos($r['out'], 'Not a valid host') !== false);

// --- admin settings page
$r = page(array('page' => 'diag_settings', 'role' => 'admin', 'token' => true, 'method' => 'POST', 'post' => array('csrf' => 'tok123',
	'diag_test_targets' => "tests.example.org\nbad host\n8.8.4.4", 'diag_admin_allow_private' => '1', 'diag_rate_per_user' => 'abc', 'diag_speed_endpoints' => '[]')));
check('settings: saves valid targets only', $r['saved']['diag_test_targets'] === "tests.example.org\n8.8.4.4" && strpos($r['out'], 'bad') !== false);
check('settings: bad limit falls back to default', $r['saved']['diag_rate_per_user'] === '12' && $r['saved']['diag_admin_allow_private'] === '1');
$r = page(array('page' => 'diag_settings', 'role' => 'admin', 'token' => true, 'method' => 'POST', 'post' => array('diag_test_targets' => 'x.example.org')));
check('settings: refused without CSRF token', $r['saved'] === array() && strpos($r['out'], 'expired') !== false);
echo "All page tests passed\n";
