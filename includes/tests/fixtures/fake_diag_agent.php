<?php
// Test-only stand-in for an agent that implements docs/agent-diagnostics-protocol.md with real sockets.
// Run by diag_agent_integration_test.php as a router: php -S 127.0.0.1:PORT fake_diag_agent.php
// Env: FAKE_AGENT_KEY (shared key), FAKE_AGENT_LEGACY=1 (an old agent: answers diagnostics calls with a fault).
// It is NOT the real agent. The real agents are implemented in their own repositories.
require_once dirname(__DIR__, 2) . '/../Crypt/XXTEA.php';
require_once dirname(__DIR__) . '/../diagnostics/target_policy.php';

$key = getenv('FAKE_AGENT_KEY');
$xxtea = new Crypt_XXTEA();
$xxtea->setKey($key);
$decrypt = function ($p) use ($xxtea) { return base64_decode($xxtea->decrypt(base64_decode($p))); };

function struct_reply($fields)
{
	return array_map(function ($v) { return base64_encode((string)$v); }, $fields);
}

function out_error($status, $message = '')
{
	return struct_reply(array('success' => '0', 'status' => $status, 'message' => $message, 'result' => '', 'protocol_version' => '1'));
}

function out_ok($result, $ms)
{
	return struct_reply(array('success' => '1', 'status' => 'ok', 'message' => '', 'result' => json_encode($result), 'protocol_version' => '1', 'duration_ms' => (int)$ms, 'truncated' => '0'));
}

function in_range($v, $min, $max) { return is_int($v) && $v >= $min && $v <= $max; }

// Address rule of protocol doc section 4. Returns '' if allowed.
function address_check($p)
{
	$ip = isset($p['host_ip']) ? $p['host_ip'] : '';
	if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
		return 'bad_params';
	}
	$class = diag_ip_class($ip);
	if ($class === 'blocked' || ($class === 'private' && empty($p['allow_private']))) {
		return 'target_blocked';
	}
	return '';
}

function ms_since($t) { return round((hrtime(true) - $t) / 1000000, 1); }

function run_tcp($p)
{
	if (!in_range($p['port'] ?? null, 1, 65535) || !in_range($p['timeout_ms'] ?? null, 100, 10000)) {
		return out_error('bad_params', 'range');
	}
	$start = hrtime(true);
	$s = @stream_socket_client('tcp://' . (strpos($p['host_ip'], ':') !== false ? '[' . $p['host_ip'] . ']' : $p['host_ip']) . ':' . $p['port'], $errno, $errstr, $p['timeout_ms'] / 1000);
	$ms = ms_since($start);
	if ($s) {
		fclose($s);
		return out_ok(array('outcome' => 'connected', 'connect_ms' => $ms, 'remote_ip' => $p['host_ip'], 'remote_port' => $p['port'], 'detail' => ''), $ms);
	}
	$outcome = array(111 => 'refused', 110 => 'timeout', 101 => 'unreachable', 113 => 'unreachable')[$errno] ?? ($errno === 0 ? 'timeout' : 'error');
	return out_ok(array('outcome' => $outcome, 'connect_ms' => $outcome === 'refused' ? $ms : null, 'remote_ip' => $p['host_ip'], 'detail' => $outcome === 'error' ? $errstr : ''), $ms);
}

function run_udp($p)
{
	$checks = in_range($p['port'] ?? null, 1, 65535) && in_range($p['timeout_ms'] ?? null, 100, 10000) && in_range($p['idle_ms'] ?? null, 50, 2000)
		&& in_range($p['max_datagrams'] ?? null, 1, 16) && in_range($p['max_bytes'] ?? null, 64, 32768);
	$payload = base64_decode((string)($p['payload_b64'] ?? ''), true);
	if (!$checks || $payload === false || $payload === '' || strlen($payload) > 1400) {
		return out_error('bad_params', 'range');
	}
	$c = stream_socket_client('udp://' . $p['host_ip'] . ':' . $p['port'], $errno, $errstr, 1);
	$start = hrtime(true);
	fwrite($c, $payload);
	$datagrams = array();
	$bytes = 0;
	while (count($datagrams) < $p['max_datagrams'] && $bytes < $p['max_bytes']) {
		$wait = $datagrams ? $p['idle_ms'] / 1000 : $p['timeout_ms'] / 1000;
		$r = array($c);
		$w = $e = null;
		if (stream_select($r, $w, $e, (int)$wait, (int)(($wait - (int)$wait) * 1000000)) < 1) {
			break;
		}
		$d = @fread($c, 2048);
		if ($d === false || $d === '') {
			fclose($c);
			return out_ok(array('outcome' => 'icmp_unreachable', 'datagrams' => array(), 'bytes' => 0, 'remote_ip' => $p['host_ip'], 'detail' => ''), ms_since($start));
		}
		$d = substr($d, 0, $p['max_bytes'] - $bytes);
		$bytes += strlen($d);
		$datagrams[] = array('t_ms' => ms_since($start), 'data_b64' => base64_encode($d));
	}
	fclose($c);
	return out_ok(array('outcome' => $datagrams ? 'replied' : 'no_reply', 'datagrams' => $datagrams, 'bytes' => $bytes, 'remote_ip' => $p['host_ip'], 'detail' => ''), ms_since($start));
}

$xml = file_get_contents('php://input');
$method = '';
$params = xmlrpc_decode_request($xml, $method);
$legacy = getenv('FAKE_AGENT_LEGACY') === '1';
header('Content-Type: text/xml');

if ($method === 'quick_chk') {
	echo xmlrpc_encode_request(null, $decrypt($params[0]) === 'hello' ? 0 : 1);
} elseif ($legacy && in_array($method, array('diag_capabilities', 'diag_run'), true)) {
	echo xmlrpc_encode_request(null, array('faultCode' => 1, 'faultString' => "Can't locate object method \"$method\" via package agent"));
} elseif ($method === 'diag_capabilities' && $decrypt(end($params)) === 'Encryption checking OK') {
	echo xmlrpc_encode_request(null, struct_reply(array('success' => '1', 'status' => 'ok', 'protocol_version' => '1', 'agent_version' => '0.6.0', 'os' => 'linux',
		'tools' => '["tcp_connect","udp_exchange"]', 'limits' => '{"max_runtime_s":60,"max_concurrent_jobs":2}')));
} elseif ($method === 'diag_run') {
	if ($decrypt(end($params)) !== 'Encryption checking OK') {
		echo xmlrpc_encode_request(null, out_error('bad_encryption'));
		exit;
	}
	$tool = $decrypt($params[0]);
	$p = json_decode($decrypt($params[1]), true);
	$blocked = is_array($p) ? address_check($p) : 'bad_params';
	if (!in_array($tool, array('tcp_connect', 'udp_exchange'), true)) {
		echo xmlrpc_encode_request(null, out_error('unsupported_tool'));
	} elseif ($blocked !== '') {
		echo xmlrpc_encode_request(null, out_error($blocked));
	} else {
		echo xmlrpc_encode_request(null, $tool === 'tcp_connect' ? run_tcp($p) : run_udp($p));
	}
} elseif ($method === 'shell_action' || $method === 'get_file_part') {
	// Serves one log file for the File Manager Log Analyzer tests: any path ending in the name of FAKE_AGENT_LOGFILE.
	$file = getenv('FAKE_AGENT_LOGFILE');
	$path = $decrypt($params[0]);
	if ($file === false || ($method === 'get_file_part' && basename($path) !== basename($file))) {
		echo xmlrpc_encode_request(null, -1);
	} elseif ($method === 'shell_action') {
		echo xmlrpc_encode_request(null, '1;' . base64_encode((string)filesize($file)));
	} else {
		$offset = (int)$decrypt($params[1]);
		$data = (string)file_get_contents($file, false, null, $offset, 3420000);
		echo xmlrpc_encode_request(null, ($offset + strlen($data)) . ';' . base64_encode(gzcompress($data, 9)));
	}
} elseif ($method === 'what_os') {
	echo xmlrpc_encode_request(null, 'Linux');
} else {
	echo xmlrpc_encode_request(null, array('faultCode' => 1, 'faultString' => "Unknown method $method"));
}
