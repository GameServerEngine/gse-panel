<?php
/*
 * Agent diagnostics contract, panel side. The full contract is in
 * docs/agent-diagnostics-protocol.md. Nothing here talks to the network or
 * needs the xmlrpc extension, so tests can run it anywhere.
 */

define('DIAG_PROTOCOL_VERSION', 1);

// Agent version that first supports diagnostics, per OS. The Linux and Windows agents
// have separate version lines. Placeholders until the agent runs release; keep in sync.
function diag_min_agent_version($os)
{
	$versions = array('linux' => '0.6.0', 'windows' => '1.6.0');
	return isset($versions[$os]) ? $versions[$os] : $versions['linux'];
}

function diag_known_tools()
{
	return array('tcp_connect', 'udp_exchange', 'traceroute', 'http_download');
}

// Ranges the panel sends. The agent re-checks every one (protocol doc, section 5).
function diag_param_limits()
{
	return array(
		'timeout_ms' => array(100, 10000),
		'idle_ms' => array(50, 2000),
		'max_datagrams' => array(1, 16),
		'max_bytes' => array(64, 32768),
		'max_hops' => array(1, 30),
		'per_hop_timeout_ms' => array(200, 5000),
		'probes_per_hop' => array(1, 3),
		'total_timeout_s' => array(5, 60),
		'port' => array(1, 65535),
		'connections' => array(1, 4),
	);
}

// Returns '' when the value is inside the range, otherwise the name of the bad field.
function diag_check_params($params)
{
	$limits = diag_param_limits();
	foreach ($limits as $name => $range) {
		if (!array_key_exists($name, $params)) {
			continue;
		}
		$value = $params[$name];
		if (!is_int($value) || $value < $range[0] || $value > $range[1]) {
			return $name;
		}
	}
	return '';
}

// Turns the decoded capability reply into one array, or null when the reply is not a valid probe.
function diag_parse_capabilities($reply)
{
	if (!is_array($reply) || empty($reply['success']) || ($reply['status'] ?? '') !== 'ok') {
		return null;
	}
	$tools = json_decode((string)($reply['tools'] ?? ''), true);
	$limits = json_decode((string)($reply['limits'] ?? ''), true);
	if (!is_array($tools)) {
		return null;
	}
	return array(
		'protocol_version' => (int)($reply['protocol_version'] ?? 0),
		'agent_version' => (string)($reply['agent_version'] ?? ''),
		'os' => (($reply['os'] ?? '') === 'windows') ? 'windows' : 'linux',
		'tools' => array_values(array_intersect($tools, diag_known_tools())),
		'limits' => is_array($limits) ? $limits : array(),
	);
}

// The "can this agent run this tool" decision, in one place.
// $probe: result of diag_probe_state(). Returns array(ok, code, message).
function diag_support_state($probe, $tool, $os_hint = 'linux')
{
	if ($probe['state'] === 'offline') {
		return array('ok' => false, 'code' => 'agent_offline', 'message' => 'The selected agent is offline.');
	}
	$caps = $probe['caps'];
	$old = ($probe['state'] === 'unsupported') || $caps === null
		|| $caps['protocol_version'] < DIAG_PROTOCOL_VERSION
		|| !in_array($tool, $caps['tools'], true);
	if ($old) {
		$os = $caps === null ? $os_hint : $caps['os'];
		$msg = 'This agent needs to be updated to version ' . diag_min_agent_version($os) . ' to run this test.';
		return array('ok' => false, 'code' => 'agent_needs_update', 'message' => $msg);
	}
	return array('ok' => true, 'code' => 'ok', 'message' => '');
}

// Normalizes a diag_run reply (already decoded) into array(success, status, message, result, ...).
function diag_parse_run_reply($reply)
{
	$bad = array('success' => false, 'status' => 'bad_reply', 'message' => 'The agent sent an unreadable reply.',
		'result' => array(), 'duration_ms' => 0, 'truncated' => false);
	if (!is_array($reply) || !isset($reply['success'])) {
		return $bad;
	}
	$result = json_decode((string)($reply['result'] ?? ''), true);
	return array(
		'success' => $reply['success'] === true || $reply['success'] === '1' || $reply['success'] === 1,
		'status' => (string)($reply['status'] ?? 'bad_reply'),
		'message' => substr((string)($reply['message'] ?? ''), 0, 300),
		'result' => is_array($result) ? $result : array(),
		'duration_ms' => (int)($reply['duration_ms'] ?? 0),
		'truncated' => (string)($reply['truncated'] ?? '0') === '1',
	);
}

// Plain-language text for each agent error code.
function diag_error_text($status)
{
	$texts = array(
		'bad_encryption' => 'The agent rejected the panel key.',
		'bad_params' => 'The agent rejected the test settings.',
		'unsupported_tool' => 'This agent does not support that test.',
		'target_blocked' => 'The agent refused this address (blocked range).',
		'busy' => 'The agent is running other tests. Try again in a moment.',
		'timeout' => 'The test ran too long and was stopped.',
		'tool_missing' => 'The agent machine has no traceroute tool installed.',
		'redirect_blocked' => 'The test endpoint tried to redirect; redirects are not followed.',
		'internal_error' => 'The agent had an internal error.',
	);
	return isset($texts[$status]) ? $texts[$status] : 'The agent reported an error (' . $status . ').';
}
