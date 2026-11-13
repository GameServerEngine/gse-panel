<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 * Port block allocator administration for GSE Phase 2 testing.
 *
 */

if (!function_exists('port_blocks_h')) {
	function port_blocks_h($value)
	{
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('port_blocks_rows')) {
	function port_blocks_rows($db, $query)
	{
		$rows = $db->resultQuery($query);
		return is_array($rows) ? $rows : array();
	}
}

if (!function_exists('port_blocks_table_exists')) {
	function port_blocks_table_exists($db, $table)
	{
		$table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
		$rows = $db->resultQuery("SHOW TABLES LIKE 'DB_PREFIX" . $table . "'");
		return is_array($rows) && count($rows) > 0;
	}
}

if (!function_exists('port_blocks_schema_status')) {
	function port_blocks_schema_status($db)
	{
		$required = array(
			'port_pools',
			'port_pool_games',
			'game_port_offsets',
			'static_port_reservations',
			'home_port_blocks',
		);
		$missing = array();
		foreach ($required as $table) {
			if (!port_blocks_table_exists($db, $table)) {
				$missing[] = 'DB_PREFIX' . $table;
			}
		}
		return array(
			'ok' => empty($missing),
			'missing' => $missing,
			'events' => port_blocks_table_exists($db, 'port_allocation_events'),
		);
	}
}

if (!function_exists('port_blocks_post_string')) {
	function port_blocks_post_string($key, $max = 255)
	{
		$value = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
		if ($max > 0 && strlen($value) > $max) {
			$value = substr($value, 0, $max);
		}
		return $value;
	}
}

if (!function_exists('port_blocks_post_int')) {
	function port_blocks_post_int($key, $default = 0)
	{
		if (!isset($_POST[$key]) || $_POST[$key] === '') {
			return $default;
		}
		return intval($_POST[$key]);
	}
}

if (!function_exists('port_blocks_flag')) {
	function port_blocks_flag($key)
	{
		return !empty($_POST[$key]) ? 1 : 0;
	}
}

if (!function_exists('port_blocks_validate_port')) {
	function port_blocks_validate_port($port)
	{
		$port = intval($port);
		return $port >= 1 && $port <= 65535;
	}
}

if (!function_exists('port_blocks_validate_range')) {
	function port_blocks_validate_range($start, $end, $block_size, &$error)
	{
		$start = intval($start);
		$end = intval($end);
		$block_size = intval($block_size);
		if (!port_blocks_validate_port($start) || !port_blocks_validate_port($end)) {
			$error = 'Ports must be integers between 1 and 65535.';
			return false;
		}
		if ($start > $end) {
			$error = 'Range start must be less than or equal to range end.';
			return false;
		}
		if ($block_size <= 0) {
			$error = 'Block size must be greater than zero.';
			return false;
		}
		if (($end - $start + 1) < $block_size) {
			$error = 'The pool range must hold at least one complete block.';
			return false;
		}
		return true;
	}
}

if (!function_exists('port_blocks_value_in')) {
	function port_blocks_value_in($value, $allowed, $default)
	{
		return in_array($value, $allowed, true) ? $value : $default;
	}
}

if (!function_exists('port_blocks_id_exists')) {
	function port_blocks_id_exists($db, $table, $column, $id)
	{
		$table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
		$column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
		$id = intval($id);
		if ($id <= 0) {
			return false;
		}
		$rows = port_blocks_rows($db, "SELECT `{$column}` FROM `DB_PREFIX{$table}` WHERE `{$column}`={$id} LIMIT 1");
		return !empty($rows);
	}
}

if (!function_exists('port_blocks_csrf_token')) {
	function port_blocks_csrf_token()
	{
		// Keep the last 10 tokens issued in this session, so an older open tab still submits.
		if (!isset($_SESSION['port_blocks_csrf_list']) || !is_array($_SESSION['port_blocks_csrf_list'])) {
			$_SESSION['port_blocks_csrf_list'] = array();
		}
		$token = sha1(uniqid('', true) . mt_rand());
		$_SESSION['port_blocks_csrf_list'][] = $token;
		$_SESSION['port_blocks_csrf_list'] = array_slice($_SESSION['port_blocks_csrf_list'], -10);
		$_SESSION['port_blocks_csrf'] = $token;
		return $token;
	}
}

if (!function_exists('port_blocks_csrf_valid')) {
	function port_blocks_csrf_valid()
	{
		if (!isset($_POST['port_blocks_token']) || !isset($_SESSION['port_blocks_csrf_list']) || !is_array($_SESSION['port_blocks_csrf_list'])) {
			return false;
		}
		$given = (string)$_POST['port_blocks_token'];
		foreach ($_SESSION['port_blocks_csrf_list'] as $known) {
			if (hash_equals((string)$known, $given)) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('port_blocks_token_input')) {
	function port_blocks_token_input()
	{
		return "<input type='hidden' name='port_blocks_token' value='" . port_blocks_h(port_blocks_csrf_token()) . "' />";
	}
}

if (!function_exists('port_blocks_audit')) {
	function port_blocks_audit($db, $schema, $event_type, $message, $metadata = array())
	{
		if (empty($schema['events'])) {
			return;
		}
		$allowed = array('allocated','released','imported','conflict_detected','reservation_added','reservation_removed','manual_override','failed_allocation');
		$event_type = port_blocks_value_in($event_type, $allowed, 'manual_override');
		$actor = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 'NULL';
		$message = $db->realEscapeSingle(substr((string)$message, 0, 255));
		$metadata_json = $db->realEscapeSingle(json_encode($metadata));
		$db->query(
			"INSERT INTO `DB_PREFIXport_allocation_events`
			 (`event_type`, `actor_user_id`, `source`, `message`, `metadata_json`, `created_at`)
			 VALUES ('{$event_type}', {$actor}, 'admin_ui', '{$message}', '{$metadata_json}', NOW())"
		);
	}
}

if (!function_exists('port_blocks_central')) {
	function port_blocks_central($datetime)
	{
		if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
			return '';
		}
		try {
			$date = new DateTime((string)$datetime);
			$date->setTimezone(new DateTimeZone('America/Chicago'));
			return $date->format('Y-m-d H:i') . ' CT';
		} catch (Exception $e) {
			return (string)$datetime;
		}
	}
}

if (!function_exists('port_blocks_agent_label')) {
	function port_blocks_agent_label($agents, $remote_server_id)
	{
		foreach ($agents as $agent) {
			if (intval($agent['remote_server_id']) === intval($remote_server_id)) {
				return $agent['remote_server_name'];
			}
		}
		return '';
	}
}

if (!function_exists('port_blocks_scope_agent')) {
	// Agent id posted from a form: 0 means global (every agent).
	function port_blocks_scope_agent($db, $key = 'remote_server_id')
	{
		$id = port_blocks_post_int($key, 0);
		return ($id > 0 && port_blocks_id_exists($db, 'remote_servers', 'remote_server_id', $id)) ? $id : 0;
	}
}

if (!function_exists('port_blocks_adopt_running')) {
	// Record servers that have ports but no block in the ledger, keeping their current ports.
	function port_blocks_adopt_running($db, $schema)
	{
		$size = port_blocks_current_block_size($db);
		$rows = port_blocks_rows($db, "SELECT sh.home_id, sh.home_cfg_id, hip.ip_id, MIN(hip.port) AS port FROM `DB_PREFIXserver_homes` sh INNER JOIN `DB_PREFIXhome_ip_ports` hip ON hip.home_id=sh.home_id LEFT JOIN `DB_PREFIXhome_port_blocks` hpb ON hpb.home_id=sh.home_id AND hpb.status='active' WHERE hpb.block_id IS NULL GROUP BY sh.home_id, sh.home_cfg_id, hip.ip_id");
		$done = 0;
		$seen = array();
		foreach ($rows as $row) {
			if (isset($seen[$row['home_id']])) {
				continue;
			}
			$seen[$row['home_id']] = true;
			$port = intval($row['port']);
			$ok = $db->query("INSERT INTO `DB_PREFIXhome_port_blocks` (`home_id`,`ip_id`,`home_cfg_id`,`block_start`,`block_end`,`primary_port`,`block_size`,`allocation_source`,`status`,`notes`,`created_at`) VALUES (" . intval($row['home_id']) . "," . intval($row['ip_id']) . "," . intval($row['home_cfg_id']) . ",{$port}," . min(65535, $port + $size - 1) . ",{$port},{$size},'legacy_imported','active','Adopted with its existing ports',NOW())");
			if ($ok) {
				$done++;
			}
		}
		port_blocks_audit($db, $schema, 'imported', 'Existing game servers recorded with their current ports', array('servers' => $done));
		return $done;
	}
}

if (!function_exists('port_blocks_handle_post')) {
	function port_blocks_handle_post($db, $schema)
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			return;
		}
		if (!port_blocks_csrf_valid()) {
			print_failure('Invalid form token. Refresh the page and try again.');
			return;
		}

		$action = port_blocks_post_string('port_blocks_action', 64);
		$error = '';
		$ok = false;
		$message = 'Saved.';

		if ($action === 'save_block_size') {
			$block_size = port_blocks_post_int('port_block_size', 10);
			if ($block_size < 1 || $block_size > 1000) {
				$error = 'Ports per game server must be from 1 to 1000.';
			} else {
				$ok = $db->query("INSERT INTO `DB_PREFIXsettings` (`setting`,`value`) VALUES ('port_block_size','" . $db->realEscapeSingle($block_size) . "') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
				port_blocks_audit($db, $schema, 'manual_override', 'Ports per game server updated', array('port_block_size' => $block_size));
			}
		} elseif ($action === 'add_range') {
			$agent = port_blocks_scope_agent($db);
			$start = port_blocks_post_int('range_start');
			$count = port_blocks_post_int('range_count', 100);
			$label = port_blocks_post_string('label', 128);
			$end = $start + $count - 1;
			if ($count < 1 || !port_blocks_validate_range($start, $end, 1, $error)) {
				$error = $error !== '' ? $error : 'The number of ports must be at least 1.';
			} else {
				$agent_sql = $agent > 0 ? (string)$agent : 'NULL';
				$ok = $db->query("INSERT INTO `DB_PREFIXport_ranges` (`remote_server_id`,`range_start`,`range_end`,`label`,`created_at`) VALUES ({$agent_sql},{$start},{$end},'" . $db->realEscapeSingle($label) . "',NOW())");
				port_blocks_audit($db, $schema, 'manual_override', 'Game port range added', array('start' => $start, 'end' => $end, 'remote_server_id' => $agent));
				$message = "Game ports {$start}-{$end} added.";
			}
		} elseif ($action === 'delete_range') {
			$id = port_blocks_post_int('range_id');
			$ok = $id > 0 && $db->query("DELETE FROM `DB_PREFIXport_ranges` WHERE `range_id`={$id}");
			port_blocks_audit($db, $schema, 'manual_override', 'Game port range removed', array('range_id' => $id));
			$message = 'Range removed.';
		} elseif ($action === 'add_disabled') {
			$agent = port_blocks_scope_agent($db);
			$reason = port_blocks_post_string('service_name', 128);
			$start = port_blocks_post_int('port_start');
			$count = port_blocks_post_int('port_count', 1);
			$end = $start + $count - 1;
			if ($reason === '') {
				$error = 'A reason is required, for example "Remote desktop".';
			} elseif ($count < 1 || !port_blocks_validate_range($start, $end, 1, $error)) {
				$error = $error !== '' ? $error : 'The number of ports must be at least 1.';
			} else {
				$scope = $agent > 0 ? 'remote_server' : 'global';
				$agent_sql = $agent > 0 ? (string)$agent : 'NULL';
				$type = $count === 1 ? 'single_port' : 'range';
				$ok = $db->query("INSERT INTO `DB_PREFIXstatic_port_reservations` (`scope`,`ip_id`,`remote_server_id`,`service_name`,`reservation_type`,`port_start`,`port_end`,`protocol`,`source`,`status`,`notes`,`created_at`) VALUES ('{$scope}',NULL,{$agent_sql},'" . $db->realEscapeSingle($reason) . "','{$type}',{$start},{$end},'tcp_udp','admin','active','',NOW())");
				port_blocks_audit($db, $schema, 'reservation_added', 'Ports disabled', array('start' => $start, 'end' => $end, 'remote_server_id' => $agent));
				$message = "Ports {$start}-{$end} disabled.";
			}
		} elseif ($action === 'delete_disabled') {
			$id = port_blocks_post_int('reservation_id');
			$ok = $id > 0 && $db->query("DELETE FROM `DB_PREFIXstatic_port_reservations` WHERE `reservation_id`={$id}");
			port_blocks_audit($db, $schema, 'reservation_removed', 'Disabled ports re-enabled', array('reservation_id' => $id));
			$message = 'Ports can be used again.';
		} elseif ($action === 'adopt_running') {
			$count = port_blocks_adopt_running($db, $schema);
			$ok = true;
			$message = $count . ' server(s) recorded with their current ports.';
		} else {
			$error = 'Unknown action.';
		}

		if ($error !== '') {
			print_failure(port_blocks_h($error));
		} elseif ($ok) {
			print_success(port_blocks_h($message));
		} else {
			print_failure('Unable to save. Check the Panel logs for details.');
		}
	}
}

if (!function_exists('port_blocks_select')) {
	function port_blocks_select($name, $options, $selected, $empty_label = '')
	{
		$html = "<select name='" . port_blocks_h($name) . "'>";
		if ($empty_label !== '') {
			$html .= "<option value=''>" . port_blocks_h($empty_label) . "</option>";
		}
		foreach ($options as $value => $label) {
			$is_selected = ((string)$value === (string)$selected) ? " selected='selected'" : '';
			$html .= "<option value='" . port_blocks_h($value) . "'{$is_selected}>" . port_blocks_h($label) . "</option>";
		}
		return $html . "</select>";
	}
}

if (!function_exists('port_blocks_current_block_size')) {
	function port_blocks_current_block_size($db)
	{
		$rows = port_blocks_rows($db, "SELECT `value` FROM `DB_PREFIXsettings` WHERE `setting`='port_block_size' LIMIT 1");
		if (empty($rows)) {
			$db->query("INSERT INTO `DB_PREFIXsettings` (`setting`,`value`) VALUES ('port_block_size','10') ON DUPLICATE KEY UPDATE `value`=`value`");
		}
		$value = !empty($rows) ? intval($rows[0]['value']) : 10;
		if ($value < 1 || $value > 1000) {
			$value = 10;
		}
		return $value;
	}
}

if (!function_exists('port_blocks_fit')) {
	// Does a server's ports fit the rules for its agent? Returns array(ok, text).
	function port_blocks_fit($port, $end, array $ranges, array $disabled)
	{
		if ($port <= 0) {
			return array(false, 'No port assigned');
		}
		foreach ($disabled as $d) {
			if ($port <= $d['end'] && $end >= $d['start']) {
				return array(false, 'Uses disabled ports ' . $d['start'] . '-' . $d['end']);
			}
		}
		if (!empty($ranges)) {
			foreach ($ranges as $r) {
				if ($port >= $r['start'] && $end <= $r['end']) {
					return array(true, 'Fits');
				}
			}
			return array(false, 'Outside the game port ranges');
		}
		return array(true, 'Fits');
	}
}

if (!function_exists('port_blocks_rule_rows')) {
	function port_blocks_rule_rows($rows, $agents, $scope, $id_key, $delete_action, $columns)
	{
		$html = '';
		foreach ($rows as $row) {
			$agent = isset($row['remote_server_id']) && $row['remote_server_id'] !== null ? intval($row['remote_server_id']) : 0;
			if ($scope !== 'all' && $agent !== 0 && $agent !== intval($scope)) {
				continue;
			}
			$html .= "<tr><td>" . ($agent > 0 ? port_blocks_h(port_blocks_agent_label($agents, $agent)) : 'Global') . "</td>";
			foreach ($columns as $col) {
				$html .= "<td>" . port_blocks_h($row[$col]) . "</td>";
			}
			$html .= "<td><form method='post' action='?m=server&amp;p=port_blocks&amp;agent=" . port_blocks_h($scope) . "'>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='{$delete_action}' /><input type='hidden' name='{$id_key}' value='" . intval($row[$id_key]) . "' /><input type='submit' value='Remove' /></form></td></tr>";
		}
		return $html;
	}
}

if (!function_exists('port_blocks_render')) {
	function port_blocks_render($db, $schema)
	{
		$agents = port_blocks_rows($db, "SELECT remote_server_id, remote_server_name FROM `DB_PREFIXremote_servers` ORDER BY remote_server_name ASC");
		$agent_options = array();
		foreach ($agents as $a) {
			$agent_options[$a['remote_server_id']] = $a['remote_server_name'];
		}
		$scope = isset($_GET['agent']) ? preg_replace('/[^0-9a-z]/', '', (string)$_GET['agent']) : 'all';
		if ($scope !== 'all' && $scope !== 'global' && !isset($agent_options[$scope])) {
			$scope = 'all';
		}
		$size = port_blocks_current_block_size($db);
		$self = '?m=server&amp;p=port_blocks';
		$have_ranges = port_blocks_table_exists($db, 'port_ranges');

		echo "<style>.port-blocks-wrap{overflow-x:auto;max-width:100%;}.port-blocks-agents{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0;}.port-blocks-agents a{box-sizing:border-box;min-width:8.5em;min-height:2.4em;padding:0 10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid currentColor;border-radius:4px;text-decoration:none;}.port-blocks-agents a.on{font-weight:bold;text-decoration:underline;}.port-blocks-bad{color:#c0392b;}</style>";
		echo "<h2>Port Blocks</h2>";
		echo "<p class='info'>This page decides which ports game servers are given. Each new game server gets <b>" . intval($size) . "</b> consecutive ports, starting at its game's default port and moving up until a free set is found. <b>Game port ranges</b> limit where those ports may come from, and <b>Disabled ports</b> are never handed out (the agent's own port, remote desktop and so on). Rules can be global, or apply to a single agent. Servers that already exist keep the ports they have; the list at the bottom shows whether each one fits the rules.</p>";

		echo "<div class='port-blocks-agents'><a class='" . ($scope === 'all' ? 'on' : '') . "' href='{$self}&amp;agent=all'>All rules</a><a class='" . ($scope === 'global' ? 'on' : '') . "' href='{$self}&amp;agent=global'>Global</a>";
		foreach ($agents as $a) {
			echo "<a class='" . ((string)$scope === (string)$a['remote_server_id'] ? 'on' : '') . "' href='{$self}&amp;agent=" . intval($a['remote_server_id']) . "'>" . port_blocks_h($a['remote_server_name']) . "</a>";
		}
		echo "</div>";
		$form_action = $self . "&amp;agent=" . port_blocks_h($scope);
		$default_agent = ctype_digit($scope) ? $scope : '';

		echo "<h3>Ports per game server</h3><form method='post' action='{$form_action}'>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='save_block_size' />Each game server gets <input type='text' name='port_block_size' value='" . intval($size) . "' size='4' inputmode='numeric' /> consecutive ports (1-1000). <input type='submit' value='Save' /></form>";

		$ranges = $have_ranges ? port_blocks_rows($db, "SELECT * FROM `DB_PREFIXport_ranges` ORDER BY range_start ASC") : array();
		echo "<h3>Game port ranges</h3><p class='info'>Where game servers may be given ports. With no range set for an agent, ports are taken upward from each game's default port.</p>";
		echo "<div class='port-blocks-wrap'><table class='center'><tr><th>Applies to</th><th>First port</th><th>Last port</th><th>Ports</th><th>Note</th><th></th></tr>";
		foreach ($ranges as &$r) {
			$r['count'] = intval($r['range_end']) - intval($r['range_start']) + 1;
		}
		unset($r);
		echo port_blocks_rule_rows($ranges, $agents, $scope, 'range_id', 'delete_range', array('range_start', 'range_end', 'count', 'label'));
		if ($have_ranges) {
			echo "<form method='post' action='{$form_action}'><tr>" . "<td>" . port_blocks_select('remote_server_id', $agent_options, $default_agent, 'Global (all agents)') . "</td><td><input type='text' name='range_start' size='6' placeholder='27000' /></td><td colspan='1'></td><td><input type='text' name='range_count' value='100' size='5' /></td><td><input type='text' name='label' size='18' placeholder='Note (optional)' /></td><td>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='add_range' /><input type='submit' value='Add range' /></td></tr></form>";
		} else {
			echo "<tr><td colspan='6'>Run the Remote Agents module update to enable ranges.</td></tr>";
		}
		echo "</table></div>";

		$disabled_rows = port_blocks_rows($db, "SELECT * FROM `DB_PREFIXstatic_port_reservations` WHERE status='active' ORDER BY port_start ASC");
		foreach ($disabled_rows as &$d) {
			$d['count'] = intval($d['port_end']) - intval($d['port_start']) + 1;
		}
		unset($d);
		echo "<h3>Disabled ports</h3><p class='info'>Ports that are never given to a game server.</p>";
		echo "<div class='port-blocks-wrap'><table class='center'><tr><th>Applies to</th><th>Reason</th><th>First port</th><th>Last port</th><th>Ports</th><th></th></tr>";
		$html = '';
		foreach ($disabled_rows as $row) {
			$agent = $row['scope'] === 'remote_server' ? intval($row['remote_server_id']) : 0;
			if ($row['scope'] === 'ip') {
				$agent = -1;
			}
			if ($scope !== 'all' && $agent !== 0 && $agent !== intval($scope)) {
				continue;
			}
			$who = $agent > 0 ? port_blocks_agent_label($agents, $agent) : ($agent === 0 ? 'Global' : 'One IP');
			$html .= "<tr><td>" . port_blocks_h($who) . "</td><td>" . port_blocks_h($row['service_name']) . "</td><td>" . intval($row['port_start']) . "</td><td>" . intval($row['port_end']) . "</td><td>" . intval($row['count']) . "</td><td><form method='post' action='{$form_action}'>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='delete_disabled' /><input type='hidden' name='reservation_id' value='" . intval($row['reservation_id']) . "' /><input type='submit' value='Enable' /></form></td></tr>";
		}
		echo $html;
		echo "<form method='post' action='{$form_action}'><tr><td>" . port_blocks_select('remote_server_id', $agent_options, $default_agent, 'Global (all agents)') . "</td><td><input type='text' name='service_name' size='18' placeholder='Reason' /></td><td><input type='text' name='port_start' size='6' /></td><td></td><td><input type='text' name='port_count' value='1' size='5' /></td><td>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='add_disabled' /><input type='submit' value='Disable ports' /></td></tr></form>";
		echo "</table></div>";

		// Game servers with ports, including running ones.
		$agent_filter = isset($_GET['server_agent']) ? intval($_GET['server_agent']) : (ctype_digit($scope) ? intval($scope) : 0);
		$state_filter = isset($_GET['fit']) ? preg_replace('/[^a-z]/', '', (string)$_GET['fit']) : '';
		$where = $agent_filter > 0 ? "WHERE sh.remote_server_id=" . $agent_filter : '';
		$servers = port_blocks_rows($db, "SELECT sh.home_id, sh.home_name, sh.remote_server_id, rs.remote_server_name, ch.game_name, hpb.block_id, hpb.block_start, hpb.block_end, hpb.primary_port, hpb.allocation_source, (SELECT MIN(hip.port) FROM `DB_PREFIXhome_ip_ports` hip WHERE hip.home_id=sh.home_id) AS current_port FROM `DB_PREFIXserver_homes` sh LEFT JOIN `DB_PREFIXhome_port_blocks` hpb ON hpb.home_id=sh.home_id AND hpb.status='active' LEFT JOIN `DB_PREFIXconfig_homes` ch ON ch.home_cfg_id=sh.home_cfg_id LEFT JOIN `DB_PREFIXremote_servers` rs ON rs.remote_server_id=sh.remote_server_id {$where} ORDER BY rs.remote_server_name ASC, sh.home_id ASC");

		echo "<h3>Game servers</h3><p class='info'>Every game server, including ones that are already running. Existing servers keep their ports; \"Fits\" means the ports are inside the game port ranges and clear of disabled ports.</p>";
		echo "<form method='get' action='home.php'><input type='hidden' name='m' value='server' /><input type='hidden' name='p' value='port_blocks' /><input type='hidden' name='agent' value='" . port_blocks_h($scope) . "' />Agent: " . port_blocks_select('server_agent', $agent_options, $agent_filter ?: '', 'All agents') . " Show: " . port_blocks_select('fit', array('fits' => 'Fits', 'problem' => 'Needs attention', 'unrecorded' => 'Not yet recorded'), $state_filter, 'All') . " <input type='submit' value='Filter' /></form>";
		echo "<div class='port-blocks-wrap'><table class='center'><tr><th>Server</th><th>Game</th><th>Agent</th><th>Ports</th><th>Recorded</th><th>Rules</th></tr>";
		$shown = 0;
		$unrecorded = 0;
		foreach ($servers as $s) {
			$agent = intval($s['remote_server_id']);
			$agent_ranges = array();
			foreach ($ranges as $r) {
				if ($r['remote_server_id'] === null || intval($r['remote_server_id']) === $agent) {
					$agent_ranges[] = array('start' => intval($r['range_start']), 'end' => intval($r['range_end']));
				}
			}
			$agent_disabled = array();
			foreach ($disabled_rows as $d) {
				if ($d['scope'] === 'global' || ($d['scope'] === 'remote_server' && intval($d['remote_server_id']) === $agent)) {
					$agent_disabled[] = array('start' => intval($d['port_start']), 'end' => intval($d['port_end']));
				}
			}
			$recorded = $s['block_id'] !== null;
			$port = $recorded ? intval($s['primary_port']) : intval($s['current_port']);
			$end = $recorded ? intval($s['block_end']) : ($port > 0 ? $port + $size - 1 : 0);
			list($fits, $text) = port_blocks_fit($port, $end, $agent_ranges, $agent_disabled);
			if (!$recorded) {
				$unrecorded++;
			}
			if (($state_filter === 'fits' && !$fits) || ($state_filter === 'problem' && $fits) || ($state_filter === 'unrecorded' && $recorded)) {
				continue;
			}
			$shown++;
			echo "<tr><td>#" . intval($s['home_id']) . " " . port_blocks_h($s['home_name']) . "</td><td>" . port_blocks_h($s['game_name']) . "</td><td>" . port_blocks_h($s['remote_server_name']) . "</td><td>" . ($port > 0 ? $port . "-" . $end : '') . "</td><td>" . ($recorded ? 'Yes' : 'No') . "</td><td class='" . ($fits ? '' : 'port-blocks-bad') . "'>" . port_blocks_h($text) . "</td></tr>";
		}
		if ($shown === 0) {
			echo "<tr><td colspan='6'>No game servers match.</td></tr>";
		}
		echo "</table></div>";
		if ($unrecorded > 0) {
			echo "<form method='post' action='{$form_action}'>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='adopt_running' /><p>" . intval($unrecorded) . " server(s) have ports that are not recorded yet. <input type='submit' value='Record their current ports' /> (nothing is moved or restarted)</p></form>";
		}

		echo "<h3>Allocation Events</h3>";
		if (!empty($schema['events'])) {
			$events = port_blocks_rows($db, "SELECT pae.*, i.ip FROM `DB_PREFIXport_allocation_events` pae LEFT JOIN `DB_PREFIXremote_server_ips` i ON i.ip_id=pae.ip_id ORDER BY pae.event_id DESC LIMIT 50");
			echo "<div class='port-blocks-wrap'><table class='center'><tr><th>Time</th><th>Type</th><th>Server</th><th>IP</th><th>Source</th><th>Message</th></tr>";
			foreach ($events as $event) {
				echo "<tr><td>" . port_blocks_h(port_blocks_central($event['created_at'])) . "</td><td>" . port_blocks_h($event['event_type']) . "</td><td>" . ($event['home_id'] ? '#' . intval($event['home_id']) : '') . "</td><td>" . port_blocks_h($event['ip']) . "</td><td>" . port_blocks_h($event['source']) . "</td><td>" . port_blocks_h($event['message']) . "</td></tr>";
			}
			if (empty($events)) {
				echo "<tr><td colspan='6'>No allocation events found.</td></tr>";
			}
			echo "</table></div>";
		} else {
			echo "<p class='info'>The optional allocation events table is not installed.</p>";
		}
	}
}

function exec_module()
{
	global $db;

	if (empty($_SESSION['user_id']) || !$db->isAdmin($_SESSION['user_id'])) {
		print_failure(get_lang('no_rights'));
		return;
	}

	$schema = port_blocks_schema_status($db);
	if (empty($schema['ok'])) {
		print_failure('Global port allocation tables are missing. Run the Remote Agents module update before using this page.');
		if (!empty($schema['missing'])) {
			echo "<p>Missing: " . port_blocks_h(implode(', ', $schema['missing'])) . "</p>";
		}
		return;
	}

	port_blocks_handle_post($db, $schema);
	port_blocks_render($db, $schema);
}
?>
