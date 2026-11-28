<?php

require_once dirname(__DIR__, 3) . '/includes/port_allocator.php';

class PortAllocatorFakeDb
{
	public $blocks = array();
	public $homeIpPorts = array();
	public $staticReservations = array();
	public $offsets = array();
	public $arrangePorts = array();
	public $configHomes = array();
	public $portBlockSize = 10;
	public $ranges = array();
	public $mappedPoolsEnabled = true;
	public $queries = array();
	private $nextBlockId = 1;

	public function realEscapeSingle($value)
	{
		return addslashes((string)$value);
	}

	public function resultQuery($query)
	{
		$this->queries[] = $query;
		if (strpos($query, 'SHOW TABLES LIKE') !== false) {
			return array(array('Tables_in_test' => 'ok'));
		}
		if (strpos($query, 'FROM `DB_PREFIXport_ranges`') !== false) {
			return $this->ranges;
		}
		if (strpos($query, 'FROM `DB_PREFIXsettings`') !== false && strpos($query, "setting`='port_block_size'") !== false) {
			return array(array('value' => $this->portBlockSize));
		}
		if (strpos($query, 'FROM `DB_PREFIXconfig_homes`') !== false) {
			if (preg_match('/home_cfg_id=(\d+)/', $query, $m) && isset($this->configHomes[(int)$m[1]])) {
				return array($this->configHomes[(int)$m[1]]);
			}
			return array();
		}
		if (strpos($query, 'FROM `DB_PREFIXremote_server_ips`') !== false) {
			if (preg_match('/ip_id=(\d+)/', $query, $m)) {
				$ipId = (int)$m[1];
				return in_array($ipId, array(10, 11), true) ? array(array('ip_id' => $ipId, 'remote_server_id' => 1)) : array();
			}
			return array(array('ip_id' => 10, 'ip' => '155.94.173.138'), array('ip_id' => 11, 'ip' => '75.127.4.194'));
		}
		if (strpos($query, 'FROM `DB_PREFIXserver_homes`') !== false) {
			if (preg_match('/home_id=(\d+)/', $query, $m)) {
				return array(array('home_id' => (int)$m[1], 'remote_server_id' => 1, 'home_cfg_id' => 5));
			}
		}
		if (strpos($query, 'INNER JOIN `DB_PREFIXport_pools`') !== false) {
			if (!$this->mappedPoolsEnabled) {
				return array();
			}
			return array(array(
				'pool_id' => 1,
				'pool_key' => 'source-27015',
				'range_start' => 27015,
				'range_end' => 27104,
				'block_size' => 10,
				'role' => 'preferred',
			));
		}
		if (strpos($query, "pool_key='gse-overflow-32000'") !== false) {
			return array(array(
				'pool_id' => 9,
				'pool_key' => 'gse-overflow-32000',
				'range_start' => 32000,
				'range_end' => 32999,
				'block_size' => 10,
			));
		}
		if (strpos($query, 'FROM `DB_PREFIXarrange_ports`') !== false) {
			if (preg_match('/ip_id=(\d+)/', $query, $ip) && preg_match('/home_cfg_id=(\d+)/', $query, $cfg)) {
				foreach ($this->arrangePorts as $row) {
					if ((int)$row['ip_id'] === (int)$ip[1] && (int)$row['home_cfg_id'] === (int)$cfg[1]) {
						return array($row);
					}
				}
			}
			return array();
		}
		if (strpos($query, 'FROM `DB_PREFIXgame_port_offsets`') !== false) {
			return $this->offsets;
		}
		if (strpos($query, 'FROM `DB_PREFIXstatic_port_reservations`') !== false) {
			return $this->matchingStaticReservation($query);
		}
		if (strpos($query, 'FROM `DB_PREFIXhome_ip_ports`') !== false) {
			return $this->matchingHomeIpPorts($query);
		}
		if (strpos($query, 'FROM `DB_PREFIXhome_port_blocks`') !== false) {
			return $this->matchingBlocks($query);
		}
		return array();
	}

	public function query($query)
	{
		$this->queries[] = $query;
		if (strpos($query, 'INSERT INTO `DB_PREFIXhome_port_blocks`') !== false) {
			if (!preg_match("/VALUES\s+\((\d+),\s*(\d+),\s*(NULL|\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*'([^']+)'/s", $query, $m)) {
				return false;
			}
			$this->blocks[] = array(
				'block_id' => $this->nextBlockId++,
				'home_id' => (int)$m[1],
				'ip_id' => (int)$m[2],
				'pool_id' => $m[3] === 'NULL' ? null : (int)$m[3],
				'home_cfg_id' => (int)$m[4],
				'block_start' => (int)$m[5],
				'block_end' => (int)$m[6],
				'primary_port' => (int)$m[7],
				'block_size' => (int)$m[8],
				'allocation_source' => $m[9],
				'status' => 'active',
			);
			return true;
		}
		if (strpos($query, 'INSERT INTO `DB_PREFIXhome_ip_ports`') !== false) {
			if (!preg_match('/SELECT\s+(\d+),\s+(\d+),\s+(\d+)/', $query, $m)) {
				return false;
			}
			foreach ($this->homeIpPorts as $row) {
				if ((int)$row['ip_id'] === (int)$m[1] && (int)$row['port'] === (int)$m[2]) {
					return true;
				}
			}
			$this->homeIpPorts[] = array('ip_id' => (int)$m[1], 'port' => (int)$m[2], 'home_id' => (int)$m[3]);
			return true;
		}
		if (strpos($query, 'UPDATE `DB_PREFIXhome_port_blocks` SET status=') !== false && preg_match('/block_id=(\d+)/', $query, $m)) {
			foreach ($this->blocks as &$row) {
				if ((int)$row['block_id'] === (int)$m[1]) {
					$row['status'] = 'released';
				}
			}
			unset($row);
			return true;
		}
		return true;
	}

	private function matchingBlocks($query)
	{
		if (preg_match('/WHERE home_id=(\d+)/', $query, $m)) {
			foreach ($this->blocks as $row) {
				if ((int)$row['home_id'] === (int)$m[1] && $row['status'] === 'active') {
					return array($row);
				}
			}
			return array();
		}
		if (!preg_match('/ip_id=(\d+)/', $query, $ip) || !preg_match('/block_start <= (\d+)/', $query, $end) || !preg_match('/block_end >= (\d+)/', $query, $start)) {
			return array();
		}
		foreach ($this->blocks as $row) {
			if ((int)$row['ip_id'] === (int)$ip[1] && $row['status'] === 'active' && port_allocator_ranges_overlap($start[1], $end[1], $row['block_start'], $row['block_end'])) {
				return array($row);
			}
		}
		return array();
	}

	private function matchingHomeIpPorts($query)
	{
		if (preg_match('/ip_id=(\d+) AND port=(\d+)/', $query, $m)) {
			foreach ($this->homeIpPorts as $row) {
				if ((int)$row['ip_id'] === (int)$m[1] && (int)$row['port'] === (int)$m[2]) {
					return array($row);
				}
			}
		}
		if (preg_match('/ip_id=(\d+).*port BETWEEN (\d+) AND (\d+)/s', $query, $m)) {
			foreach ($this->homeIpPorts as $row) {
				if ((int)$row['ip_id'] === (int)$m[1] && (int)$row['port'] >= (int)$m[2] && (int)$row['port'] <= (int)$m[3]) {
					return array($row);
				}
			}
		}
		return array();
	}

	private function matchingStaticReservation($query)
	{
		if (!preg_match('/port_start <= (\d+)/', $query, $end) || !preg_match('/port_end >= (\d+)/', $query, $start)) {
			return array();
		}
		foreach ($this->staticReservations as $row) {
			if ($row['status'] === 'active' && port_allocator_ranges_overlap($start[1], $end[1], $row['port_start'], $row['port_end'])) {
				return array($row);
			}
		}
		return array();
	}
}

function port_allocator_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$starts = port_allocator_candidate_starts(32000, 32999, 10);
port_allocator_test_assert(count($starts) === 991, 'candidate scan advances by one port for maximum packing');
port_allocator_test_assert($starts[0] === 32000 && $starts[990] === 32990, 'candidate scan stops at the last complete block');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$first = port_allocate_home_block($db, 'DB_PREFIX', 100, 1, 5);
port_allocator_test_assert(!empty($first['ok']), 'allocates without manual port pool mappings');
port_allocator_test_assert($first['ip_id'] === 10 && $first['port'] === 25565 && $first['block_end'] === 25574, 'allocator tries the game default port first');
port_allocator_test_assert($first['block_size'] === 10, 'default global block size is 10');
port_allocator_test_assert(count($db->homeIpPorts) === 1 && $db->homeIpPorts[0]['port'] === 25565, 'allocation mirrors primary port to home_ip_ports');

$second = port_allocate_home_block($db, 'DB_PREFIX', 101, 1, 5);
port_allocator_test_assert(!empty($second['ok']) && $second['port'] === 25575, 'occupied block advances to the next free contiguous block');
port_allocator_test_assert(!port_allocator_block_has_conflict($db, 'DB_PREFIX', 11, 1, 25565, 25574), 'same block can be reused on a different ip_id');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$db->staticReservations[] = array('reservation_id' => 1, 'status' => 'active', 'port_start' => 25565, 'port_end' => 25574);
$reserved = port_allocate_home_block($db, 'DB_PREFIX', 102, 1, 5);
port_allocator_test_assert(!empty($reserved['ok']) && $reserved['port'] === 25575, 'static service reservation blocks the overlapping game block');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$db->portBlockSize = 4;
$custom = port_allocate_home_block($db, 'DB_PREFIX', 103, 1, 5);
port_allocator_test_assert(!empty($custom['ok']) && $custom['block_size'] === 4 && $custom['block_end'] === 25568, 'custom global block size is honored');

// Only public addresses are used on an agent that is not behind NAT; a VPN or LAN address never is.
$mixedIps = array(
	array('ip_id' => 7, 'ip' => '10.255.0.130'),
	array('ip_id' => 8, 'ip' => '155.94.173.138'),
	array('ip_id' => 9, 'ip' => '192.168.1.20'),
	array('ip_id' => 12, 'ip' => '75.127.4.194'),
);
port_allocator_test_assert(port_allocator_order_ip_rows($mixedIps, 0) === array(8, 12), 'an agent not behind NAT only gets its public IPs, in ip_id order');
port_allocator_test_assert(port_allocator_order_ip_rows($mixedIps, 1) === array(8, 12, 7, 9), 'an agent behind NAT tries public IPs first, then private ones');
port_allocator_test_assert(port_allocator_order_ip_rows(array(array('ip_id' => 3, 'ip' => '172.16.0.5')), 1) === array(3), 'an agent behind NAT with only a private IP keeps it');
port_allocator_test_assert(port_allocator_order_ip_rows(array(array('ip_id' => 3, 'ip' => '172.16.0.5')), 0) === array(), 'an agent not behind NAT with only a private IP gets no IP');
port_allocator_test_assert(game_ip_allowed('10.255.0.140', 0) === false && game_ip_allowed('75.127.4.194', 0) === true && game_ip_allowed('10.255.0.140', 1) === true, 'the start check refuses a VPN address unless the agent is behind NAT');


$db = new PortAllocatorFakeDb();
$fallback = port_allocator_find_candidate($db, 'DB_PREFIX', 1, 99, 10, null);
port_allocator_test_assert(!empty($fallback['ok']) && $fallback['primary_port'] === 27015 && $fallback['default_port_source'] === 'fallback', 'unknown games use the safe fallback base port');


$rangeDb = new PortAllocatorFakeDb();
$rangeDb->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$rangeDb->ranges[] = array('range_start' => 30000, 'range_end' => 30099);
$ranged = port_allocate_home_block($rangeDb, 'DB_PREFIX', 200, 1, 5);
port_allocator_test_assert(!empty($ranged['ok']) && $ranged['port'] === 30000, 'a game port range moves allocation into the range');

// Source games reserve the client port 10 below the game port and SourceTV 5 above it (garrysmod.xml).
port_allocator_test_assert(port_allocator_xml_port_offsets('garrysmod.xml') === array('below' => 10, 'above' => 5), 'the XML reserve_ports give 10 below and 5 above for a Source game');
port_allocator_test_assert(port_allocator_xml_port_offsets('no_such_game.xml') === array('below' => 0, 'above' => 0), 'a missing XML gives no extra ports');
port_allocator_test_assert(port_allocator_block_span(27015, 10, array('below' => 10, 'above' => 5)) === array(27005, 27024), 'the block widens down to the client port and keeps the standard size above');
port_allocator_test_assert(port_allocator_block_span(25565, 10, array('below' => 0, 'above' => 0)) === array(25565, 25574), 'a game without extra ports keeps the standard block');

$sourceDb = new PortAllocatorFakeDb();
$sourceDb->configHomes[91] = array('home_cfg_id' => 91, 'game_key' => 'garrysmod', 'game_name' => 'Garrys Mod', 'home_cfg_file' => 'garrysmod.xml');
$gmodFirst = port_allocate_home_block($sourceDb, 'DB_PREFIX', 300, 1, 91);
port_allocator_test_assert(!empty($gmodFirst['ok']) && $gmodFirst['port'] === 27015 && $gmodFirst['block_start'] === 27005 && $gmodFirst['block_end'] === 27024, 'a Source game gets 27015 with a block that holds its client port 27005');
$gmodSecond = port_allocate_home_block($sourceDb, 'DB_PREFIX', 301, 1, 91);
port_allocator_test_assert(!empty($gmodSecond['ok']) && $gmodSecond['port'] === 27035 && $gmodSecond['block_start'] === 27025, 'the next Source game on the same IP starts where its client port is free (27035, client port 27025)');

echo "All port allocator tests passed.\n";
