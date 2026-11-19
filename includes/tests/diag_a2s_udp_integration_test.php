<?php
// Integration test: the A2S adapter against a real UDP socket on 127.0.0.1.
// The "agent" below is a test-only transport that does what the udp_exchange tool must do
// (send once on a connected socket, collect datagrams, time them). The panel never does this itself.
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/diagnostics/query_adapters.php';

// A tiny game server living in this process: answers what the script says, one request at a time.
class LocalA2sServer
{
	public $socket;
	public $port;
	public $handler;
	public $received = array();

	public function __construct($handler)
	{
		$this->socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
		$this->port = (int)substr(strrchr(stream_socket_get_name($this->socket, false), ':'), 1);
		$this->handler = $handler;
	}

	// Reads one waiting request and sends the answer datagrams.
	public function serve_one()
	{
		$read = array($this->socket);
		$write = $except = null;
		if (stream_select($read, $write, $except, 1) < 1) {
			return;
		}
		$request = stream_socket_recvfrom($this->socket, 2048, 0, $peer);
		$this->received[] = $request;
		foreach (call_user_func($this->handler, $request) as $datagram) {
			stream_socket_sendto($this->socket, $datagram, 0, $peer);
		}
	}
}

class LocalUdpTransport
{
	private $server;

	public function __construct($server)
	{
		$this->server = $server;
	}

	public function exchange($ip, $port, $payload, $options)
	{
		$client = stream_socket_client("udp://$ip:$port", $errno, $errstr, 1);
		$start = hrtime(true);
		fwrite($client, $payload);
		if ($this->server) {
			$this->server->serve_one();
		}
		$datagrams = array();
		$first_ms = null;
		$deadline = $options['timeout_ms'] / 1000;
		while (count($datagrams) < $options['max_datagrams']) {
			$read = array($client);
			$write = $except = null;
			$wait = $datagrams ? $options['idle_ms'] / 1000 : $deadline;
			if (stream_select($read, $write, $except, (int)$wait, (int)(($wait - (int)$wait) * 1000000)) < 1) {
				break;
			}
			$data = @fread($client, 2048);
			if ($data === false || $data === '') {
				return array('outcome' => 'icmp_unreachable', 'datagrams' => array(), 'first_ms' => null, 'detail' => '');
			}
			$datagrams[] = $data;
			$first_ms = $first_ms === null ? round((hrtime(true) - $start) / 1000000, 1) : $first_ms;
		}
		fclose($client);
		return array('outcome' => $datagrams ? 'replied' : 'no_reply', 'datagrams' => $datagrams, 'first_ms' => $first_ms, 'detail' => '');
	}
}

$adapter = new A2sQueryAdapter();
$opts = array('transport' => array('timeout_ms' => 400, 'idle_ms' => 100, 'max_datagrams' => 16, 'max_bytes' => 32768));

// 1. Challenge then a reply split into 3 packets over a real socket
$whole = test_a2s_info_reply('Socket Server', 'de_inferno', 9, 24);
$server = new LocalA2sServer(function ($request) use ($whole) {
	if (substr($request, 0, 5) === "\xff\xff\xff\xffT" && strlen($request) === 25) {
		return array(test_a2s_challenge_reply("\x11\x22\x33\x44"));
	}
	$cut = array(substr($whole, 0, 22), substr($whole, 22, 20), substr($whole, 42));
	$out = array();
	foreach (array(2, 0, 1) as $i) {
		$out[] = "\xfe\xff\xff\xff" . pack('V', 42) . chr(3) . chr($i) . pack('v', 1248) . $cut[$i];
	}
	return $out;
});
$r = $adapter->query(new LocalUdpTransport($server), '127.0.0.1', $server->port, $opts);
check('socket: online through challenge and split packets', $r['status'] === 'online' && $r['info']['name'] === 'Socket Server' && $r['info']['players'] === 9);
check('socket: server saw the challenge on the second request', count($server->received) === 2 && substr($server->received[1], -4) === "\x11\x22\x33\x44");
check('socket: latency measured and small', $r['latency_ms'] !== null && $r['latency_ms'] < 400);

// 2. Silent server: a real timeout, reported as no_response
$silent = new LocalA2sServer(function ($request) { return array(); });
$started = hrtime(true);
$r = $adapter->query(new LocalUdpTransport($silent), '127.0.0.1', $silent->port, $opts);
$elapsed = (hrtime(true) - $started) / 1000000;
check('socket: silent server is no_response', $r['status'] === 'no_response');
check('socket: timeout was honoured (about 400 ms)', $elapsed >= 350 && $elapsed < 1500);

// 3. Server answering garbage
$junk = new LocalA2sServer(function ($request) { return array("this is not a game server"); });
$r = $adapter->query(new LocalUdpTransport($junk), '127.0.0.1', $junk->port, $opts);
check('socket: garbage reply is invalid_response', $r['status'] === 'invalid_response');

// 4. Nothing listening: the OS reports ICMP unreachable on the connected socket
$probe = stream_socket_server('udp://127.0.0.1:0', $e, $es, STREAM_SERVER_BIND);
$closed_port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$r = $adapter->query(new LocalUdpTransport(null), '127.0.0.1', $closed_port, $opts);
check('socket: closed port is port_unreachable or no_response, never online', in_array($r['status'], array('port_unreachable', 'no_response'), true));
echo "Status for a closed local port was: " . $r['status'] . "\n";
echo "All A2S socket tests passed\n";
