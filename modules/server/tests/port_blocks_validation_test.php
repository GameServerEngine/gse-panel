<?php

require_once dirname(__DIR__) . '/port_blocks.php';

class GspPortBlocksFakeDb
{
	public $tables = array();
	public $settings = array();

	public function resultQuery($query)
	{
		if (strpos($query, 'SHOW TABLES LIKE') !== false && preg_match("/LIKE '([^']+)'/", $query, $m)) {
			$table = str_replace('GSP_DB_PREFIX', 'gsp_', $m[1]);
			return in_array($table, $this->tables, true) ? array(array('table' => $table)) : false;
		}
		if (strpos($query, 'FROM `GSP_DB_PREFIXsettings`') !== false) {
			return isset($this->settings['port_block_size']) ? array(array('value' => $this->settings['port_block_size'])) : array();
		}
		return array();
	}

	public function query($query)
	{
		if (strpos($query, "VALUES ('port_block_size','10')") !== false && !isset($this->settings['port_block_size'])) {
			$this->settings['port_block_size'] = 10;
		}
		return true;
	}
}

function gsp_port_blocks_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$error = '';
gsp_port_blocks_test_assert(gsp_port_blocks_validate_port(1), 'port 1 is valid');
gsp_port_blocks_test_assert(gsp_port_blocks_validate_port(65535), 'port 65535 is valid');
gsp_port_blocks_test_assert(!gsp_port_blocks_validate_port(0), 'port 0 is rejected');
gsp_port_blocks_test_assert(!gsp_port_blocks_validate_port(65536), 'port 65536 is rejected');
gsp_port_blocks_test_assert(gsp_port_blocks_validate_range(32000, 32999, 10, $error), 'valid overflow range accepts 10-port blocks');
gsp_port_blocks_test_assert(!gsp_port_blocks_validate_range(32999, 32000, 10, $error), 'reversed range is rejected');
gsp_port_blocks_test_assert(!gsp_port_blocks_validate_range(32000, 32005, 10, $error), 'range that cannot hold one block is rejected');
gsp_port_blocks_test_assert(!gsp_port_blocks_validate_range(32000, 32009, 0, $error), 'zero block size is rejected');
gsp_port_blocks_test_assert(gsp_port_blocks_validate_range(32000, 32999, 1000, $error), 'maximum supported block size fits a matching range');
gsp_port_blocks_test_assert(gsp_port_blocks_value_in('preferred', array('preferred','compatible'), 'compatible') === 'preferred', 'allowed enum value is preserved');
gsp_port_blocks_test_assert(gsp_port_blocks_value_in('bad', array('preferred','compatible'), 'compatible') === 'compatible', 'unknown enum value falls back');

$db = new GspPortBlocksFakeDb();
$schema = gsp_port_blocks_schema_status($db);
gsp_port_blocks_test_assert(empty($schema['ok']) && count($schema['missing']) === 5, 'missing allocation tables are reported safely');

$db->tables = array(
	'gsp_port_pools',
	'gsp_port_pool_games',
	'gsp_game_port_offsets',
	'gsp_static_port_reservations',
	'gsp_home_port_blocks',
	'gsp_port_allocation_events',
);
$schema = gsp_port_blocks_schema_status($db);
gsp_port_blocks_test_assert(!empty($schema['ok']) && !empty($schema['events']), 'complete allocation schema is accepted');
gsp_port_blocks_test_assert(gsp_port_blocks_current_block_size($db) === 10, 'missing global block size defaults to 10');
$db->settings['port_block_size'] = 25;
gsp_port_blocks_test_assert(gsp_port_blocks_current_block_size($db) === 25, 'saved global block size is read from settings');
$db->settings['port_block_size'] = 5000;
gsp_port_blocks_test_assert(gsp_port_blocks_current_block_size($db) === 10, 'invalid global block size falls back to 10');

echo "All port block UI validation tests passed.\n";
