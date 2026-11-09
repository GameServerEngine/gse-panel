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

		if ($action === 'save_block_size') {
			$block_size = port_blocks_post_int('port_block_size', 10);
			if ($block_size < 1 || $block_size > 1000) {
				$error = 'Port block size must be an integer from 1 to 1000.';
			} else {
				$ok = $db->query(
					"INSERT INTO `DB_PREFIXsettings` (`setting`,`value`)
					 VALUES ('port_block_size','" . $db->realEscapeSingle($block_size) . "')
					 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Global port block size updated', array('port_block_size' => $block_size));
			}
		} elseif ($action === 'create_pool') {
			$pool_key = preg_replace('/[^a-zA-Z0-9_-]/', '', port_blocks_post_string('pool_key', 64));
			$name = port_blocks_post_string('name', 128);
			$purpose = port_blocks_value_in(port_blocks_post_string('purpose', 32), array('game','overflow','service','manual','legacy'), 'game');
			$base_port = port_blocks_post_int('base_port');
			$range_start = port_blocks_post_int('range_start');
			$range_end = port_blocks_post_int('range_end');
			$block_size = port_blocks_post_int('block_size', 10);
			$priority = port_blocks_post_int('priority', 100);
			$enabled = port_blocks_flag('enabled');
			$allow_manual = port_blocks_flag('allow_manual');
			$notes = port_blocks_post_string('notes', 2000);

			if ($pool_key === '' || $name === '') {
				$error = 'Pool key and name are required.';
			} elseif (!port_blocks_validate_range($range_start, $range_end, $block_size, $error)) {
				// Error set by validator.
			} elseif (!port_blocks_validate_port($base_port) || $base_port < $range_start || $base_port > $range_end) {
				$error = 'Base port must be inside the pool range.';
			} else {
				$ok = $db->query(
					"INSERT INTO `DB_PREFIXport_pools`
					 (`pool_key`,`name`,`purpose`,`base_port`,`range_start`,`range_end`,`block_size`,`priority`,`enabled`,`allow_manual`,`notes`,`created_at`)
					 VALUES ('" . $db->realEscapeSingle($pool_key) . "','" . $db->realEscapeSingle($name) . "','" . $db->realEscapeSingle($purpose) . "',{$base_port},{$range_start},{$range_end},{$block_size},{$priority},{$enabled},{$allow_manual},'" . $db->realEscapeSingle($notes) . "',NOW())"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Port pool created', array('pool_key' => $pool_key));
			}
		} elseif ($action === 'update_pool') {
			$pool_id = port_blocks_post_int('pool_id');
			$name = port_blocks_post_string('name', 128);
			$purpose = port_blocks_value_in(port_blocks_post_string('purpose', 32), array('game','overflow','service','manual','legacy'), 'game');
			$priority = port_blocks_post_int('priority', 100);
			$enabled = port_blocks_flag('enabled');
			$allow_manual = port_blocks_flag('allow_manual');
			$notes = port_blocks_post_string('notes', 2000);
			if (!port_blocks_id_exists($db, 'port_pools', 'pool_id', $pool_id) || $name === '') {
				$error = 'Valid pool and name are required.';
			} else {
				$ok = $db->query(
					"UPDATE `DB_PREFIXport_pools`
					    SET `name`='" . $db->realEscapeSingle($name) . "',
					        `purpose`='" . $db->realEscapeSingle($purpose) . "',
					        `priority`={$priority},
					        `enabled`={$enabled},
					        `allow_manual`={$allow_manual},
					        `notes`='" . $db->realEscapeSingle($notes) . "',
					        `updated_at`=NOW()
					  WHERE `pool_id`={$pool_id}"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Port pool updated', array('pool_id' => $pool_id));
			}
		} elseif ($action === 'save_mapping') {
			$pool_game_id = port_blocks_post_int('pool_game_id');
			$pool_id = port_blocks_post_int('pool_id');
			$home_cfg_id = port_blocks_post_int('home_cfg_id');
			$role = port_blocks_value_in(port_blocks_post_string('role', 32), array('preferred','compatible','fallback','excluded'), 'preferred');
			$priority = port_blocks_post_int('priority', 100);
			$override = port_blocks_post_int('block_size_override', 0);
			$override_sql = $override > 0 ? (string)$override : 'NULL';
			$policy = port_blocks_value_in(port_blocks_post_string('allocation_policy', 32), array('standard','non_standard','manual_review','service_specific'), 'standard');
			$enabled = port_blocks_flag('enabled');
			$notes = port_blocks_post_string('notes', 2000);
			if (!port_blocks_id_exists($db, 'port_pools', 'pool_id', $pool_id) || !port_blocks_id_exists($db, 'config_homes', 'home_cfg_id', $home_cfg_id)) {
				$error = 'Valid pool and game config are required.';
			} elseif ($override < 0) {
				$error = 'Block size override must be empty or greater than zero.';
			} elseif ($pool_game_id > 0) {
				$ok = $db->query(
					"UPDATE `DB_PREFIXport_pool_games`
					    SET `pool_id`={$pool_id}, `home_cfg_id`={$home_cfg_id}, `role`='" . $db->realEscapeSingle($role) . "',
					        `priority`={$priority}, `block_size_override`={$override_sql}, `allocation_policy`='" . $db->realEscapeSingle($policy) . "',
					        `enabled`={$enabled}, `notes`='" . $db->realEscapeSingle($notes) . "', `updated_at`=NOW()
					  WHERE `pool_game_id`={$pool_game_id}"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Game pool mapping updated', array('pool_game_id' => $pool_game_id));
			} else {
				$ok = $db->query(
					"INSERT INTO `DB_PREFIXport_pool_games`
					 (`pool_id`,`home_cfg_id`,`role`,`priority`,`block_size_override`,`allocation_policy`,`enabled`,`notes`,`created_at`)
					 VALUES ({$pool_id},{$home_cfg_id},'" . $db->realEscapeSingle($role) . "',{$priority},{$override_sql},'" . $db->realEscapeSingle($policy) . "',{$enabled},'" . $db->realEscapeSingle($notes) . "',NOW())"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Game pool mapping created', array('pool_id' => $pool_id, 'home_cfg_id' => $home_cfg_id));
			}
		} elseif ($action === 'save_offset') {
			$offset_id = port_blocks_post_int('offset_id');
			$home_cfg_id = port_blocks_post_int('home_cfg_id');
			$role = port_blocks_value_in(port_blocks_post_string('port_role', 32), array('primary','query','rcon','web','steam','peer','admin','filetransfer','query_ssh','custom'), 'custom');
			$token_name = port_blocks_post_string('token_name', 64);
			$protocol = port_blocks_value_in(port_blocks_post_string('protocol', 32), array('tcp','udp','tcp_udp','unknown'), 'unknown');
			$offset_type = port_blocks_value_in(port_blocks_post_string('offset_type', 32), array('add','subtract','absolute'), 'add');
			$offset_value = port_blocks_post_int('offset_value', 0);
			$required = port_blocks_flag('required');
			$fits = port_blocks_flag('fits_standard_block');
			$source = port_blocks_value_in(port_blocks_post_string('source', 32), array('xml_query_port','xml_reserve_ports','lgsl_conversion','manual','imported'), 'manual');
			$notes = port_blocks_post_string('notes', 2000);
			if (!port_blocks_id_exists($db, 'config_homes', 'home_cfg_id', $home_cfg_id)) {
				$error = 'Valid game config is required.';
			} elseif ($offset_type === 'absolute' && !port_blocks_validate_port($offset_value)) {
				$error = 'Absolute offset values must be valid ports.';
			} elseif (abs($offset_value) > 65535) {
				$error = 'Offset value is outside the supported port range.';
			} elseif ($offset_id > 0) {
				$ok = $db->query(
					"UPDATE `DB_PREFIXgame_port_offsets`
					    SET `home_cfg_id`={$home_cfg_id}, `port_role`='" . $db->realEscapeSingle($role) . "',
					        `token_name`='" . $db->realEscapeSingle($token_name) . "', `protocol`='" . $db->realEscapeSingle($protocol) . "',
					        `offset_type`='" . $db->realEscapeSingle($offset_type) . "', `offset_value`={$offset_value},
					        `required`={$required}, `fits_standard_block`={$fits}, `source`='" . $db->realEscapeSingle($source) . "',
					        `notes`='" . $db->realEscapeSingle($notes) . "', `updated_at`=NOW()
					  WHERE `offset_id`={$offset_id}"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Game port offset updated', array('offset_id' => $offset_id));
			} else {
				$ok = $db->query(
					"INSERT INTO `DB_PREFIXgame_port_offsets`
					 (`home_cfg_id`,`port_role`,`token_name`,`protocol`,`offset_type`,`offset_value`,`required`,`fits_standard_block`,`source`,`notes`,`created_at`)
					 VALUES ({$home_cfg_id},'" . $db->realEscapeSingle($role) . "','" . $db->realEscapeSingle($token_name) . "','" . $db->realEscapeSingle($protocol) . "','" . $db->realEscapeSingle($offset_type) . "',{$offset_value},{$required},{$fits},'" . $db->realEscapeSingle($source) . "','" . $db->realEscapeSingle($notes) . "',NOW())"
				);
				port_blocks_audit($db, $schema, 'manual_override', 'Game port offset created', array('home_cfg_id' => $home_cfg_id, 'port_role' => $role));
			}
		} elseif ($action === 'save_reservation') {
			$reservation_id = port_blocks_post_int('reservation_id');
			$scope = port_blocks_value_in(port_blocks_post_string('scope', 32), array('ip','remote_server','global'), 'ip');
			$ip_id = port_blocks_post_int('ip_id', 0);
			$remote_server_id = port_blocks_post_int('remote_server_id', 0);
			$service_name = port_blocks_post_string('service_name', 128);
			$type = port_blocks_value_in(port_blocks_post_string('reservation_type', 32), array('single_port','block','range'), 'block');
			$port_start = port_blocks_post_int('port_start');
			$port_end = port_blocks_post_int('port_end');
			$protocol = port_blocks_value_in(port_blocks_post_string('protocol', 32), array('tcp','udp','tcp_udp','unknown'), 'tcp_udp');
			$source = port_blocks_value_in(port_blocks_post_string('source', 32), array('admin','migration','detected','policy'), 'admin');
			$status = port_blocks_value_in(port_blocks_post_string('status', 32), array('active','disabled','needs-review'), 'active');
			$notes = port_blocks_post_string('notes', 2000);
			if ($service_name === '') {
				$error = 'Reservation reason/service name is required.';
			} elseif (!port_blocks_validate_range($port_start, $port_end, 1, $error)) {
				// Error set by validator.
			} elseif ($scope === 'ip' && !port_blocks_id_exists($db, 'remote_server_ips', 'ip_id', $ip_id)) {
				$error = 'A valid IP is required for IP-scoped reservations.';
			} elseif ($scope === 'remote_server' && !port_blocks_id_exists($db, 'remote_servers', 'remote_server_id', $remote_server_id)) {
				$error = 'A valid remote server is required for remote-server reservations.';
			} else {
				$ip_sql = $scope === 'ip' ? (string)$ip_id : 'NULL';
				$remote_sql = $scope === 'remote_server' ? (string)$remote_server_id : 'NULL';
				if ($reservation_id > 0) {
					$ok = $db->query(
						"UPDATE `DB_PREFIXstatic_port_reservations`
						    SET `scope`='" . $db->realEscapeSingle($scope) . "', `ip_id`={$ip_sql}, `remote_server_id`={$remote_sql},
						        `service_name`='" . $db->realEscapeSingle($service_name) . "', `reservation_type`='" . $db->realEscapeSingle($type) . "',
						        `port_start`={$port_start}, `port_end`={$port_end}, `protocol`='" . $db->realEscapeSingle($protocol) . "',
						        `source`='" . $db->realEscapeSingle($source) . "', `status`='" . $db->realEscapeSingle($status) . "',
						        `notes`='" . $db->realEscapeSingle($notes) . "', `updated_at`=NOW()
						  WHERE `reservation_id`={$reservation_id}"
					);
					port_blocks_audit($db, $schema, 'reservation_added', 'Static reservation updated', array('reservation_id' => $reservation_id));
				} else {
					$ok = $db->query(
						"INSERT INTO `DB_PREFIXstatic_port_reservations`
						 (`scope`,`ip_id`,`remote_server_id`,`service_name`,`reservation_type`,`port_start`,`port_end`,`protocol`,`source`,`status`,`notes`,`created_at`)
						 VALUES ('" . $db->realEscapeSingle($scope) . "',{$ip_sql},{$remote_sql},'" . $db->realEscapeSingle($service_name) . "','" . $db->realEscapeSingle($type) . "',{$port_start},{$port_end},'" . $db->realEscapeSingle($protocol) . "','" . $db->realEscapeSingle($source) . "','" . $db->realEscapeSingle($status) . "','" . $db->realEscapeSingle($notes) . "',NOW())"
					);
					port_blocks_audit($db, $schema, 'reservation_added', 'Static reservation created', array('scope' => $scope, 'service_name' => $service_name));
				}
			}
		} else {
			$error = 'Unknown port block action.';
		}

		if ($error !== '') {
			print_failure(port_blocks_h($error));
		} elseif ($ok) {
			print_success('Port block settings saved.');
		} else {
			print_failure('Unable to save port block settings. Check the Panel logs for details.');
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
		$html .= "</select>";
		return $html;
	}
}

if (!function_exists('port_blocks_options')) {
	function port_blocks_options($rows, $id_key, $label_keys)
	{
		$options = array();
		foreach ($rows as $row) {
			$parts = array();
			foreach ($label_keys as $key) {
				if (isset($row[$key]) && $row[$key] !== '') {
					$parts[] = $row[$key];
				}
			}
			$options[$row[$id_key]] = '#' . $row[$id_key] . ' ' . implode(' - ', $parts);
		}
		return $options;
	}
}

if (!function_exists('port_blocks_checkbox')) {
	function port_blocks_checkbox($name, $checked)
	{
		$is_checked = intval($checked) ? " checked='checked'" : '';
		return "<input type='checkbox' name='" . port_blocks_h($name) . "' value='1'{$is_checked} />";
	}
}

if (!function_exists('port_blocks_render_pool_rows')) {
	function port_blocks_render_pool_rows($pools)
	{
		$purpose = array('game' => 'game', 'overflow' => 'overflow', 'service' => 'service', 'manual' => 'manual', 'legacy' => 'legacy');
		foreach ($pools as $pool) {
			$capacity = intval(floor((intval($pool['range_end']) - intval($pool['range_start']) + 1) / max(1, intval($pool['block_size']))));
			echo "<form method='post' action='?m=server&amp;p=port_blocks'><tr>";
			echo "<td>" . intval($pool['pool_id']) . "<input type='hidden' name='pool_id' value='" . intval($pool['pool_id']) . "' /></td>";
			echo "<td>" . port_blocks_h($pool['pool_key']) . "</td>";
			echo "<td><input type='text' name='name' value='" . port_blocks_h($pool['name']) . "' size='20' /></td>";
			echo "<td>" . port_blocks_select('purpose', $purpose, $pool['purpose']) . "</td>";
			echo "<td>" . intval($pool['base_port']) . "</td>";
			echo "<td>" . intval($pool['range_start']) . "-" . intval($pool['range_end']) . "</td>";
			echo "<td>" . intval($pool['block_size']) . "</td>";
			echo "<td>" . $capacity . "</td>";
			echo "<td><input type='text' name='priority' value='" . intval($pool['priority']) . "' size='4' /></td>";
			echo "<td>" . port_blocks_checkbox('enabled', $pool['enabled']) . "</td>";
			echo "<td>" . port_blocks_checkbox('allow_manual', $pool['allow_manual']) . "</td>";
			echo "<td><input type='text' name='notes' value='" . port_blocks_h($pool['notes']) . "' size='32' /></td>";
			echo "<td>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='update_pool' /><input type='submit' value='Save' /></td>";
			echo "</tr></form>";
		}
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

if (!function_exists('port_blocks_render')) {
	function port_blocks_render($db, $schema)
	{
		$remote_servers = port_blocks_rows($db, "SELECT remote_server_id, remote_server_name, agent_ip FROM `DB_PREFIXremote_servers` ORDER BY remote_server_name ASC");
		$ips = port_blocks_rows($db, "SELECT i.ip_id, i.ip, r.remote_server_name FROM `DB_PREFIXremote_server_ips` i LEFT JOIN `DB_PREFIXremote_servers` r ON r.remote_server_id=i.remote_server_id ORDER BY r.remote_server_name ASC, i.ip ASC");
		$remote_options = port_blocks_options($remote_servers, 'remote_server_id', array('remote_server_name', 'agent_ip'));
		$ip_options = port_blocks_options($ips, 'ip_id', array('ip', 'remote_server_name'));
		$block_size = port_blocks_current_block_size($db);

		echo "<style>.port-blocks-wrap{overflow-x:auto;max-width:100%;}.port-blocks-setting input[type=text]{max-width:90px;}.port-blocks-note{line-height:1.5;}</style>";
		echo "<h2>Automatic Port Allocation</h2>";
		echo "<p class='info port-blocks-note'>GSE now uses each game's default base port, assigns the first available contiguous block of N ports on the selected remote server/IP, and skips reserved ports plus already used blocks. Existing homes remain compatible through <b>DB_PREFIXhome_ip_ports</b>. Legacy pool, mapping, and offset tables are retained for compatibility but are not part of the normal admin workflow.</p>";

		echo "<h3>Global Block Size</h3>";
		echo "<form class='port-blocks-setting' method='post' action='?m=server&amp;p=port_blocks'>";
		echo port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='save_block_size' />";
		echo "<p>Allocate <input type='text' name='port_block_size' value='" . intval($block_size) . "' inputmode='numeric' /> consecutive ports for each new game server. Valid range: 1-1000. Default: 10.</p>";
		echo "<p><input type='submit' value='Save block size' /></p>";
		echo "</form>";

		$reservations = port_blocks_rows($db, "SELECT spr.*, i.ip, rs.remote_server_name FROM `DB_PREFIXstatic_port_reservations` spr LEFT JOIN `DB_PREFIXremote_server_ips` i ON i.ip_id=spr.ip_id LEFT JOIN `DB_PREFIXremote_servers` rs ON rs.remote_server_id=spr.remote_server_id ORDER BY spr.status ASC, spr.port_start ASC, spr.reservation_id ASC");
		echo "<h3>Reserved Ports And Ranges</h3>";
		echo "<p class='info'>Active reservations block automatic allocation on their matching IP, remote server, or global scope. Use this for services, manually reserved single ports, and protected ranges.</p>";
		$protocols = array('tcp' => 'tcp', 'udp' => 'udp', 'tcp_udp' => 'tcp_udp', 'unknown' => 'unknown');
		$scope_options = array('ip' => 'ip', 'remote_server' => 'remote_server', 'global' => 'global');
		$reservation_types = array('single_port' => 'single_port', 'block' => 'block', 'range' => 'range');
		$reservation_sources = array('admin' => 'admin', 'migration' => 'migration', 'detected' => 'detected', 'policy' => 'policy');
		$reservation_status = array('active' => 'active', 'disabled' => 'disabled', 'needs-review' => 'needs-review');
		echo "<div class='port-blocks-wrap'><table class='center'><tr><th>ID</th><th>Scope</th><th>IP</th><th>Remote server</th><th>Reason</th><th>Type</th><th>Start</th><th>End</th><th>Protocol</th><th>Source</th><th>Status</th><th>Notes</th><th>Action</th></tr>";
		$reservation_rows = array_merge(array(array('reservation_id' => 0, 'scope' => 'ip', 'ip_id' => '', 'remote_server_id' => '', 'service_name' => '', 'reservation_type' => 'block', 'port_start' => '', 'port_end' => '', 'protocol' => 'tcp_udp', 'source' => 'admin', 'status' => 'active', 'notes' => '')), $reservations);
		foreach ($reservation_rows as $row) {
			echo "<form method='post' action='?m=server&amp;p=port_blocks'><tr>";
			echo "<td>" . (intval($row['reservation_id']) ?: 'New') . "<input type='hidden' name='reservation_id' value='" . intval($row['reservation_id']) . "' /></td>";
			echo "<td>" . port_blocks_select('scope', $scope_options, $row['scope']) . "</td>";
			echo "<td>" . port_blocks_select('ip_id', $ip_options, $row['ip_id'], 'n/a') . "</td>";
			echo "<td>" . port_blocks_select('remote_server_id', $remote_options, $row['remote_server_id'], 'n/a') . "</td>";
			echo "<td><input type='text' name='service_name' value='" . port_blocks_h($row['service_name']) . "' size='14' /></td>";
			echo "<td>" . port_blocks_select('reservation_type', $reservation_types, $row['reservation_type']) . "</td>";
			echo "<td><input type='text' name='port_start' value='" . port_blocks_h($row['port_start']) . "' size='5' /></td>";
			echo "<td><input type='text' name='port_end' value='" . port_blocks_h($row['port_end']) . "' size='5' /></td>";
			echo "<td>" . port_blocks_select('protocol', $protocols, $row['protocol']) . "</td>";
			echo "<td>" . port_blocks_select('source', $reservation_sources, $row['source']) . "</td>";
			echo "<td>" . port_blocks_select('status', $reservation_status, $row['status']) . "</td>";
			echo "<td><input type='text' name='notes' value='" . port_blocks_h($row['notes']) . "' size='20' /></td>";
			echo "<td>" . port_blocks_token_input() . "<input type='hidden' name='port_blocks_action' value='save_reservation' /><input type='submit' value='Save' /></td>";
			echo "</tr></form>";
		}
		echo "</table></div>";

		$status_filter = isset($_GET['block_status']) ? preg_replace('/[^a-zA-Z_-]/', '', $_GET['block_status']) : '';
		$status_sql = $status_filter !== '' ? "WHERE hpb.status='" . $db->realEscapeSingle($status_filter) . "'" : '';
		$blocks = port_blocks_rows($db, "SELECT hpb.*, sh.home_name, i.ip, pp.name AS pool_name, ch.game_name FROM `DB_PREFIXhome_port_blocks` hpb LEFT JOIN `DB_PREFIXserver_homes` sh ON sh.home_id=hpb.home_id LEFT JOIN `DB_PREFIXremote_server_ips` i ON i.ip_id=hpb.ip_id LEFT JOIN `DB_PREFIXport_pools` pp ON pp.pool_id=hpb.pool_id LEFT JOIN `DB_PREFIXconfig_homes` ch ON ch.home_cfg_id=hpb.home_cfg_id {$status_sql} ORDER BY hpb.created_at DESC, hpb.block_id DESC LIMIT 200");
		echo "<h3>Allocated Blocks</h3>";
		echo "<form method='get' action='home.php'><input type='hidden' name='m' value='server' /><input type='hidden' name='p' value='port_blocks' />Status: " . port_blocks_select('block_status', array('active' => 'active', 'pending' => 'pending', 'released' => 'released', 'needs-review' => 'needs-review', 'conflict' => 'conflict'), $status_filter, 'All') . " <input type='submit' value='Filter' /></form>";
		echo "<div class='port-blocks-wrap'><table class='center'><tr><th>Block</th><th>Home</th><th>Game</th><th>IP</th><th>Primary</th><th>Range</th><th>Size</th><th>Source</th><th>Status</th><th>Created</th></tr>";
		foreach ($blocks as $row) {
			echo "<tr><td>" . intval($row['block_id']) . "</td><td>#" . intval($row['home_id']) . " " . port_blocks_h($row['home_name']) . "</td><td>" . port_blocks_h($row['game_name']) . "</td><td>" . port_blocks_h($row['ip']) . "</td><td>" . intval($row['primary_port']) . "</td><td>" . intval($row['block_start']) . "-" . intval($row['block_end']) . "</td><td>" . intval($row['block_size']) . "</td><td>" . port_blocks_h($row['allocation_source']) . "</td><td>" . port_blocks_h($row['status']) . "</td><td>" . port_blocks_h($row['created_at']) . "</td></tr>";
		}
		if (empty($blocks)) {
			echo "<tr><td colspan='10'>No allocated blocks found.</td></tr>";
		}
		echo "</table></div>";

		echo "<h3>Allocation Events</h3>";
		if (!empty($schema['events'])) {
			$events = port_blocks_rows($db, "SELECT pae.*, pp.name AS pool_name, i.ip FROM `DB_PREFIXport_allocation_events` pae LEFT JOIN `DB_PREFIXport_pools` pp ON pp.pool_id=pae.pool_id LEFT JOIN `DB_PREFIXremote_server_ips` i ON i.ip_id=pae.ip_id ORDER BY pae.created_at DESC, pae.event_id DESC LIMIT 50");
			echo "<div class='port-blocks-wrap'><table class='center'><tr><th>Event</th><th>Type</th><th>Home</th><th>IP</th><th>Source</th><th>Message</th><th>Created</th></tr>";
			foreach ($events as $event) {
				echo "<tr><td>" . intval($event['event_id']) . "</td><td>" . port_blocks_h($event['event_type']) . "</td><td>" . intval($event['home_id']) . "</td><td>" . port_blocks_h($event['ip']) . "</td><td>" . port_blocks_h($event['source']) . "</td><td>" . port_blocks_h($event['message']) . "</td><td>" . port_blocks_h($event['created_at']) . "</td></tr>";
			}
			if (empty($events)) {
				echo "<tr><td colspan='7'>No allocation events found.</td></tr>";
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
		print_failure('Global port allocation tables are missing. Run the server module update or apply sql/migrations/0003_global_port_allocation.sql before using this admin page.');
		if (!empty($schema['missing'])) {
			echo "<p>Missing: " . port_blocks_h(implode(', ', $schema['missing'])) . "</p>";
		}
		return;
	}

	port_blocks_handle_post($db, $schema);
	port_blocks_render($db, $schema);
}
?>
