<?php
/*
 * Builds what includes/diagnostics/runner.php needs from the panel session and database:
 * who the user is, which agents and server addresses they may use, the settings.
 * The diagnostics code itself never reads $_SESSION or the database.
 */
require_once 'includes/lib_remote.php';
require_once 'includes/diagnostics/runner.php';
require_once 'includes/diagnostics/csrf.php';

// Game server rows the user may see: each has remote_server_id, ip, port, home_id, home_name, game_key.
function util_diag_server_rows($db, $user_id)
{
	$rows = $db->getIpPortsForUser($user_id);
	return is_array($rows) ? $rows : array();
}

function util_diag_all_agents($db)
{
	$agents = array();
	foreach ((array)$db->getRemoteServers() as $server) {
		$agents[(int)$server['remote_server_id']] = $server;
	}
	return $agents;
}

// Admins may use every agent. Others only agents that host one of their game servers.
function util_diag_agent_ids($db, $user_id, $role, $server_rows)
{
	if ($role === 'admin') {
		return array_keys(util_diag_all_agents($db));
	}
	$ids = array();
	foreach ($server_rows as $row) {
		$ids[(int)$row['remote_server_id']] = true;
	}
	return array_keys($ids);
}

function util_diag_own_ips($server_rows)
{
	$ips = array();
	foreach ($server_rows as $row) {
		$packed = @inet_pton((string)$row['ip']);
		if ($packed !== false) {
			$ips[] = inet_ntop($packed);
		}
	}
	return $ips;
}

function util_diag_agent_factory($db)
{
	return function ($agent_id, $timeout_s) use ($db) {
		$server = $db->getRemoteServerById($agent_id);
		return new RemoteLibrary($server['agent_ip'], $server['agent_port'], $server['encryption_key'], $timeout_s);
	};
}

function util_diag_env($db)
{
	$settings = $db->getSettings();
	$user = $db->getUserById($_SESSION['user_id']);
	$role = $user['users_role'];
	$rows = util_diag_server_rows($db, $_SESSION['user_id']);
	return array(
		'user_id' => (int)$_SESSION['user_id'],
		'role' => $role,
		'client_ip' => getClientIPAddress(),
		'now' => time(),
		'store' => new DiagAuditDb($db),
		'limits' => diag_limit_settings($settings),
		'policy' => diag_policy_context($role, util_diag_own_ips($rows), $settings),
		'agent_ids' => util_diag_agent_ids($db, $_SESSION['user_id'], $role, $rows),
		'agent' => util_diag_agent_factory($db),
		'speed_endpoints' => diag_load_speed_endpoints(isset($settings['diag_speed_endpoints']) ? $settings['diag_speed_endpoints'] : ''),
		'rows' => $rows,
	);
}
