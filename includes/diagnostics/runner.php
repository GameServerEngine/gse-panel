<?php
/*
 * Runs one diagnostic tool for one user. Order of every run:
 *   1. role may use the tool        2. user may use the agent     3. form values are valid
 *   4. audit row + rate/concurrency caps     5. target policy (resolve, pin the IP)
 *   6. agent supports the tool      7. agent runs it              8. result cleaned and audited
 * Everything it needs comes in through $env, so tests can fake the database, DNS and agents:
 *   user_id, role, client_ip, now, store, limits, policy (diag_check_target context),
 *   agent_ids (agents this user may use), agent (callable id, timeout_s => agent object),
 *   speed_endpoints, tool_access.
 * An agent object has diag_probe() and diag_run($tool, $params): see RemoteLibrary.
 */

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/target_policy.php';
require_once __DIR__ . '/limits.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/query_adapters.php';

// Which roles may use which tool. Traceroute and the speed test stay admin-only, as the old traceroute was.
function diag_default_tool_access()
{
	$everyone = array('admin', 'user', 'subuser');
	return array(
		'ping' => $everyone, 'port_check' => $everyone, 'tcp_test' => $everyone, 'server_query' => $everyone,
		'traceroute' => array('admin'), 'speed_test' => array('admin'),
	);
}

// Sends datagrams through the agent for the query adapters.
class DiagAgentTransport
{
	private $agent;
	private $allow_private;

	public function __construct($agent, $allow_private)
	{
		$this->agent = $agent;
		$this->allow_private = $allow_private;
	}

	public function exchange($ip, $port, $payload, $options)
	{
		$params = array(
			'host_ip' => $ip, 'port' => $port, 'payload_b64' => base64_encode($payload),
			'timeout_ms' => $options['timeout_ms'], 'idle_ms' => $options['idle_ms'],
			'max_datagrams' => $options['max_datagrams'], 'max_bytes' => $options['max_bytes'],
			'allow_private' => $this->allow_private,
		);
		$reply = $this->agent->diag_run('udp_exchange', $params);
		if (!$reply['success']) {
			return array('outcome' => 'agent_error', 'datagrams' => array(), 'first_ms' => null, 'detail' => $reply['message'] !== '' ? $reply['message'] : diag_error_text($reply['status']));
		}
		return diag_normalize_udp($reply['result']);
	}
}

function diag_fail($code, $message)
{
	return array('ok' => false, 'code' => $code, 'message' => $message);
}

// Validates the form values for $tool. Returns array(ok, message, host, port, adapter, ...).
function diag_parse_input($tool, $input, $env)
{
	$bad = function ($message) {
		return array('ok' => false, 'message' => $message);
	};
	if ($tool === 'speed_test') {
		return diag_parse_speed_input($input, $env);
	}
	$host = diag_parse_host(isset($input['host']) ? $input['host'] : '');
	if (!$host['ok']) {
		return $bad($host['message']);
	}
	$out = array('ok' => true, 'host' => $host['host'], 'port' => 0, 'protocol' => $tool, 'adapter' => '', 'players' => false);
	if ($tool === 'ping') {
		return $out;
	}
	if ($tool === 'traceroute') {
		$hops = diag_parse_int(isset($input['max_hops']) ? $input['max_hops'] : 20, 1, 30);
		return $hops === null ? $bad('Maximum hops must be 1 to 30.') : array_merge($out, array('max_hops' => $hops));
	}
	$out['port'] = diag_parse_port(isset($input['port']) ? $input['port'] : '');
	if ($out['port'] === null) {
		return $bad('Port must be a number from 1 to 65535.');
	}
	$out['timeout_ms'] = diag_parse_int(isset($input['timeout_ms']) ? $input['timeout_ms'] : 3000, 500, 10000);
	if ($out['timeout_ms'] === null) {
		return $bad('Timeout must be 500 to 10000 milliseconds.');
	}
	return diag_parse_protocol($tool, $input, $out, $bad);
}

function diag_parse_protocol($tool, $input, $out, $bad)
{
	$adapters = diag_query_adapters();
	$wanted = isset($input['adapter']) ? (string)$input['adapter'] : 'a2s';
	$udp = ($tool === 'server_query') || ($tool === 'port_check' && (isset($input['protocol']) ? $input['protocol'] : 'tcp') === 'udp');
	if ($tool === 'port_check' && !in_array(isset($input['protocol']) ? $input['protocol'] : 'tcp', array('tcp', 'udp'), true)) {
		return $bad('Protocol must be TCP or UDP.');
	}
	if ($udp && !isset($adapters[$wanted])) {
		return $bad('Choose a supported game query protocol.');
	}
	$out['protocol'] = $udp ? 'udp' : 'tcp';
	$out['adapter'] = $udp ? $wanted : '';
	$out['players'] = ($tool === 'server_query' && !empty($input['players']));
	return $out;
}

// Speed test: the user only picks a configured endpoint and one of its sizes.
function diag_parse_speed_input($input, $env)
{
	$endpoint_id = isset($input['endpoint']) ? (string)$input['endpoint'] : '';
	$size_id = isset($input['size']) ? (string)$input['size'] : '';
	foreach ($env['speed_endpoints'] as $endpoint) {
		foreach ($endpoint['sizes'] as $size) {
			if ($endpoint['id'] === $endpoint_id && $size['id'] === $size_id) {
				return array('ok' => true, 'host' => $endpoint['host'], 'port' => $endpoint['port'], 'protocol' => 'http',
					'adapter' => '', 'players' => false, 'endpoint' => $endpoint, 'size' => $size);
			}
		}
	}
	return array('ok' => false, 'message' => 'Choose one of the configured test endpoints and sizes.');
}

// Reads the configured speed endpoints (JSON setting). Bad entries are dropped, never fetched.
// Shape: [{"id":"x","label":"X","sizes":[{"id":"10","label":"10 MB","bytes":10485760,"url":"https://..."}]}]
function diag_load_speed_endpoints($json)
{
	$list = json_decode((string)$json, true);
	$good = array();
	foreach ((is_array($list) ? $list : array()) as $item) {
		$endpoint = diag_clean_endpoint($item);
		if ($endpoint !== null) {
			$good[] = $endpoint;
		}
	}
	return $good;
}

function diag_clean_endpoint($item)
{
	if (!is_array($item) || !preg_match('/^[a-z0-9_-]{1,32}$/', (string)($item['id'] ?? ''))) {
		return null;
	}
	$sizes = array();
	$host = '';
	foreach ((array)($item['sizes'] ?? array()) as $size) {
		$parts = is_array($size) && is_string($size['url'] ?? null) && strlen($size['url']) <= 512 ? parse_url($size['url']) : false;
		$bytes = is_array($size) ? ($size['bytes'] ?? 0) : 0;
		$ok = is_array($parts) && in_array($parts['scheme'] ?? '', array('http', 'https'), true) && !isset($parts['user']) && !isset($parts['pass'])
			&& diag_parse_host($parts['host'] ?? '')['ok'] && is_int($bytes) && $bytes >= 1048576 && $bytes <= 268435456
			&& preg_match('/^[a-z0-9_-]{1,16}$/', (string)($size['id'] ?? ''));
		if ($ok && ($host === '' || $host === strtolower($parts['host']))) {
			$host = strtolower($parts['host']);
			$sizes[] = array('id' => $size['id'], 'label' => diag_text($size['label'] ?? $size['id'], 40), 'bytes' => $bytes, 'url' => $size['url'],
				'port' => $parts['port'] ?? (($parts['scheme'] === 'https') ? 443 : 80));
		}
	}
	if (count($sizes) === 0) {
		return null;
	}
	return array('id' => $item['id'], 'label' => diag_text($item['label'] ?? $item['id'], 60), 'host' => $host, 'port' => $sizes[0]['port'], 'sizes' => $sizes);
}

function diag_ms($start_ns)
{
	return (int)round((hrtime(true) - $start_ns) / 1000000);
}

// Runs a tool. Returns array(ok, code, message) on refusal, or array(ok => true, view => ..., source => ...).
function diag_execute($tool, $input, $env)
{
	$access = isset($env['tool_access']) ? $env['tool_access'] : diag_default_tool_access();
	$agent_id = isset($input['agent']) ? (int)$input['agent'] : 0;
	$info = array('user_id' => $env['user_id'], 'remote_server_id' => $agent_id, 'tool' => $tool, 'target' => '', 'port' => 0, 'protocol' => '', 'client_ip' => $env['client_ip']);
	if (!isset($access[$tool]) || !in_array($env['role'], $access[$tool], true)) {
		return diag_refuse($env, $info, 'forbidden', 'Your account may not use this tool.');
	}
	if (!in_array($agent_id, $env['agent_ids'], true)) {
		return diag_refuse($env, $info, 'forbidden', 'You may not use this agent.');
	}
	$in = diag_parse_input($tool, $input, $env);
	if (!$in['ok']) {
		return diag_fail('bad_input', $in['message']);
	}
	$info['target'] = $in['host'];
	$info['port'] = $in['port'];
	$info['protocol'] = $in['protocol'];
	return diag_execute_checked($tool, $in, $info, $env);
}

// Audit row for a refused attempt, then the refusal.
function diag_refuse($env, $info, $code, $message)
{
	$id = diag_audit_start($env['store'], $info, $env['now']);
	diag_audit_finish($env['store'], $id, 'denied', $code, 0, $message);
	return diag_fail($code, $message);
}

function diag_execute_checked($tool, $in, $info, $env)
{
	$id = diag_audit_start($env['store'], $info, $env['now']);
	$blocked = diag_guard_check($env['store'], $info['user_id'], $info['remote_server_id'], $env['now'], $env['limits']);
	if ($blocked !== '') {
		diag_audit_finish($env['store'], $id, 'rate_limited', $blocked, 0, '');
		return diag_fail($blocked, diag_guard_message($blocked));
	}
	$target = diag_resolve_for_tool($tool, $in, $env);
	if (!$target['ok']) {
		diag_audit_finish($env['store'], $id, 'denied', $target['code'], 0, $target['message']);
		return diag_fail($target['code'], $target['message']);
	}
	$start = hrtime(true);
	$run = diag_call_agent($tool, $in, $target, $env, $info['remote_server_id']);
	diag_audit_finish($env['store'], $id, diag_audit_status($run),
		$run['ok'] ? $run['outcome'] : $run['code'], diag_ms($start), $run['ok'] ? $target['ip'] . ':' . $in['port'] : $run['message']);
	if (!$run['ok']) {
		return $run;
	}
	$run['source'] = array('agent' => $info['remote_server_id'], 'target' => $in['host'], 'ip' => $target['ip'], 'port' => $in['port'],
		'protocol' => $in['protocol'], 'duration_ms' => diag_ms($start));
	return $run;
}

function diag_audit_status($run)
{
	if ($run['ok']) {
		return 'ok';
	}
	return ($run['code'] === 'agent_needs_update') ? 'unsupported' : 'failed';
}

// Policy check for tools that take a user target. The speed test target is the admin's endpoint.
function diag_resolve_for_tool($tool, $in, $env)
{
	if ($tool === 'speed_test') {
		return diag_check_endpoint_host($in['host'], $env);
	}
	return diag_check_target($in['host'], $env['policy']);
}

// An endpoint host that is an IP literal must be public. A name is resolved by the agent,
// which applies the same blocked ranges at connect time (protocol doc, section 3.4).
function diag_check_endpoint_host($host, $env)
{
	if (filter_var($host, FILTER_VALIDATE_IP) && diag_ip_class($host) !== 'public') {
		return array('ok' => false, 'code' => 'blocked', 'message' => diag_deny_message('blocked'), 'ip' => '');
	}
	return array('ok' => true, 'code' => 'ok', 'message' => '', 'ip' => $host, 'allow_private' => false);
}

// Old agents cannot tell us their OS through the probe, but every agent answers what_os().
function diag_agent_os($agent)
{
	return preg_match('/CYGWIN/', (string)$agent->what_os()) ? 'windows' : 'linux';
}

function diag_agent_tool($tool, $in)
{
	if ($tool === 'traceroute') {
		return 'traceroute';
	}
	if ($tool === 'speed_test') {
		return 'http_download';
	}
	return $in['protocol'] === 'udp' ? 'udp_exchange' : 'tcp_connect';
}

function diag_call_agent($tool, $in, $target, $env, $agent_id)
{
	$agent_tool = diag_agent_tool($tool, $in);
	// The probe uses a short timeout, so an agent that does not answer cannot hold the page for the full test time.
	$probe = call_user_func($env['agent'], $agent_id, 4)->diag_probe();
	$run_timeout = in_array($tool, array('traceroute', 'speed_test'), true) ? 65 : 15;
	$agent = call_user_func($env['agent'], $agent_id, $run_timeout);
	if ($tool === 'ping') {
		return $probe['state'] === 'offline' ? diag_fail('agent_offline', 'The selected agent is offline.') : diag_run_ping($agent, $target);
	}
	$support = diag_support_state($probe, $agent_tool, isset($env['os_hint']) ? $env['os_hint'] : diag_agent_os($agent));
	if (!$support['ok']) {
		return diag_fail($support['code'], $support['message']);
	}
	$handlers = array(
		'port_check' => 'diag_run_port_check', 'tcp_test' => 'diag_run_tcp', 'server_query' => 'diag_run_server_query',
		'traceroute' => 'diag_run_traceroute', 'speed_test' => 'diag_run_speed',
	);
	return call_user_func($handlers[$tool], $agent, $in, $target, $probe['caps']);
}

// Shared by tool handlers: one agent call, or a failure array.
function diag_agent_call($agent, $agent_tool, $params)
{
	$reply = $agent->diag_run($agent_tool, $params);
	if (!$reply['success']) {
		$text = $reply['message'] !== '' ? $reply['message'] : diag_error_text($reply['status']);
		return array('ok' => false, 'code' => $reply['status'], 'message' => $text);
	}
	return array('ok' => true, 'result' => $reply['result']);
}

function diag_done($outcome, $summary, $rows, $extra = array())
{
	return array_merge(array('ok' => true, 'outcome' => $outcome, 'summary' => $summary, 'rows' => $rows), $extra);
}

function diag_tcp_summary($outcome)
{
	$text = array(
		'connected' => 'Open: the TCP connection succeeded.',
		'refused' => 'Refused: the host answered, but nothing is listening on this TCP port.',
		'timeout' => 'Timed out: no answer. A firewall may be dropping the packets, or the host is down. This alone does not prove the port is closed.',
		'unreachable' => 'Unreachable: there is no network route to the host.',
		'error' => 'Network error: the connection could not be tried.',
	);
	return $text[$outcome];
}

function diag_run_tcp($agent, $in, $target, $caps)
{
	$params = array('host_ip' => $target['ip'], 'port' => $in['port'], 'timeout_ms' => $in['timeout_ms'], 'allow_private' => $target['allow_private']);
	$call = diag_agent_call($agent, 'tcp_connect', $params);
	if (!$call['ok']) {
		return $call;
	}
	$r = diag_normalize_tcp($call['result']);
	$rows = array(array('Result', $r['outcome']), array('Connection time', $r['connect_ms'] === null ? 'n/a' : $r['connect_ms'] . ' ms'));
	if ($r['detail'] !== '') {
		$rows[] = array('Detail', $r['detail']);
	}
	return diag_done($r['outcome'], diag_tcp_summary($r['outcome']), $rows, array('note' => 'A TCP success does not verify the game protocol. Most game servers use UDP.'));
}

function diag_run_port_check($agent, $in, $target, $caps)
{
	if ($in['protocol'] === 'tcp') {
		return diag_run_tcp($agent, $in, $target, $caps);
	}
	$out = diag_run_server_query($agent, $in, $target, $caps);
	if ($out['ok']) {
		$out['note'] = 'UDP has no connection. This test sends a real game query and checks the answer. No answer does not mean the port is closed.';
	}
	return $out;
}

function diag_query_options($in)
{
	return array('players' => $in['players'], 'transport' => array('timeout_ms' => $in['timeout_ms'], 'idle_ms' => 300, 'max_datagrams' => 16, 'max_bytes' => 32768));
}

function diag_run_server_query($agent, $in, $target, $caps)
{
	$adapters = diag_query_adapters();
	$transport = new DiagAgentTransport($agent, $target['allow_private']);
	$result = $adapters[$in['adapter']]->query($transport, $target['ip'], $in['port'], diag_query_options($in));
	$rows = array(array('Status', $result['status_text']));
	if ($result['latency_ms'] !== null) {
		$rows[] = array('Query latency', $result['latency_ms'] . ' ms');
	}
	if ($result['message'] !== '') {
		$rows[] = array('Detail', $result['message']);
	}
	$rows = array_merge($rows, diag_info_rows($result['info']));
	return diag_done($result['status'], $result['status_text'], $rows, array('info' => $result['info'], 'players' => $result['players']));
}

function diag_info_rows($info)
{
	if (!$info) {
		return array();
	}
	$rows = array(array('Server name', $info['name']), array('Game', $info['game']), array('Map', $info['map']),
		array('Players', $info['players'] . ' / ' . $info['max_players'] . ($info['bots'] ? ' (' . $info['bots'] . ' bots)' : '')),
		array('Password protected', $info['password'] ? 'Yes' : 'No'));
	if (isset($info['version'])) {
		$rows[] = array('Server version', $info['version']);
	}
	return $rows;
}

function diag_run_traceroute($agent, $in, $target, $caps)
{
	$params = array('host_ip' => $target['ip'], 'max_hops' => $in['max_hops'], 'per_hop_timeout_ms' => 2000, 'probes_per_hop' => 1,
		'total_timeout_s' => 45, 'resolve_names' => false, 'allow_private' => $target['allow_private']);
	$call = diag_agent_call($agent, 'traceroute', $params);
	if (!$call['ok']) {
		return $call;
	}
	$r = diag_normalize_traceroute($call['result'], $in['max_hops']);
	$summary = $r['reached'] ? 'The destination was reached.' : 'The destination was not reached (' . $r['outcome'] . ').';
	return diag_done($r['outcome'], $summary, array(array('Hops shown', count($r['hops']))), array('hops' => $r['hops'], 'method' => $r['method']));
}

function diag_run_speed($agent, $in, $target, $caps)
{
	$size = $in['size'];
	$params = array('url' => $size['url'], 'size_bytes' => $size['bytes'], 'max_bytes' => (int)($size['bytes'] * 1.1),
		'timeout_s' => 30, 'connections' => 1, 'allow_private' => false);
	$call = diag_agent_call($agent, 'http_download', $params);
	if (!$call['ok']) {
		return $call;
	}
	$r = diag_normalize_download($call['result'], $params['max_bytes'] * 4);
	$rows = array(array('Endpoint', $in['endpoint']['label'] . ' (' . $size['label'] . ')'), array('Result', $r['outcome']),
		array('Transferred', $r['bytes'] === null ? 'n/a' : $r['bytes'] . ' bytes'), array('Elapsed', $r['elapsed_ms'] === null ? 'n/a' : $r['elapsed_ms'] . ' ms'),
		array('Average speed', $r['mbps'] === null ? 'n/a' : $r['mbps'] . ' Mbps'));
	return diag_done($r['outcome'], $r['outcome'] === 'ok' ? 'Download finished.' : 'Download did not finish normally (' . $r['outcome'] . ').', $rows,
		array('note' => 'This is throughput from the agent to the test endpoint. It is not the speed between players and the game server.'));
}

// Ping is not part of the diagnostics contract yet (protocol doc, section 9). It keeps using the agent's
// existing exec call, but only with an IP address the policy already checked: no user text reaches the command.
function diag_run_ping($agent, $target)
{
	$os = diag_agent_os($agent);
	$command = ($os === 'linux' ? 'ping -c 4 ' : 'ping ') . $target['ip'];
	if (trim((string)$agent->exec('which ping')) === '') {
		return diag_fail('tool_missing', 'The agent machine has no ping command.');
	}
	$output = $agent->exec($command);
	if ($output === null) {
		return diag_fail('exec_failed', 'The agent could not run ping.');
	}
	return diag_done('done', 'Ping finished. Read the replies below.', array(array('Command', $command)), array('output' => diag_text(trim($output), 4000)));
}
