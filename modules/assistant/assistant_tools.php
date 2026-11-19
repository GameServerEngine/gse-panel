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
	return array('data' => array('filter' => $filter, 'log' => '<log_data>' . $lines . '</log_data>'));
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

// What each scope may call. Anything else is refused before it reaches a function.
function assistant_allowed_tools($scope_type)
{
	if ($scope_type === 'admin') {
		return array('list_servers', 'server_status', 'server_log', 'panel_health', 'mysql_health', 'game_databases', 'agents_health');
	}
	return array('server_status', 'server_log', 'database_info', 'ftp_info', 'propose_action', 'propose_reset');
}

// $scope is array('type' => 'server', 'home_id' => n) or array('type' => 'admin').
// In a server scope the server id comes from the scope, never from the model.
function assistant_run_tool($db, $user_id, $panel_root, $name, $input, $scope)
{
	$type = isset($scope['type']) ? $scope['type'] : 'server';
	if (!in_array($name, assistant_allowed_tools($type), true)) {
		return array('data' => array('error' => 'Unknown tool.'));
	}
	if ($type === 'admin' && !$db->isAdmin($user_id)) {
		return array('data' => array('error' => 'Not allowed.'));
	}
	$home_id = $type === 'server' ? (int)$scope['home_id'] : (isset($input['home_id']) ? (int)$input['home_id'] : 0);
	$filter = isset($input['filter']) ? (string)$input['filter'] : 'recent';

	switch ($name) {
		case 'list_servers':
			return assistant_tool_list_servers($db, $user_id);
		case 'server_status':
			return assistant_tool_server_status($db, $user_id, $home_id, $panel_root);
		case 'server_log':
			return assistant_tool_server_log($db, $user_id, $home_id, $filter, $panel_root);
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
		default:
			require_once(__DIR__ . '/assistant_admin.php');
			return assistant_admin_tool($db, $name, $panel_root);
	}
}
?>
