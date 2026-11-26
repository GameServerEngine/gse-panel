<?php
/*
 * Port check: which ports a machine is really listening on.
 * The agent runs one fixed command (netstat on Windows, ss on Linux) and sends the text back.
 * Everything here only reads that text, so it can be tested without an agent.
 * No user text is ever put into the command.
 */

function diag_listen_command($os)
{
	return $os === 'windows' ? 'netstat -ano' : 'ss -H -lntup';
}

// "0.0.0.0:2302", "[::]:80", "*:22", "127.0.0.53%lo:53" -> array(address, port). Null if it is not an endpoint.
function diag_split_endpoint($text)
{
	$pos = strrpos($text, ':');
	if ($pos === false || !ctype_digit(substr($text, $pos + 1))) {
		return null;
	}
	$addr = trim(substr($text, 0, $pos), '[]');
	$addr = preg_replace('/%.*$/', '', $addr);
	return array($addr, (int)substr($text, $pos + 1));
}

function diag_listener($proto, $endpoint, $pid, $process)
{
	$parts = diag_split_endpoint($endpoint);
	if ($parts === null || $parts[1] < 1 || $parts[1] > 65535) {
		return null;
	}
	return array('proto' => $proto, 'addr' => $parts[0], 'port' => $parts[1], 'pid' => $pid, 'process' => $process);
}

// Windows "netstat -ano". TCP rows count when LISTENING. UDP rows have no state; a UDP socket with a
// real remote address is an outgoing connection, not a listener.
function diag_parse_netstat($text)
{
	$found = array();
	foreach (preg_split('/\r?\n/', (string)$text) as $line) {
		$f = preg_split('/\s+/', trim($line));
		$listener = null;
		if (count($f) >= 5 && strtoupper($f[0]) === 'TCP' && strtoupper($f[3]) === 'LISTENING') {
			$listener = diag_listener('tcp', $f[1], (int)$f[4], '');
		} elseif (count($f) >= 4 && strtoupper($f[0]) === 'UDP' && $f[2] === '*:*') {
			$listener = diag_listener('udp', $f[1], (int)$f[3], '');
		}
		if ($listener !== null) {
			$found[] = $listener;
		}
	}
	return $found;
}

// Linux "ss -H -lntup": netid state recv-q send-q local peer [users:(("name",pid=1,fd=3))]
function diag_parse_ss($text)
{
	$found = array();
	foreach (preg_split('/\r?\n/', (string)$text) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (count($f) < 6 || !in_array($f[0], array('tcp', 'udp'), true) || !in_array($f[1], array('LISTEN', 'UNCONN'), true)) {
			continue;
		}
		$pid = 0;
		$process = '';
		if (preg_match('/\(\("([^"]*)",pid=(\d+)/', $line, $m)) {
			$process = $m[1];
			$pid = (int)$m[2];
		}
		$listener = diag_listener($f[0], $f[4], $pid, $process);
		if ($listener !== null) {
			$found[] = $listener;
		}
	}
	return $found;
}

// Windows "tasklist /FO CSV /NH" -> array(pid => program name)
function diag_parse_tasklist($text)
{
	$names = array();
	foreach (preg_split('/\r?\n/', (string)$text) as $line) {
		$f = str_getcsv(trim($line));
		if (count($f) >= 2 && ctype_digit($f[1])) {
			$names[(int)$f[1]] = $f[0];
		}
	}
	return $names;
}

function diag_listen_wildcard($addr)
{
	return in_array($addr, array('0.0.0.0', '::', '*'), true);
}

function diag_same_address($a, $b)
{
	$pa = @inet_pton($a);
	return $pa !== false && $pa === @inet_pton($b);
}

// One protocol of one port. 'yes' = listening on the server's address (or on every address),
// 'other' = listening, but only on a different address, 'no' = nothing listens.
function diag_listener_match($listeners, $proto, $port, $ip)
{
	$other = null;
	foreach ($listeners as $l) {
		if ($l['proto'] !== $proto || $l['port'] !== $port) {
			continue;
		}
		if (diag_listen_wildcard($l['addr']) || diag_same_address($l['addr'], $ip)) {
			return array('state' => 'yes', 'listener' => $l);
		}
		$other = $l;
	}
	return array('state' => $other === null ? 'no' : 'other', 'listener' => $other);
}

// $ports: array(array('port' => int, 'label' => text)). Returns one array per port with a tcp and a udp answer.
function diag_listen_check($listeners, $ports, $ip)
{
	$checked = array();
	foreach ($ports as $p) {
		$checked[] = array('port' => $p['port'], 'label' => $p['label'],
			'tcp' => diag_listener_match($listeners, 'tcp', $p['port'], $ip), 'udp' => diag_listener_match($listeners, 'udp', $p['port'], $ip));
	}
	return $checked;
}

function diag_port_is_listening($c)
{
	return $c['tcp']['state'] === 'yes' || $c['udp']['state'] === 'yes';
}

function diag_listen_outcome($checked)
{
	$up = count(array_filter($checked, 'diag_port_is_listening'));
	if ($up === count($checked)) {
		return 'ok';
	}
	return $up === 0 ? 'not_listening' : 'partial';
}

function diag_listen_summary($outcome, $up, $total)
{
	if ($outcome === 'ok') {
		return 'All ' . $total . ' port' . ($total === 1 ? ' is' : 's are') . ' listening.';
	}
	if ($outcome === 'not_listening') {
		return 'Nothing is listening on the checked port' . ($total === 1 ? '' : 's') . '. If the game server should be running, check that it started.';
	}
	return $up . ' of ' . $total . ' ports are listening. A server may start without opening every reserved port, so check the ones below.';
}

// What one protocol says. Admins also see the program behind the port.
function diag_listen_state_text($label, $answer, $is_admin)
{
	$text = array('yes' => 'listening', 'other' => 'listening on another address only', 'no' => 'not listening');
	$out = $label . ' ' . $text[$answer['state']];
	$l = $answer['listener'];
	if ($is_admin && $l !== null) {
		$out .= ' (' . $l['addr'] . ($l['pid'] ? ', PID ' . $l['pid'] : '') . ($l['process'] !== '' ? ', ' . $l['process'] : '') . ')';
	}
	return $out;
}

function diag_listen_rows($checked, $is_admin)
{
	$rows = array();
	foreach ($checked as $c) {
		$rows[] = array($c['label'] . ' ' . $c['port'], diag_listen_state_text('UDP', $c['udp'], $is_admin) . '; ' . diag_listen_state_text('TCP', $c['tcp'], $is_admin));
	}
	return $rows;
}

// Adds the program name to listeners from the process list (Windows netstat only gives the PID).
function diag_name_listeners($listeners, $names)
{
	foreach ($listeners as $i => $l) {
		if ($l['process'] === '' && isset($names[$l['pid']])) {
			$listeners[$i]['process'] = $names[$l['pid']];
		}
	}
	return $listeners;
}

// The ports one game server uses, from its game config (SimpleXML) and its game port:
// the game port, the query port and every reserved port. Duplicates are listed once.
function diag_ports_from_config($server_xml, $game_port)
{
	$ports = array(array('port' => (int)$game_port, 'label' => 'Game port'));
	$add = function ($port, $label) use (&$ports) {
		foreach ($ports as $p) {
			if ($p['port'] === $port) {
				return;
			}
		}
		if ($port >= 1 && $port <= 65535) {
			$ports[] = array('port' => $port, 'label' => $label);
		}
	};
	$shift = function ($node) use ($game_port) {
		return $node['type'] == 'subtract' ? (int)$game_port - (int)$node : (int)$game_port + (int)$node;
	};
	if ($server_xml && $server_xml->query_port) {
		$add($shift($server_xml->query_port), 'Query port');
	}
	if ($server_xml && $server_xml->reserve_ports) {
		foreach ($server_xml->reserve_ports->port as $reserved) {
			$add($shift($reserved), 'Reserved port');
		}
	}
	return $ports;
}
