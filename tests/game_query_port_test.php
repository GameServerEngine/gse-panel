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
echo $failures ? "\n$failures FAILED\n" : "\nall game query port tests passed\n";
exit($failures ? 1 : 0);
