<?php
/*
 * The tools the assistant can use. A customer's chat is tied to one server (the scope), and every
 * tool checks again that the logged-in customer owns it (admins may use any server). Nothing here
 * changes anything: propose_action returns a link to the panel's own Start / Stop / Restart page and
 * propose_reset returns a button; the customer has to press it (see confirm.php).
 */

require_once(__DIR__ . '/assistant_lib.php');

// Returns array(home_info, '') when allowed, or array(null, 'reason') when not.
function assistant_open_home($db, $user_id, $home_id)
{
	$home_id = (int)$home_id;
	if ($home_id < 1) {
		return array(null, 'Missing or invalid server id.');
	}
	if ($db->isAdmin($user_id)) {
		$owned = $db->getGameHome($home_id);
	} else {
		$owned = $db->getUserGameHome($user_id, $home_id);
	}
	if (!is_array($owned)) {
		return array(null, 'That server is not on this account.');
	}
	// Ownership is proven; now read the address too.
	$home = $db->getGameHome($home_id, true);
	if (!is_array($home) || empty($home['mods'])) {
		return array(null, 'Server details are not available.');
	}
	return array($home, '');
}

function assistant_tool_list_servers($db, $user_id)
{
	$rows = $db->isAdmin($user_id) ? $db->getIpPorts() : $db->getIpPortsForUser($user_id);
	$servers = array();
	foreach ((array)$rows as $row) {
		$servers[$row['home_id']] = array(
			'home_id' => (int)$row['home_id'],
			'name' => $row['home_name'],
			'address' => $row['ip'] . ':' . $row['port'],
			'game' => isset($row['game_name']) ? $row['game_name'] : '',
		);
	}
	return array('data' => array('servers' => array_values($servers)));
}

function assistant_tool_server_status($db, $user_id, $home_id, $panel_root)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	require_once($panel_root . '/includes/lib_remote.php');
	require_once($panel_root . '/includes/lifecycle_contract_helpers.php');
	require_once($panel_root . '/modules/config_games/server_config_parser.php');
	require_once($panel_root . '/includes/game_path_helpers.php');

	$server_xml = read_server_config(SERVER_CONFIG_LOCATION . '/' . $home['home_cfg_file'], os_for_install_row($home));
	if ($server_xml === false) {
		return array('data' => array('error' => 'Server configuration is missing.'));
	}
	$remote = new RemoteLibrary($home['agent_ip'], $home['agent_port'], $home['encryption_key'], $home['timeout']);
	if ($remote->status_chk() !== 1) {
		return array('data' => array('error' => 'The machine this server runs on is not answering.'));
	}
	$status = lifecycle_collect_agent_status($remote, $server_xml, $home['home_id'], $home['ip'], $home['port']);
	$report = array();
	foreach (array('status', 'ready', 'process_running', 'game_port_listening', 'query_port_listening', 'last_error') as $field) {
		$report[$field] = isset($status[$field]) ? $status[$field] : '';
	}
	return array('data' => $report);
}

function assistant_tool_server_log($db, $user_id, $home_id, $filter, $panel_root)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	if (!defined('GET_SERVER_LOG_SKIP_MAIN')) {
		define('GET_SERVER_LOG_SKIP_MAIN', true);
	}
	require_once($panel_root . '/modules/gamemanager/get_server_log.php');
	$mod_id = (int)key($home['mods']);
	$response = get_server_log_response($db, $home['home_id'], $mod_id, $user_id, $panel_root);
	if ($response['status'] !== 200) {
		return array('data' => array('error' => 'Could not read the log (code ' . $response['status'] . ').'));
	}
	// The panel wraps the log in a <pre> block. Take the text back out.
	$text = html_entity_decode(strip_tags($response['body']), ENT_QUOTES, 'UTF-8');
	$lines = assistant_filter_log($text, $filter);
	require_once(__DIR__ . '/assistant_diff.php');
	return array('data' => array('filter' => $filter, 'log' => assistant_wrap('log_data', assistant_redact($lines))));
}

function assistant_tool_propose_action($db, $user_id, $home_id, $action)
{
	if (!in_array($action, array('start', 'stop', 'restart'), true)) {
		return array('data' => array('error' => 'Unknown action.'));
	}
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	$mod_id = (int)key($home['mods']);
	$url = 'home.php?m=gamemanager&p=' . $action
		. '&home_id=' . (int)$home['home_id'] . '&mod_id=' . $mod_id
		. '&ip=' . rawurlencode($home['ip']) . '&port=' . (int)$home['port'];
	$label = ucfirst($action) . ' ' . $home['home_name'];
	return array(
		'data' => array('ok' => true, 'note' => 'A "' . $label . '" button is shown to the customer. Nothing has happened yet.'),
		'button' => array('label' => $label, 'url' => $url),
	);
}

// Sends one push notification to an ntfy topic. $prefix is put in front of the message (the server's name).
// Returns array('data' => ...). Never throws: a failed notification is reported to the model, not raised.
function assistant_send_ntfy($db, $panel_root, $topic, $input, $prefix)
{
	require_once($panel_root . '/modules/notifications/ntfy_helpers.php');
	$settings = $db->getSettings();
	$base = isset($settings['ntfy_base_url']) ? trim((string)$settings['ntfy_base_url']) : '';
	if ($base === '') {
		return array('data' => array('error' => 'The ntfy server is not set up on this panel (Notifications settings).'));
	}
	// Header values must be one plain line.
	$title = preg_replace('/[^\x20-\x7E]/', ' ', isset($input['title']) ? (string)$input['title'] : '');
	$title = trim(substr($title, 0, 80));
	$message = trim(substr(isset($input['message']) ? (string)$input['message'] : '', 0, 500));
	if ($message === '') {
		return array('data' => array('error' => 'The message is empty.'));
	}
	$priority = isset($input['priority']) && in_array($input['priority'], array('low', 'default', 'high'), true) ? $input['priority'] : 'default';
	$request = ntfy_build_request($base, $topic, $title === '' ? 'Ask Dave' : $title, $prefix . $message, $priority, array('speech_balloon'));
	if (!ntfy_send_request($request)) {
		return array('data' => array('error' => 'The ntfy server did not accept the notification.'));
	}
	return array('data' => array('ok' => true, 'sent_to_topic' => $topic));
}

// The topic is made from the server's id, so the model cannot pick another one.
function assistant_tool_notify_server($db, $user_id, $home_id, $input, $panel_root)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	require_once($panel_root . '/modules/notifications/ntfy_helpers.php');
	return assistant_send_ntfy($db, $panel_root, ntfy_topic_for_home((int)$home['home_id']), $input, '[' . $home['home_name'] . '] ');
}

function assistant_tool_notify_admins($db, $input, $panel_root)
{
	require_once($panel_root . '/modules/notifications/ntfy_helpers.php');
	return assistant_send_ntfy($db, $panel_root, NTFY_ADMIN_TOPIC, $input, '');
}

// $scope is array('type' => 'server', 'home_id' => n) or array('type' => 'admin').
// In a server scope the server id comes from the scope, never from the model.
// $cfg (assistant_config) switches groups of tools off; the allowed list is built from the catalog.
function assistant_run_tool($db, $user_id, $panel_root, $name, $input, $scope, $cfg = null)
{
	$type = isset($scope['type']) ? $scope['type'] : 'server';
	if (!in_array($name, assistant_allowed_tools($type, $cfg), true)) {
		return array('data' => array('error' => 'Unknown tool.'));
	}
	if ($type === 'admin' && !$db->isAdmin($user_id)) {
		return array('data' => array('error' => 'Not allowed.'));
	}
	$home_id = $type === 'server' ? (int)$scope['home_id'] : (isset($input['home_id']) ? (int)$input['home_id'] : 0);
	$filter = isset($input['filter']) ? (string)$input['filter'] : 'recent';
	$result = assistant_dispatch_tool($db, $user_id, $panel_root, $name, $input, $home_id, $filter, $cfg);
	// In the admin chat a button must say which server it is for (a customer's scope already knows).
	if ($type === 'admin' && $home_id > 0 && isset($result['button']['type']) && $result['button']['type'] === 'reset') {
		$result['button']['home_id'] = $home_id;
	}
	return $result;
}

function assistant_dispatch_tool($db, $user_id, $panel_root, $name, $input, $home_id, $filter, $cfg)
{
	switch ($name) {
		case 'list_servers':
			return assistant_tool_list_servers($db, $user_id);
		case 'server_status':
			return assistant_tool_server_status($db, $user_id, $home_id, $panel_root);
		case 'server_log':
			return assistant_tool_server_log($db, $user_id, $home_id, $filter, $panel_root);
		case 'notify_server':
			return assistant_tool_notify_server($db, $user_id, $home_id, $input, $panel_root);
		case 'notify_admins':
			return $db->isAdmin($user_id) ? assistant_tool_notify_admins($db, $input, $panel_root) : array('data' => array('error' => 'Not allowed.'));
		case 'propose_action':
			return assistant_tool_propose_action($db, $user_id, $home_id, isset($input['action']) ? (string)$input['action'] : '');
		case 'database_info':
		case 'ftp_info':
		case 'propose_reset':
			require_once(__DIR__ . '/assistant_manage.php');
			if ($name === 'database_info') {
				return assistant_tool_database_info($db, $user_id, $home_id);
			}
			if ($name === 'ftp_info') {
				return assistant_tool_ftp_info($db, $user_id, $home_id, $panel_root);
			}
			return assistant_tool_propose_reset($db, $user_id, $home_id, isset($input['what']) ? (string)$input['what'] : '', isset($input['db_id']) ? (int)$input['db_id'] : 0);
		case 'list_files':
		case 'read_file':
		case 'db_query':
		case 'propose_file_edit':
			require_once(__DIR__ . '/assistant_files.php');
			if ($name === 'list_files') {
				return assistant_tool_list_files($db, $user_id, $home_id, isset($input['path']) ? (string)$input['path'] : '', $panel_root);
			}
			if ($name === 'read_file') {
				return assistant_tool_read_file($db, $user_id, $home_id, $input, $panel_root);
			}
			if ($name === 'db_query') {
				return assistant_tool_db_query($db, $user_id, $home_id, $input, $panel_root);
			}
			return assistant_tool_propose_file_edit($db, $user_id, $home_id, $input, $panel_root);
		case 'propose_changes':
		case 'db_read':
			require_once(__DIR__ . '/assistant_admin_fix.php');
			if ($name === 'db_read') {
				return assistant_tool_db_read($db, $input);
			}
			return assistant_tool_propose_changes($db, $user_id, $panel_root, $input);
		case 'panel_list':
		case 'panel_read':
		case 'panel_search':
		case 'agent_list':
		case 'agent_read':
		case 'propose_patch':
			require_once(__DIR__ . '/assistant_admin_files.php');
			$cfg = $cfg === null ? assistant_config($db->getSettings()) : $cfg;
			if ($name === 'panel_list') {
				return assistant_tool_panel_list($panel_root, $cfg, $input);
			}
			if ($name === 'panel_read') {
				return assistant_tool_panel_read($panel_root, $cfg, $input);
			}
			if ($name === 'panel_search') {
				return assistant_tool_panel_search($panel_root, $cfg, $input);
			}
			if ($name === 'agent_list') {
				return assistant_tool_agent_list($db, $panel_root, $cfg, $input);
			}
			if ($name === 'agent_read') {
				return assistant_tool_agent_read($db, $panel_root, $cfg, $input);
			}
			return assistant_tool_propose_patch($db, $user_id, $panel_root, $cfg, $input);
		default:
			require_once(__DIR__ . '/assistant_admin.php');
			return assistant_admin_tool($db, $name, $panel_root);
	}
}
?>
