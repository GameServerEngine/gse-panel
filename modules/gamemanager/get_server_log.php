<?php

if (!function_exists('gsp_get_server_log_escape')) {
	function gsp_get_server_log_escape($text)
	{
		return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('gsp_get_server_log_pre')) {
	function gsp_get_server_log_pre($text, $status_code = 200)
	{
		return array(
			'status' => $status_code,
			'body' => "<pre style='background:black;color:white;'>" . gsp_get_server_log_escape($text) . "</pre>",
		);
	}
}

if (!function_exists('gsp_get_server_log_log')) {
	function gsp_get_server_log_log($message)
	{
		error_log('[GSP get_server_log] ' . $message);
	}
}

if (!function_exists('gsp_get_server_log_valid_id')) {
	function gsp_get_server_log_valid_id($value)
	{
		if (!is_scalar($value)) {
			return false;
		}
		$value = trim((string)$value);
		if ($value === '' || !ctype_digit($value)) {
			return false;
		}
		return ((int)$value) > 0;
	}
}

if (!function_exists('gsp_get_server_log_db_is_valid')) {
	function gsp_get_server_log_db_is_valid($candidate)
	{
		if (!is_object($candidate)) {
			return false;
		}
		if (class_exists('GSPDatabase')) {
			return $candidate instanceof GSPDatabase;
		}
		return true;
	}
}

if (!function_exists('gsp_get_server_log_authenticated_user_id')) {
	function gsp_get_server_log_authenticated_user_id($session)
	{
		if (!is_array($session) || empty($session['users_login']) || empty($session['user_id'])) {
			return false;
		}
		return $session['user_id'];
	}
}

if (!function_exists('gsp_get_server_log_ensure_remote_library')) {
	function gsp_get_server_log_ensure_remote_library($panel_root)
	{
		if (class_exists('GSPRemoteLibrary')) {
			return true;
		}
		if (!function_exists('xmlrpc_encode_request') || !function_exists('xmlrpc_decode')) {
			gsp_get_server_log_log('PHP xmlrpc extension is unavailable; cannot load remote library.');
			return false;
		}
		require_once($panel_root . '/includes/lib_remote.php');
		return class_exists('GSPRemoteLibrary');
	}
}

if (!function_exists('gsp_get_server_log_response')) {
	function gsp_get_server_log_response($db, $home_id_input, $mod_id_input, $user_id, $panel_root, $remote_factory = null)
	{
		if (!gsp_get_server_log_db_is_valid($db)) {
			gsp_get_server_log_log('Database connection is unavailable or invalid.');
			return gsp_get_server_log_pre('Unable to connect to the panel database.', 500);
		}
		if (!gsp_get_server_log_valid_id($home_id_input)) {
			return gsp_get_server_log_pre('Missing or invalid home_id.', 400);
		}
		if (!gsp_get_server_log_valid_id($mod_id_input)) {
			return gsp_get_server_log_pre('Missing or invalid mod_id.', 400);
		}

		$home_id = (int)$home_id_input;
		$mod_id = (int)$mod_id_input;
		$is_admin = method_exists($db, 'isAdmin') && $db->isAdmin($user_id);

		if ($is_admin) {
			$home_info = method_exists($db, 'getGameHome') ? $db->getGameHome($home_id) : false;
			if ($home_info === false || !is_array($home_info)) {
				return gsp_get_server_log_pre('Game server home was not found.', 404);
			}
		} else {
			$home_info = method_exists($db, 'getUserGameHome') ? $db->getUserGameHome($user_id, $home_id) : false;
			if ($home_info === false || !is_array($home_info)) {
				return gsp_get_server_log_pre('Access denied.', 403);
			}
		}

		if (!isset($home_info['mods']) || !is_array($home_info['mods']) || !array_key_exists($mod_id, $home_info['mods']) || !is_array($home_info['mods'][$mod_id])) {
			return gsp_get_server_log_pre('Selected mod does not belong to this game server home.', 404);
		}
		if (empty($home_info['home_cfg_file'])) {
			gsp_get_server_log_log('Home ' . $home_id . ' has no home_cfg_file.');
			return gsp_get_server_log_pre('Server configuration is missing.', 500);
		}
			if (!function_exists('read_server_config')) {
				require_once($panel_root . '/modules/config_games/server_config_parser.php');
			}
			if (!function_exists('gsp_with_game_root')) {
				require_once($panel_root . '/includes/game_path_helpers.php');
			}

		$server_xml = read_server_config(SERVER_CONFIG_LOCATION . '/' . $home_info['home_cfg_file'], gsp_os_for_install_row($home_info));
		if ($server_xml === false) {
			gsp_get_server_log_log('Failed to load server XML for home ' . $home_id . '.');
			return gsp_get_server_log_pre('Server configuration is missing or invalid.', 500);
		}
		$path_error = '';
		$home_info = gsp_with_game_root($home_info, $server_xml, $path_error);
		if ($home_info === false) {
			gsp_get_server_log_log('Invalid game root for home ' . $home_id . ': ' . $path_error);
			return gsp_get_server_log_pre('Server game directory is invalid: ' . $path_error, 500);
		}

		$required_home_fields = array('agent_ip', 'agent_port', 'encryption_key', 'timeout', 'home_path', 'home_id');
		foreach ($required_home_fields as $field) {
			if (!array_key_exists($field, $home_info) || $home_info[$field] === '') {
				gsp_get_server_log_log('Home ' . $home_id . ' is missing required field ' . $field . '.');
				return gsp_get_server_log_pre('Server log endpoint is missing required server data.', 500);
			}
		}

		if ($remote_factory !== null) {
			$remote = call_user_func($remote_factory, $home_info);
		} else {
			if (!gsp_get_server_log_ensure_remote_library($panel_root)) {
				return gsp_get_server_log_pre('Unable to load the remote log service.', 500);
			}
			$remote = new GSPRemoteLibrary($home_info['agent_ip'], $home_info['agent_port'], $home_info['encryption_key'], $home_info['timeout']);
		}
		if (!is_object($remote) || !method_exists($remote, 'get_log')) {
			gsp_get_server_log_log('Remote log service is unavailable for home ' . $home_id . '.');
			return gsp_get_server_log_pre('Unable to contact the remote log service.', 500);
		}

		$home_log = '';
		$log_path = null;
		if (isset($server_xml->console_log) && trim((string)$server_xml->console_log) !== '') {
			$mod_key = isset($home_info['mods'][$mod_id]['mod_key']) ? $home_info['mods'][$mod_id]['mod_key'] : '';
			$log_path = preg_replace('/%mod%/i', $mod_key, (string)$server_xml->console_log);
			$log_retval = $remote->get_log(
				GSP_SCREEN_TYPE_HOME,
				$home_info['home_id'],
				clean_path($home_info['game_root']),
				$home_log,
				100,
				$log_path
			);
		} elseif (isset($server_xml->exe_location) && trim((string)$server_xml->exe_location) !== '') {
			$log_retval = $remote->get_log(
				GSP_SCREEN_TYPE_HOME,
				$home_info['home_id'],
				clean_path($home_info['game_root'] . '/' . $server_xml->exe_location),
				$home_log
			);
		} else {
			gsp_get_server_log_log('Home ' . $home_id . ' XML has neither console_log nor exe_location.');
			return gsp_get_server_log_pre('Server log configuration is missing.', 500);
		}

		$lines = explode("\n", (string)$home_log);
		$home_log = implode("\n", array_slice($lines, -40));

		if (is_numeric($log_retval) && (int)$log_retval > 0) {
			return gsp_get_server_log_pre($home_log, 200);
		}

		if ((string)$log_retval === '-8' && $log_path !== null && preg_match('/[*?\[]/', $log_path)) {
			$wildcard_msg = $home_log !== '' ? $home_log : ('No log files matched pattern: ' . $log_path);
			return gsp_get_server_log_pre($wildcard_msg, 200);
		}

		if ((string)$log_retval === '0') {
			return gsp_get_server_log_pre('The Agent that is controlling this server is offline or still starting.', 503);
		}

		gsp_get_server_log_log('Remote get_log failed for home ' . $home_id . ' with retval ' . (string)$log_retval . '.');
		return gsp_get_server_log_pre('Unable to get log: ' . (string)$log_retval, 502);
	}
}

if (!function_exists('gsp_get_server_log_fatal_shutdown')) {
	function gsp_get_server_log_fatal_shutdown()
	{
		if (!empty($GLOBALS['gsp_get_server_log_completed'])) {
			return;
		}
		$error = error_get_last();
		$fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (is_array($error) && in_array($error['type'], $fatal_types)) {
			gsp_get_server_log_log('Fatal endpoint failure: ' . $error['message']);
			while (ob_get_level() > (int)$GLOBALS['gsp_get_server_log_ob_level']) {
				ob_end_clean();
			}
			if (!headers_sent()) {
				header('Content-Type: text/html; charset=UTF-8', true, 500);
			}
			$response = gsp_get_server_log_pre('Unable to load the server log right now.', 500);
			echo $response['body'];
		}
	}
}

if (!function_exists('gsp_get_server_log_main')) {
	function gsp_get_server_log_main()
	{
		$GLOBALS['gsp_get_server_log_completed'] = false;
		$GLOBALS['gsp_get_server_log_ob_level'] = ob_get_level();
		ob_start();
		register_shutdown_function('gsp_get_server_log_fatal_shutdown');

		$panel_root = realpath(__DIR__ . '/../..');
		if ($panel_root === false) {
			gsp_get_server_log_log('Unable to resolve panel root.');
			$response = gsp_get_server_log_pre('Panel path could not be resolved.', 500);
			http_response_code($response['status']);
			echo $response['body'];
			$GLOBALS['gsp_get_server_log_completed'] = true;
			ob_end_flush();
			return;
		}
		chdir($panel_root);

		require_once($panel_root . '/includes/functions.php');
		require_once($panel_root . '/includes/helpers.php');
		startSession();

		if (!defined('IMAGES')) {
			define('IMAGES', 'images/');
		}
		if (!defined('INCLUDES')) {
			define('INCLUDES', 'includes/');
		}
		if (!defined('MODULES')) {
			define('MODULES', 'modules/');
		}
		if (!defined('CONFIG_FILE')) {
			define('CONFIG_FILE', $panel_root . '/includes/config.inc.php');
		}
		if (!is_readable(CONFIG_FILE)) {
			gsp_get_server_log_log('Panel config file is missing or unreadable.');
			$response = gsp_get_server_log_pre('Panel configuration is unavailable.', 500);
			http_response_code($response['status']);
			echo $response['body'];
			$GLOBALS['gsp_get_server_log_completed'] = true;
			ob_end_flush();
			return;
		}

		require_once(CONFIG_FILE);
		require_once($panel_root . '/modules/config_games/server_config_parser.php');

		if (!headers_sent()) {
			header('Content-Type: text/html; charset=UTF-8');
		}

		$session_user_id = gsp_get_server_log_authenticated_user_id($_SESSION);
		if ($session_user_id === false) {
			$response = gsp_get_server_log_pre('Authentication required.', 401);
			http_response_code($response['status']);
			echo $response['body'];
			$GLOBALS['gsp_get_server_log_completed'] = true;
			ob_end_flush();
			return;
		}

		global $db, $db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, $db_port;
		if (!isset($db) || !gsp_get_server_log_db_is_valid($db)) {
			$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : null);
		}

		$response = gsp_get_server_log_response(
			$db,
			isset($_GET['home_id']) ? $_GET['home_id'] : null,
			isset($_GET['mod_id']) ? $_GET['mod_id'] : null,
			$session_user_id,
			$panel_root
		);
		http_response_code($response['status']);
		echo $response['body'];
		$GLOBALS['gsp_get_server_log_completed'] = true;
		ob_end_flush();
	}
}

if (!defined('GSP_GET_SERVER_LOG_SKIP_MAIN')) {
	gsp_get_server_log_main();
}

?>
