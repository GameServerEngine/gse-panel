<?php

require_once dirname(__DIR__, 3) . '/includes/port_allocator.php';

class PortAllocatorFakeDb
{
	public $blocks = array();
	public $homeIpPorts = array();
	public $staticReservations = array();
	public $configHomes = array();
	public $portBlockSize = 100;
	public $ranges = array();
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

// Blocks start on block boundaries: 2000, 2100, ... and stop at the last whole block in the range.
$starts = port_allocator_candidate_starts(2000, 24000, 100);
port_allocator_test_assert(count($starts) === 220 && $starts[0] === 2000 && $starts[1] === 2100 && end($starts) === 23900, 'the standard range has 220 blocks of 100, from 2000 to 23900');
port_allocator_test_assert(port_allocator_candidate_starts(2050, 2399, 100) === array(2100, 2200, 2300), 'a range that starts mid-block begins at the next block boundary');

$minecraft = array('home_cfg_id' => 5, 'game_key' => 'minecraft_linux64', 'game_name' => 'Minecraft', 'home_cfg_file' => 'minecraft_linux64.xml');
$db = new PortAllocatorFakeDb();
$db->configHomes[5] = $minecraft;
$first = port_allocate_home_block($db, 'DB_PREFIX', 100, 1, 5);
port_allocator_test_assert(!empty($first['ok']) && $first['ip_id'] === 10 && $first['port'] === 2000 && $first['block_start'] === 2000 && $first['block_end'] === 2099, 'the first server gets port 2000, the first port of block 2000-2099');
port_allocator_test_assert($first['block_size'] === 100, 'the standard block size is 100');
port_allocator_test_assert(count($db->homeIpPorts) === 1 && $db->homeIpPorts[0]['port'] === 2000, 'allocation mirrors the game port to home_ip_ports');
$second = port_allocate_home_block($db, 'DB_PREFIX', 101, 1, 5);
port_allocator_test_assert(!empty($second['ok']) && $second['port'] === 2100, 'the next server gets the next block, 2100');
port_allocator_test_assert(!port_allocator_block_has_conflict($db, 'DB_PREFIX', 11, 1, 2000, 2099), 'the same block is free on another IP of the agent');
$again = port_allocate_home_block($db, 'DB_PREFIX', 100, 1, 5);
port_allocator_test_assert(!empty($again['ok']) && $again['port'] === 2000, 'a server that has a block keeps it');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = $minecraft;
$db->staticReservations[] = array('reservation_id' => 1, 'status' => 'active', 'port_start' => 2050, 'port_end' => 2050);
$reserved = port_allocate_home_block($db, 'DB_PREFIX', 102, 1, 5);
port_allocator_test_assert(!empty($reserved['ok']) && $reserved['port'] === 2100, 'a block that holds a reserved port (such as the agent on 12679) is skipped');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = $minecraft;
$db->homeIpPorts[] = array('ip_id' => 10, 'port' => 2302, 'home_id' => 7);
$db->homeIpPorts[] = array('ip_id' => 10, 'port' => 2000, 'home_id' => 8);
$skip = port_allocate_home_block($db, 'DB_PREFIX', 103, 1, 5);
port_allocator_test_assert(!empty($skip['ok']) && $skip['port'] === 2100, 'a block another server already uses a port in is skipped');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = $minecraft;
$db->portBlockSize = 50;
$custom = port_allocate_home_block($db, 'DB_PREFIX', 104, 1, 5);
port_allocator_test_assert(!empty($custom['ok']) && $custom['block_size'] === 50 && $custom['block_end'] === 2049, 'a block size set in the settings is honoured');

$rangeDb = new PortAllocatorFakeDb();
$rangeDb->configHomes[5] = $minecraft;
$rangeDb->ranges[] = array('range_start' => 30000, 'range_end' => 30099);
$ranged = port_allocate_home_block($rangeDb, 'DB_PREFIX', 200, 1, 5);
port_allocator_test_assert(!empty($ranged['ok']) && $ranged['port'] === 30000, 'a port range set for the agent replaces the standard range');
$rangeDb->ranges = array(array('range_start' => 30000, 'range_end' => 30050));
$tooSmall = port_allocator_find_candidate($rangeDb, 'DB_PREFIX', 1, 5);
port_allocator_test_assert(empty($tooSmall['ok']) && strpos($tooSmall['error'], 'No free 100-port block') !== false, 'a range without a whole free block is refused with a clear message');

$db = new PortAllocatorFakeDb();
$unknown = port_allocator_find_candidate($db, 'DB_PREFIX', 1, 99, 10, null);
port_allocator_test_assert(!empty($unknown['ok']) && $unknown['primary_port'] === 2000, 'a game without an XML still gets the first free block');

$db = new PortAllocatorFakeDb();
$db->configHomes[5] = $minecraft;
$manual = port_allocator_find_candidate($db, 'DB_PREFIX', 1, 5, 10, 27015, true);
port_allocator_test_assert(!empty($manual['ok']) && $manual['primary_port'] === 27015 && $manual['block_end'] === 27114 && $manual['allocation_source'] === 'admin_override', 'an admin-set port gets a whole block from that port');

// Extra ports come from <reserve_ports>; every one must be 1-99 above the game port.
$gmod = port_allocator_xml_port_offsets('garrysmod.xml');
$gmodOffsets = array();
foreach ($gmod as $o) { $gmodOffsets[$o['id']] = $o['offset']; }
port_allocator_test_assert($gmodOffsets === array('CLIENTPORT' => 10, 'TVPORT' => 5), 'a Source game has its client port 10 and SourceTV 5 above the game port');
port_allocator_test_assert(port_allocator_xml_port_offsets('no_such_game.xml') === array(), 'a missing XML gives no extra ports');
port_allocator_test_assert(port_allocator_offsets_problem($gmod, 100) === '', 'the Source game fits in its block');
port_allocator_test_assert(strpos(port_allocator_offsets_problem(array(array('id' => 'QUERY_PORT', 'offset' => 19238)), 100, 'arkse.xml'), 'QUERY_PORT at +19238') !== false, 'a far extra port is reported');
port_allocator_test_assert(port_allocator_offsets_problem(array(array('id' => 'CLIENTPORT', 'offset' => -10)), 100) !== '', 'an extra port below the game port is reported');
$misfits = array();
foreach (glob(dirname(__DIR__, 2) . '/config_games/server_configs/*.xml') as $xmlFile) {
	if (port_allocator_offsets_problem(port_allocator_xml_port_offsets(basename($xmlFile)), 100, basename($xmlFile)) !== '') {
		$misfits[] = basename($xmlFile);
	}
}
port_allocator_test_assert($misfits === array(), 'every game XML keeps its extra ports inside a 100-port block' . ($misfits ? ': ' . implode(', ', $misfits) : ''));

$badDb = new PortAllocatorFakeDb();
$badDb->configHomes[6] = array('home_cfg_id' => 6, 'game_key' => 'x', 'game_name' => 'X', 'home_cfg_file' => 'garrysmod.xml');
$badDb->portBlockSize = 10;
$refused = port_allocator_find_candidate($badDb, 'DB_PREFIX', 1, 6);
port_allocator_test_assert(empty($refused['ok']) && strpos($refused['error'], 'CLIENTPORT') !== false, 'a game whose extra ports do not fit the block size is refused, not overlapped');

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

echo "All port allocator tests passed.\n";
