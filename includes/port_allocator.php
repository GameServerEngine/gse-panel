<?php
/*
 * Port allocation for game servers.
 *
 * Every agent IP has its own range (2000-24000 unless the port_ranges table sets one) split into
 * blocks of port_block_size ports (100). A game server gets one whole block: its game port is the
 * first port of the block (2000, 2100, 2200 ...) and every extra port the game uses (query, RCON,
 * client, SourceTV ...) is the game port plus a small offset from <reserve_ports> in its XML, so it
 * lies inside the same block. A block that holds a reserved port (static_port_reservations: the
 * agent, RDP, MySQL, Proxmox ...) or another server's port is skipped. The same block numbers are
 * used on every agent IP, because each IP has its own ports.
 */
require_once __DIR__ . '/game_ip.php';
defined('PORT_ALLOCATOR_RANGE_START') || define('PORT_ALLOCATOR_RANGE_START', 2000);
defined('PORT_ALLOCATOR_RANGE_END') || define('PORT_ALLOCATOR_RANGE_END', 24000);
defined('PORT_ALLOCATOR_BLOCK_SIZE') || define('PORT_ALLOCATOR_BLOCK_SIZE', 100);

if (!function_exists('port_allocator_int')) {
	function port_allocator_int($value)
	{
		return intval($value);
	}
}

if (!function_exists('port_allocator_ranges_overlap')) {
	function port_allocator_ranges_overlap($start_a, $end_a, $start_b, $end_b)
	{
		return port_allocator_int($start_a) <= port_allocator_int($end_b)
			&& port_allocator_int($end_a) >= port_allocator_int($start_b);
	}
}

if (!function_exists('port_allocator_candidate_starts')) {
	/** First ports of the whole blocks inside a range, on block boundaries (2000, 2100, ...). */
	function port_allocator_candidate_starts($range_start, $range_end, $block_size)
	{
		$range_start = port_allocator_int($range_start);
		$range_end = port_allocator_int($range_end);
		$block_size = max(1, port_allocator_int($block_size));
		$starts = array();
		$first = (int)(ceil($range_start / $block_size) * $block_size);
		for ($port = $first; $port + $block_size - 1 <= $range_end; $port += $block_size) {
			$starts[] = $port;
		}
		return $starts;
	}
}
if (!function_exists('port_allocator_table_exists')) {
	function port_allocator_table_exists($db, $db_prefix, $table)
	{
		$name = $db_prefix . $table;
		$escaped = str_replace('_', '\_', $db->realEscapeSingle($name));
		$rows = $db->resultQuery("SHOW TABLES LIKE '{$escaped}'");
		return is_array($rows) && count($rows) > 0;
	}
}

if (!function_exists('port_allocator_schema_available')) {
	function port_allocator_schema_available($db, $db_prefix)
	{
		foreach (array('port_pools', 'static_port_reservations', 'home_port_blocks') as $table) {
			if (!port_allocator_table_exists($db, $db_prefix, $table)) {
				return false;
			}
		}
		return true;
	}
}

if (!function_exists('port_allocator_rows')) {
	function port_allocator_rows($db, $query)
	{
		$rows = $db->resultQuery($query);
		return is_array($rows) ? $rows : array();
	}
}

if (!function_exists('port_allocator_global_block_size')) {
	function port_allocator_global_block_size($db, $db_prefix)
	{
		$rows = port_allocator_rows(
			$db,
			"SELECT `value` FROM `{$db_prefix}settings` WHERE `setting`='port_block_size' LIMIT 1"
		);
		if (empty($rows)) {
			$db->query("INSERT INTO `{$db_prefix}settings` (`setting`,`value`) VALUES ('port_block_size','" . PORT_ALLOCATOR_BLOCK_SIZE . "') ON DUPLICATE KEY UPDATE `value`=`value`");
		}
		$value = !empty($rows) ? port_allocator_int($rows[0]['value'] ?? PORT_ALLOCATOR_BLOCK_SIZE) : PORT_ALLOCATOR_BLOCK_SIZE;
		if ($value < 10 || $value > 1000) {
			$value = PORT_ALLOCATOR_BLOCK_SIZE;
		}
		return $value;
	}
}
if (!function_exists('port_allocator_game_context_by_cfg')) {
	function port_allocator_game_context_by_cfg($db, $db_prefix, $home_cfg_id)
	{
		$rows = port_allocator_rows(
			$db,
			"SELECT home_cfg_id, game_key, game_name, home_cfg_file
			   FROM `{$db_prefix}config_homes`
			  WHERE home_cfg_id=" . port_allocator_int($home_cfg_id) . "
			  LIMIT 1"
		);
		return empty($rows) ? array() : $rows[0];
	}
}

if (!function_exists('port_allocator_xml_port_offsets')) {
	/**
	 * The extra ports a game uses next to its game port, from <reserve_ports> in its XML (both the
	 * Windows and the Linux section): a list of array('id' => port name, 'offset' => signed offset
	 * from the game port). The panel puts these on the start command, so the offsets decide where
	 * the extra ports are.
	 */
	function port_allocator_xml_port_offsets($home_cfg_file)
	{
		$offsets = array();
		$file = basename((string)$home_cfg_file);
		if ($file === '' || !function_exists('simplexml_load_file')) {
			return $offsets;
		}
		$path = dirname(__DIR__) . '/modules/config_games/server_configs/' . $file;
		$xml = is_readable($path) ? @simplexml_load_file($path) : false;
		if (!$xml) {
			return $offsets;
		}
		foreach ($xml->xpath('//reserve_ports/port') ?: array() as $port) {
			$value = port_allocator_int(trim((string)$port));
			$offsets[] = array(
				'id' => (string)$port['id'],
				'offset' => (string)$port['type'] === 'subtract' ? -$value : $value,
			);
		}
		return $offsets;
	}
}

if (!function_exists('port_allocator_offsets_problem')) {
	/**
	 * Why a game's extra ports do not fit in one block, or '' when they do: every extra port must be
	 * 1 to block size - 1 above the game port, because the game port is the first port of its block.
	 */
	function port_allocator_offsets_problem(array $offsets, $block_size, $home_cfg_file = '')
	{
		foreach ($offsets as $o) {
			if ($o['offset'] < 0 || $o['offset'] >= $block_size) {
				return "The game config " . basename((string)$home_cfg_file) . " puts its port " . $o['id'] . " at "
					. ($o['offset'] >= 0 ? '+' : '') . $o['offset'] . " from the game port, outside the server's "
					. $block_size . "-port block. Change that <reserve_ports> offset to 1-" . ($block_size - 1) . ".";
			}
		}
		return '';
	}
}
if (!function_exists('port_allocator_log_event')) {
	function port_allocator_log_event($db, $db_prefix, $event_type, array $candidate, $message, array $metadata = array())
	{
		if (!port_allocator_table_exists($db, $db_prefix, 'port_allocation_events')) {
			return;
		}
		$allowed = array('allocated','released','imported','conflict_detected','reservation_added','reservation_removed','manual_override','failed_allocation');
		if (!in_array($event_type, $allowed, true)) {
			$event_type = 'failed_allocation';
		}
		$message = $db->realEscapeSingle(substr((string)$message, 0, 255));
		$metadata_json = $db->realEscapeSingle(json_encode($metadata));
		$block_id = !empty($candidate['block_id']) ? port_allocator_int($candidate['block_id']) : 'NULL';
		$home_id = !empty($candidate['home_id']) ? port_allocator_int($candidate['home_id']) : 'NULL';
		$ip_id = !empty($candidate['ip_id']) ? port_allocator_int($candidate['ip_id']) : 'NULL';
		$pool_id = !empty($candidate['pool_id']) ? port_allocator_int($candidate['pool_id']) : 'NULL';
		$db->query(
			"INSERT INTO `{$db_prefix}port_allocation_events`
			 (`block_id`, `home_id`, `ip_id`, `pool_id`, `event_type`, `source`, `message`, `metadata_json`, `created_at`)
			 VALUES ({$block_id}, {$home_id}, {$ip_id}, {$pool_id}, '" . $db->realEscapeSingle($event_type) . "', 'allocator', '{$message}', '{$metadata_json}', NOW())"
		);
	}
}

if (!function_exists('port_allocator_remote_ip_ids')) {
	function port_allocator_remote_ip_ids($db, $db_prefix, $remote_server_id, $preferred_ip_id = null)
	{
		$remote_server_id = port_allocator_int($remote_server_id);
		if ($preferred_ip_id !== null) {
			$preferred_ip_id = port_allocator_int($preferred_ip_id);
			$rows = port_allocator_rows(
				$db,
				"SELECT ip_id FROM `{$db_prefix}remote_server_ips` WHERE ip_id={$preferred_ip_id} AND remote_server_id={$remote_server_id} LIMIT 1"
			);
			return empty($rows) ? array() : array($preferred_ip_id);
		}

		$rows = port_allocator_rows(
			$db,
			"SELECT ip_id, ip FROM `{$db_prefix}remote_server_ips` WHERE remote_server_id={$remote_server_id} ORDER BY ip_id ASC"
		);
		$nat = port_allocator_rows($db, "SELECT use_nat FROM `{$db_prefix}remote_servers` WHERE remote_server_id={$remote_server_id} LIMIT 1");
		return port_allocator_order_ip_rows($rows, !empty($nat) ? $nat[0]['use_nat'] : 0);
	}
}

if (!function_exists('port_allocator_order_ip_rows')) {
	/**
	 * The ip_ids of one agent a new game server may use, in the order to try them: public addresses
	 * first, each in ip_id order. Private (LAN or VPN) addresses are only used on an agent behind NAT,
	 * after its public ones (see includes/game_ip.php); on any other agent they are left out.
	 */
	function port_allocator_order_ip_rows(array $rows, $use_nat = 0)
	{
		$public = array();
		$private = array();
		foreach ($rows as $row) {
			$ip_id = port_allocator_int($row['ip_id'] ?? 0);
			$ip = (string)($row['ip'] ?? '');
			if ($ip_id <= 0) {
				continue;
			}
			if (game_ip_is_public($ip)) {
				$public[] = $ip_id;
			} elseif (game_ip_allowed($ip, $use_nat)) {
				$private[] = $ip_id;
			}
		}
		return array_merge($public, $private);
	}
}

if (!function_exists('port_allocator_home_context')) {
	function port_allocator_home_context($db, $db_prefix, $home_id, $ip_id = null)
	{
		$home_id = port_allocator_int($home_id);
		$rows = port_allocator_rows(
			$db,
			"SELECT home_id, remote_server_id, home_cfg_id FROM `{$db_prefix}server_homes` WHERE home_id={$home_id} LIMIT 1"
		);
		if (empty($rows)) {
			return array();
		}
		$context = $rows[0];
		if ($ip_id !== null) {
			$ip_id = port_allocator_int($ip_id);
			$ipRows = port_allocator_rows(
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

if (!function_exists('port_allocator_block_has_conflict')) {
	function port_allocator_block_has_conflict($db, $db_prefix, $ip_id, $remote_server_id, $block_start, $block_end, $exclude_home_id = 0)
	{
		$ip_id = port_allocator_int($ip_id);
		$remote_server_id = port_allocator_int($remote_server_id);
		$block_start = port_allocator_int($block_start);
		$block_end = port_allocator_int($block_end);
		$exclude_home_id = port_allocator_int($exclude_home_id);
		$excludeBlockHome = $exclude_home_id > 0 ? "AND (home_id IS NULL OR home_id <> {$exclude_home_id})" : '';
		$blockRows = port_allocator_rows(
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
		$primaryRows = port_allocator_rows(
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

		$reservationRows = port_allocator_rows(
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

if (!function_exists('port_allocator_allowed_ranges')) {
	/**
	 * Port ranges game servers may use on this agent: ranges an admin set in port_ranges (global
	 * and for this agent), or the standard 2000-24000 when there are none.
	 */
	function port_allocator_allowed_ranges($db, $db_prefix, $remote_server_id)
	{
		$ranges = array();
		if (port_allocator_table_exists($db, $db_prefix, 'port_ranges')) {
			$rows = port_allocator_rows(
				$db,
				"SELECT range_start, range_end FROM `{$db_prefix}port_ranges`
				  WHERE remote_server_id IS NULL OR remote_server_id=" . port_allocator_int($remote_server_id) . "
				  ORDER BY range_start ASC"
			);
			foreach ($rows as $row) {
				$ranges[] = array(port_allocator_int($row['range_start']), port_allocator_int($row['range_end']));
			}
		}
		return empty($ranges) ? array(array(PORT_ALLOCATOR_RANGE_START, PORT_ALLOCATOR_RANGE_END)) : $ranges;
	}
}
if (!function_exists('port_allocator_find_candidate')) {
	/**
	 * The block for a new game server: the first free block on the agent's first usable IP, or the
	 * block starting at $manual_port when an admin sets the port. Refused when the game's extra
	 * ports do not fit in a block.
	 */
	function port_allocator_find_candidate($db, $db_prefix, $remote_server_id, $home_cfg_id, $preferred_ip_id = null, $manual_port = null, $allow_admin_override = false)
	{
		if (!port_allocator_schema_available($db, $db_prefix)) {
			return array('ok' => false, 'error' => 'global port allocation schema is not installed', 'schema_unavailable' => true);
		}

		$remote_server_id = port_allocator_int($remote_server_id);
		$home_cfg_id = port_allocator_int($home_cfg_id);
		$ip_ids = port_allocator_remote_ip_ids($db, $db_prefix, $remote_server_id, $preferred_ip_id);
		if (empty($ip_ids)) {
			return array('ok' => false, 'error' => "No IP addresses are configured for remote server #{$remote_server_id}.");
		}

		$block_size = port_allocator_global_block_size($db, $db_prefix);
		$context = port_allocator_game_context_by_cfg($db, $db_prefix, $home_cfg_id);
		$problem = port_allocator_offsets_problem(port_allocator_xml_port_offsets($context['home_cfg_file'] ?? ''), $block_size, $context['home_cfg_file'] ?? '');
		if ($problem !== '') {
			port_allocator_log_event($db, $db_prefix, 'failed_allocation', array(), $problem, array('remote_server_id' => $remote_server_id, 'home_cfg_id' => $home_cfg_id));
			return array('ok' => false, 'error' => $problem);
		}

		$candidate = function ($ip_id, $start, $source) use ($block_size) {
			return array(
				'ok' => true,
				'ip_id' => port_allocator_int($ip_id),
				'pool_id' => 0,
				'pool_key' => 'automatic',
				'block_start' => $start,
				'block_end' => $start + $block_size - 1,
				'primary_port' => $start,
				'block_size' => $block_size,
				'allocation_source' => $source,
				'legacy_range_id' => 0,
			);
		};
		$ranges = port_allocator_allowed_ranges($db, $db_prefix, $remote_server_id);
		foreach ($ip_ids as $ip_id) {
			if ($manual_port !== null && $allow_admin_override) {
				$start = port_allocator_int($manual_port);
				if ($start >= 1 && $start + $block_size - 1 <= 65535
					&& !port_allocator_block_has_conflict($db, $db_prefix, $ip_id, $remote_server_id, $start, $start + $block_size - 1)) {
					return $candidate($ip_id, $start, 'admin_override');
				}
				continue;
			}
			foreach ($ranges as $range) {
				foreach (port_allocator_candidate_starts($range[0], $range[1], $block_size) as $start) {
					if (!port_allocator_block_has_conflict($db, $db_prefix, $ip_id, $remote_server_id, $start, $start + $block_size - 1)) {
						return $candidate($ip_id, $start, 'preferred');
					}
				}
			}
		}

		$error = $manual_port !== null
			? "Port " . port_allocator_int($manual_port) . " to " . (port_allocator_int($manual_port) + $block_size - 1) . " is not free on remote server #{$remote_server_id}."
			: "No free {$block_size}-port block is left on remote server #{$remote_server_id}.";
		port_allocator_log_event($db, $db_prefix, 'failed_allocation', array(), $error, array(
			'remote_server_id' => $remote_server_id,
			'home_cfg_id' => $home_cfg_id,
			'block_size' => $block_size,
		));
		return array('ok' => false, 'error' => $error);
	}
}
if (!function_exists('port_allocator_preview_next_port')) {
	function port_allocator_preview_next_port($db, $db_prefix, $ip_id, $home_cfg_id)
	{
		$contextRows = port_allocator_rows(
			$db,
			"SELECT remote_server_id FROM `{$db_prefix}remote_server_ips` WHERE ip_id=" . port_allocator_int($ip_id) . " LIMIT 1"
		);
		if (empty($contextRows)) {
			return false;
		}
		$candidate = port_allocator_find_candidate($db, $db_prefix, $contextRows[0]['remote_server_id'], $home_cfg_id, $ip_id);
		return !empty($candidate['ok']) ? $candidate['primary_port'] : false;
	}
}

if (!function_exists('port_allocator_insert_block')) {
	function port_allocator_insert_block($db, $db_prefix, $home_id, $home_cfg_id, array $candidate, $notes = '')
	{
		$home_id = port_allocator_int($home_id);
		$home_cfg_id = port_allocator_int($home_cfg_id);
		$pool_id = port_allocator_int($candidate['pool_id'] ?? 0);
		$poolSql = $pool_id > 0 ? (string)$pool_id : 'NULL';
		$legacy_range_id = port_allocator_int($candidate['legacy_range_id'] ?? 0);
		$legacySql = $legacy_range_id > 0 ? (string)$legacy_range_id : 'NULL';
		$source = $db->realEscapeSingle($candidate['allocation_source'] ?? 'preferred');
		$notes = $db->realEscapeSingle($notes);
		$ok = $db->query(
			"INSERT INTO `{$db_prefix}home_port_blocks`
			 (`home_id`, `ip_id`, `pool_id`, `home_cfg_id`, `block_start`, `block_end`, `primary_port`, `block_size`, `allocation_source`, `status`, `legacy_range_id`, `notes`, `created_at`)
			 VALUES
			 ({$home_id}, " . port_allocator_int($candidate['ip_id']) . ", {$poolSql}, {$home_cfg_id}, " .
			 port_allocator_int($candidate['block_start']) . ", " . port_allocator_int($candidate['block_end']) . ", " .
			 port_allocator_int($candidate['primary_port']) . ", " . port_allocator_int($candidate['block_size']) . ",
			 '{$source}', 'active', {$legacySql}, '{$notes}', NOW())"
		);
		if (!$ok) {
			return false;
		}
		$rows = port_allocator_rows(
			$db,
			"SELECT block_id FROM `{$db_prefix}home_port_blocks`
			  WHERE home_id={$home_id}
			    AND ip_id=" . port_allocator_int($candidate['ip_id']) . "
			    AND primary_port=" . port_allocator_int($candidate['primary_port']) . "
			    AND status='active'
			  ORDER BY block_id DESC
			  LIMIT 1"
		);
		return empty($rows) ? true : port_allocator_int($rows[0]['block_id']);
	}
}

if (!function_exists('port_allocator_mirror_home_ip')) {
	function port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $ip_id, $port)
	{
		$home_id = port_allocator_int($home_id);
		$ip_id = port_allocator_int($ip_id);
		$port = port_allocator_int($port);
		$existing = port_allocator_rows(
			$db,
			"SELECT home_id FROM `{$db_prefix}home_ip_ports` WHERE ip_id={$ip_id} AND port={$port} LIMIT 1"
		);
		if (!empty($existing)) {
			return port_allocator_int($existing[0]['home_id'] ?? 0) === $home_id;
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
		$verify = port_allocator_rows(
			$db,
			"SELECT home_id FROM `{$db_prefix}home_ip_ports` WHERE ip_id={$ip_id} AND port={$port} AND home_id={$home_id} LIMIT 1"
		);
		return !empty($verify);
	}
}

if (!function_exists('port_allocate_home_block')) {
	function port_allocate_home_block($db, $db_prefix, $home_id, $remote_server_id, $home_cfg_id)
	{
		if (!port_allocator_schema_available($db, $db_prefix)) {
			return array('ok' => false, 'error' => 'global port allocation schema is not installed', 'schema_unavailable' => true);
		}
		$home_id = port_allocator_int($home_id);
		$existing = port_allocator_rows(
			$db,
			"SELECT block_id, ip_id, primary_port, block_start, block_end, block_size, allocation_source
			   FROM `{$db_prefix}home_port_blocks`
			  WHERE home_id={$home_id} AND status='active'
			  ORDER BY block_id ASC
			  LIMIT 1"
		);
		if (!empty($existing)) {
			$row = $existing[0];
			if (!port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $row['ip_id'], $row['primary_port'])) {
				return array('ok' => false, 'error' => 'Existing block could not be mirrored to home_ip_ports.');
			}
			return array(
				'ok' => true,
				'block_id' => port_allocator_int($row['block_id'] ?? 0),
				'ip_id' => port_allocator_int($row['ip_id'] ?? 0),
				'port' => port_allocator_int($row['primary_port'] ?? 0),
				'block_start' => port_allocator_int($row['block_start'] ?? 0),
				'block_end' => port_allocator_int($row['block_end'] ?? 0),
				'block_size' => port_allocator_int($row['block_size'] ?? 0),
				'allocation_source' => (string)($row['allocation_source'] ?? ''),
			);
		}

		$candidate = port_allocator_find_candidate($db, $db_prefix, $remote_server_id, $home_cfg_id);
		if (empty($candidate['ok'])) {
			return $candidate;
		}
		$block_id = port_allocator_insert_block($db, $db_prefix, $home_id, $home_cfg_id, $candidate);
		if (!$block_id) {
			return array('ok' => false, 'error' => 'Could not write home_port_blocks allocation.');
		}
		if (!port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $candidate['ip_id'], $candidate['primary_port'])) {
			$db->query("UPDATE `{$db_prefix}home_port_blocks` SET status='released', released_at=NOW(), updated_at=NOW() WHERE block_id=" . port_allocator_int($block_id));
			return array('ok' => false, 'error' => 'Could not mirror allocated primary port to home_ip_ports.');
		}
		$candidate['ok'] = true;
		$candidate['block_id'] = port_allocator_int($block_id);
		$candidate['home_id'] = $home_id;
		$candidate['port'] = $candidate['primary_port'];
		port_allocator_log_event($db, $db_prefix, 'allocated', $candidate, 'Automatic port block allocated', array(
			'block_size' => $candidate['block_size'] ?? 0,
		));
		return $candidate;
	}
}

if (!function_exists('port_reserve_manual_home_block')) {
	function port_reserve_manual_home_block($db, $db_prefix, $home_id, $ip_id, $port, $source = 'admin_override')
	{
		$home_id = port_allocator_int($home_id);
		$ip_id = port_allocator_int($ip_id);
		$port = port_allocator_int($port);
		$context = port_allocator_home_context($db, $db_prefix, $home_id, $ip_id);
		if (empty($context)) {
			return array('ok' => false, 'error' => "Home #{$home_id} was not found.");
		}
		$candidate = port_allocator_find_candidate(
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
		$block_id = port_allocator_insert_block($db, $db_prefix, $home_id, $context['home_cfg_id'], $candidate, 'manual compatibility reservation');
		if (!$block_id) {
			return array('ok' => false, 'error' => 'Could not write manual home_port_blocks allocation.');
		}
		if (!port_allocator_mirror_home_ip($db, $db_prefix, $home_id, $ip_id, $port)) {
			$db->query("UPDATE `{$db_prefix}home_port_blocks` SET status='released', released_at=NOW(), updated_at=NOW() WHERE block_id=" . port_allocator_int($block_id));
			return array('ok' => false, 'error' => 'Could not write home_ip_ports compatibility row.');
		}
		$candidate['block_id'] = port_allocator_int($block_id);
		$candidate['home_id'] = $home_id;
		$candidate['port'] = $port;
		port_allocator_log_event($db, $db_prefix, 'manual_override', $candidate, 'Manual compatibility port block reserved', array(
			'requested_port' => $port,
			'block_size' => $candidate['block_size'] ?? 0,
		));
		return $candidate;
	}
}

if (!function_exists('port_release_home_block')) {
	function port_release_home_block($db, $db_prefix, $home_id, $ip_id, $port)
	{
		if (!port_allocator_schema_available($db, $db_prefix)) {
			return true;
		}
		return $db->query(
			"UPDATE `{$db_prefix}home_port_blocks`
			    SET status='released', released_at=NOW(), updated_at=NOW()
			  WHERE home_id=" . port_allocator_int($home_id) . "
			    AND ip_id=" . port_allocator_int($ip_id) . "
			    AND primary_port=" . port_allocator_int($port) . "
			    AND status IN ('active','pending','needs-review','conflict')"
		);
	}
}

?>
