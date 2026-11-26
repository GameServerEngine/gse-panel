<?php
// Port check tests: reading netstat / ss text, matching ports, building the answer rows. No agent, no network.
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/diagnostics/listening.php';

$netstat = "\r\nActive Connections\r\n\r\n  Proto  Local Address          Foreign Address        State           PID\r\n"
	. "  TCP    0.0.0.0:135            0.0.0.0:0              LISTENING       1012\r\n"
	. "  TCP    [::]:80                [::]:0                 LISTENING       4\r\n"
	. "  TCP    10.0.0.5:50000         93.184.216.34:443      ESTABLISHED     3344\r\n"
	. "  UDP    0.0.0.0:2302           *:*                                    2200\r\n"
	. "  UDP    142.54.183.235:2302    *:*                                    2200\r\n"
	. "  UDP    127.0.0.1:2303         *:*                                    2201\r\n"
	. "  UDP    10.0.0.5:61000         8.8.8.8:53                             3344\r\n"
	. "  UDP    [::]:2304              *:*                                    2202\r\n";
$win = diag_parse_netstat($netstat);
check('netstat: two TCP listeners, established row skipped', count(array_filter($win, function ($l) { return $l['proto'] === 'tcp'; })) === 2);
check('netstat: UDP rows with *:* count, outgoing UDP row skipped', count(array_filter($win, function ($l) { return $l['proto'] === 'udp'; })) === 4);
check('netstat: IPv6 wildcard and PID read', $win[1]['addr'] === '::' && $win[1]['port'] === 80 && $win[1]['pid'] === 4);

$ss = "tcp   LISTEN 0      128          0.0.0.0:22         0.0.0.0:*    users:((\"sshd\",pid=811,fd=3))\n"
	. "tcp   LISTEN 0      4096       127.0.0.53%lo:53     0.0.0.0:*\n"
	. "udp   UNCONN 0      0                  *:2302            *:*    users:((\"arma2oaserver\",pid=4242,fd=7))\n"
	. "tcp   LISTEN 0      511             [::]:80            [::]:*\n"
	. "tcp   ESTAB  0      0           10.0.0.5:22      10.0.0.9:51000\n"
	. "garbage line\n";
$lin = diag_parse_ss($ss);
check('ss: four listeners, ESTAB and junk skipped', count($lin) === 4);
check('ss: process name and PID read', $lin[0]['process'] === 'sshd' && $lin[0]['pid'] === 811);
check('ss: interface suffix removed', $lin[1]['addr'] === '127.0.0.53' && $lin[1]['port'] === 53 && $lin[1]['process'] === '');
check('ss: star address kept as wildcard', $lin[2]['addr'] === '*' && $lin[2]['port'] === 2302 && $lin[2]['proto'] === 'udp');
check('ss: bracketed IPv6 read', $lin[3]['addr'] === '::' && $lin[3]['port'] === 80);
check('empty output gives no listeners', diag_parse_ss('') === array() && diag_parse_netstat('') === array());

$names = diag_parse_tasklist("\"System\",\"4\",\"Services\",\"0\",\"140 K\"\r\n\"arma2oaserver.exe\",\"2200\",\"Console\",\"1\",\"212,332 K\"\r\nINFO: junk\r\n");
check('tasklist: pid to name', $names[2200] === 'arma2oaserver.exe' && $names[4] === 'System' && count($names) === 2);
$named = diag_name_listeners($win, $names);
check('names added to listeners without a name', $named[3]['process'] === 'arma2oaserver.exe');

// Matching: wildcard, exact address, other address, nothing.
$ip = '142.54.183.235';
check('match: exact address', diag_listener_match($win, 'udp', 2302, $ip)['state'] === 'yes');
check('match: wildcard address', diag_listener_match($win, 'udp', 2304, $ip)['state'] === 'yes');
check('match: loopback only is another address', diag_listener_match($win, 'udp', 2303, $ip)['state'] === 'other');
check('match: nothing', diag_listener_match($win, 'tcp', 2302, $ip)['state'] === 'no');
check('match: protocols are separate', diag_listener_match($lin, 'tcp', 2302, '1.2.3.4')['state'] === 'no' && diag_listener_match($lin, 'udp', 2302, '1.2.3.4')['state'] === 'yes');
check('match: IPv6 written two ways is the same address', diag_same_address('2606:4700:4700::1111', '2606:4700:4700:0:0:0:0:1111') && !diag_same_address('1.2.3.4', 'not an ip'));

$checked = diag_listen_check($win, array(array('port' => 2302, 'label' => 'Game port'), array('port' => 2303, 'label' => 'Query port'), array('port' => 9999, 'label' => 'Reserved port')), $ip);
check('outcome: partial when some ports listen', diag_listen_outcome($checked) === 'partial');
check('outcome: ok when all listen', diag_listen_outcome(array_slice($checked, 0, 1)) === 'ok');
check('outcome: not_listening when none listen', diag_listen_outcome(array_slice($checked, 2)) === 'not_listening');
check('summary counts ports', strpos(diag_listen_summary('partial', 1, 3), '1 of 3') === 0);

// What users and admins see
$user_rows = diag_listen_rows($checked, false);
$admin_rows = diag_listen_rows(diag_listen_check($named, array(array('port' => 2302, 'label' => 'Game port')), $ip), true);
check('rows: label and port', $user_rows[0][0] === 'Game port 2302' && $user_rows[2][0] === 'Reserved port 9999');
check('rows: user sees yes or no only, no PID or program', strpos($user_rows[0][1], 'UDP listening; TCP not listening') === 0 && strpos(json_encode($user_rows), 'PID') === false && strpos(json_encode($user_rows), 'arma2') === false);
check('rows: user is told about another-address listeners', strpos($user_rows[1][1], 'listening on another address only') !== false);
check('rows: admin sees address, PID and program', strpos($admin_rows[0][1], 'PID 2200') !== false && strpos($admin_rows[0][1], 'arma2oaserver.exe') !== false);

// Commands are fixed text
check('command per OS', diag_listen_command('windows') === 'netstat -ano' && diag_listen_command('linux') === 'ss -H -lntup');

// Ports from a game config
$xml = simplexml_load_string('<game_server><query_port type="add">1</query_port><reserve_ports><port type="add" id="A">2</port><port type="subtract" id="B">1</port><port type="add" id="C">1</port></reserve_ports></game_server>');
$ports = diag_ports_from_config($xml, 2302);
check('config: game, query and reserved ports, duplicate listed once', array_column($ports, 'port') === array(2302, 2303, 2304, 2301) && $ports[1]['label'] === 'Query port');
check('config: no XML still gives the game port', diag_ports_from_config(false, 27015) === array(array('port' => 27015, 'label' => 'Game port')));
check('config: port below 1 dropped', array_column(diag_ports_from_config(simplexml_load_string('<g><query_port type="subtract">5</query_port></g>'), 3), 'port') === array(3));

echo "All listening tests passed\n";
