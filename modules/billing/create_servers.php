<?php
require_once __DIR__ . '/../../includes/lib_remote.php';
require_once __DIR__ . '/../../includes/port_allocator.php';
require_once __DIR__ . '/../config_games/server_config_parser.php';
require_once __DIR__ . '/../gamemanager/update_actions.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/classes/BillingRepository.php';
require_once __DIR__ . '/classes/BillingLifecycleService.php';

if (!defined('BILLING_INSTALL_MECHANISM')) {
	define('BILLING_INSTALL_MECHANISM', 'gamemanager_trigger_update_install');
}
if (!defined('BILLING_CPU_AFFINITY_NA')) {
	define('BILLING_CPU_AFFINITY_NA', 'NA');
}
if (!defined('BILLING_NICE_DEFAULT')) {
	define('BILLING_NICE_DEFAULT', '0');
}
if (!defined('BILLING_PROVISION_TRACE_LOG')) {
	define('BILLING_PROVISION_TRACE_LOG', 'modules/billing/logs/provisioning_trace.log');
}

if (!function_exists('billing_provision_trace_relative_path')) {
	function billing_provision_trace_relative_path(): string
	{
		return BILLING_PROVISION_TRACE_LOG;
	}
}

if (!function_exists('billing_provision_trace_path')) {
	function billing_provision_trace_path(): string
	{
		return __DIR__ . '/logs/provisioning_trace.log';
	}
}

if (!function_exists('billing_set_trace_error')) {
	function billing_set_trace_error(string $message): void
	{
		$GLOBALS['BILLING_PROVISION_TRACE_ERROR'] = $message;
		error_log('billing_provision_trace: ' . $message);
	}
}

if (!function_exists('billing_format_trace_value')) {
	function billing_format_trace_value($value): string
	{
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return 'null';
		}
		if (is_scalar($value)) {
			return (string)$value;
		}
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return $json === false ? '[unserializable]' : $json;
	}
}

if (!function_exists('billing_ensure_trace_log_ready')) {
	function billing_ensure_trace_log_ready(): array
	{
		$logFile = billing_provision_trace_path();
		$logDir = dirname($logFile);
		if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
			$error = "Could not create billing trace log directory: {$logDir}";
			billing_set_trace_error($error);
			return array('ok' => false, 'error' => $error, 'path' => $logFile);
		}
		if (!is_writable($logDir)) {
			$error = "Billing trace log directory is not writable: {$logDir}";
			billing_set_trace_error($error);
			return array('ok' => false, 'error' => $error, 'path' => $logFile);
		}
		if (!file_exists($logFile)) {
			$result = @file_put_contents($logFile, '', FILE_APPEND | LOCK_EX);
			if ($result === false) {
				$error = "Could not create billing trace log file: {$logFile}";
				billing_set_trace_error($error);
				return array('ok' => false, 'error' => $error, 'path' => $logFile);
			}
		}
		if (!is_writable($logFile)) {
			$error = "Billing trace log file is not writable: {$logFile}";
			billing_set_trace_error($error);
			return array('ok' => false, 'error' => $error, 'path' => $logFile);
		}
		return array('ok' => true, 'path' => $logFile);
	}
}

if (!function_exists('billing_provision_trace')) {
	function billing_provision_trace($message, array $context = array()): bool
	{
		$ready = billing_ensure_trace_log_ready();
		if (empty($ready['ok'])) {
			return false;
		}
		$baseContext = isset($GLOBALS['BILLING_PROVISION_TRACE_CONTEXT']) && is_array($GLOBALS['BILLING_PROVISION_TRACE_CONTEXT'])
			? $GLOBALS['BILLING_PROVISION_TRACE_CONTEXT']
			: array();
		$merged = array_merge($baseContext, $context);
		$line = '[' . date('Y-m-d H:i:s') . '] ' . trim((string)$message);
		if (!empty($merged)) {
			$parts = array();
			foreach ($merged as $key => $value) {
				$parts[] = $key . '=' . billing_format_trace_value($value);
			}
			$line .= ' | ' . implode(' | ', $parts);
		}
		$result = @file_put_contents($ready['path'], $line . PHP_EOL, FILE_APPEND | LOCK_EX);
		if ($result === false) {
			billing_set_trace_error('Failed to append billing trace log: ' . $ready['path']);
			return false;
		}
		return true;
	}
}

if (!function_exists('billing_get_server_home_row')) {
	function billing_get_server_home_row($db, string $db_prefix, int $home_id): array
	{
		if ($home_id <= 0) {
			return array();
		}
		$row = $db->resultQuery(
			"SELECT * FROM `{$db_prefix}server_homes`
			 WHERE home_id=" . $db->realEscapeSingle($home_id) . "
			 LIMIT 1"
		);
		return !empty($row[0]) ? (array)$row[0] : array();
	}
}

if (!function_exists('billing_trace_home_info_summary')) {
	function billing_trace_home_info_summary(array $home_info): array
	{
		$mods = array();
		foreach ((array)($home_info['mods'] ?? array()) as $modId => $modInfo) {
			$mods[] = array(
				'mod_id' => intval($modId),
				'mod_key' => $modInfo['mod_key'] ?? '',
			);
		}
		return array(
			'home_id' => intval($home_info['home_id'] ?? 0),
			'home_cfg_id' => intval($home_info['home_cfg_id'] ?? 0),
			'home_cfg_file' => $home_info['home_cfg_file'] ?? '',
			'remote_server_id' => intval($home_info['remote_server_id'] ?? 0),
			'home_path' => $home_info['home_path'] ?? '',
			'agent_ip' => $home_info['agent_ip'] ?? '',
			'agent_port' => $home_info['agent_port'] ?? '',
			'mods' => $mods,
		);
	}
}

if (!function_exists('billing_trace_settings_summary')) {
	function billing_trace_settings_summary(array $settings): array
	{
		return array(
			'panel_name' => $settings['panel_name'] ?? '',
			'steam_user_configured' => !empty($settings['steam_user']),
			'steam_guard_configured' => !empty($settings['steam_guard']),
		);
	}
}

if (!function_exists('billing_detect_install_state')) {
	function billing_detect_install_state(array $home_info): array
	{
		$state = array(
			'complete' => false,
			'remote_status' => 'unknown',
			'update_active' => false,
			'exec_path' => '',
			'exec_exists' => false,
			'reason' => '',
		);
		if (empty($home_info['home_id'])) {
			$state['reason'] = 'home_info is missing home_id.';
			return $state;
		}
		if (empty($home_info['home_cfg_file'])) {
			$state['reason'] = 'home_cfg_file is missing; install completion cannot be verified.';
			return $state;
		}
		$xml_cfg_file = $home_info['home_cfg_file'] ?? '';
		$xml_rel = rtrim(SERVER_CONFIG_LOCATION, '/') . '/' . $xml_cfg_file;
		$xml_abs = $xml_rel;
		if (!is_readable($xml_rel)) {
			$panel_root = realpath(__DIR__ . '/../../');
			if ($panel_root !== false) {
				$xml_abs = $panel_root . '/' . ltrim($xml_rel, '/');
			}
		}
		if (function_exists('billing_provision_trace')) {
			billing_provision_trace('billing_detect_install_state: XML path resolution.', array(
				'home_id'         => intval($home_info['home_id'] ?? 0),
				'home_cfg_file'   => $xml_cfg_file,
				'xml_rel_path'    => $xml_rel,
				'xml_abs_path'    => $xml_abs,
				'cwd'             => getcwd(),
				'xml_file_exists' => file_exists($xml_abs),
				'xml_is_readable' => is_readable($xml_abs),
			));
		}
		$server_xml = read_server_config($xml_abs);
		if (!$server_xml) {
			$state['reason'] = "Could not read server config XML; install completion cannot be verified. Tried: {$xml_abs}";
			return $state;
		}
		$path_error = '';
		$home_info = with_game_root($home_info, $server_xml, $path_error);
		if ($home_info === false) {
			$state['reason'] = "Invalid game directory; install completion cannot be verified. {$path_error}";
			return $state;
		}
		$server_exec_name = trim((string)($server_xml->server_exec_name ?? ''));
		if ($server_exec_name === '') {
			$state['reason'] = 'server_exec_name is empty; install completion cannot be verified.';
			return $state;
		}
		$remote = new RemoteLibrary($home_info['agent_ip'], $home_info['agent_port'], $home_info['encryption_key'], $home_info['timeout']);
		$hostStat = $remote->status_chk();
		$state['remote_status'] = ($hostStat === 1) ? 'online' : 'offline';
		if ($hostStat !== 1) {
			$state['reason'] = 'Agent is offline; install completion cannot be verified.';
			return $state;
		}
		$log_txt = '';
		$update_active = $remote->get_log(SCREEN_TYPE_UPDATE, intval($home_info['home_id']), clean_path($home_info['home_path']), $log_txt);
		$state['update_active'] = ($update_active == 1);
		$state['update_log'] = $log_txt;
		$execFolder = clean_path($home_info['game_root'] . "/" . (string)($server_xml->exe_location ?? ''));
		$execPath = clean_path($execFolder . "/" . $server_exec_name);
		$state['exec_path'] = $execPath;
		$state['exec_exists'] = ($remote->rfile_exists($execPath) === 1);
		$state['complete'] = $state['exec_exists'];
		if ($state['exec_exists']) {
			$state['reason'] = 'Expected executable already exists on the remote server.';
		} elseif (!empty($state['update_active'])) {
			$state['reason'] = 'Server installation is in progress.';
		} else {
			$state['reason'] = 'Expected executable is missing on the remote server.';
		}
		return $state;
	}
}

if (!function_exists('billing_store_provision_session_result')) {
	function billing_store_provision_session_result(string $key, array $payload): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION['billing_provision_results'][$key] = $payload;
		}
	}
}

if (!function_exists('billing_generate_provision_password')) {
	function billing_generate_provision_password()
	{
		$length = 6;
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$alphabetLen = strlen($alphabet);
		$password = '';
		try {
			for ($i = 0; $i < $length; $i++) {
				$password .= $alphabet[random_int(0, $alphabetLen - 1)];
			}
			return $password;
		} catch (Throwable $e) {
			billing_provision_trace('Password generation fallback after exception.', array('exception' => $e->getMessage()));
			for ($i = 0; $i < $length; $i++) {
				$password .= $alphabet[mt_rand(0, $alphabetLen - 1)];
			}
			return $password;
		}
	}
}

if (!function_exists('billing_is_valid_provision_password')) {
	function billing_is_valid_provision_password($value): bool
	{
		return is_string($value) && preg_match('/^[A-Za-z0-9]{6}$/', $value) === 1;
	}
}

if (!function_exists('billing_should_regenerate_provision_password')) {
	function billing_should_regenerate_provision_password($value): bool
	{
		return !billing_is_valid_provision_password($value) || strcasecmp((string)$value, 'ChangeMe') === 0;
	}
}

if (!function_exists('billing_agent_offline_reason')) {
	function billing_agent_offline_reason(int $remote_server_id, array $home_info): string
	{
		return "Agent is offline for remote server #{$remote_server_id} (" . ($home_info['agent_ip'] ?? 'unknown') . ":" . ($home_info['agent_port'] ?? 'unknown') . ").";
	}
}

if (!function_exists('billing_detect_service_os')) {
	function billing_detect_service_os(string $cfg_file, string $game_key): string
	{
		$haystack = strtolower(trim($cfg_file !== '' ? $cfg_file : $game_key));
		if ($haystack === '') {
			return 'any';
		}
		if (preg_match('/(?:^|[_\\-])(win|windows)(?:[_\\-]|$)/i', $haystack)) {
			return 'windows';
		}
		if (preg_match('/(?:^|[_\\-])linux(?:[_\\-]|$)/i', $haystack)) {
			return 'linux';
		}
		return 'any';
	}
}

if (!function_exists('billing_normalize_node_os')) {
	function billing_normalize_node_os(string $server_os): string
	{
		$value = strtolower(trim($server_os));
		if ($value === '' || $value === 'any') {
			return 'any';
		}
		if (str_starts_with($value, 'win')) {
			return 'windows';
		}
		if (str_starts_with($value, 'lin')) {
			return 'linux';
		}
		return $value;
	}
}

if (!function_exists('billing_remote_servers_has_os_column')) {
	function billing_remote_servers_has_os_column($db, string $db_prefix): bool
	{
		static $cache = array();
		if (isset($cache[$db_prefix])) {
			return $cache[$db_prefix];
		}
		$rows = $db->resultQuery("SHOW COLUMNS FROM `{$db_prefix}remote_servers` LIKE 'server_os'");
		$cache[$db_prefix] = !empty($rows);
		return $cache[$db_prefix];
	}
}

if (!function_exists('billing_invoke_provision')) {
	function billing_invoke_provision(array $options = array())
	{
		if (empty($options['caller_source'])) {
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
			$caller = $trace[1] ?? array();
			$options['caller_source'] = ($caller['file'] ?? __FILE__) . ':' . intval($caller['line'] ?? 0);
		}
		unset($GLOBALS['BILLING_PROVISION_TRACE_ERROR'], $GLOBALS['BILLING_PROVISION_TRACE_CONTEXT']);
		$GLOBALS['BILLING_PROVISION_OVERRIDE'] = $options;
		ob_start();
		require_once __DIR__ . '/includes/provision_body.php';
		billing_run_provision_body();
		$output = ob_get_clean();
		$result = isset($GLOBALS['BILLING_PROVISION_LAST_RESULT']) ? $GLOBALS['BILLING_PROVISION_LAST_RESULT'] : array();
		$result['output'] = $output;
		$result['trace_log_path'] = billing_provision_trace_relative_path();
		if (!empty($GLOBALS['BILLING_PROVISION_TRACE_ERROR'])) {
			$result['trace_error'] = $GLOBALS['BILLING_PROVISION_TRACE_ERROR'];
		}
		unset($GLOBALS['BILLING_PROVISION_OVERRIDE'], $GLOBALS['BILLING_PROVISION_LAST_RESULT'], $GLOBALS['BILLING_PROVISION_TRACE_ERROR'], $GLOBALS['BILLING_PROVISION_TRACE_CONTEXT']);
		return $result;
	}
}

if (!function_exists('billing_get_remote_ip_ids')) {
	function billing_get_remote_ip_ids($db, string $db_prefix, int $remote_server_id): array
	{
		$rows = $db->resultQuery(
			"SELECT ip_id FROM `{$db_prefix}remote_server_ips` WHERE remote_server_id=" . $db->realEscapeSingle($remote_server_id) . " ORDER BY ip_id ASC"
		);
		$ipIds = array();
		foreach ((array)$rows as $row) {
			$ipId = intval($row['ip_id'] ?? 0);
			if ($ipId > 0) {
				$ipIds[] = $ipId;
			}
		}
		return $ipIds;
	}
}

if (!function_exists('billing_lifecycle_service_from_config')) {
	function billing_lifecycle_service_from_config(string $db_prefix)
	{
		global $db_host, $db_user, $db_pass, $db_name, $db_port;
		if (empty($db_host) || empty($db_user) || empty($db_name)) {
			return null;
		}
		$port = isset($db_port) && $db_port !== '' ? intval($db_port) : null;
		$link = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $port);
		if (!$link) {
			return null;
		}
		mysqli_set_charset($link, 'utf8mb4');
		return new BillingLifecycleService(new BillingRepository($link, $db_prefix));
	}
}

if (!function_exists('billing_allocate_home_port')) {
	function billing_allocate_home_port($db, string $db_prefix, int $home_id, int $remote_server_id, int $home_cfg_id): array
	{
		if (port_allocator_schema_available($db, $db_prefix)) {
			$allocatedBlock = port_allocate_home_block($db, $db_prefix, $home_id, $remote_server_id, $home_cfg_id);
			billing_provision_trace('Attempted global port-block allocation.', array(
				'home_id' => $home_id,
				'selected_remote_server_id' => $remote_server_id,
				'selected_home_cfg_id' => $home_cfg_id,
				'global_port_block_result' => $allocatedBlock,
			));
			if (!empty($allocatedBlock['ok'])) {
				return array(
					'ok' => true,
					'ip_id' => intval($allocatedBlock['ip_id'] ?? 0),
					'port' => intval($allocatedBlock['port'] ?? $allocatedBlock['primary_port'] ?? 0),
					'block_id' => intval($allocatedBlock['block_id'] ?? 0),
					'block_start' => intval($allocatedBlock['block_start'] ?? 0),
					'block_end' => intval($allocatedBlock['block_end'] ?? 0),
					'allocation_source' => (string)($allocatedBlock['allocation_source'] ?? ''),
				);
			}
			return array('ok' => false, 'error' => (string)($allocatedBlock['error'] ?? 'No available port blocks.'));
		}

		$ipIds = billing_get_remote_ip_ids($db, $db_prefix, $remote_server_id);
		billing_provision_trace('Resolved remote server IP IDs for port allocation.', array(
			'home_id' => $home_id,
			'selected_remote_server_id' => $remote_server_id,
			'selected_home_cfg_id' => $home_cfg_id,
			'ip_ids' => $ipIds,
		));
		if (empty($ipIds)) {
			billing_provision_trace('Port allocation failed because no remote_server_ips rows were found.', array(
				'home_id' => $home_id,
				'selected_remote_server_id' => $remote_server_id,
				'selected_home_cfg_id' => $home_cfg_id,
			));
			return array('ok' => false, 'error' => "No IP addresses are configured for remote server #{$remote_server_id}.");
		}

		$ips_with_no_range = array();
		$ips_exhausted = array();
		foreach ($ipIds as $ipId) {
			$ranges = $db->resultQuery(
				"SELECT start_port, end_port, port_increment
				 FROM `{$db_prefix}arrange_ports`
				 WHERE ip_id=" . $db->realEscapeSingle($ipId) . "
				   AND home_cfg_id=" . $db->realEscapeSingle($home_cfg_id) . "
				 ORDER BY range_id ASC"
			);
			if (empty($ranges)) {
				$ranges = $db->resultQuery(
					"SELECT start_port, end_port, port_increment
					 FROM `{$db_prefix}arrange_ports`
					 WHERE ip_id=" . $db->realEscapeSingle($ipId) . "
					   AND home_cfg_id=0
					 ORDER BY range_id ASC"
				);
			}
			if (empty($ranges)) {
				$ips_with_no_range[] = $ipId;
				billing_provision_trace('No port range found for current ip_id.', array(
					'home_id' => $home_id,
					'selected_ip_id' => $ipId,
					'selected_home_cfg_id' => $home_cfg_id,
				));
				continue;
			}
			billing_provision_trace('Loaded port ranges for current ip_id.', array(
				'home_id' => $home_id,
				'selected_ip_id' => $ipId,
				'port_ranges_used' => $ranges,
			));

			$usedRows = $db->resultQuery(
				"SELECT port FROM `{$db_prefix}home_ip_ports` WHERE ip_id=" . $db->realEscapeSingle($ipId)
			);
			$usedPorts = array();
			foreach ((array)$usedRows as $usedRow) {
				$usedPorts[intval($usedRow['port'] ?? 0)] = true;
			}

			foreach ((array)$ranges as $range) {
				$start = intval($range['start_port'] ?? 0);
				$end = intval($range['end_port'] ?? 0);
				$increment = max(1, intval($range['port_increment'] ?? 1));
				if ($start <= 0 || $end <= 0 || $start > $end) {
					continue;
				}

				for ($port = $start; $port <= $end; $port += $increment) {
					if (isset($usedPorts[$port])) {
						continue;
					}
					$safeIpId = $db->realEscapeSingle($ipId);
					$safePort = $db->realEscapeSingle($port);
					$safeHome = $db->realEscapeSingle($home_id);
					$insertOk = $db->query(
						"INSERT INTO `{$db_prefix}home_ip_ports` (`ip_id`, `port`, `home_id`)
						 SELECT {$safeIpId}, {$safePort}, {$safeHome}
						 FROM DUAL
						 WHERE NOT EXISTS (
							SELECT 1
							FROM `{$db_prefix}home_ip_ports`
							WHERE ip_id = {$safeIpId}
							  AND port = {$safePort}
						 )"
					);
					billing_provision_trace('Attempted home_ip_ports insert.', array(
						'home_id' => $home_id,
						'selected_ip_id' => $ipId,
						'selected_port' => $port,
						'home_ip_ports_insert_succeeded' => (bool)$insertOk,
					));
					if (!$insertOk) {
						continue;
					}
					$verify = $db->resultQuery(
						"SELECT home_id FROM `{$db_prefix}home_ip_ports`
						 WHERE ip_id = {$safeIpId}
						   AND port = {$safePort}
						   AND home_id = {$safeHome}
						 LIMIT 1"
					);
					if (!empty($verify)) {
						billing_provision_trace('Port allocation succeeded.', array(
							'home_id' => $home_id,
							'selected_ip_id' => $ipId,
							'selected_port' => $port,
							'home_ip_ports_insert_succeeded' => true,
						));
						return array('ok' => true, 'ip_id' => $ipId, 'port' => intval($port));
					}
				}
			}
			$ips_exhausted[] = $ipId;
		}

		if (!empty($ips_with_no_range) && count($ips_with_no_range) === count($ipIds)) {
			billing_provision_trace('Port allocation failed because no matching arrange_ports ranges were found.', array(
				'home_id' => $home_id,
				'selected_remote_server_id' => $remote_server_id,
				'ips_with_no_range' => $ips_with_no_range,
			));
			return array('ok' => false, 'error' => "No port range found for home_cfg_id #{$home_cfg_id} on ip_id(s) [" . implode(',', $ips_with_no_range) . "] for remote server #{$remote_server_id}.");
		}
		billing_provision_trace('Port allocation failed because all ranges were exhausted.', array(
			'home_id' => $home_id,
			'selected_remote_server_id' => $remote_server_id,
			'selected_home_cfg_id' => $home_cfg_id,
			'ips_exhausted' => !empty($ips_exhausted) ? $ips_exhausted : $ipIds,
		));
		return array('ok' => false, 'error' => "No available port in arrange_ports for remote server #{$remote_server_id}, home_cfg_id #{$home_cfg_id}, ip_id(s) [" . implode(',', !empty($ips_exhausted) ? $ips_exhausted : $ipIds) . "].");
	}
}

if (!function_exists('billing_resolve_mod_cfg_id')) {
	function billing_resolve_mod_cfg_id($db, int $home_cfg_id, int $preferred_mod_cfg_id): array
	{
		$mods = $db->getCfgMods($home_cfg_id);
		billing_provision_trace('Loaded config mods for home_cfg_id.', array(
			'selected_home_cfg_id' => $home_cfg_id,
			'preferred_mod_cfg_id' => $preferred_mod_cfg_id,
			'cfg_mod_rows' => $mods,
		));
		if (empty($mods)) {
			billing_provision_trace('No config mods found for home_cfg_id.', array(
				'selected_home_cfg_id' => $home_cfg_id,
			));
			return array('ok' => false, 'error' => "No config_mods rows found for home_cfg_id #{$home_cfg_id}.");
		}

		$first = null;
		$available_mod_cfg_ids = array();
		foreach ((array)$mods as $mod) {
			$modCfgId = intval($mod['mod_cfg_id'] ?? 0);
			if ($modCfgId <= 0) {
				continue;
			}
			$available_mod_cfg_ids[] = $modCfgId;
			if ($first === null) {
				$first = $modCfgId;
			}
			if ($preferred_mod_cfg_id > 0 && $modCfgId === $preferred_mod_cfg_id) {
				billing_provision_trace('Selected preferred mod_cfg_id for provisioning.', array(
					'selected_home_cfg_id' => $home_cfg_id,
					'selected_mod_cfg_id' => $modCfgId,
				));
				return array('ok' => true, 'mod_cfg_id' => $modCfgId);
			}
		}

		if ($first !== null) {
			billing_provision_trace('Selected fallback mod_cfg_id for provisioning.', array(
				'selected_home_cfg_id' => $home_cfg_id,
				'selected_mod_cfg_id' => $first,
			));
			return array('ok' => true, 'mod_cfg_id' => $first);
		}

		billing_provision_trace('No usable mod_cfg_id was found for provisioning.', array(
			'selected_home_cfg_id' => $home_cfg_id,
			'available_mod_cfg_ids' => $available_mod_cfg_ids,
		));
		return array('ok' => false, 'error' => "No usable mod_cfg_id found for home_cfg_id #{$home_cfg_id}. Available mod_cfg_id values: [" . implode(',', $available_mod_cfg_ids) . "].");
	}
}

if (!function_exists('billing_get_home_ip_port')) {
	function billing_get_home_ip_port($db, string $db_prefix, int $home_id): array
	{
		$row = $db->resultQuery(
			"SELECT ip_id, port
			 FROM `{$db_prefix}home_ip_ports`
			 WHERE home_id=" . $db->realEscapeSingle($home_id) . "
			 ORDER BY ip_id ASC, port ASC
			 LIMIT 1"
		);
		if (!empty($row[0])) {
			return array(
				'ok' => true,
				'ip_id' => intval($row[0]['ip_id'] ?? 0),
				'port' => intval($row[0]['port'] ?? 0),
			);
		}
		return array('ok' => false, 'ip_id' => 0, 'port' => 0);
	}
}

if (!function_exists('billing_write_provision_log')) {
	/**
	 * Writes one JSON line per provisioning attempt to modules/billing/logs/provisioning.log.
	 * Fields include order/invoice/user/home/home_cfg/mod/ip/port/mechanism/install_result/error/message.
	 */
	function billing_write_provision_log(array $context): void
	{
		$logDir = __DIR__ . '/logs';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0755, true);
		}
		$status = strtoupper((string)($context['install_result'] ?? 'INFO'));
		$line = '[' . date('Y-m-d H:i:s') . '] [' . $status . '] ' . json_encode($context, JSON_UNESCAPED_SLASHES) . PHP_EOL;
		$result = file_put_contents($logDir . '/provisioning.log', $line, FILE_APPEND | LOCK_EX);
		if ($result === false) {
			error_log('billing_write_provision_log: failed to append provisioning.log');
		}
	}
}

?>
