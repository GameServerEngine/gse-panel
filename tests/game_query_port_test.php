<?php
// Tests for includes/game_query_port.php against real game XML sections.
require_once __DIR__ . '/../includes/game_query_port.php';
$failures = 0;
function gqp_check($ok, $name) { global $failures; echo ($ok ? 'ok' : 'FAIL') . " - $name\n"; if (!$ok) $failures++; }
function gqp_section($file, $index) { $x = simplexml_load_file(__DIR__ . '/../modules/config_games/server_configs/' . $file); $s = $x->xpath('//reserve_ports/..'); return $s[$index]; }

gqp_check(get_query_port(gqp_section('arkse.xml', 0), 2000) === 2015, 'ARK queries on the QueryPort its start command sets (game port + 15)');
gqp_check(get_query_port(gqp_section('teamspeak3.xml', 0), 2000) === 2024, 'TeamSpeak 3 query port is game port + 24, from its XML');
gqp_check(xml_query_port_offset(gqp_section('garrysmod.xml', 0)) === null, 'a Source game sets no query port, so the game port is used');
gqp_check(get_query_port(gqp_section('garrysmod.xml', 0), 2100) === 2100, 'without one, the query port is the game port');
gqp_check(get_query_port(simplexml_load_string('<s><query_port type="add">3</query_port></s>'), 2000) === 2003, 'the older <query_port> tag still works');
gqp_check(get_query_port(simplexml_load_string('<s><reserve_ports><port id="QUERY_SSH_PORT" type="add">2</port></reserve_ports></s>'), 2000) === 2000, 'an SSH query port is not taken for the game query port');
// Guard: the panel never takes the LGSL default query port without first checking the game XML.
$skip = '#/(protocol/lgsl/lgsl_protocol\.php|lgsl_with_img_mod/|themes/AdminLTE|tests/)#';
$offenders = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (substr($path, -4) !== '.php' || preg_match($skip, $path) || strpos($path, '/.git/') !== false) continue;
	$lines = file($path);
	foreach ($lines as $i => $line) {
		if (strpos($line, 'lgsl_port_conversion(') === false || strpos($line, 'function lgsl_port_conversion') !== false) continue;
		$window = implode('', array_slice($lines, $i, 5));
		if (preg_match("/\\['1'\\]|list\\(/", $window) && strpos($window, 'xml_query_port_offset') === false && strpos($window, 'only reached when the XML sets no query port') === false && strpos($path, 'protocol/query.php') === false) {
			$offenders[] = str_replace(dirname(__DIR__) . '/', '', $path) . ':' . ($i + 1);
		}
	}
}
gqp_check($offenders === array(), 'every LGSL query port lookup checks the game XML first' . ($offenders ? ': ' . implode(', ', $offenders) : ''));
// Every game XML's query protocol names a query type that the shipped GameQ or LGSL has.
require_once __DIR__ . '/../protocol/lgsl/lgsl_protocol.php';
$lgslTypes = lgsl_type_list();
$unknown = array();
foreach (glob(__DIR__ . '/../modules/config_games/server_configs/*.xml') as $xmlFile) {
	$x = simplexml_load_file($xmlFile);
	foreach ($x->xpath('//protocol/..') as $section) {
		$p = (string)$section->protocol;
		if ($p === 'lgsl' && !isset($lgslTypes[(string)$section->lgsl_query_name])) $unknown[] = basename($xmlFile) . ' lgsl:' . $section->lgsl_query_name;
		if ($p === 'gameq' && !is_file(__DIR__ . '/../protocol/GameQ/Protocols/' . ucfirst(strtolower((string)$section->gameq_query_name)) . '.php')) $unknown[] = basename($xmlFile) . ' gameq:' . $section->gameq_query_name;
	}
}
gqp_check($unknown === array(), 'every XML query name exists in the shipped GameQ or LGSL' . ($unknown ? ': ' . implode(', ', $unknown) : ''));
echo $failures ? "\n$failures FAILED\n" : "\nall game query port tests passed\n";
exit($failures ? 1 : 0);
