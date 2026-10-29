<?php
// Regression test: the agent's answer decides server status.
// Run: php tests/status_sync_test.php   (exit 0 = all passed)
if (!defined('GSP_SCREEN_TYPE_HOME')) { define('GSP_SCREEN_TYPE_HOME', 'HOME'); }
require_once(__DIR__ . '/../modules/gamemanager/home_handling_functions.php');

class StubRemote
{
	public $agent;      // array returned by remote_server_status, or null = RPC unavailable
	public $screen;     // bool: a screen session exists for this home
	public function remote_server_status($home_id, $ip, $port, $qp, $rp, $t, $hint) { return $this->agent; }
	public function is_screen_running($type, $home_id) { return $this->screen ? 1 : 0; }  // the agent answers 1 or 0
	public function exec($cmd) { return ''; }   // no ports listening
}

$server_xml = new SimpleXMLElement('<server/>');
$failures = 0; $passed = 0;

function check($name, $got, $want, &$failures, &$passed) {
	if ($got === $want) { $passed++; echo "PASS $name\n"; }
	else { $failures++; echo "FAIL $name: wanted $want, got " . var_export($got, true) . "\n"; }
}

// 1. Agent says OFFLINE, leftover screen session exists -> must stay OFFLINE.
$r = new StubRemote(); $r->agent = ['status' => 'OFFLINE']; $r->screen = true;
$s = get_agent_server_status($r, $server_xml, 49, '173.208.136.13', '2302');
check('stopped agent beats leftover screen', $s['status'], 'OFFLINE', $failures, $passed);
check('stopped agent clears session flag', (int)$s['session_running'], 0, $failures, $passed);

// 2. Agent says OFFLINE with no session -> OFFLINE.
$r = new StubRemote(); $r->agent = ['status' => 'OFFLINE']; $r->screen = false;
$s = get_agent_server_status($r, $server_xml, 49, '173.208.136.13', '2302');
check('stopped agent, no session', $s['status'], 'OFFLINE', $failures, $passed);

// 3. Agent says ONLINE -> ONLINE.
$r = new StubRemote(); $r->agent = ['status' => 'ONLINE']; $r->screen = false;
$s = get_agent_server_status($r, $server_xml, 49, '173.208.136.13', '2302');
check('agent online is reported', $s['status'], 'ONLINE', $failures, $passed);

// 4. Agent RPC unavailable + screen session exists -> fallback may say ONLINE (agent unknown).
$r = new StubRemote(); $r->agent = null; $r->screen = true;
$s = get_agent_server_status($r, $server_xml, 49, '173.208.136.13', '2302');
check('unknown agent never claims ONLINE from screen', $s['status'] !== 'ONLINE', true, $failures, $passed);

// 5. Agent RPC unavailable, nothing running -> OFFLINE.
$r = new StubRemote(); $r->agent = null; $r->screen = false;
$s = get_agent_server_status($r, $server_xml, 49, '173.208.136.13', '2302');
// With no agent answer and no evidence, the panel must not claim the server is running.
check('unknown agent never shows ONLINE', $s['status'] !== 'ONLINE', true, $failures, $passed);

echo "\n$passed passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
