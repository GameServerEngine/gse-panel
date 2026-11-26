<?php
/*
 * Runs one network tool for one user. There are two tools:
 *   ping        pings an address (default: the user's own public IP) from the chosen agent machine
 *   port_check  lists the ports the chosen game server's machine is listening on and compares them with the game server's ports
 * Order of every run:
 *   1. role may use the tool        2. user may use the agent     3. form values are valid
 *   4. audit row + rate/concurrency caps     5. target policy (ping only)
 *   6. agent runs it                7. result cleaned and audited
 * Everything it needs comes in through $env, so tests can fake the database, DNS and agents:
 *   user_id, role, client_ip, now, store, limits, policy (diag_check_target context),
 *   agent_ids (agents this user may use), agent (callable id, timeout_s => agent object),
 *   rows (the game server rows this user may see), server_ports (callable row => ports), tool_access.
 * Both tools use the agent's existing exec() call with fixed command text. No user text reaches a command.
 */

require_once __DIR__ . '/target_policy.php';
require_once __DIR__ . '/limits.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/listening.php';

// Which roles may use which tool.
function diag_default_tool_access()
{
	$everyone = array('admin', 'user', 'subuser');
	return array('ping' => $everyone, 'port_check' => $everyone);
}

function diag_fail($code, $message)
{
	return array('ok' => false, 'code' => $code, 'message' => $message);
}

// Validates the form values for $tool. Returns array(ok, message, host, port, protocol, ...).
function diag_parse_input($tool, $input, $env)
{
	if ($tool === 'port_check') {
		return diag_parse_port_check($input, $env);
	}
	// An empty target means "my IP": the address the user is visiting the panel from.
	$typed = trim(isset($input['host']) ? (string)$input['host'] : '');
	$host = diag_parse_host($typed === '' ? $env['client_ip'] : $typed);
	if (!$host['ok']) {
		return array('ok' => false, 'message' => $host['message']);
	}
	return array('ok' => true, 'host' => $host['host'], 'port' => 0, 'protocol' => 'ping');
}

// The user picks one of their own game servers (agent + address + port). Its ports come from the game config.
// Only an admin may add ports of their own.
function diag_parse_port_check($input, $env)
{
	$bad = function ($message) {
		return array('ok' => false, 'message' => $message);
	};
	$ip = isset($input['server_ip']) ? (string)$input['server_ip'] : '';
	$port = diag_parse_port(isset($input['server_port']) ? $input['server_port'] : '');
	$row = diag_find_server_row($env['rows'], isset($input['agent']) ? (int)$input['agent'] : 0, $ip, $port);
	if ($row === null) {
		return $bad('Choose one of your game servers.');
	}
	$is_admin = ($env['role'] === 'admin');
	$ports = call_user_func($env['server_ports'], $row);
	$extra = diag_parse_extra_ports($is_admin && isset($input['extra_ports']) ? (string)$input['extra_ports'] : '');
	if ($extra === null) {
		return $bad('Extra ports must be numbers from 1 to 65535, separated by commas (at most 10).');
	}
	foreach ($extra as $number) {
		if (!in_array($number, array_column($ports, 'port'), true)) {
			$ports[] = array('port' => $number, 'label' => 'Extra port');
		}
	}
	return array('ok' => true, 'host' => $ip, 'port' => $port, 'protocol' => 'listen', 'ports' => $ports, 'is_admin' => $is_admin);
}

function diag_find_server_row($rows, $agent_id, $ip, $port)
{
	foreach ($rows as $row) {
		if ((int)$row['remote_server_id'] === $agent_id && (string)$row['ip'] === $ip && (int)$row['port'] === $port) {
			return $row;
		}
	}
	return null;
}

// "27015, 27016" -> array(27015, 27016). Empty text is no ports. Null when something is not a port or there are more than 10.
function diag_parse_extra_ports($text)
{
	$numbers = array();
	foreach (preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $part) {
		$port = diag_parse_port($part);
		if ($port === null) {
			return null;
		}
		$numbers[$port] = $port;
	}
	return count($numbers) > 10 ? null : array_values($numbers);
}

function diag_ms($start_ns)
{
	return (int)round((hrtime(true) - $start_ns) / 1000000);
}

// Runs a tool. Returns array(ok, code, message) on refusal, or array(ok => true, outcome, summary, rows, source).
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
	diag_audit_finish($env['store'], $id, $run['ok'] ? 'ok' : 'failed',
		$run['ok'] ? $run['outcome'] : $run['code'], diag_ms($start), $run['ok'] ? $target['ip'] . ':' . $in['port'] : $run['message']);
	if (!$run['ok']) {
		return $run;
	}
	$run['source'] = array('agent' => $info['remote_server_id'], 'target' => $in['host'], 'ip' => $target['ip'], 'port' => $in['port'],
		'protocol' => $in['protocol'], 'duration_ms' => diag_ms($start));
	return $run;
}

// Ping may only go to a checked address (the policy pins the IP). The port check never leaves the user's own game server.
function diag_resolve_for_tool($tool, $in, $env)
{
	if ($tool === 'port_check') {
		return array('ok' => true, 'code' => 'ok', 'message' => '', 'ip' => $in['host'], 'allow_private' => true);
	}
	return diag_check_target($in['host'], $env['policy']);
}

// Every agent answers what_os().
function diag_agent_os($agent)
{
	return preg_match('/CYGWIN/', (string)$agent->what_os()) ? 'windows' : 'linux';
}

function diag_call_agent($tool, $in, $target, $env, $agent_id)
{
	// The probe uses a short timeout, so an agent that does not answer cannot hold the page.
	$probe = call_user_func($env['agent'], $agent_id, 4)->diag_probe();
	if ($probe['state'] === 'offline') {
		return diag_fail('agent_offline', 'The selected agent is offline.');
	}
	$agent = call_user_func($env['agent'], $agent_id, 15);
	return $tool === 'ping' ? diag_run_ping($agent, $target) : diag_run_port_check($agent, $in);
}

function diag_done($outcome, $summary, $rows, $extra = array())
{
	return array_merge(array('ok' => true, 'outcome' => $outcome, 'summary' => $summary, 'rows' => $rows), $extra);
}

function diag_run_port_check($agent, $in)
{
	$os = diag_agent_os($agent);
	$listeners = $os === 'windows' ? diag_parse_netstat($agent->exec(diag_listen_command($os))) : diag_parse_ss($agent->exec(diag_listen_command($os)));
	// Every machine listens on something. An empty list means the command did not run or its answer was unreadable.
	if (count($listeners) === 0) {
		return diag_fail('exec_failed', 'The agent could not list the open ports on its machine.');
	}
	if ($in['is_admin'] && $os === 'windows') {
		$listeners = diag_name_listeners($listeners, diag_parse_tasklist($agent->exec('tasklist /FO CSV /NH')));
	}
	$checked = diag_listen_check($listeners, $in['ports'], $in['host']);
	$outcome = diag_listen_outcome($checked);
	$up = count(array_filter($checked, 'diag_port_is_listening'));
	return diag_done($outcome, diag_listen_summary($outcome, $up, count($checked)), diag_listen_rows($checked, $in['is_admin']),
		array('note' => 'This shows what the game server machine is listening on. It does not test the firewall from the internet. A port that is not listening may just mean the game server is stopped.'));
}

// Ping uses the agent's existing exec call, but only with an IP address the policy already checked.
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
