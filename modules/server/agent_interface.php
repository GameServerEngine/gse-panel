<?php

require_once('modules/server/agent_helpers.php');
require_once('modules/gamemanager/home_handling_functions.php');
require_once('modules/config_games/server_config_parser.php');

function gsp_agent_print_result($result)
{
	if (!is_array($result)) {
		print_failure('Agent returned an invalid response.');
		return;
	}
	$message = isset($result['message']) ? $result['message'] : '';
	if (!empty($result['success'])) {
		print_success(gsp_agent_html($message ?: 'Operation succeeded.'));
	} else {
		print_failure(gsp_agent_html($message ?: 'Operation failed.'));
	}
	echo "<pre class='log'>" . gsp_agent_html(json_encode($result, JSON_PRETTY_PRINT)) . "</pre>";
}

function gsp_agent_home_key($row)
{
	return (int)$row['home_id'] . '-' . (int)$row['mod_id'] . '-' . rawurlencode((string)$row['ip']) . '-' . (int)$row['port'];
}

function gsp_agent_get_server_xml($home)
{
	if (empty($home['home_cfg_file'])) {
		return false;
	}
	return read_server_config(SERVER_CONFIG_LOCATION . "/" . $home['home_cfg_file'], gsp_os_for_install_row($home));
}

function gsp_agent_force_stop_action($db, $remote_server, $remote)
{
	if (empty($_POST['force_stop_home_id']) || empty($_POST['force_stop_ip']) || empty($_POST['force_stop_port']) || empty($_POST['force_stop_confirm'])) {
		return;
	}
	$home_id = (int)$_POST['force_stop_home_id'];
	$ip = trim($_POST['force_stop_ip']);
	$port = (int)$_POST['force_stop_port'];
	$home = $db->getGameHome($home_id);
	if (!$home || (int)$home['remote_server_id'] !== (int)$remote_server['remote_server_id']) {
		print_failure('Force Stop refused: game server does not belong to this agent.');
		return;
	}
	$assigned = $db->getHomeIpPorts($home_id);
	$matched = false;
	foreach ((array)$assigned as $ip_port) {
		if ((string)$ip_port['ip'] === $ip && (int)$ip_port['port'] === $port) {
			$matched = true;
			break;
		}
	}
	if (!$matched) {
		print_failure('Force Stop refused: selected IP/port is not assigned to this game server.');
		return;
	}
	$server_xml = gsp_agent_get_server_xml($home);
	if ($server_xml) {
		$path_error = '';
		$home_with_root = gsp_with_game_root($home, $server_xml, $path_error);
		if ($home_with_root === false) {
			print_failure('Force Stop refused: invalid game directory for this server: ' . gsp_agent_html($path_error));
			return;
		}
		$payload = gsp_lifecycle_stop_payload($home_with_root, $server_xml, $ip, $port, true);
		$result = $remote->remote_force_stop_server($home_id, $ip, $port, $home_with_root['management_home'], $payload);
	} else {
		$result = $remote->remote_force_stop_server($home_id, $ip, $port, clean_path($home['home_path']));
	}
	$db->logger('Admin Force Stop requested for HOME ID:' . $home_id . ' on agent ' . $remote_server['remote_server_id'] . ' target ' . $ip . ':' . $port);
	gsp_agent_print_result($result);
}

function gsp_agent_update_action($db, $remote_server, $remote, $snapshot)
{
	if (empty($_POST['agent_update_confirm'])) {
		return;
	}
	$os = is_array($snapshot) && !empty($snapshot['os']) ? $snapshot['os'] : $remote->what_os();
	$component = gsp_agent_component_from_os($os);
	$payload = gsp_agent_update_payload($db, $component, $os);
	$result = $remote->agent_self_update($payload);
	$db->logger('Admin queued self-update for remote server ID:' . $remote_server['remote_server_id'] . ' component ' . $component);
	if (!empty($result['success'])) {
		$restart_result = $remote->agent_restart();
		$result['restart_requested'] = ($restart_result === 1) ? 1 : 0;
		$result['restart_status'] = ($restart_result === 1) ? 'requested' : 'failed';
		$result['message'] = 'Agent update queued and agent restart requested. The startup wrapper will apply the queued update when agent_auto_update is enabled.';
		$db->logger('Admin requested agent restart after queued self-update for remote server ID:' . $remote_server['remote_server_id']);
	}
	gsp_agent_print_result($result);
}

function exec_gsp_module() {
	global $view;
	global $db;

	$rhost_id = isset($_REQUEST['rhost_id']) ? (int)$_REQUEST['rhost_id'] : 0;
	$remote_server = $db->getRemoteServer($rhost_id);
	if (!$remote_server) {
		print_failure('Remote host not found.');
		return;
	}

	gsp_agent_ensure_snapshot_table($db);
	$remote = gsp_agent_build_remote($remote_server);
	$host_stat = $remote->status_chk();
	$snapshot = gsp_agent_get_snapshot($db, $rhost_id);

	echo "<h2>Agent Interface: " . gsp_agent_html($remote_server['remote_server_name']) . "</h2>";
	echo "<p><a href='?m=server'>[Back to Agents]</a></p>";

	if (isset($_POST['refresh_snapshot'])) {
		$snapshot = gsp_agent_refresh_snapshot($db, $remote_server);
		gsp_agent_print_result(array(
			'success' => !empty($snapshot['success']),
			'status' => isset($snapshot['status']) ? $snapshot['status'] : 'unknown',
			'message' => !empty($snapshot['success']) ? 'Resource snapshot refreshed.' : (isset($snapshot['message']) ? $snapshot['message'] : 'Resource snapshot failed.')
		));
	}

	gsp_agent_force_stop_action($db, $remote_server, $remote);
	gsp_agent_update_action($db, $remote_server, $remote, $snapshot);

	$update_status = null;
	if (isset($_GET['update_status'])) {
		$update_status = $remote->agent_update_status();
	}

	$os = gsp_agent_snapshot_field($snapshot, 'os', '');
	$version = gsp_agent_snapshot_field($snapshot, 'agent_version', '');
	$install_path = gsp_agent_snapshot_field($snapshot, 'install_path', '');
	$cpu_percent = gsp_agent_snapshot_field($snapshot, 'cpu_percent', '');
	$ram_percent = gsp_agent_snapshot_field($snapshot, 'ram_percent', '');
	$disk_percent = gsp_agent_snapshot_field($snapshot, 'disk_percent', '');
	$load_average = gsp_agent_snapshot_field($snapshot, 'load_average', '');
	$snapshot_diagnostic = gsp_agent_snapshot_diagnostic($snapshot);

	echo "<table class='center' style='width:100%;'>";
	echo "<tr><th colspan='4'>Agent Summary</th></tr>";
	echo "<tr><td><b>ID</b></td><td>" . (int)$remote_server['remote_server_id'] . "</td><td><b>Status</b></td><td>" . gsp_agent_status_label($host_stat) . "</td></tr>";
	echo "<tr><td><b>Name</b></td><td>" . gsp_agent_html($remote_server['remote_server_name']) . "</td><td><b>Address</b></td><td>" . gsp_agent_html($remote_server['agent_ip']) . ":" . (int)$remote_server['agent_port'] . "</td></tr>";
	echo "<tr><td><b>OS</b></td><td>" . gsp_agent_html($os ?: 'unknown') . "</td><td><b>Agent Version</b></td><td>" . gsp_agent_html($version ?: 'unknown') . "</td></tr>";
	echo "<tr><td><b>Install Path</b></td><td colspan='3'>" . gsp_agent_html($install_path ?: 'not reported') . "</td></tr>";
	echo "<tr><td><b>Resources</b></td><td colspan='3'>CPU: " . gsp_agent_html(is_numeric($cpu_percent) ? round($cpu_percent, 1) . '%' : 'n/a') .
		" RAM: " . gsp_agent_html(is_numeric($ram_percent) ? round($ram_percent, 1) . '%' : 'n/a') .
		" Disk: " . gsp_agent_html(is_numeric($disk_percent) ? round($disk_percent, 1) . '%' : 'n/a') .
		" Load: " . gsp_agent_html($load_average !== '' ? $load_average : 'n/a') .
		" Snapshot: " . gsp_agent_html(gsp_agent_format_age($snapshot)) . "<br />" . gsp_agent_resource_badges($snapshot) .
		($snapshot_diagnostic !== '' ? "<br />" . $snapshot_diagnostic : '') . "</td></tr>";
	echo "<tr><td><b>Actions</b></td><td colspan='3'>
		<form method='post' style='display:inline;'><button name='refresh_snapshot' value='1'>Refresh Now</button></form>
		<a href='?m=server&amp;p=log&amp;rhost_id=" . (int)$rhost_id . "'>[View Agent Log]</a>
		<a href='?m=server&amp;p=restart&amp;rhost_id=" . (int)$rhost_id . "'>[Restart Agent]</a>
		<a href='?m=server&amp;p=agent_interface&amp;rhost_id=" . (int)$rhost_id . "&amp;update_agent=1'>[Queue Update And Restart]</a>
		<a href='?m=server&amp;p=agent_interface&amp;rhost_id=" . (int)$rhost_id . "&amp;update_status=1'>[View Update Status]</a>
		<a href='?m=server&amp;p=reboot&amp;rhost_id=" . (int)$rhost_id . "'>[Reboot Host]</a>
	</td></tr>";
	echo "</table>";

	if ($update_status !== null) {
		echo "<h3>Agent Update Status</h3>";
		gsp_agent_print_result($update_status);
	}

	if (isset($_GET['update_agent'])) {
		$component = gsp_agent_component_from_os($os);
		echo "<h3>Update Agent</h3>";
		echo "<p class='note'>This queues a restart-time update on this agent and then requests a normal agent restart. The startup wrapper applies the queued update when agent_auto_update is enabled, preserving local config and runtime data.</p>";
		echo "<form method='post' action='?m=server&amp;p=agent_interface&amp;rhost_id=" . (int)$rhost_id . "'>";
		echo "<p>Detected target: " . gsp_agent_html($component) . " (" . gsp_agent_html($os ?: 'OS unknown') . ")</p>";
		echo "<p><label><input type='checkbox' name='agent_update_confirm' value='1' required /> Confirm queued update and agent restart for this host.</label></p>";
		echo "<p><button type='submit'>Queue Update And Restart Agent</button></p>";
		echo "</form>";
	}

	$homes = $db->getGameHomesByRemoteServerId($rhost_id);
	echo "<h3>Managed Game Servers</h3>";
	if (empty($homes)) {
		echo "<p class='note'>No game servers are assigned to this agent.</p>";
		return;
	}

	echo "<table class='center' style='width:100%;'>";
	echo "<tr><th>home_id</th><th>Name / Owner</th><th>Game / Mod</th><th>Address</th><th>Status</th><th>Process Details</th><th>Last Message</th><th>Actions</th></tr>";
	foreach ((array)$homes as $home) {
		$ip = isset($home['ip']) ? $home['ip'] : '';
		$port = isset($home['port']) ? $home['port'] : '';
		$status = array('status' => 'UNKNOWN', 'last_error' => 'Status not checked.');
		$server_xml = gsp_agent_get_server_xml($home);
		if ($host_stat === 1 && $server_xml && $ip !== '' && $port !== '') {
			$status = get_agent_server_status($remote, $server_xml, $home['home_id'], $ip, $port);
		} elseif ($host_stat === 0) {
			$status['last_error'] = 'Agent offline.';
		} elseif ($host_stat === -1) {
			$status['last_error'] = 'Encryption key mismatch.';
		}
		$home_key = (!empty($home['mod_id']) && $ip !== '' && $port !== '') ? gsp_agent_home_key($home) : '';
		$actions = "";
		if ($home_key !== '') {
			$actions .= "<a href='?m=gamemanager&amp;p=log&amp;home_id-mod_id-ip-port=" . $home_key . "'>[View Game Log]</a> ";
			$actions .= "<a href='?m=gamemanager&amp;p=stop&amp;home_id=" . (int)$home['home_id'] . "&amp;mod_id=" . (int)$home['mod_id'] . "&amp;ip=" . rawurlencode($ip) . "&amp;port=" . (int)$port . "'>[Normal Stop]</a> ";
			$actions .= "<a href='?m=gamemanager&amp;p=restart&amp;home_id=" . (int)$home['home_id'] . "&amp;mod_id=" . (int)$home['mod_id'] . "&amp;ip=" . rawurlencode($ip) . "&amp;port=" . (int)$port . "'>[Normal Restart]</a> ";
		}
		if ($ip !== '' && $port !== '') {
			$actions .= "<form method='post' style='display:inline;' onsubmit=\"return confirm('Force Stop will kill the tracked game process/session and any process listening on this game server port. It will not restart the server.');\">";
			$actions .= "<input type='hidden' name='force_stop_home_id' value='" . (int)$home['home_id'] . "' />";
			$actions .= "<input type='hidden' name='force_stop_ip' value='" . gsp_agent_html($ip) . "' />";
			$actions .= "<input type='hidden' name='force_stop_port' value='" . (int)$port . "' />";
			$actions .= "<input type='hidden' name='force_stop_confirm' value='1' />";
			$actions .= "<button type='submit'>Force Stop</button>";
			$actions .= "</form>";
		}
		$details = "process: " . (!empty($status['process_running']) ? 'yes' : 'no') .
			"<br />session: " . (!empty($status['session_running']) ? 'yes' : 'no') .
			"<br />game port: " . (!empty($status['game_port_listening']) ? 'listening' : 'closed') .
			"<br />query port: " . (!empty($status['query_port_listening']) ? 'listening' : 'n/a') .
			"<br />rcon port: " . (!empty($status['rcon_port_listening']) ? 'listening' : 'n/a') .
			"<br />source: " . gsp_agent_html(isset($status['status_source']) ? $status['status_source'] : '') .
			"<br />hint: " . gsp_agent_html(isset($status['hint_state']) ? $status['hint_state'] : '') .
			"<br />hint age: " . gsp_agent_html(isset($status['hint_age_seconds']) && $status['hint_age_seconds'] !== '' ? $status['hint_age_seconds'] . 's' : 'n/a') .
			"<br />autorestart: " . (!empty($status['autorestart_enabled']) ? 'yes' : 'no') .
			"<br />pid metadata: " . (!empty($status['pid_metadata_present']) ? 'present' : 'none') .
			"<br />pid: " . gsp_agent_html(isset($status['pid']) ? $status['pid'] : '') .
			"<br />session: " . gsp_agent_html(isset($status['session_name']) ? $status['session_name'] : '');

		echo "<tr>";
		echo "<td>" . (int)$home['home_id'] . "</td>";
		echo "<td>" . gsp_agent_html($home['home_name']) . "<br />" . gsp_agent_html($home['users_login'] ?: 'owner unknown') . "</td>";
		echo "<td>" . gsp_agent_html($home['game_name']) . "<br />" . gsp_agent_html($home['mod_name'] ?: $home['mod_key']) . "</td>";
		echo "<td>" . gsp_agent_html($ip ?: 'unassigned') . ":" . gsp_agent_html($port ?: 'n/a') . "</td>";
		echo "<td>" . gsp_agent_html(isset($status['status']) ? $status['status'] : 'UNKNOWN') . "</td>";
		echo "<td>" . $details . "</td>";
		echo "<td>" . gsp_agent_html(isset($status['last_error']) ? $status['last_error'] : '') . "</td>";
		echo "<td>" . $actions . "</td>";
		echo "</tr>";
	}
	echo "</table>";
}

?>
