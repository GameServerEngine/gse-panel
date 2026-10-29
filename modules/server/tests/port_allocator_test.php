<?php

require_once dirname(__DIR__, 3) . '/includes/port_allocator.php';

class GspPortAllocatorFakeDb
{
	public $blocks = array();
	public $homeIpPorts = array();
	public $staticReservations = array();
	public $offsets = array();
	public $arrangePorts = array();
	public $configHomes = array();
	public $portBlockSize = 10;
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
		if (strpos($query, 'FROM `GSP_DB_PREFIXsettings`') !== false && strpos($query, "setting`='port_block_size'") !== false) {
			return array(array('value' => $this->portBlockSize));
		}
		if (strpos($query, 'FROM `GSP_DB_PREFIXconfig_homes`') !== false) {
			if (preg_match('/home_cfg_id=(\d+)/', $query, $m) && isset($this->configHomes[(int)$m[1]])) {
				return array($this->configHomes[(int)$m[1]]);
			}
			return array();
		}
		if (strpos($query, 'FROM `GSP_DB_PREFIXremote_server_ips`') !== false) {
			if (preg_match('/ip_id=(\d+)/', $query, $m)) {
				$ipId = (int)$m[1];
				return in_array($ipId, array(10, 11), true) ? array(array('ip_id' => $ipId, 'remote_server_id' => 1)) : array();
			}
			return array(array('ip_id' => 10), array('ip_id' => 11));
		}
		if (strpos($query, 'FROM `GSP_DB_PREFIXserver_homes`') !== false) {
			if (preg_match('/home_id=(\d+)/', $query, $m)) {
				return array(array('home_id' => (int)$m[1], 'remote_server_id' => 1, 'home_cfg_id' => 5));
			}
		}
		if (strpos($query, 'INNER JOIN `gsp_port_pools`') !== false) {
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
		if (strpos($query, 'FROM `GSP_DB_PREFIXarrange_ports`') !== false) {
			if (preg_match('/ip_id=(\d+)/', $query, $ip) && preg_match('/home_cfg_id=(\d+)/', $query, $cfg)) {
				foreach ($this->arrangePorts as $row) {
					if ((int)$row['ip_id'] === (int)$ip[1] && (int)$row['home_cfg_id'] === (int)$cfg[1]) {
						return array($row);
					}
				}
			}
			return array();
		}
		if (strpos($query, 'FROM `gsp_game_port_offsets`') !== false) {
			return $this->offsets;
		}
		if (strpos($query, 'FROM `gsp_static_port_reservations`') !== false) {
			return $this->matchingStaticReservation($query);
		}
		if (strpos($query, 'FROM `GSP_DB_PREFIXhome_ip_ports`') !== false) {
			return $this->matchingHomeIpPorts($query);
		}
		if (strpos($query, 'FROM `gsp_home_port_blocks`') !== false) {
			return $this->matchingBlocks($query);
		}
		return array();
	}

	public function query($query)
	{
		$this->queries[] = $query;
		if (strpos($query, 'INSERT INTO `gsp_home_port_blocks`') !== false) {
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
		if (strpos($query, 'INSERT INTO `GSP_DB_PREFIXhome_ip_ports`') !== false) {
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
		if (strpos($query, 'UPDATE `gsp_home_port_blocks` SET status=') !== false && preg_match('/block_id=(\d+)/', $query, $m)) {
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
			if ((int)$row['ip_id'] === (int)$ip[1] && $row['status'] === 'active' && gsp_port_allocator_ranges_overlap($start[1], $end[1], $row['block_start'], $row['block_end'])) {
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
			if ($row['status'] === 'active' && gsp_port_allocator_ranges_overlap($start[1], $end[1], $row['port_start'], $row['port_end'])) {
				return array($row);
			}
		}
		return array();
	}
}

function gsp_port_allocator_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$starts = gsp_port_allocator_candidate_starts(32000, 32999, 10);
gsp_port_allocator_test_assert(count($starts) === 991, 'candidate scan advances by one port for maximum packing');
gsp_port_allocator_test_assert($starts[0] === 32000 && $starts[990] === 32990, 'candidate scan stops at the last complete block');

$db = new GspPortAllocatorFakeDb();
$db->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$first = gsp_port_allocate_home_block($db, 'gsp_', 100, 1, 5);
gsp_port_allocator_test_assert(!empty($first['ok']), 'allocates without manual port pool mappings');
gsp_port_allocator_test_assert($first['ip_id'] === 10 && $first['port'] === 25565 && $first['block_end'] === 25574, 'allocator tries the game default port first');
gsp_port_allocator_test_assert($first['block_size'] === 10, 'default global block size is 10');
gsp_port_allocator_test_assert(count($db->homeIpPorts) === 1 && $db->homeIpPorts[0]['port'] === 25565, 'allocation mirrors primary port to home_ip_ports');

$second = gsp_port_allocate_home_block($db, 'gsp_', 101, 1, 5);
gsp_port_allocator_test_assert(!empty($second['ok']) && $second['port'] === 25575, 'occupied block advances to the next free contiguous block');
gsp_port_allocator_test_assert(!gsp_port_allocator_block_has_conflict($db, 'gsp_', 11, 1, 25565, 25574), 'same block can be reused on a different ip_id');

$db = new GspPortAllocatorFakeDb();
$db->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$db->staticReservations[] = array('reservation_id' => 1, 'status' => 'active', 'port_start' => 25565, 'port_end' => 25574);
$reserved = gsp_port_allocate_home_block($db, 'gsp_', 102, 1, 5);
gsp_port_allocator_test_assert(!empty($reserved['ok']) && $reserved['port'] === 25575, 'static service reservation blocks the overlapping game block');

$db = new GspPortAllocatorFakeDb();
$db->configHomes[5] = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$db->portBlockSize = 4;
$custom = gsp_port_allocate_home_block($db, 'gsp_', 103, 1, 5);
gsp_port_allocator_test_assert(!empty($custom['ok']) && $custom['block_size'] === 4 && $custom['block_end'] === 25568, 'custom global block size is honored');

$db = new GspPortAllocatorFakeDb();
$fallback = gsp_port_allocator_find_candidate($db, 'gsp_', 1, 99, 10, null);
gsp_port_allocator_test_assert(!empty($fallback['ok']) && $fallback['primary_port'] === 27015 && $fallback['default_port_source'] === 'fallback', 'unknown games use the safe fallback base port');

echo "All port allocator tests passed.\n";
