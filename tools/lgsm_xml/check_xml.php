<?php
/*
 * Check game XML files the way the panel reads them.
 *
 * Usage (from the panel root):
 *   php tools/lgsm_xml/check_xml.php [file.xml ...]
 * With no files, every file in modules/config_games/server_configs is checked.
 *
 * For each file and each OS block it:
 *   1. loads it with read_server_config() (schema check and OS merge), and
 *   2. builds the start line with sample values, the same steps as
 *      get_start_cmd() in modules/gamemanager/home_handling_functions.php,
 *      and reports any %PLACEHOLDER% that nothing replaces.
 * Exit code 1 when any file fails.
 */

chdir(dirname(__DIR__, 2));
require_once 'modules/config_games/server_config_parser.php';

$files = array_slice($argv, 1);
if (count($files) === 0) {
	$files = glob(SERVER_CONFIG_LOCATION . '*.xml');
}

$failed = 0;
foreach ($files as $file) {
	$problems = check_file($file);
	if (count($problems) > 0) {
		$failed++;
		echo basename($file) . ":\n";
		foreach ($problems as $problem) {
			echo "  - $problem\n";
		}
	}
}
echo count($files) . " files checked, $failed with problems.\n";
exit($failed > 0 ? 1 : 0);

// Returns a list of problems for one file (empty when it is fine).
function check_file($file)
{
	$dom = new DOMDocument();
	if (!@$dom->load($file)) {
		return array('not valid XML');
	}
	$os_names = config_os_names($dom);
	if (count($os_names) === 0) {
		$os_names = array(null);
	}
	$problems = array();
	foreach ($os_names as $os) {
		$GLOBALS['_xml_errors'] = array(); // the panel collects errors across calls
		$xml = read_server_config($file, $os);
		if ($xml === false) {
			foreach (get_xml_errors() as $error) {
				$problems[] = ($os ?: 'root') . ': ' . trim(preg_replace('/\s+/', ' ', $error['details'] ?? $error['title'] ?? 'load failed'));
			}
			if (count($problems) === 0) {
				$problems[] = ($os ?: 'root') . ': read_server_config() failed';
			}
			continue;
		}
		foreach (leftover_placeholders($xml) as $name) {
			$problems[] = ($os ?: 'root') . ": start line keeps %$name%";
		}
	}
	return $problems;
}

// Builds the start line with sample values and returns placeholders still in it.
function leftover_placeholders($xml)
{
	if (!$xml->cli_template) {
		return array();
	}
	$port = 27015;
	$sample = array(
		'IP' => '192.0.2.10', 'PORT' => $port, 'PLAYERS' => '10', 'HOSTNAME' => 'Sample',
		'MAP' => 'sample_map', 'QUERY_PORT' => $port, 'CONTROL_PASSWORD' => 'secret',
		'PID_FILE' => 'game_startup.pid', 'GAME_TYPE' => 'mod', 'BASE_PATH' => '/game',
		'HOME_PATH' => '/game', 'SAVE_PATH' => '/game', 'OUTPUT_PATH' => '/game', 'USER_PATH' => '/game',
	);
	$command = (string)$xml->cli_template;
	if ($xml->cli_params) {
		foreach ($xml->cli_params->cli_param as $cli) {
			$space = preg_match('/s/', (string)$cli['options']) ? ' ' : '';
			$value = $sample[(string)$cli['id']] ?? '';
			$command = str_replace('%' . $cli['id'] . '%', $cli['cli_string'] . $space . $value, $command);
		}
	}
	if ($xml->reserve_ports) {
		foreach ($xml->reserve_ports->port as $reserve) {
			$value = $reserve['type'] == 'add' ? $port + (int)$reserve : $port - (int)$reserve;
			$space = preg_match('/s/', (string)$reserve['options']) ? ' ' : '';
			$command = str_replace('%' . $reserve['id'] . '%', $reserve['cli_string'] . $space . $value, $command);
		}
	}
	if ($xml->server_params) {
		// Settings left empty are removed, as the panel does.
		foreach ($xml->server_params->param as $param) {
			if ((string)$param['id'] !== '') {
				$command = str_replace('%' . $param['id'] . '%', (string)$param['key'] . ' ' . (string)$param->default, $command);
			}
		}
	}
	preg_match_all('/%([A-Z][A-Z0-9_]*)%/', $command, $matches);
	return array_unique($matches[1]);
}
