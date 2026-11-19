<?php
/*
 * The four tools the assistant can use. Each one first checks that the logged-in
 * customer owns the server (admins may use any server). Nothing here changes a server:
 * propose_action only returns a link to the panel's own Start / Stop / Restart page,
 * and the customer has to press it.
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
	$rows = $db->getIpPortsForUser($user_id);
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

// The only way the model reaches the tools. Unknown names are refused.
function assistant_run_tool($db, $user_id, $panel_root, $name, $input)
{
	$home_id = isset($input['home_id']) ? (int)$input['home_id'] : 0;
	if ($name === 'list_servers') {
		return assistant_tool_list_servers($db, $user_id);
	}
	if ($name === 'server_status') {
		return assistant_tool_server_status($db, $user_id, $home_id, $panel_root);
	}
	if ($name === 'server_log') {
		$filter = isset($input['filter']) ? (string)$input['filter'] : 'recent';
		return assistant_tool_server_log($db, $user_id, $home_id, $filter, $panel_root);
	}
	if ($name === 'propose_action') {
		$action = isset($input['action']) ? (string)$input['action'] : '';
		return assistant_tool_propose_action($db, $user_id, $home_id, $action);
	}
	return array('data' => array('error' => 'Unknown tool.'));
}
?>
