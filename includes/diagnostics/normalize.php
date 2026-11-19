<?php
/*
 * Cleans the result JSON an agent returns before the panel shows or stores it.
 * An agent can be old, buggy or compromised, so every field is checked for type and size
 * and unknown fields are dropped. Output is plain text; the page escapes it when it prints.
 */

function diag_text($value, $max = 200)
{
	if (!is_scalar($value)) {
		return '';
	}
	$text = substr((string)$value, 0, $max);
	return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text);
}

function diag_num($value, $min, $max)
{
	if (!is_int($value) && !is_float($value)) {
		return null;
	}
	return round(max($min, min($max, (float)$value)), 1);
}

function diag_pick($value, $allowed, $fallback)
{
	return in_array($value, $allowed, true) ? $value : $fallback;
}

function diag_ip_or_empty($value)
{
	return (is_string($value) && filter_var($value, FILTER_VALIDATE_IP)) ? $value : '';
}

function diag_normalize_tcp($result)
{
	return array(
		'outcome' => diag_pick($result['outcome'] ?? '', array('connected', 'refused', 'timeout', 'unreachable', 'error'), 'error'),
		'connect_ms' => diag_num($result['connect_ms'] ?? null, 0, 600000),
		'remote_ip' => diag_ip_or_empty($result['remote_ip'] ?? ''),
		'detail' => diag_text($result['detail'] ?? ''),
	);
}

// The UDP exchange keeps the raw datagrams (binary) for the protocol parser.
function diag_normalize_udp($result)
{
	$datagrams = array();
	$first_ms = null;
	$total = 0;
	foreach ((array)($result['datagrams'] ?? array()) as $item) {
		$bytes = is_array($item) && is_string($item['data_b64'] ?? null) ? base64_decode($item['data_b64'], true) : false;
		$total += $bytes === false ? 0 : strlen($bytes);
		if ($bytes === false || count($datagrams) >= 16 || $total > 32768) {
			continue;
		}
		$datagrams[] = $bytes;
		if ($first_ms === null) {
			$first_ms = diag_num($item['t_ms'] ?? null, 0, 600000);
		}
	}
	$outcome = diag_pick($result['outcome'] ?? '', array('replied', 'no_reply', 'icmp_unreachable', 'error'), 'error');
	if ($outcome === 'replied' && count($datagrams) === 0) {
		$outcome = 'error';
	}
	return array('outcome' => $outcome, 'datagrams' => $datagrams, 'first_ms' => $first_ms, 'detail' => diag_text($result['detail'] ?? ''));
}

function diag_normalize_traceroute($result, $max_hops)
{
	$hops = array();
	foreach ((array)($result['hops'] ?? array()) as $hop) {
		if (!is_array($hop) || count($hops) >= $max_hops) {
			continue;
		}
		$probes = array();
		foreach ((array)($hop['probes'] ?? array()) as $probe) {
			if (is_array($probe) && count($probes) < 3) {
				$probes[] = array(
					'ip' => diag_ip_or_empty($probe['ip'] ?? ''),
					'host' => diag_text($probe['host'] ?? '', 255),
					'rtt_ms' => diag_num($probe['rtt_ms'] ?? null, 0, 600000),
					'timeout' => !empty($probe['timeout']),
				);
			}
		}
		$hops[] = array('hop' => count($hops) + 1, 'probes' => $probes);
	}
	return array(
		'outcome' => diag_pick($result['outcome'] ?? '', array('completed', 'max_hops', 'timeout', 'error'), 'error'),
		'method' => diag_text($result['method'] ?? '', 20),
		'reached' => !empty($result['reached']),
		'hops' => $hops,
		'detail' => diag_text($result['detail'] ?? ''),
	);
}

// Mbps is worked out here from bytes and time, so a wrong figure from the agent is not shown.
function diag_normalize_download($result, $byte_cap)
{
	$bytes = diag_num($result['bytes'] ?? null, 0, $byte_cap);
	$elapsed = diag_num($result['elapsed_ms'] ?? null, 0, 600000);
	$mbps = ($bytes !== null && $elapsed !== null && $elapsed > 0) ? round($bytes * 8 / 1000000 / ($elapsed / 1000), 2) : null;
	$outcomes = array('ok', 'timeout', 'http_error', 'redirect_blocked', 'tls_error', 'dns_error', 'connect_error', 'error');
	return array(
		'outcome' => diag_pick($result['outcome'] ?? '', $outcomes, 'error'),
		'bytes' => $bytes === null ? null : (int)$bytes,
		'elapsed_ms' => $elapsed,
		'connect_ms' => diag_num($result['connect_ms'] ?? null, 0, 600000),
		'mbps' => $mbps,
		'http_status' => is_int($result['http_status'] ?? null) ? $result['http_status'] : null,
		'remote_ip' => diag_ip_or_empty($result['remote_ip'] ?? ''),
		'connections' => is_int($result['connections'] ?? null) ? max(1, min(4, $result['connections'])) : 1,
	);
}
