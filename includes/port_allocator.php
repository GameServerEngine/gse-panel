<?php

if (!function_exists('gsp_port_allocator_int')) {
	function gsp_port_allocator_int($value)
	{
		return intval($value);
	}
}

if (!function_exists('gsp_port_allocator_ranges_overlap')) {
	function gsp_port_allocator_ranges_overlap($start_a, $end_a, $start_b, $end_b)
	{
		return gsp_port_allocator_int($start_a) <= gsp_port_allocator_int($end_b)
			&& gsp_port_allocator_int($end_a) >= gsp_port_allocator_int($start_b);
	}
}

if (!function_exists('gsp_port_allocator_candidate_starts')) {
	function gsp_port_allocator_candidate_starts($range_start, $range_end, $block_size)
	{
		$range_start = gsp_port_allocator_int($range_start);
		$range_end = gsp_port_allocator_int($range_end);
		$block_size = max(1, gsp_port_allocator_int($block_size));
		$starts = array();
		for ($port = $range_start; $port + $block_size - 1 <= $range_end; $port++) {
			$starts[] = $port;
		}
		return $starts;
	}
}

if (!function_exists('gsp_port_allocator_table_exists')) {
	function gsp_port_allocator_table_exists($db, $db_prefix, $table)
	{
		$name = $db_prefix . $table;
		$escaped = str_replace('_', '\_', $db->realEscapeSingle($name));
		$rows = $db->resultQuery("SHOW TABLES LIKE '{$escaped}'");
		return is_array($rows) && count($rows) > 0;
	}
}

if (!function_exists('gsp_port_allocator_schema_available')) {
	function gsp_port_allocator_schema_available($db, $db_prefix)
	{
		foreach (array('port_pools', 'static_port_reservations', 'home_port_blocks') as $table) {
			if (!gsp_port_allocator_table_exists($db, $db_prefix, $table)) {
				return false;
			}
		}
		return true;
	}
}

if (!function_exists('gsp_port_allocator_rows')) {
	function gsp_port_allocator_rows($db, $query)
	{
		$rows = $db->resultQuery($query);
		return is_array($rows) ? $rows : array();
	}
}

if (!function_exists('gsp_port_allocator_global_block_size')) {
	function gsp_port_allocator_global_block_size($db, $db_prefix)
	{
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT `value` FROM `{$db_prefix}settings` WHERE `setting`='port_block_size' LIMIT 1"
		);
		if (empty($rows)) {
			$db->query("INSERT INTO `{$db_prefix}settings` (`setting`,`value`) VALUES ('port_block_size','10') ON DUPLICATE KEY UPDATE `value`=`value`");
		}
		$value = !empty($rows) ? gsp_port_allocator_int($rows[0]['value'] ?? 10) : 10;
		if ($value < 1 || $value > 1000) {
			$value = 10;
		}
		return $value;
	}
}

if (!function_exists('gsp_port_allocator_game_context_by_cfg')) {
	function gsp_port_allocator_game_context_by_cfg($db, $db_prefix, $home_cfg_id)
	{
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT home_cfg_id, game_key, game_name, home_cfg_file
			   FROM `{$db_prefix}config_homes`
			  WHERE home_cfg_id=" . gsp_port_allocator_int($home_cfg_id) . "
			  LIMIT 1"
		);
		return empty($rows) ? array() : $rows[0];
	}
}

if (!function_exists('gsp_port_allocator_xml_query_names')) {
	function gsp_port_allocator_xml_query_names($home_cfg_file)
	{
		$file = basename((string)$home_cfg_file);
		if ($file === '' || !function_exists('simplexml_load_file')) {
			return array();
		}
		$path = dirname(__DIR__) . '/modules/config_games/server_configs/' . $file;
		if (!is_readable($path)) {
			return array();
		}
		$xml = @simplexml_load_file($path);
		if (!$xml) {
			return array();
		}
		return array(
			'lgsl_query_name' => isset($xml->lgsl_query_name) ? trim((string)$xml->lgsl_query_name) : '',
			'gameq_query_name' => isset($xml->gameq_query_name) ? trim((string)$xml->gameq_query_name) : '',
		);
	}
}

if (!function_exists('gsp_port_allocator_lgsl_default_port')) {
	function gsp_port_allocator_lgsl_default_port($query_name)
	{
		$query_name = trim((string)$query_name);
		if ($query_name === '') {
			return 0;
		}
		if (!function_exists('lgsl_port_conversion')) {
			$lgsl = dirname(__DIR__) . '/protocol/lgsl/lgsl_protocol.php';
			if (is_readable($lgsl)) {
				require_once $lgsl;
			}
		}
		if (!function_exists('lgsl_port_conversion')) {
			return 0;
		}
		$ports = lgsl_port_conversion($query_name, '', '', '');
		return is_array($ports) && !empty($ports[0]) ? gsp_port_allocator_int($ports[0]) : 0;
	}
}

if (!function_exists('gsp_port_allocator_gameq_default_port')) {
	function gsp_port_allocator_gameq_default_port($query_name)
	{
		$query_name = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$query_name);
		if ($query_name === '') {
			return 0;
		}
		$candidates = array(
			dirname(__DIR__) . '/protocol/GameQ/gameq/protocols/' . strtolower($query_name) . '.php',
			dirname(__DIR__) . '/protocol/GameQ/Protocols/' . ucfirst(strtolower($query_name)) . '.php',
		);
		foreach ($candidates as $path) {
			if (!is_readable($path)) {
				continue;
			}
			$source = file_get_contents($path);
			if (preg_match('/protected\s+\$port\s*=\s*(\d+)/', $source, $match)) {
				return gsp_port_allocator_int($match[1]);
			}
		}
		return 0;
	}
}

if (!function_exists('gsp_port_allocator_planned_default_port')) {
	function gsp_port_allocator_planned_default_port(array $context)
	{
		$haystack = strtolower(
			(string)($context['game_key'] ?? '') . ' ' .
			(string)($context['game_name'] ?? '') . ' ' .
			(string)($context['home_cfg_file'] ?? '')
		);
		$patterns = array(
			2302 => array('arma', 'dayz'),
			2456 => array('valheim'),
			7777 => array('ark', 'atlas', 'conan', 'killingfloor2', 'mordhau', 'sandstorm', 'unreal'),
			25565 => array('minecraft', 'spigot', 'feedthebeast'),
			26900 => array('7daystodie', '7_days_to_die', '7 days to die', '7dtd'),
			27015 => array('source', 'counter', 'tf2', 'garrys', 'half-life', 'hl2', 'dod', 'insurgency', 'unturned', 'theforest'),
			28015 => array('rust'),
			28960 => array('quake', 'callofduty', 'call of duty', 'cod', 'jedi', 'urban_terror', 'warsow'),
		);
		foreach ($patterns as $port => $needles) {
			foreach ($needles as $needle) {
				if ($needle !== '' && strpos($haystack, $needle) !== false) {
					return $port;
				}
			}
		}
		return 0;
	}
}

if (!function_exists('gsp_port_allocator_game_default_port')) {
	function gsp_port_allocator_game_default_port($db, $db_prefix, $home_cfg_id, &$source = 'fallback')
	{
		$context = gsp_port_allocator_game_context_by_cfg($db, $db_prefix, $home_cfg_id);
		$planned = gsp_port_allocator_planned_default_port($context);
		if ($planned > 0) {
			$source = 'game_metadata';
			return $planned;
		}

		$xml = gsp_port_allocator_xml_query_names($context['home_cfg_file'] ?? '');
		$port = gsp_port_allocator_lgsl_default_port($xml['lgsl_query_name'] ?? '');
		if ($port > 0) {
			$source = 'lgsl_metadata';
			return $port;
		}
		$port = gsp_port_allocator_gameq_default_port($xml['gameq_query_name'] ?? '');
		if ($port > 0) {
			$source = 'gameq_metadata';
			return $port;
		}

		$source = 'fallback';
		return 27015;
	}
}

if (!function_exists('gsp_port_allocator_log_event')) {
	function gsp_port_allocator_log_event($db, $db_prefix, $event_type, array $candidate, $message, array $metadata = array())
	{
		if (!gsp_port_allocator_table_exists($db, $db_prefix, 'port_allocation_events')) {
			return;
		}
		$allowed = array('allocated','released','imported','conflict_detected','reservation_added','reservation_removed','manual_override','failed_allocation');
		if (!in_array($event_type, $allowed, true)) {
			$event_type = 'failed_allocation';
		}
		$message = $db->realEscapeSingle(substr((string)$message, 0, 255));
		$metadata_json = $db->realEscapeSingle(json_encode($metadata));
		$block_id = !empty($candidate['block_id']) ? gsp_port_allocator_int($candidate['block_id']) : 'NULL';
		$home_id = !empty($candidate['home_id']) ? gsp_port_allocator_int($candidate['home_id']) : 'NULL';
		$ip_id = !empty($candidate['ip_id']) ? gsp_port_allocator_int($candidate['ip_id']) : 'NULL';
		$pool_id = !empty($candidate['pool_id']) ? gsp_port_allocator_int($candidate['pool_id']) : 'NULL';
		$db->query(
			"INSERT INTO `{$db_prefix}port_allocation_events`
			 (`block_id`, `home_id`, `ip_id`, `pool_id`, `event_type`, `source`, `message`, `metadata_json`, `created_at`)
			 VALUES ({$block_id}, {$home_id}, {$ip_id}, {$pool_id}, '" . $db->realEscapeSingle($event_type) . "', 'allocator', '{$message}', '{$metadata_json}', NOW())"
		);
	}
}

if (!function_exists('gsp_port_allocator_remote_ip_ids')) {
	function gsp_port_allocator_remote_ip_ids($db, $db_prefix, $remote_server_id, $preferred_ip_id = null)
	{
		$remote_server_id = gsp_port_allocator_int($remote_server_id);
		if ($preferred_ip_id !== null) {
			$preferred_ip_id = gsp_port_allocator_int($preferred_ip_id);
			$rows = gsp_port_allocator_rows(
				$db,
				"SELECT ip_id FROM `{$db_prefix}remote_server_ips` WHERE ip_id={$preferred_ip_id} AND remote_server_id={$remote_server_id} LIMIT 1"
			);
			return empty($rows) ? array() : array($preferred_ip_id);
		}

		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT ip_id FROM `{$db_prefix}remote_server_ips` WHERE remote_server_id={$remote_server_id} ORDER BY ip_id ASC"
		);
		$ids = array();
		foreach ($rows as $row) {
			$ip_id = gsp_port_allocator_int($row['ip_id'] ?? 0);
			if ($ip_id > 0) {
				$ids[] = $ip_id;
			}
		}
		return $ids;
	}
}

if (!function_exists('gsp_port_allocator_home_context')) {
	function gsp_port_allocator_home_context($db, $db_prefix, $home_id, $ip_id = null)
	{
		$home_id = gsp_port_allocator_int($home_id);
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT home_id, remote_server_id, home_cfg_id FROM `{$db_prefix}server_homes` WHERE home_id={$home_id} LIMIT 1"
		);
		if (empty($rows)) {
			return array();
		}
		$context = $rows[0];
		if ($ip_id !== null) {
			$ip_id = gsp_port_allocator_int($ip_id);
			$ipRows = gsp_port_allocator_rows(
				$db,
				"SELECT remote_server_id FROM `{$db_prefix}remote_server_ips` WHERE ip_id={$ip_id} LIMIT 1"
			);
			if (!empty($ipRows)) {
				$context['remote_server_id'] = $ipRows[0]['remote_server_id'];
			}
		}
		return $context;
	}
}

if (!function_exists('gsp_port_allocator_pool_candidates')) {
	function gsp_port_allocator_pool_candidates($db, $db_prefix, $home_cfg_id)
	{
		$home_cfg_id = gsp_port_allocator_int($home_cfg_id);
		$candidates = array();
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT pp.pool_id, pp.pool_key, pp.range_start, pp.range_end,
			        COALESCE(ppg.block_size_override, pp.block_size, 10) AS block_size,
			        ppg.role, ppg.priority AS game_priority, pp.priority AS pool_priority
			   FROM `{$db_prefix}port_pool_games` ppg
			   INNER JOIN `{$db_prefix}port_pools` pp ON pp.pool_id = ppg.pool_id
			  WHERE ppg.home_cfg_id={$home_cfg_id}
			    AND ppg.enabled=1
			    AND pp.enabled=1
			    AND ppg.role IN ('preferred','compatible','fallback')
			  ORDER BY FIELD(ppg.role, 'preferred', 'compatible', 'fallback'), ppg.priority ASC, pp.priority ASC, pp.pool_id ASC"
		);
		foreach ($rows as $row) {
			$candidates[] = array(
				'pool_id' => gsp_port_allocator_int($row['pool_id'] ?? 0),
				'pool_key' => (string)($row['pool_key'] ?? ''),
				'range_start' => gsp_port_allocator_int($row['range_start'] ?? 0),
				'range_end' => gsp_port_allocator_int($row['range_end'] ?? 0),
				'block_size' => max(1, gsp_port_allocator_int($row['block_size'] ?? 10)),
				'allocation_source' => (string)($row['role'] ?? 'preferred'),
			);
		}
		return $candidates;
	}
}

if (!function_exists('gsp_port_allocator_legacy_candidates')) {
	function gsp_port_allocator_legacy_candidates($db, $db_prefix, $ip_id, $home_cfg_id)
	{
		$ip_id = gsp_port_allocator_int($ip_id);
		$home_cfg_id = gsp_port_allocator_int($home_cfg_id);
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT range_id, start_port, end_port, port_increment
			   FROM `{$db_prefix}arrange_ports`
			  WHERE ip_id={$ip_id} AND home_cfg_id={$home_cfg_id}
			  ORDER BY range_id ASC"
		);
		if (empty($rows)) {
			$rows = gsp_port_allocator_rows(
				$db,
				"SELECT range_id, start_port, end_port, port_increment
				   FROM `{$db_prefix}arrange_ports`
				  WHERE ip_id={$ip_id} AND home_cfg_id=0
				  ORDER BY range_id ASC"
			);
		}

		$candidates = array();
		foreach ($rows as $row) {
			$increment = max(10, gsp_port_allocator_int($row['port_increment'] ?? 10));
			$candidates[] = array(
				'pool_id' => 0,
				'pool_key' => 'legacy-arrange-ports',
				'range_start' => gsp_port_allocator_int($row['start_port'] ?? 0),
				'range_end' => gsp_port_allocator_int($row['end_port'] ?? 0),
				'block_size' => $increment,
				'allocation_source' => 'legacy_imported',
				'legacy_range_id' => gsp_port_allocator_int($row['range_id'] ?? 0),
			);
		}
		return $candidates;
	}
}

if (!function_exists('gsp_port_allocator_overflow_candidate')) {
	function gsp_port_allocator_overflow_candidate($db, $db_prefix)
	{
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT pool_id, pool_key, range_start, range_end, block_size
			   FROM `{$db_prefix}port_pools`
			  WHERE pool_key='gse-overflow-32000' AND enabled=1
			  LIMIT 1"
		);
		if (empty($rows)) {
			return array();
		}
		$row = $rows[0];
		return array(array(
			'pool_id' => gsp_port_allocator_int($row['pool_id'] ?? 0),
			'pool_key' => (string)($row['pool_key'] ?? 'gse-overflow-32000'),
			'range_start' => gsp_port_allocator_int($row['range_start'] ?? 32000),
			'range_end' => gsp_port_allocator_int($row['range_end'] ?? 32999),
			'block_size' => max(10, gsp_port_allocator_int($row['block_size'] ?? 10)),
			'allocation_source' => 'overflow',
		));
	}
}

if (!function_exists('gsp_port_allocator_block_has_conflict')) {
	function gsp_port_allocator_block_has_conflict($db, $db_prefix, $ip_id, $remote_server_id, $block_start, $block_end, $exclude_home_id = 0)
	{
		$ip_id = gsp_port_allocator_int($ip_id);
		$remote_server_id = gsp_port_allocator_int($remote_server_id);
		$block_start = gsp_port_allocator_int($block_start);
		$block_end = gsp_port_allocator_int($block_end);
		$exclude_home_id = gsp_port_allocator_int($exclude_home_id);
		$excludeBlockHome = $exclude_home_id > 0 ? "AND (home_id IS NULL OR home_id <> {$exclude_home_id})" : '';
		$blockRows = gsp_port_allocator_rows(
			$db,
			"SELECT block_id
			   FROM `{$db_prefix}home_port_blocks`
			  WHERE ip_id={$ip_id}
			    AND status IN ('active','pending','needs-review','conflict')
			    {$excludeBlockHome}
			    AND block_start <= {$block_end}
			    AND block_end >= {$block_start}
			  LIMIT 1"
		);
		if (!empty($blockRows)) {
			return true;
		}

		$excludeHomeIp = $exclude_home_id > 0 ? "AND home_id <> {$exclude_home_id}" : '';
		$primaryRows = gsp_port_allocator_rows(
			$db,
			"SELECT home_id
			   FROM `{$db_prefix}home_ip_ports`
			  WHERE ip_id={$ip_id}
			    AND port BETWEEN {$block_start} AND {$block_end}
			    {$excludeHomeIp}
			  LIMIT 1"
		);
		if (!empty($primaryRows)) {
			return true;
		}

		$reservationRows = gsp_port_allocator_rows(
			$db,
			"SELECT reservation_id
			   FROM `{$db_prefix}static_port_reservations`
			  WHERE status='active'
			    AND (scope='global'
			         OR (scope='ip' AND ip_id={$ip_id})
			         OR (scope='remote_server' AND remote_server_id={$remote_server_id}))
			    AND port_start <= {$block_end}
			    AND port_end >= {$block_start}
			  LIMIT 1"
		);
		return !empty($reservationRows);
	}
}

if (!function_exists('gsp_port_allocator_offsets_fit')) {
	function gsp_port_allocator_offsets_fit($db, $db_prefix, $home_cfg_id, $primary_port, $block_start, $block_end, &$reason = '')
	{
		if (!gsp_port_allocator_table_exists($db, $db_prefix, 'game_port_offsets')) {
			return true;
		}
		$home_cfg_id = gsp_port_allocator_int($home_cfg_id);
		$primary_port = gsp_port_allocator_int($primary_port);
		$block_start = gsp_port_allocator_int($block_start);
		$block_end = gsp_port_allocator_int($block_end);
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT port_role, token_name, offset_type, offset_value, fits_standard_block
			   FROM `{$db_prefix}game_port_offsets`
			  WHERE home_cfg_id={$home_cfg_id} AND required=1"
		);
		foreach ($rows as $row) {
			if (gsp_port_allocator_int($row['fits_standard_block'] ?? 1) !== 1) {
				$reason = 'required offset marked outside standard block';
				return false;
			}
			$type = (string)($row['offset_type'] ?? 'add');
			$value = gsp_port_allocator_int($row['offset_value'] ?? 0);
			if ($type === 'subtract') {
				$port = $primary_port - $value;
			} elseif ($type === 'absolute') {
				$port = $value;
			} else {
				$port = $primary_port + $value;
			}
			if ($port < $block_start || $port > $block_end) {
				$reason = 'required offset resolves outside allocated block';
				return false;
			}
		}
		return true;
	}
}

if (!function_exists('gsp_port_allocator_find_candidate')) {
	function gsp_port_allocator_find_candidate($db, $db_prefix, $remote_server_id, $home_cfg_id, $preferred_ip_id = null, $manual_port = null, $allow_admin_override = false)
	{
		if (!gsp_port_allocator_schema_available($db, $db_prefix)) {
			return array('ok' => false, 'error' => 'global port allocation schema is not installed', 'schema_unavailable' => true);
		}

		$remote_server_id = gsp_port_allocator_int($remote_server_id);
		$home_cfg_id = gsp_port_allocator_int($home_cfg_id);
		$ip_ids = gsp_port_allocator_remote_ip_ids($db, $db_prefix, $remote_server_id, $preferred_ip_id);
		if (empty($ip_ids)) {
			return array('ok' => false, 'error' => "No IP addresses are configured for remote server #{$remote_server_id}.");
		}

		$block_size = gsp_port_allocator_global_block_size($db, $db_prefix);
		$default_source = 'fallback';
		$default_port = gsp_port_allocator_game_default_port($db, $db_prefix, $home_cfg_id, $default_source);
		$default_port = max(1, min(65535, gsp_port_allocator_int($default_port)));
		$range_end = 65535;
		foreach ($ip_ids as $ip_id) {
			if ($manual_port !== null && $allow_admin_override) {
				$manual_start = gsp_port_allocator_int($manual_port);
				$manual_end = $manual_start + $block_size - 1;
				if ($manual_start >= 1 && $manual_end <= 65535 && !gsp_port_allocator_block_has_conflict($db, $db_prefix, $ip_id, $remote_server_id, $manual_start, $manual_end)) {
					return array(
						'ok' => true,
						'ip_id' => gsp_port_allocator_int($ip_id),
						'pool_id' => 0,
						'pool_key' => 'automatic',
						'block_start' => $manual_start,
						'block_end' => $manual_end,
						'primary_port' => $manual_start,
						'block_size' => $block_size,
						'allocation_source' => 'admin_override',
						'legacy_range_id' => 0,
						'default_port' => $default_port,
						'default_port_source' => $default_source,
					);
				}
				continue;
			}

			foreach (gsp_port_allocator_candidate_starts($default_port, $range_end, $block_size) as $block_start) {
				$block_end = $block_start + $block_size - 1;
				if (gsp_port_allocator_block_has_conflict($db, $db_prefix, $ip_id, $remote_server_id, $block_start, $block_end)) {
					continue;
				}
				return array(
					'ok' => true,
					'ip_id' => gsp_port_allocator_int($ip_id),
					'pool_id' => 0,
					'pool_key' => 'automatic',
					'block_start' => $block_start,
					'block_end' => $block_end,
					'primary_port' => $block_start,
					'block_size' => $block_size,
					'allocation_source' => $default_source === 'fallback' ? 'fallback' : 'preferred',
					'legacy_range_id' => 0,
					'default_port' => $default_port,
					'default_port_source' => $default_source,
				);
			}
		}

		$error = "No available contiguous {$block_size}-port block for remote server #{$remote_server_id}, home_cfg_id #{$home_cfg_id}, starting at default port {$default_port}.";
		gsp_port_allocator_log_event($db, $db_prefix, 'failed_allocation', array(), $error, array(
			'remote_server_id' => $remote_server_id,
			'home_cfg_id' => $home_cfg_id,
			'default_port' => $default_port,
			'default_port_source' => $default_source,
			'block_size' => $block_size,
		));
		return array('ok' => false, 'error' => $error);
	}
}

if (!function_exists('gsp_port_allocator_preview_next_port')) {
	function gsp_port_allocator_preview_next_port($db, $db_prefix, $ip_id, $home_cfg_id)
	{
		$contextRows = gsp_port_allocator_rows(
			$db,
			"SELECT remote_server_id FROM `{$db_prefix}remote_server_ips` WHERE ip_id=" . gsp_port_allocator_int($ip_id) . " LIMIT 1"
		);
		if (empty($contextRows)) {
			return false;
		}
		$candidate = gsp_port_allocator_find_candidate($db, $db_prefix, $contextRows[0]['remote_server_id'], $home_cfg_id, $ip_id);
		return !empty($candidate['ok']) ? $candidate['primary_port'] : false;
	}
}

if (!function_exists('gsp_port_allocator_insert_block')) {
	function gsp_port_allocator_insert_block($db, $db_prefix, $home_id, $home_cfg_id, array $candidate, $notes = '')
	{
		$home_id = gsp_port_allocator_int($home_id);
		$home_cfg_id = gsp_port_allocator_int($home_cfg_id);
		$pool_id = gsp_port_allocator_int($candidate['pool_id'] ?? 0);
		$poolSql = $pool_id > 0 ? (string)$pool_id : 'NULL';
		$legacy_range_id = gsp_port_allocator_int($candidate['legacy_range_id'] ?? 0);
		$legacySql = $legacy_range_id > 0 ? (string)$legacy_range_id : 'NULL';
		$source = $db->realEscapeSingle($candidate['allocation_source'] ?? 'preferred');
		$notes = $db->realEscapeSingle($notes);
		$ok = $db->query(
			"INSERT INTO `{$db_prefix}home_port_blocks`
			 (`home_id`, `ip_id`, `pool_id`, `home_cfg_id`, `block_start`, `block_end`, `primary_port`, `block_size`, `allocation_source`, `status`, `legacy_range_id`, `notes`, `created_at`)
			 VALUES
			 ({$home_id}, " . gsp_port_allocator_int($candidate['ip_id']) . ", {$poolSql}, {$home_cfg_id}, " .
			 gsp_port_allocator_int($candidate['block_start']) . ", " . gsp_port_allocator_int($candidate['block_end']) . ", " .
			 gsp_port_allocator_int($candidate['primary_port']) . ", " . gsp_port_allocator_int($candidate['block_size']) . ",
			 '{$source}', 'active', {$legacySql}, '{$notes}', NOW())"
		);
		if (!$ok) {
			return false;
		}
		$rows = gsp_port_allocator_rows(
			$db,
			"SELECT block_id FROM `{$db_prefix}home_port_blocks`
			  WHERE home_id={$home_id}
			    AND ip_id=" . gsp_port_allocator_int($candidate['ip_id']) . "
			    AND primary_port=" . gsp_port_allocator_int($candidate['primary_port']) . "
			    AND status='active'
			  ORDER BY block_id DESC
			  LIMIT 1"
		);
		return empty($rows) ? true : gsp_port_allocator_int($rows[0]['block_id']);
	}
}

if (!function_exists('gsp_port_allocator_mirror_home_ip')) {
	function gsp_port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $ip_id, $port)
	{
		$home_id = gsp_port_allocator_int($home_id);
		$ip_id = gsp_port_allocator_int($ip_id);
		$port = gsp_port_allocator_int($port);
		$existing = gsp_port_allocator_rows(
			$db,
			"SELECT home_id FROM `{$db_prefix}home_ip_ports` WHERE ip_id={$ip_id} AND port={$port} LIMIT 1"
		);
		if (!empty($existing)) {
			return gsp_port_allocator_int($existing[0]['home_id'] ?? 0) === $home_id;
		}
		$ok = $db->query(
			"INSERT INTO `{$db_prefix}home_ip_ports` (`ip_id`, `port`, `home_id`)
			 SELECT {$ip_id}, {$port}, {$home_id}
			 FROM DUAL
			 WHERE NOT EXISTS (
				SELECT 1 FROM `{$db_prefix}home_ip_ports` WHERE ip_id={$ip_id} AND port={$port}
			 )"
		);
		if (!$ok) {
			return false;
		}
		$verify = gsp_port_allocator_rows(
			$db,
			"SELECT home_id FROM `{$db_prefix}home_ip_ports` WHERE ip_id={$ip_id} AND port={$port} AND home_id={$home_id} LIMIT 1"
		);
		return !empty($verify);
	}
}

if (!function_exists('gsp_port_allocate_home_block')) {
	function gsp_port_allocate_home_block($db, $db_prefix, $home_id, $remote_server_id, $home_cfg_id)
	{
		if (!gsp_port_allocator_schema_available($db, $db_prefix)) {
			return array('ok' => false, 'error' => 'global port allocation schema is not installed', 'schema_unavailable' => true);
		}
		$home_id = gsp_port_allocator_int($home_id);
		$existing = gsp_port_allocator_rows(
			$db,
			"SELECT block_id, ip_id, primary_port, block_start, block_end, block_size, allocation_source
			   FROM `{$db_prefix}home_port_blocks`
			  WHERE home_id={$home_id} AND status='active'
			  ORDER BY block_id ASC
			  LIMIT 1"
		);
		if (!empty($existing)) {
			$row = $existing[0];
			if (!gsp_port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $row['ip_id'], $row['primary_port'])) {
				return array('ok' => false, 'error' => 'Existing block could not be mirrored to home_ip_ports.');
			}
			return array(
				'ok' => true,
				'block_id' => gsp_port_allocator_int($row['block_id'] ?? 0),
				'ip_id' => gsp_port_allocator_int($row['ip_id'] ?? 0),
				'port' => gsp_port_allocator_int($row['primary_port'] ?? 0),
				'block_start' => gsp_port_allocator_int($row['block_start'] ?? 0),
				'block_end' => gsp_port_allocator_int($row['block_end'] ?? 0),
				'block_size' => gsp_port_allocator_int($row['block_size'] ?? 0),
				'allocation_source' => (string)($row['allocation_source'] ?? ''),
			);
		}

		$candidate = gsp_port_allocator_find_candidate($db, $db_prefix, $remote_server_id, $home_cfg_id);
		if (empty($candidate['ok'])) {
			return $candidate;
		}
		$block_id = gsp_port_allocator_insert_block($db, $db_prefix, $home_id, $home_cfg_id, $candidate);
		if (!$block_id) {
			return array('ok' => false, 'error' => 'Could not write home_port_blocks allocation.');
		}
		if (!gsp_port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $candidate['ip_id'], $candidate['primary_port'])) {
			$db->query("UPDATE `{$db_prefix}home_port_blocks` SET status='released', released_at=NOW(), updated_at=NOW() WHERE block_id=" . gsp_port_allocator_int($block_id));
			return array('ok' => false, 'error' => 'Could not mirror allocated primary port to home_ip_ports.');
		}
		$candidate['ok'] = true;
		$candidate['block_id'] = gsp_port_allocator_int($block_id);
		$candidate['home_id'] = $home_id;
		$candidate['port'] = $candidate['primary_port'];
		gsp_port_allocator_log_event($db, $db_prefix, 'allocated', $candidate, 'Automatic port block allocated', array(
			'default_port' => $candidate['default_port'] ?? 0,
			'default_port_source' => $candidate['default_port_source'] ?? '',
			'block_size' => $candidate['block_size'] ?? 0,
		));
		return $candidate;
	}
}

if (!function_exists('gsp_port_reserve_manual_home_block')) {
	function gsp_port_reserve_manual_home_block($db, $db_prefix, $home_id, $ip_id, $port, $source = 'admin_override')
	{
		$home_id = gsp_port_allocator_int($home_id);
		$ip_id = gsp_port_allocator_int($ip_id);
		$port = gsp_port_allocator_int($port);
		$context = gsp_port_allocator_home_context($db, $db_prefix, $home_id, $ip_id);
		if (empty($context)) {
			return array('ok' => false, 'error' => "Home #{$home_id} was not found.");
		}
		$candidate = gsp_port_allocator_find_candidate(
			$db,
			$db_prefix,
			$context['remote_server_id'],
			$context['home_cfg_id'],
			$ip_id,
			$port,
			true
		);
		if (empty($candidate['ok'])) {
			return $candidate;
		}
		$candidate['allocation_source'] = $source;
		$block_id = gsp_port_allocator_insert_block($db, $db_prefix, $home_id, $context['home_cfg_id'], $candidate, 'manual compatibility reservation');
		if (!$block_id) {
			return array('ok' => false, 'error' => 'Could not write manual home_port_blocks allocation.');
		}
		if (!gsp_port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $ip_id, $port)) {
			$db->query("UPDATE `{$db_prefix}home_port_blocks` SET status='released', released_at=NOW(), updated_at=NOW() WHERE block_id=" . gsp_port_allocator_int($block_id));
			return array('ok' => false, 'error' => 'Could not write home_ip_ports compatibility row.');
		}
		$candidate['block_id'] = gsp_port_allocator_int($block_id);
		$candidate['home_id'] = $home_id;
		$candidate['port'] = $port;
		gsp_port_allocator_log_event($db, $db_prefix, 'manual_override', $candidate, 'Manual compatibility port block reserved', array(
			'requested_port' => $port,
			'block_size' => $candidate['block_size'] ?? 0,
		));
		return $candidate;
	}
}

if (!function_exists('gsp_port_release_home_block')) {
	function gsp_port_release_home_block($db, $db_prefix, $home_id, $ip_id, $port)
	{
		if (!gsp_port_allocator_schema_available($db, $db_prefix)) {
			return true;
		}
		return $db->query(
			"UPDATE `{$db_prefix}home_port_blocks`
			    SET status='released', released_at=NOW(), updated_at=NOW()
			  WHERE home_id=" . gsp_port_allocator_int($home_id) . "
			    AND ip_id=" . gsp_port_allocator_int($ip_id) . "
			    AND primary_port=" . gsp_port_allocator_int($port) . "
			    AND status IN ('active','pending','needs-review','conflict')"
		);
	}
}

?>
