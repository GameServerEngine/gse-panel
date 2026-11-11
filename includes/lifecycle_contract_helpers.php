<?php

/**
 * Returns the protocol/port context the agent needs to validate runtime state.
 */
function lifecycle_port_context($server_xml, $server_ip, $server_port)
{
	$query_port = '';
	$rcon_port = '';
	if (isset($server_xml->protocol) && (string)$server_xml->protocol === 'gameq') {
		if ($server_xml->query_port) {
			if ($server_xml->query_port['type'] == 'add') {
				$query_port = $server_port + $server_xml->query_port;
			} elseif ($server_xml->query_port['type'] == 'subtract') {
				$query_port = $server_port - $server_xml->query_port;
			}
		}
		if ($query_port === '') {
			$query_port = $server_port;
		}
	} elseif (isset($server_xml->protocol) && (string)$server_xml->protocol === 'lgsl' && isset($server_xml->lgsl_query_name)) {
		require_once('protocol/lgsl/lgsl_protocol.php');
		$ports = lgsl_port_conversion((string)$server_xml->lgsl_query_name, $server_port, '', '');
		$query_port = isset($ports['1']) ? $ports['1'] : '';
	} elseif (isset($server_xml->protocol) && (string)$server_xml->protocol === 'teamspeak3') {
		$query_port = '10011';
	}
	if (isset($server_xml->rcon_port) && is_numeric((string)$server_xml->rcon_port)) {
		$rcon_port = (string)$server_xml->rcon_port;
	}
	return array(
		'ip' => (string)$server_ip,
		'game_port' => (int)$server_port,
		'query_port' => $query_port,
		'rcon_port' => $rcon_port,
		'protocol' => isset($server_xml->protocol) ? (string)$server_xml->protocol : '',
		'control_protocol' => isset($server_xml->control_protocol) ? (string)$server_xml->control_protocol : '',
		'control_protocol_type' => isset($server_xml->control_protocol_type) ? (string)$server_xml->control_protocol_type : '',
		'lgsl_query_name' => isset($server_xml->lgsl_query_name) ? (string)$server_xml->lgsl_query_name : '',
		'gameq_query_name' => isset($server_xml->gameq_query_name) ? (string)$server_xml->gameq_query_name : '',
	);
}

/**
 * Starts a fresh per-request replace-text result collector.
 */
function lifecycle_begin_replace_text_tracking()
{
	$GLOBALS['replace_text_results'] = array();
}

/**
 * Returns the replace-text results gathered by cfg_text_replace.php.
 */
function lifecycle_replace_text_results()
{
	return isset($GLOBALS['replace_text_results']) && is_array($GLOBALS['replace_text_results'])
		? $GLOBALS['replace_text_results']
		: array();
}

/**
 * Resolves the currently enabled one-bot definition from Server Content state.
 */
function lifecycle_current_bot_definition($db, array $home_info)
{
	$helper = dirname(__DIR__) . '/modules/addonsmanager/server_content_helpers.php';
	if (!function_exists('scm_get_enabled_server_bot_rows') && file_exists($helper)) {
		require_once($helper);
	}
	if (!function_exists('scm_get_enabled_server_bot_rows') || !function_exists('scm_server_bot_normalize_row')) {
		return null;
	}
	$rows = scm_get_enabled_server_bot_rows($db, (int)$home_info['home_id']);
	if (empty($rows)) {
		return null;
	}
	$bot = scm_server_bot_normalize_row($rows[0]);
	if ($bot['workdir'] === '' || $bot['executable'] === '') {
		return null;
	}
	return array(
		'enabled' => 1,
		'working_directory' => $bot['workdir'],
		'command' => $bot['executable'],
		'executable' => $bot['executable'],
		'arguments' => $bot['arguments'],
	);
}

/**
 * Checks whether an existing bot.def is absent or owned by the Panel generator.
 */
function lifecycle_bot_def_is_managed($remote, $path)
{
	$existing = '';
	$read = (int)$remote->remote_readfile($path, $existing);
	if ($read === 0) {
		return true;
	}
	if ($read !== 1) {
		return false;
	}
	// The live agent's readfile RPC auto-creates a blank file the first time
	// any path is read that doesn't exist yet, then reports success with
	// empty content - it never actually returns the "0 = not found" result
	// this function's $read === 0 branch above was written for (confirmed
	// against the real GSE Windows agent; the unit test's fake remote is the
	// only thing that still returns 0 for a missing path). An empty file -
	// whether genuinely blank or just auto-created by that read - carries no
	// foreign content to conflict with, so treat it the same as "absent".
	if ((string)$existing === '') {
		return true;
	}
	return strpos((string)$existing, '# LIFECYCLE_BOT_DEF_GENERATED') === 0;
}

/**
 * Writes the simple current bot.def in management_home for agents that read it.
 */
function lifecycle_sync_bot_definition($remote, array $home_info, $bot_definition, &$error = '')
{
	$error = '';
	$management_home = rtrim(clean_path((string)$home_info['management_home']), '/');
	if ($management_home === '') {
		$error = 'Unable to resolve management home for bot definition.';
		return false;
	}
	$bot_def_path = clean_path($management_home . '/bot.def');
	if ($bot_definition === null) {
		// No Server Bot is enabled: only remove a file the Panel generated.
		// A manual bot.def is left alone and must not block Start/Restart.
		if (lifecycle_bot_def_is_managed($remote, $bot_def_path)) {
			$remote->exec('rm -f ' . escapeshellarg($bot_def_path));
		}
		return true;
	}
	if (!lifecycle_bot_def_is_managed($remote, $bot_def_path)) {
		$error = 'A non-generated bot.def already exists. Remove or migrate it before enabling lifecycle-managed Server Bot content.';
		return false;
	}
	foreach (array('working_directory', 'command', 'arguments') as $key) {
		$value = isset($bot_definition[$key]) ? (string)$bot_definition[$key] : '';
		if (strpos($value, "\0") !== false || strpos($value, "\r") !== false || strpos($value, "\n") !== false) {
			$error = 'Server Bot definition contains an invalid character.';
			return false;
		}
	}
	$contents = "# LIFECYCLE_BOT_DEF_GENERATED\n"
		. "workdir=" . $bot_definition['working_directory'] . "\n"
		. "executable=" . $bot_definition['command'] . "\n"
		. "arguments=" . $bot_definition['arguments'] . "\n";
	$remote->exec('mkdir -p ' . escapeshellarg(dirname($bot_def_path)));
	if ((int)$remote->remote_writefile($bot_def_path, $contents) !== 1) {
		$error = 'Failed to write current Server Bot definition.';
		return false;
	}
	return true;
}

/**
 * Builds the structured lifecycle payload appended to Start/Restart RPCs.
 */
function lifecycle_start_payload($db, $remote, array $home_info, $server_xml, $mod_id, $server_ip, $server_port, $startup_cmd, $preStart, $envVars, $replace_text_results = array(), $restart_reason = '')
{
	$run_dir = clean_path(isset($server_xml->exe_location) ? (string)$server_xml->exe_location : "");
	$absolute_run_dir = clean_path(rtrim($home_info['game_root'], '/') . '/' . (isset($server_xml->exe_location) ? (string)$server_xml->exe_location : ''));
	$last_param = json_decode(isset($home_info['last_param']) ? (string)$home_info['last_param'] : '', true);
	return array(
		'contract' => 'gse.lifecycle.v2',
		'operation' => $restart_reason === '' ? 'start' : 'restart_start',
		'home_id' => (int)$home_info['home_id'],
		'management_home' => $home_info['management_home'],
		'game_key' => $home_info['game_key'],
		'game_root' => $home_info['game_root'],
		'executable' => isset($server_xml->server_exec_name) ? (string)$server_xml->server_exec_name : '',
		'run_directory' => $run_dir,
		'absolute_run_directory' => $absolute_run_dir,
		'arguments' => (string)$startup_cmd,
		'environment' => (string)$envVars,
		'pre_start' => (string)$preStart,
		'port_context' => lifecycle_port_context($server_xml, $server_ip, $server_port),
		'user_options' => is_array($last_param) ? $last_param : array(),
		'mod' => isset($home_info['mods'][$mod_id]) ? $home_info['mods'][$mod_id] : array(),
		'replace_text_results' => is_array($replace_text_results) ? $replace_text_results : array(),
		'restart_reason' => $restart_reason,
	);
}

/**
 * Builds the structured lifecycle payload appended to Stop/Force Stop RPCs.
 */
function lifecycle_stop_payload(array $home_info, $server_xml, $server_ip, $server_port, $force = false)
{
	return array(
		'contract' => 'gse.lifecycle.v2',
		'operation' => $force ? 'force_stop' : 'stop',
		'home_id' => (int)$home_info['home_id'],
		'management_home' => isset($home_info['management_home']) ? $home_info['management_home'] : clean_path($home_info['home_path']),
		'game_key' => isset($home_info['game_key']) ? $home_info['game_key'] : '',
		'game_root' => isset($home_info['game_root']) ? $home_info['game_root'] : '',
		'port_context' => lifecycle_port_context($server_xml, $server_ip, $server_port),
	);
}

/**
 * Returns true only when status evidence proves no managed runtime survived.
 */
function lifecycle_status_is_verified_stopped($agent_status)
{
	if (!is_array($agent_status)) {
		return false;
	}
	$status = isset($agent_status['status']) ? strtoupper((string)$agent_status['status']) : 'UNKNOWN';
	$active_keys = array('process_running', 'session_running', 'game_port_listening', 'port_listening', 'ready');
	foreach ($active_keys as $key) {
		if (!empty($agent_status[$key])) {
			return false;
		}
	}
	foreach (array('pid', 'game_pid', 'bot_pid', 'watchdog_pid', 'screen_pid') as $key) {
		if (!empty($agent_status[$key])) {
			return false;
		}
	}
	foreach (array('port_owners', 'remaining_pids', 'surviving_pids') as $key) {
		if (!empty($agent_status[$key])) {
			return false;
		}
	}
	return in_array($status, array('OFFLINE', 'STOPPED', 'STOPPING'), true);
}

/**
 * Collects structured status plus narrow legacy session/port fallback evidence.
 */
function lifecycle_collect_agent_status($remote, $server_xml, $home_id, $server_ip, $server_port, $state_hint = '', $startup_timeout = 180)
{
	$ports = lifecycle_port_context($server_xml, $server_ip, $server_port);
	$agent_status = array(
		'status' => 'UNKNOWN',
		'ready' => 0,
		'process_running' => 0,
		'session_running' => 0,
		'game_port_listening' => 0,
		'query_port_listening' => 0,
		'rcon_port_listening' => 0,
		'ip' => $server_ip,
		'port' => $server_port,
		'query_port' => $ports['query_port'],
		'rcon_port' => $ports['rcon_port'],
		'last_error' => 'Agent status RPC unavailable.',
	);
	if (method_exists($remote, 'remote_server_status')) {
		$remote_status = $remote->remote_server_status($home_id, $server_ip, $server_port, $ports['query_port'], $ports['rcon_port'], $startup_timeout, $state_hint);
		if (is_array($remote_status)) {
			$agent_status = array_merge($agent_status, $remote_status);
		}
	}
	$fallback_session = null;
	if (method_exists($remote, 'is_screen_running')) {
		$screen_result = $remote->is_screen_running(SCREEN_TYPE_HOME, $home_id);
		if ($screen_result === 1) {
			$fallback_session = true;
			$agent_status['session_running'] = 1;
			$agent_status['process_running'] = 1;
		} elseif ($screen_result === 0) {
			$fallback_session = false;
		}
	}
	$fallback_port = null;
	if (method_exists($remote, 'exec') && (int)$server_port > 0) {
		$port_pattern = '[:.]' . (int)$server_port . '([[:space:]]|$)';
		$command = 'if command -v ss >/dev/null 2>&1; then ss -lntu 2>/dev/null; else netstat -an 2>/dev/null; fi '
			. '| tr -d "\r" '
			. '| grep -Eai "(LISTEN|LISTENING|UDP|tcp|udp)" '
			. '| grep -E ' . escapeshellarg($port_pattern) . ' >/dev/null '
			. '&& echo PORT_LISTENING || echo PORT_CLOSED';
		$output = $remote->exec($command);
		if (strpos((string)$output, 'PORT_LISTENING') !== false) {
			$fallback_port = true;
			$agent_status['game_port_listening'] = 1;
		} elseif (strpos((string)$output, 'PORT_CLOSED') !== false) {
			$fallback_port = false;
		}
	}
	$status_name = isset($agent_status['status']) ? strtoupper((string)$agent_status['status']) : 'UNKNOWN';
	if (($status_name === 'UNKNOWN' || $status_name === 'OFFLINE') && ($fallback_session === true || $fallback_port === true)) {
		$agent_status['status'] = 'ONLINE';
		$agent_status['ready'] = 1;
		$agent_status['last_error'] = '';
		$agent_status['status_source'] = $fallback_port === true ? 'legacy_port_fallback' : 'legacy_session_fallback';
	} elseif ($status_name === 'UNKNOWN' && $fallback_session === false && $fallback_port === false) {
		$agent_status['status'] = 'OFFLINE';
		$agent_status['last_error'] = '';
		$agent_status['status_source'] = 'legacy_absence_fallback';
	}
	return $agent_status;
}

/**
 * Formats structured agent failure evidence for API and UI messages.
 */
function lifecycle_result_message($result, $fallback)
{
	if (is_array($result) && isset($result['message']) && (string)$result['message'] !== '') {
		return (string)$result['message'];
	}
	if (is_array($result) && isset($result['status']) && (string)$result['status'] !== '') {
		return $fallback . ' Agent status: ' . (string)$result['status'] . '.';
	}
	return $fallback;
}

?>
