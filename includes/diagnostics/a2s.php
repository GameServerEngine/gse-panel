<?php
/*
 * Valve A2S query protocol (GoldSrc and Source): request builders and response parsers.
 * Pure functions on byte strings. No sockets: the agent only moves datagrams
 * (see docs/agent-diagnostics-protocol.md, tool udp_exchange) and the panel parses them here.
 *
 * Handled: challenge replies, split packets (Source and GoldSrc layouts), packet size limits,
 * truncated or garbage data (always an A2sParseException, never a PHP warning),
 * GoldSrc "obsolete" info replies. Not handled: bz2 compressed split packets (very old servers):
 * they are reported as an error instead of being decoded.
 */

class A2sParseException extends Exception
{
}

define('A2S_HEADER_SINGLE', "\xff\xff\xff\xff");
define('A2S_HEADER_SPLIT', "\xfe\xff\xff\xff");
define('A2S_MAX_FRAGMENTS', 16);
define('A2S_MAX_TOTAL_BYTES', 32768);
define('A2S_MAX_STRING', 256);

function a2s_build_info_request($challenge = null)
{
	return A2S_HEADER_SINGLE . 'T' . "Source Engine Query\0" . ($challenge === null ? '' : $challenge);
}

// A challenge of "\xff\xff\xff\xff" asks the server to send a real challenge.
function a2s_build_player_request($challenge)
{
	return A2S_HEADER_SINGLE . 'U' . $challenge;
}

function a2s_no_challenge()
{
	return "\xff\xff\xff\xff";
}

// Reads values from a byte string and throws when the data ends early.
class A2sReader
{
	private $data;
	private $pos = 0;

	public function __construct($data)
	{
		$this->data = $data;
	}

	public function left()
	{
		return strlen($this->data) - $this->pos;
	}

	private function take($length)
	{
		if ($length < 0 || $length > $this->left()) {
			throw new A2sParseException('Response ended early.');
		}
		$piece = substr($this->data, $this->pos, $length);
		$this->pos += $length;
		return $piece;
	}

	public function byte()
	{
		return ord($this->take(1));
	}

	public function int16()
	{
		$v = unpack('v', $this->take(2));
		return $v[1];
	}

	public function int32()
	{
		$v = unpack('l', $this->take(4));
		return $v[1];
	}

	public function float32()
	{
		$v = unpack('g', $this->take(4));
		return $v[1];
	}

	public function raw($length)
	{
		return $this->take($length);
	}

	// A zero terminated string. A missing terminator is an error.
	public function string()
	{
		$end = strpos($this->data, "\0", $this->pos);
		if ($end === false) {
			throw new A2sParseException('Text in the response is not terminated.');
		}
		$text = substr($this->data, $this->pos, $end - $this->pos);
		$this->pos = $end + 1;
		return a2s_clean_text($text);
	}
}

// Server text is untrusted. Keep it short and drop control characters; keep valid UTF-8 as is.
function a2s_clean_text($text)
{
	$text = substr($text, 0, A2S_MAX_STRING);
	if (!preg_match('//u', $text)) {
		$text = utf8_encode_safe($text);
	}
	return preg_replace('/[\x00-\x1f\x7f]/u', '', $text);
}

// Old servers send Latin-1. Convert byte by byte so no extension is needed.
function utf8_encode_safe($text)
{
	$out = '';
	for ($i = 0; $i < strlen($text); $i++) {
		$c = ord($text[$i]);
		$out .= $c < 0x80 ? chr($c) : chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
	}
	return $out;
}

// Splits one split-packet datagram into array(id, total, number, payload), or throws.
// $layout is 'source' (total, number, size) or 'goldsrc' (one byte: number high, total low).
function a2s_split_fragment($packet, $layout)
{
	$r = new A2sReader(substr($packet, 4));
	$id = $r->int32();
	if (($id & 0x80000000) !== 0 || $id < 0) {
		throw new A2sParseException('Compressed split packets are not supported.');
	}
	if ($layout === 'source') {
		$total = $r->byte();
		$number = $r->byte();
		$r->int16();
	} else {
		$both = $r->byte();
		$total = $both & 0x0F;
		$number = $both >> 4;
	}
	if ($total < 1 || $total > A2S_MAX_FRAGMENTS || $number >= $total) {
		throw new A2sParseException('Split packet numbers are not valid.');
	}
	return array('id' => $id, 'total' => $total, 'number' => $number, 'payload' => $r->raw($r->left()));
}

// Tries the Source layout first; if any fragment does not fit it, uses the GoldSrc layout.
function a2s_split_fragments($packets)
{
	foreach (array('source', 'goldsrc') as $layout) {
		try {
			$fragments = array();
			foreach ($packets as $packet) {
				$fragments[] = a2s_split_fragment($packet, $layout);
			}
			return a2s_check_fragment_set($fragments);
		} catch (A2sParseException $e) {
			$last = $e;
		}
	}
	throw $last;
}

// All fragments must share one id, count and be complete with no duplicates. Returns them in order.
function a2s_check_fragment_set($fragments)
{
	$total = $fragments[0]['total'];
	$ordered = array();
	foreach ($fragments as $f) {
		if ($f['id'] !== $fragments[0]['id'] || $f['total'] !== $total) {
			throw new A2sParseException('Split packets belong to different replies.');
		}
		if (isset($ordered[$f['number']])) {
			throw new A2sParseException('Split packet received twice.');
		}
		$ordered[$f['number']] = $f['payload'];
	}
	if (count($ordered) !== $total) {
		throw new A2sParseException('Split reply is incomplete (' . count($ordered) . ' of ' . $total . ' packets).');
	}
	ksort($ordered);
	return implode('', $ordered);
}

// Joins the datagrams of one reply into array(type, body): the message type byte and the rest.
// $datagrams is a list of binary strings in arrival order.
function a2s_assemble($datagrams)
{
	if (count($datagrams) === 0) {
		throw new A2sParseException('No data to read.');
	}
	$total = 0;
	foreach ($datagrams as $d) {
		$total += strlen($d);
	}
	if ($total > A2S_MAX_TOTAL_BYTES || count($datagrams) > A2S_MAX_FRAGMENTS) {
		throw new A2sParseException('Response is larger than allowed.');
	}
	$first = substr($datagrams[0], 0, 4);
	if ($first === A2S_HEADER_SINGLE) {
		$whole = $datagrams[0];
	} elseif ($first === A2S_HEADER_SPLIT) {
		$whole = a2s_split_fragments($datagrams);
	} else {
		throw new A2sParseException('Response does not start like an A2S reply.');
	}
	if (substr($whole, 0, 4) !== A2S_HEADER_SINGLE || strlen($whole) < 5) {
		throw new A2sParseException('Reply header is not valid.');
	}
	return array('type' => ord($whole[4]), 'body' => substr($whole, 5));
}

// The 4 byte challenge from a type 0x41 ('A') reply.
function a2s_parse_challenge($body)
{
	$r = new A2sReader($body);
	return $r->raw(4);
}

// Parses type 0x49 (Source) or 0x6D (GoldSrc) info replies into one array.
function a2s_parse_info($type, $body)
{
	$r = new A2sReader($body);
	if ($type === 0x49) {
		return a2s_parse_info_source($r);
	}
	if ($type === 0x6D) {
		return a2s_parse_info_goldsrc($r);
	}
	throw new A2sParseException('Unexpected reply type ' . $type . ' for a server info request.');
}

function a2s_server_type($byte)
{
	$types = array('d' => 'dedicated', 'l' => 'listen', 'p' => 'sourcetv');
	$c = chr($byte);
	return isset($types[$c]) ? $types[$c] : 'unknown';
}

function a2s_environment($byte)
{
	$types = array('l' => 'linux', 'w' => 'windows', 'm' => 'mac', 'o' => 'mac');
	$c = chr($byte);
	return isset($types[$c]) ? $types[$c] : 'unknown';
}

function a2s_parse_info_source($r)
{
	$info = array('protocol' => $r->byte());
	$info['name'] = $r->string();
	$info['map'] = $r->string();
	$info['folder'] = $r->string();
	$info['game'] = $r->string();
	$info['app_id'] = $r->int16();
	$info['players'] = $r->byte();
	$info['max_players'] = $r->byte();
	$info['bots'] = $r->byte();
	$info['server_type'] = a2s_server_type($r->byte());
	$info['os'] = a2s_environment($r->byte());
	$info['password'] = ($r->byte() === 1);
	$info['vac'] = ($r->byte() === 1);
	if ($info['app_id'] === 2400) {
		$r->raw(3); // The Ship: mode, witnesses, duration. Not shown.
	}
	$info['version'] = $r->string();
	// The extra data flag is optional: some old servers end here.
	if ($r->left() > 0) {
		$info = array_merge($info, a2s_parse_extra($r));
	}
	return $info;
}

function a2s_parse_extra($r)
{
	$extra = array();
	$flag = $r->byte();
	if ($flag & 0x80) {
		$extra['game_port'] = $r->int16();
	}
	if ($flag & 0x10) {
		$r->raw(8); // Server Steam ID
	}
	if ($flag & 0x40) {
		$r->int16();
		$r->string(); // SourceTV port and name
	}
	if ($flag & 0x20) {
		$extra['keywords'] = $r->string();
	}
	return $extra;
}

function a2s_parse_info_goldsrc($r)
{
	$info = array('address' => $r->string());
	$info['name'] = $r->string();
	$info['map'] = $r->string();
	$info['folder'] = $r->string();
	$info['game'] = $r->string();
	$info['players'] = $r->byte();
	$info['max_players'] = $r->byte();
	$info['protocol'] = $r->byte();
	$info['server_type'] = a2s_server_type($r->byte());
	$info['os'] = a2s_environment($r->byte());
	$info['password'] = ($r->byte() === 1);
	$info['app_id'] = 0;
	$info['bots'] = 0;
	if ($r->left() > 0 && $r->byte() === 1) {
		$r->string();
		$r->string();
		$r->raw(1 + 4 + 4 + 1 + 1); // Mod links, version, size, type, dll
	}
	if ($r->left() >= 2) {
		$info['vac'] = ($r->byte() === 1);
		$info['bots'] = $r->byte();
	}
	return $info;
}

// Parses type 0x44 ('D') player replies. Returns a list of array(index, name, score, seconds).
function a2s_parse_players($type, $body)
{
	if ($type !== 0x44) {
		throw new A2sParseException('Unexpected reply type ' . $type . ' for a player request.');
	}
	$r = new A2sReader($body);
	$count = $r->byte();
	$players = array();
	for ($i = 0; $i < $count; $i++) {
		$players[] = array(
			'index' => $r->byte(),
			'name' => $r->string(),
			'score' => $r->int32(),
			'seconds' => (int)round($r->float32()),
		);
	}
	return $players;
}
