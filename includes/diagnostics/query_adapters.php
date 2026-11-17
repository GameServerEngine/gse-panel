<?php
/*
 * Server Query adapters. An adapter knows one game protocol: what to send and how to read
 * the answer. It talks to the network only through a transport object, so it never knows
 * about agents. To add a game protocol: write a class with these four methods and add it
 * in diag_query_adapters().
 *
 *   id()                 short name used in forms
 *   label()              name shown to the user
 *   default_port()       suggested query port (it can differ from the game port)
 *   query($transport, $ip, $port, $options)   returns the result array from diag_query_result()
 *
 * Transport object: exchange($ip, $port, $payload, $options) returns
 *   array('outcome' => replied|no_reply|icmp_unreachable|error|agent_error,
 *         'datagrams' => [binary string, ...] (arrival order), 'first_ms' => float|null,
 *         'detail' => string)
 */

require_once __DIR__ . '/a2s.php';

// Statuses. Only "online" means the server answered. Nothing here claims "offline for sure":
// a silent UDP port can be a dead server, a wrong query port or a firewall.
function diag_query_status_text($status)
{
	$texts = array(
		'online' => 'Online (the server answered the query)',
		'no_response' => 'No answer. The server may be offline, the query port may be wrong, or a firewall may drop the query. This does not prove the server is offline.',
		'port_unreachable' => 'The host answered that nothing is listening on this UDP port. The server is probably not running on this port.',
		'invalid_response' => 'Something answered, but not with a valid reply for this protocol.',
		'agent_error' => 'The agent could not run the query.',
	);
	return isset($texts[$status]) ? $texts[$status] : $status;
}

function diag_query_result($status, $extra = array())
{
	return array_merge(array(
		'status' => $status,
		'status_text' => diag_query_status_text($status),
		'latency_ms' => null,
		'info' => array(),
		'players' => array(),
		'message' => '',
	), $extra);
}

class A2sQueryAdapter
{
	public function id()
	{
		return 'a2s';
	}

	public function label()
	{
		return 'Valve A2S (GoldSrc / Source: CS 1.6, CS:Source, Garry\'s Mod, TF2 ...)';
	}

	public function default_port()
	{
		return 27015;
	}

	public function query($transport, $ip, $port, $options)
	{
		$opts = array('timeout_ms' => 3000, 'idle_ms' => 300, 'max_datagrams' => A2S_MAX_FRAGMENTS, 'max_bytes' => A2S_MAX_TOTAL_BYTES);
		$opts = array_merge($opts, isset($options['transport']) ? $options['transport'] : array());
		$reply = $this->ask($transport, $ip, $port, a2s_build_info_request(), $opts);
		if (!is_array($reply) || isset($reply['status'])) {
			return $reply;
		}
		if ($reply['type'] === 0x41) {
			$reply = $this->ask($transport, $ip, $port, a2s_build_info_request(a2s_parse_challenge($reply['body'])), $opts);
			if (isset($reply['status'])) {
				return $reply;
			}
		}
		try {
			$result = diag_query_result('online', array('latency_ms' => $reply['first_ms'], 'info' => a2s_parse_info($reply['type'], $reply['body'])));
		} catch (A2sParseException $e) {
			return diag_query_result('invalid_response', array('message' => $e->getMessage(), 'latency_ms' => $reply['first_ms']));
		}
		if (!empty($options['players'])) {
			$result['players'] = $this->players($transport, $ip, $port, $opts);
		}
		return $result;
	}

	// One round trip. Returns array(type, body, first_ms) on a good reply, or a finished
	// result array (with a 'status' key) when the exchange failed or the reply is unreadable.
	private function ask($transport, $ip, $port, $payload, $opts)
	{
		$reply = $transport->exchange($ip, $port, $payload, $opts);
		$outcome = isset($reply['outcome']) ? $reply['outcome'] : 'error';
		if ($outcome === 'agent_error' || $outcome === 'error') {
			return diag_query_result('agent_error', array('message' => (string)($reply['detail'] ?? '')));
		}
		if ($outcome === 'no_reply') {
			return diag_query_result('no_response');
		}
		if ($outcome === 'icmp_unreachable') {
			return diag_query_result('port_unreachable');
		}
		try {
			$message = a2s_assemble($reply['datagrams']);
		} catch (A2sParseException $e) {
			return diag_query_result('invalid_response', array('message' => $e->getMessage(), 'latency_ms' => $reply['first_ms'] ?? null));
		}
		$message['first_ms'] = isset($reply['first_ms']) ? $reply['first_ms'] : null;
		return $message;
	}

	// Player list: ask for a challenge, then ask again with it. A failure gives an empty list;
	// the player list is optional and must not turn an online server into an error.
	private function players($transport, $ip, $port, $opts)
	{
		$reply = $this->ask($transport, $ip, $port, a2s_build_player_request(a2s_no_challenge()), $opts);
		if (isset($reply['status'])) {
			return array();
		}
		if ($reply['type'] === 0x41) {
			$reply = $this->ask($transport, $ip, $port, a2s_build_player_request(a2s_parse_challenge($reply['body'])), $opts);
			if (isset($reply['status'])) {
				return array();
			}
		}
		try {
			return a2s_parse_players($reply['type'], $reply['body']);
		} catch (A2sParseException $e) {
			return array();
		}
	}
}

// Every adapter, by id.
function diag_query_adapters()
{
	$adapters = array();
	foreach (array(new A2sQueryAdapter()) as $adapter) {
		$adapters[$adapter->id()] = $adapter;
	}
	return $adapters;
}

// Game list for the forms: key, label, default game port, query adapter, query port, TCP port.
// Query port is the A2S port; for Source games it is normally the game port, but the form lets
// the user change it. 'tcp' is a TCP port worth checking for that game, or 0.
function diag_game_presets()
{
	// 'game_key' is the game_key of the panel's game config (config_games), so a customer's server can pick its preset.
	$a2s = function ($key, $label, $game_key) {
		return array('key' => $key, 'label' => $label, 'port' => 27015, 'adapter' => 'a2s', 'query_port' => 27015, 'tcp_port' => 27015, 'game_key' => $game_key);
	};
	return array(
		$a2s('cs16', 'Counter-Strike 1.6', 'cstrike'),
		$a2s('czero', 'Counter-Strike: Condition Zero', 'czero'),
		$a2s('css', 'Counter-Strike: Source', 'counterstrike_source'),
		$a2s('csgo', 'Counter-Strike: Global Offensive / CS2', 'csgo'),
		$a2s('gmod', 'Garry\'s Mod', 'garrysmod'),
		$a2s('tf2', 'Team Fortress 2', 'tf2'),
		$a2s('l4d2', 'Left 4 Dead 2', 'left4dead2'),
		$a2s('hl2dm', 'Half-Life 2: Deathmatch', 'hl2dm'),
		$a2s('dods', 'Day of Defeat: Source', 'dods'),
		array('key' => 'minecraft_java', 'label' => 'Minecraft: Java (TCP only)', 'port' => 25565, 'adapter' => '', 'query_port' => 0, 'tcp_port' => 25565, 'game_key' => ''),
		array('key' => 'ssh', 'label' => 'SSH (TCP)', 'port' => 22, 'adapter' => '', 'query_port' => 0, 'tcp_port' => 22, 'game_key' => ''),
	);
}

function diag_find_preset($key)
{
	foreach (diag_game_presets() as $preset) {
		if ($preset['key'] === $key) {
			return $preset;
		}
	}
	return null;
}
