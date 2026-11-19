<?php
// A2S parser and Server Query adapter tests. Scripted transport, no network.
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/diagnostics/query_adapters.php';

// Transport that answers each exchange from a list of scripted replies and records the payloads.
class ScriptedTransport
{
	public $sent = array();
	private $script;

	public function __construct($script)
	{
		$this->script = $script;
	}

	public function exchange($ip, $port, $payload, $options)
	{
		$this->sent[] = $payload;
		return array_shift($this->script);
	}
}

function replied($datagrams, $ms = 20.5)
{
	return array('outcome' => 'replied', 'datagrams' => $datagrams, 'first_ms' => $ms, 'detail' => '');
}

function split_source($id, $parts)
{
	$out = array();
	foreach ($parts as $i => $payload) {
		$out[] = "\xfe\xff\xff\xff" . pack('V', $id) . chr(count($parts)) . chr($i) . pack('v', 1248) . $payload;
	}
	return $out;
}

$adapter = new A2sQueryAdapter();
$opts = array();

// --- plain info reply
$t = new ScriptedTransport(array(replied(array(test_a2s_info_reply()))));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('info: online status', $r['status'] === 'online');
check('info: server name', $r['info']['name'] === 'My Server');
check('info: map and game', $r['info']['map'] === 'de_dust2' && $r['info']['game'] === 'Counter-Strike');
check('info: players 5 of 16', $r['info']['players'] === 5 && $r['info']['max_players'] === 16);
check('info: password flag false', $r['info']['password'] === false);
check('info: latency from agent', $r['latency_ms'] === 20.5);
check('info: request is A2S_INFO', $t->sent[0] === "\xff\xff\xff\xffTSource Engine Query\0");

$t = new ScriptedTransport(array(replied(array(test_a2s_info_reply('Locked', 'cs_office', 1, 10, 1)))));
check('info: password flag true', $adapter->query($t, '203.0.113.5', 27015, $opts)['info']['password'] === true);

// --- challenge: first reply is a challenge, the second request must carry it
$t = new ScriptedTransport(array(replied(array(test_a2s_challenge_reply("\xde\xad\xbe\xef"))), replied(array(test_a2s_info_reply()), 31.0)));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('challenge: online after challenge', $r['status'] === 'online');
check('challenge: second request carries challenge', substr($t->sent[1], -4) === "\xde\xad\xbe\xef" && count($t->sent) === 2);
check('challenge: latency is the final answer', $r['latency_ms'] === 31.0);

// A server that keeps sending challenges must not loop: only one retry is made.
$t = new ScriptedTransport(array(replied(array(test_a2s_challenge_reply())), replied(array(test_a2s_challenge_reply()))));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('challenge loop: stops and reports invalid reply', $r['status'] === 'invalid_response' && count($t->sent) === 2);

// --- split packets (Source layout), delivered out of order
$whole = test_a2s_info_reply('Split Server');
$parts = split_source(777, array(substr($whole, 0, 20), substr($whole, 20, 15), substr($whole, 35)));
$t = new ScriptedTransport(array(replied(array($parts[2], $parts[0], $parts[1]))));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('split source: reassembled out of order', $r['status'] === 'online' && $r['info']['name'] === 'Split Server');

// split packets (GoldSrc layout: one byte, number high nibble, total low nibble)
$goldsrc = array();
foreach (array(substr($whole, 0, 25), substr($whole, 25)) as $i => $payload) {
	$goldsrc[] = "\xfe\xff\xff\xff" . pack('V', 5) . chr(($i << 4) | 2) . $payload;
}
$t = new ScriptedTransport(array(replied($goldsrc)));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('split goldsrc: reassembled', $r['status'] === 'online' && $r['info']['name'] === 'Split Server');

// split problems
$t = new ScriptedTransport(array(replied(array($parts[0], $parts[1]))));
check('split: missing packet is invalid_response', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'invalid_response');
$t = new ScriptedTransport(array(replied(array($parts[0], $parts[0], $parts[1], $parts[2]))));
check('split: duplicate packet is invalid_response', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'invalid_response');
$other = split_source(999, array('x', 'y', 'z'));
$t = new ScriptedTransport(array(replied(array($parts[0], $other[1], $parts[2]))));
check('split: mixed ids are invalid_response', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'invalid_response');
$compressed = "\xfe\xff\xff\xff" . pack('V', 0x80000001) . chr(1) . chr(0) . pack('v', 10) . 'abcd';
$t = new ScriptedTransport(array(replied(array($compressed))));
check('split: compressed is refused cleanly', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'invalid_response');

// --- malformed responses never raise warnings or exceptions out of the adapter
$bad = array(
	'garbage bytes' => "hello world",
	'empty datagram' => '',
	'header only' => "\xff\xff\xff\xff",
	'truncated info' => substr(test_a2s_info_reply(), 0, 25),
	'unterminated string' => "\xff\xff\xff\xffI\x11name-without-end",
	'wrong type' => "\xff\xff\xff\xffD\x00",
);
foreach ($bad as $label => $bytes) {
	$t = new ScriptedTransport(array(replied(array($bytes))));
	$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
	check("malformed ($label): invalid_response", $r['status'] === 'invalid_response');
}
$big = array_fill(0, 17, "\xff\xff\xff\xffI");
$t = new ScriptedTransport(array(replied($big)));
check('too many datagrams: refused', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'invalid_response');
$t = new ScriptedTransport(array(replied(array("\xff\xff\xff\xffI" . str_repeat('A', 40000)))));
check('oversize reply: refused', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'invalid_response');

// control characters and long names are cleaned
$t = new ScriptedTransport(array(replied(array(test_a2s_info_reply("<b>Evil\x07" . str_repeat('x', 400) . "</b>")))));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('server text: control chars removed, length capped', strpos($r['info']['name'], "\x07") === false && strlen($r['info']['name']) <= 256);

// --- unanswered is not offline
$t = new ScriptedTransport(array(array('outcome' => 'no_reply', 'datagrams' => array(), 'first_ms' => null, 'detail' => '')));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('no reply: status no_response', $r['status'] === 'no_response');
check('no reply: text does not claim offline for sure', stripos($r['status_text'], 'does not prove') !== false && $r['status'] !== 'offline');
$t = new ScriptedTransport(array(array('outcome' => 'icmp_unreachable', 'datagrams' => array(), 'first_ms' => null, 'detail' => '')));
check('icmp: status port_unreachable', $adapter->query($t, '203.0.113.5', 27015, $opts)['status'] === 'port_unreachable');
$t = new ScriptedTransport(array(array('outcome' => 'agent_error', 'datagrams' => array(), 'first_ms' => null, 'detail' => 'The agent is busy')));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('agent error: reported with message', $r['status'] === 'agent_error' && $r['message'] === 'The agent is busy');

// --- GoldSrc obsolete info reply (type 0x6D)
$gold = "\xff\xff\xff\xffm" . "10.0.0.5:27015\0" . "Old HL Server\0" . "crossfire\0valve\0Half-Life\0" . chr(3) . chr(20) . chr(47) . 'd' . 'w' . chr(0) . chr(0);
$t = new ScriptedTransport(array(replied(array($gold))));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('goldsrc info: parsed', $r['status'] === 'online' && $r['info']['name'] === 'Old HL Server' && $r['info']['players'] === 3 && $r['info']['os'] === 'windows');

// --- extra data flag (EDF): game port and keywords
$edf = test_a2s_info_reply() . chr(0x80 | 0x20) . pack('v', 27016) . "secure,cs\0";
$t = new ScriptedTransport(array(replied(array($edf))));
$r = $adapter->query($t, '203.0.113.5', 27015, $opts);
check('info: extra data flag read', $r['info']['game_port'] === 27016 && $r['info']['keywords'] === 'secure,cs');

// --- player list with its own challenge
$players = "\xff\xff\xff\xffD" . chr(2) . chr(0) . "Alice\0" . pack('l', 12) . pack('g', 305.5) . chr(1) . "Bob\0" . pack('l', -1) . pack('g', 10.0);
$t = new ScriptedTransport(array(
	replied(array(test_a2s_info_reply())),
	replied(array(test_a2s_challenge_reply("\x0a\x0b\x0c\x0d"))),
	replied(array($players)),
));
$r = $adapter->query($t, '203.0.113.5', 27015, array('players' => true));
check('players: two parsed', count($r['players']) === 2 && $r['players'][0]['name'] === 'Alice' && $r['players'][1]['score'] === -1);
check('players: request used the challenge', $t->sent[1] === "\xff\xff\xff\xffU\xff\xff\xff\xff" && $t->sent[2] === "\xff\xff\xff\xffU\x0a\x0b\x0c\x0d");
check('players: seconds rounded', $r['players'][0]['seconds'] === 306);

$t = new ScriptedTransport(array(replied(array(test_a2s_info_reply())), array('outcome' => 'no_reply', 'datagrams' => array(), 'first_ms' => null, 'detail' => '')));
$r = $adapter->query($t, '203.0.113.5', 27015, array('players' => true));
check('players: failure does not break an online server', $r['status'] === 'online' && $r['players'] === array());

// --- adapter registry
check('registry has a2s', isset(diag_query_adapters()['a2s']));
check('game presets include cs16, css and gmod', diag_find_preset('cs16') !== null && diag_find_preset('css') !== null && diag_find_preset('gmod') !== null);
echo "All A2S tests passed\n";
