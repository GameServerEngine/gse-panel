<?php
// Contract tests: capability probe through RemoteLibrary (fake transport), reply parsing, parameter ranges.
if (!function_exists('xmlrpc_encode_request')) {
	function xmlrpc_encode_request($method, $params) { return $method; }
}
if (!function_exists('xmlrpc_decode')) {
	function xmlrpc_decode($xml) { return $xml; }
}
if (!function_exists('xmlrpc_is_fault')) {
	function xmlrpc_is_fault($response) { return is_array($response) && isset($response['faultCode']); }
}
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/lib_remote.php';

// Answers by method name, like an agent would. A reply of 'fault' is an XML-RPC fault.
class ContractFakeRemote extends RemoteLibrary
{
	public $replies;
	public $requested = array();

	public function __construct($replies)
	{
		parent::__construct('127.0.0.1', 12679, 'test-key', 1);
		$this->replies = $replies;
	}

	protected function postRequest($request)
	{
		// With the real xmlrpc extension the request is XML; with the stand-in it is the method name.
		$method = preg_match('/<methodName>([^<]+)</', $request, $m) ? $m[1] : $request;
		$this->requested[] = $method;
		$reply = isset($this->replies[$method]) ? $this->replies[$method] : null;
		return $reply === 'fault' ? array('faultCode' => 1, 'faultString' => 'Method diag_capabilities not found') : $reply;
	}
}

// Agent replies are structs of base64 strings (component_update_response in agent_core.pl).
function agent_struct($fields)
{
	return array_map(function ($v) { return base64_encode((string)$v); }, $fields);
}

$caps = agent_struct(array('success' => '1', 'status' => 'ok', 'protocol_version' => '1', 'agent_version' => '0.6.0', 'os' => 'linux',
	'tools' => '["tcp_connect","udp_exchange","evil_tool"]', 'limits' => '{"max_runtime_s":60}'));

// --- probe
$remote = new ContractFakeRemote(array('quick_chk' => 0, 'diag_capabilities' => $caps));
$probe = $remote->diag_probe();
check('probe: supported agent', $probe['state'] === 'ok' && $probe['caps']['protocol_version'] === 1 && $probe['caps']['agent_version'] === '0.6.0');
check('probe: only known tools kept', $probe['caps']['tools'] === array('tcp_connect', 'udp_exchange'));
check('probe: limits kept', $probe['caps']['limits']['max_runtime_s'] === 60);

$remote = new ContractFakeRemote(array('quick_chk' => 0, 'diag_capabilities' => 'fault'));
check('probe: fault from an online agent is unsupported', $remote->diag_probe()['state'] === 'unsupported');
$remote = new ContractFakeRemote(array('quick_chk' => null));
check('probe: no answer to quick_chk is offline', $remote->diag_probe()['state'] === 'offline' && !in_array('diag_capabilities', $remote->requested, true));
$remote = new ContractFakeRemote(array('quick_chk' => 1));
check('probe: key mismatch is not usable', $remote->diag_probe()['state'] === 'offline');
$remote = new ContractFakeRemote(array('quick_chk' => 0, 'diag_capabilities' => agent_struct(array('success' => '0', 'status' => 'bad_encryption'))));
check('probe: bad encryption reply is unsupported', $remote->diag_probe()['state'] === 'unsupported');
$remote = new ContractFakeRemote(array('quick_chk' => 0, 'diag_capabilities' => agent_struct(array('success' => '1', 'status' => 'ok', 'tools' => 'not json'))));
check('probe: broken tools list is unsupported', $remote->diag_probe()['state'] === 'unsupported');
$remote = new ContractFakeRemote(array('quick_chk' => 0, 'diag_capabilities' => 'plain string'));
check('probe: non-struct reply is unsupported', $remote->diag_probe()['state'] === 'unsupported');

// --- run
$ok = agent_struct(array('success' => '1', 'status' => 'ok', 'message' => '', 'tool' => 'tcp_connect', 'protocol_version' => '1', 'duration_ms' => '14',
	'result' => '{"outcome":"connected","connect_ms":3.2}', 'truncated' => '0'));
$remote = new ContractFakeRemote(array('diag_run' => $ok));
$r = $remote->diag_run('tcp_connect', array('host_ip' => '203.0.113.5', 'port' => 80));
check('run: success parsed', $r['success'] === true && $r['result']['outcome'] === 'connected' && $r['duration_ms'] === 14);
$remote = new ContractFakeRemote(array('diag_run' => agent_struct(array('success' => '0', 'status' => 'busy', 'message' => 'Too many jobs', 'result' => ''))));
$r = $remote->diag_run('tcp_connect', array());
check('run: agent error parsed', $r['success'] === false && $r['status'] === 'busy' && $r['message'] === 'Too many jobs');
$remote = new ContractFakeRemote(array('diag_run' => null));
$r = $remote->diag_run('tcp_connect', array());
check('run: no reply is agent_unreachable', $r['success'] === false && $r['status'] === 'agent_unreachable');
$remote = new ContractFakeRemote(array('diag_run' => 'fault'));
$r = $remote->diag_run('tcp_connect', array());
check('run: fault is a failure, not an exception', $r['success'] === false && $r['status'] === 'agent_unreachable');
$remote = new ContractFakeRemote(array('diag_run' => agent_struct(array('success' => '1', 'status' => 'ok', 'result' => '{broken'))));
check('run: broken result JSON gives empty result', $remote->diag_run('tcp_connect', array())['result'] === array());

// --- parameters really are encrypted JSON (what the agent will decrypt)

// --- what the agent receives: tool and JSON params decrypt with the shared key, the enc check is last
class CaptureRemote extends RemoteLibrary
{
	public $request = '';
	public function __construct() { parent::__construct('127.0.0.1', 12679, 'test-key', 1); }
	protected function postRequest($request) { $this->request = $request; return null; }
}
$capture = new CaptureRemote();
$capture->diag_run('udp_exchange', array('host_ip' => '203.0.113.5', 'port' => 27015));
if (preg_match_all('#<string>([^<]*)</string>#', $capture->request, $found)) {
	$xxtea = new Crypt_XXTEA();
	$xxtea->setKey('test-key');
	$plain = array_map(function ($v) use ($xxtea) { return base64_decode($xxtea->decrypt(base64_decode($v))); }, $found[1]);
	check('wire: three params, tool first', count($plain) === 3 && $plain[0] === 'udp_exchange');
	check('wire: params are JSON', json_decode($plain[1], true) === array('host_ip' => '203.0.113.5', 'port' => 27015));
	check('wire: enc check last', $plain[2] === 'Encryption checking OK');
} else {
	echo "SKIP: wire format check needs the real xmlrpc extension\n";
}

// --- parameter ranges
check('params in range accepted', diag_check_params(array('port' => 80, 'timeout_ms' => 3000, 'max_hops' => 30, 'idle_ms' => 300)) === '');
foreach (array(array('port' => 0), array('port' => 65536), array('timeout_ms' => 50), array('timeout_ms' => 10001), array('max_hops' => 31),
	array('max_datagrams' => 17), array('max_bytes' => 40000), array('probes_per_hop' => 4), array('timeout_ms' => '3000'), array('port' => 80.5)) as $bad) {
	check('params rejected: ' . json_encode($bad), diag_check_params($bad) !== '');
}
check('support: tool missing message names the version', diag_support_state(array('state' => 'ok', 'caps' => array('protocol_version' => 1, 'tools' => array('tcp_connect'), 'os' => 'windows')), 'traceroute')['message'] === 'This agent needs to be updated to version 1.6.0 to run this test.');
check('support: ok when tool listed', diag_support_state(array('state' => 'ok', 'caps' => array('protocol_version' => 1, 'tools' => array('traceroute'), 'os' => 'linux')), 'traceroute')['ok']);
check('error text for every contract code', diag_error_text('busy') !== diag_error_text('weird') && strpos(diag_error_text('weird'), 'weird') !== false);
echo "All contract tests passed\n";
