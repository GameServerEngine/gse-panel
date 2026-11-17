<?php
// Test-only UDP game server for diag_agent_integration_test.php. Usage: php fake_a2s_server.php IP PORT
// Answers A2S_INFO with a challenge first, then with an info reply split into 3 packets. Stops after 60 s.
require_once dirname(__DIR__) . '/diag_test_helper.php';
$sock = stream_socket_server('udp://' . $argv[1] . ':' . $argv[2], $errno, $errstr, STREAM_SERVER_BIND);
echo "ready\n";
$whole = test_a2s_info_reply('Integration Server', 'de_nuke', 7, 20);
$end = time() + 60;
while (time() < $end) {
	$r = array($sock);
	$w = $e = null;
	if (stream_select($r, $w, $e, 1) < 1) {
		continue;
	}
	$req = stream_socket_recvfrom($sock, 2048, 0, $peer);
	if (strlen($req) === 25) {
		stream_socket_sendto($sock, test_a2s_challenge_reply("\x01\x02\x03\x04"), 0, $peer);
		continue;
	}
	$cut = array(substr($whole, 0, 22), substr($whole, 22, 20), substr($whole, 42));
	foreach ($cut as $i => $payload) {
		stream_socket_sendto($sock, "\xfe\xff\xff\xff" . pack('V', 9) . chr(3) . chr($i) . pack('v', 1248) . $payload, 0, $peer);
	}
}
