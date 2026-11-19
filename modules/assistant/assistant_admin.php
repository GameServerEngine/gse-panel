<?php
/*
 * Read-only diagnostics for the Admin assistant. Nothing here writes. MySQL checks read
 * server status and the size of each database from information_schema, never table rows.
 * Passwords and keys are never part of what is returned.
 */

require_once(__DIR__ . '/assistant_manage.php');

function assistant_admin_tool($db, $name, $panel_root)
{
	if ($name === 'panel_health') {
		return array('data' => assistant_admin_panel_health($db, $panel_root));
	}
	if ($name === 'mysql_health') {
		return array('data' => assistant_admin_mysql_health($db));
	}
	if ($name === 'game_databases') {
		return array('data' => assistant_admin_game_databases($db));
	}
	if ($name === 'agents_health') {
		return array('data' => assistant_admin_agents_health($db, $panel_root));
	}
	return array('data' => array('error' => 'Unknown tool.'));
}

function assistant_admin_count($db, $table)
{
	$rows = $db->resultQuery('SELECT COUNT(*) AS n FROM ' . assistant_tn($table));
	return isset($rows[0]['n']) ? (int)$rows[0]['n'] : null;
}

function assistant_admin_panel_health($db, $panel_root)
{
	if (file_exists($panel_root . '/includes/panel_version.php')) {
		require_once($panel_root . '/includes/panel_version.php');
	}
	$report = array(
		'panel_version' => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
		'php_version' => PHP_VERSION,
		'disk_free_gb' => round(disk_free_space($panel_root) / 1073741824, 1),
		'disk_total_gb' => round(disk_total_space($panel_root) / 1073741824, 1),
		'users' => assistant_admin_count($db, 'users'),
		'game_servers' => assistant_admin_count($db, 'server_homes'),
		'agents_registered' => assistant_admin_count($db, 'remote_servers'),
	);
	$rows = $db->resultQuery('SELECT VERSION() AS v');
	$report['panel_database_version'] = isset($rows[0]['v']) ? $rows[0]['v'] : 'unknown';

	$log = ini_get('error_log');
	if ($log && is_readable($log)) {
		$size = filesize($log);
		$fh = fopen($log, 'r');
		fseek($fh, max(0, $size - 65536));
		$lines = preg_split('/\r\n|\r|\n/', (string)stream_get_contents($fh));
		fclose($fh);
		$hits = array();
		foreach ($lines as $line) {
			if (preg_match('/PHP (Fatal|Warning|Parse)|GSP/i', $line)) {
				$hits[] = substr($line, 0, 300);
			}
		}
		$report['recent_php_errors'] = '<log_data>' . implode("\n", array_slice($hits, -15)) . '</log_data>';
	} else {
		$report['recent_php_errors'] = 'The PHP error log is not readable from the panel.';
	}
	return $report;
}

// Status numbers from one connection. $link is a mysqli link.
function assistant_admin_server_numbers($link)
{
	$out = array('version' => assistant_mysql_value($link, 'SELECT VERSION()'));
	$res = @mysqli_query($link, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Max_used_connections','Aborted_connects','Slow_queries')");
	while ($res && ($row = mysqli_fetch_row($res))) {
		$out[strtolower($row[0])] = (int)$row[1];
	}
	$res = @mysqli_query($link, "SHOW VARIABLES LIKE 'max_connections'");
	if ($res && ($row = mysqli_fetch_row($res))) {
		$out['max_connections'] = (int)$row[1];
	}
	return $out;
}

// Size in MB of every database on a server (names and sizes only).
function assistant_admin_sizes($link)
{
	$sizes = array();
	$res = @mysqli_query($link, "SELECT table_schema, ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema NOT IN ('information_schema','performance_schema','mysql','sys') GROUP BY table_schema");
	while ($res && ($row = mysqli_fetch_row($res))) {
		$sizes[$row[0]] = (float)$row[1];
	}
	return $sizes;
}

function assistant_admin_mysql_servers($db)
{
	$rows = $db->resultQuery('SELECT mysql_server_id, mysql_name, mysql_ip, mysql_port, mysql_admin_user, mysql_root_passwd FROM ' . assistant_tn('mysql_servers'));
	return is_array($rows) ? $rows : array();
}

function assistant_admin_mysql_health($db)
{
	global $db_host, $db_user, $db_pass, $db_name, $db_port;
	$report = array();
	$panel = assistant_mysql_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? $db_port : 3306);
	if ($panel) {
		$numbers = assistant_admin_server_numbers($panel);
		$numbers['panel_database_size_mb'] = (float)assistant_mysql_value($panel, 'SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()');
		$report['panel_database'] = $numbers;
		mysqli_close($panel);
	} else {
		$report['panel_database'] = array('error' => 'The assistant could not open its own connection to the panel database.');
	}
	$report['mysql_servers'] = array();
	foreach (assistant_admin_mysql_servers($db) as $s) {
		$entry = array('mysql_server_id' => (int)$s['mysql_server_id'], 'name' => $s['mysql_name'], 'address' => $s['mysql_ip'] . ':' . $s['mysql_port']);
		$link = assistant_mysql_connect($s['mysql_ip'], !empty($s['mysql_admin_user']) ? $s['mysql_admin_user'] : 'root', $s['mysql_root_passwd'], '', $s['mysql_port']);
		if ($link) {
			$entry['reachable'] = true;
			$entry += assistant_admin_server_numbers($link);
			$entry['database_sizes_mb'] = array_slice(assistant_admin_sizes($link), 0, 40, true);
			mysqli_close($link);
		} else {
			$entry['reachable'] = false;
			$entry['problem'] = 'Could not log in with the admin account saved in the panel (server down, firewall, or the password changed).';
		}
		$report['mysql_servers'][] = $entry;
	}
	return $report;
}

function assistant_admin_game_databases($db)
{
	$sizes = array();
	foreach (assistant_admin_mysql_servers($db) as $s) {
		$link = assistant_mysql_connect($s['mysql_ip'], !empty($s['mysql_admin_user']) ? $s['mysql_admin_user'] : 'root', $s['mysql_root_passwd'], '', $s['mysql_port']);
		if ($link) {
			$sizes[$s['mysql_server_id']] = assistant_admin_sizes($link);
			mysqli_close($link);
		}
	}
	$rows = $db->resultQuery('SELECT d.db_id, d.home_id, d.db_name, d.db_user, d.enabled, d.mysql_server_id, s.mysql_name, h.home_name FROM ' . assistant_tn('mysql_databases')
		. ' d LEFT JOIN ' . assistant_tn('mysql_servers') . ' s ON s.mysql_server_id = d.mysql_server_id LEFT JOIN ' . assistant_tn('server_homes') . ' h ON h.home_id = d.home_id ORDER BY d.db_id LIMIT 100');
	$out = array();
	foreach ((array)$rows as $r) {
		$size = isset($sizes[$r['mysql_server_id']][$r['db_name']]) ? $sizes[$r['mysql_server_id']][$r['db_name']] : null;
		$out[] = array(
			'db_id' => (int)$r['db_id'], 'game_server' => $r['home_name'], 'home_id' => (int)$r['home_id'], 'database' => $r['db_name'],
			'user' => $r['db_user'], 'mysql_server' => $r['mysql_name'], 'enabled' => (int)$r['enabled'] === 1,
			'size_mb' => $size === null ? 'unknown (server unreachable or database missing)' : $size,
		);
	}
	return array('databases' => $out);
}

function assistant_admin_agents_health($db, $panel_root)
{
	require_once($panel_root . '/includes/lib_remote.php');
	$out = array();
	foreach ((array)$db->getRemoteServers() as $r) {
		$entry = array('id' => (int)$r['remote_server_id'], 'name' => $r['remote_server_name'], 'address' => $r['agent_ip'] . ':' . $r['agent_port'], 'os' => $r['server_os'], 'enabled' => (int)$r['enabled'] === 1);
		$remote = new RemoteLibrary($r['agent_ip'], $r['agent_port'], $r['encryption_key'], 4);
		$start = microtime(true);
		$online = $remote->status_chk() === 1;
		$entry['online'] = $online;
		$entry['response_ms'] = (int)round((microtime(true) - $start) * 1000);
		if ($online) {
			$version = $remote->agent_version();
			$entry['agent_version'] = is_scalar($version) ? (string)$version : json_encode($version);
		}
		$out[] = $entry;
	}
	return array('agents' => $out);
}
?>
