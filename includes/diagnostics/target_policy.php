<?php
/*
 * Diagnostic target policy. Pure functions, no database and no network of their own:
 * DNS goes through a resolver callable so tests can control the answers.
 * The address table must match section 4 of docs/agent-diagnostics-protocol.md.
 *
 * Who may test what:
 *   - Everyone: any public address, and the IP addresses of game servers they own (those may be private).
 *   - Admins: private ranges too, but only if the admin override setting is on.
 *   - Nobody: loopback, link-local, multicast, unspecified, reserved/documentation ranges.
 */

// CIDR lists. 'blocked' is never allowed. 'private' needs an owner or admin reason.
function diag_blocked_ranges()
{
	return array(
		'0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
		'192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
		'::/128', '::1/128', 'fe80::/10', 'ff00::/8', '2001:db8::/32',
	);
}

function diag_private_ranges()
{
	return array('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7');
}

// True when binary address $bin is inside "address/bits". Both must be the same length.
function diag_in_cidr($bin, $cidr)
{
	list($net, $bits) = explode('/', $cidr);
	$net_bin = inet_pton($net);
	if ($net_bin === false || strlen($net_bin) !== strlen($bin)) {
		return false;
	}
	$bits = (int)$bits;
	$full = intdiv($bits, 8);
	if ($full > 0 && substr($bin, 0, $full) !== substr($net_bin, 0, $full)) {
		return false;
	}
	$rest = $bits % 8;
	if ($rest === 0) {
		return true;
	}
	$mask = (0xFF << (8 - $rest)) & 0xFF;
	return (ord($bin[$full]) & $mask) === (ord($net_bin[$full]) & $mask);
}

// 'invalid', 'blocked', 'private' or 'public'.
function diag_ip_class($ip)
{
	$bin = @inet_pton((string)$ip);
	if ($bin === false) {
		return 'invalid';
	}
	$bin = diag_unwrap_ipv4($bin);
	foreach (array('blocked' => diag_blocked_ranges(), 'private' => diag_private_ranges()) as $class => $ranges) {
		foreach ($ranges as $range) {
			if (diag_in_cidr($bin, $range)) {
				return $class;
			}
		}
	}
	return 'public';
}

// ::ffff:a.b.c.d and 64:ff9b::a.b.c.d are checked as the IPv4 address inside them.
function diag_unwrap_ipv4($bin)
{
	if (strlen($bin) !== 16) {
		return $bin;
	}
	$mapped = str_repeat("\0", 10) . "\xff\xff";
	if (substr($bin, 0, 12) === $mapped) {
		return substr($bin, 12);
	}
	if (substr($bin, 0, 12) === substr(inet_pton('64:ff9b::'), 0, 12)) {
		return substr($bin, 12);
	}
	return $bin;
}

// Splits user input into a host. Returns array(ok, host, is_ip, message).
// Rejects URLs, ports, paths, spaces and odd number forms like 2130706433 or 0x7f.1.
function diag_parse_host($input)
{
	$host = strtolower(trim((string)$input));
	$fail = function ($message) {
		return array('ok' => false, 'host' => '', 'is_ip' => false, 'message' => $message);
	};
	if ($host === '' || strlen($host) > 253) {
		return $fail('Enter a host name or IP address.');
	}
	if (filter_var($host, FILTER_VALIDATE_IP)) {
		return array('ok' => true, 'host' => $host, 'is_ip' => true, 'message' => '');
	}
	$labels = explode('.', $host);
	foreach ($labels as $label) {
		if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
			return $fail('Not a valid host name or IP address.');
		}
	}
	if (count($labels) < 2 || !preg_match('/^[a-z]/', end($labels))) {
		return $fail('Not a valid host name or IP address.');
	}
	return array('ok' => true, 'host' => $host, 'is_ip' => false, 'message' => '');
}

function diag_parse_port($value)
{
	if (!is_string($value) && !is_int($value)) {
		return null;
	}
	if (!preg_match('/^[0-9]{1,5}$/', trim((string)$value))) {
		return null;
	}
	$port = (int)$value;
	return ($port >= 1 && $port <= 65535) ? $port : null;
}

// A whole number from the form, inside $min..$max. Null when bad.
function diag_parse_int($value, $min, $max)
{
	if (!is_string($value) && !is_int($value)) {
		return null;
	}
	if (!preg_match('/^[0-9]{1,6}$/', trim((string)$value))) {
		return null;
	}
	$number = (int)$value;
	return ($number >= $min && $number <= $max) ? $number : null;
}

// Default resolver: IPv4 and IPv6 addresses of a host name, at most 8.
function diag_default_resolver($host)
{
	$ips = array();
	$v4 = @gethostbynamel($host);
	if (is_array($v4)) {
		$ips = $v4;
	}
	$v6 = @dns_get_record($host, DNS_AAAA);
	if (is_array($v6)) {
		foreach ($v6 as $record) {
			$ips[] = $record['ipv6'];
		}
	}
	return array_slice(array_values(array_unique($ips)), 0, 8);
}

// Decides one resolved address. Returns '' when allowed, otherwise a reason code.
function diag_address_verdict($ip, $host, $ctx)
{
	$class = diag_ip_class($ip);
	$own = in_array($ip, $ctx['own_ips'], true);
	$is_admin = ($ctx['role'] === 'admin');
	if ($class === 'invalid' || $class === 'blocked') {
		return 'blocked';
	}
	if ($class === 'private') {
		return ($own || ($is_admin && !empty($ctx['admin_allow_private']))) ? '' : 'private_denied';
	}
	// Any public address may be pinged: it is the same as running ping from any home connection.
	return '';
}

function diag_deny_message($code)
{
	$messages = array(
		'blocked' => 'This address is in a range that can never be tested (loopback, link-local, multicast or reserved).',
		'private_denied' => 'Private network addresses can only be tested when they belong to one of your game servers.',
		'no_address' => 'The host name did not resolve to an address.',
	);
	return isset($messages[$code]) ? $messages[$code] : 'This target is not allowed.';
}

// The one entry point. $ctx keys: role, own_ips[], admin_allow_private,
// resolver (callable host => ips, optional).
// Returns array(ok, code, message, host, ips[], ip, allow_private). 'ip' is the address to send to the agent.
function diag_check_target($input, $ctx)
{
	$parsed = diag_parse_host($input);
	if (!$parsed['ok']) {
		return array('ok' => false, 'code' => 'bad_target', 'message' => $parsed['message'], 'host' => '', 'ips' => array(), 'ip' => '', 'allow_private' => false);
	}
	$host = $parsed['host'];
	$resolver = isset($ctx['resolver']) ? $ctx['resolver'] : 'diag_default_resolver';
	$ips = $parsed['is_ip'] ? array($host) : call_user_func($resolver, $host);
	$ips = array_values(array_filter((array)$ips, function ($ip) { return diag_ip_class($ip) !== 'invalid'; }));
	// Compare addresses in one text form (a v6 address can be written many ways).
	$ips = array_map(function ($ip) { return inet_ntop(inet_pton($ip)); }, $ips);
	$deny = function ($code) use ($host, $ips) {
		return array('ok' => false, 'code' => $code, 'message' => diag_deny_message($code), 'host' => $host, 'ips' => $ips, 'ip' => '', 'allow_private' => false);
	};
	if (count($ips) === 0) {
		return $deny('no_address');
	}
	// Every address must pass. A name with one public and one internal answer is refused.
	foreach ($ips as $ip) {
		$verdict = diag_address_verdict($ip, $host, $ctx);
		if ($verdict !== '') {
			return $deny($verdict);
		}
	}
	$chosen = diag_pick_address($ips);
	return array('ok' => true, 'code' => 'ok', 'message' => '', 'host' => $host, 'ips' => $ips,
		'ip' => $chosen, 'allow_private' => diag_ip_class($chosen) === 'private');
}

// Prefer IPv4: it works on more game hosts.
function diag_pick_address($ips)
{
	foreach ($ips as $ip) {
		if (strpos($ip, ':') === false) {
			return $ip;
		}
	}
	return $ips[0];
}

// The settings that feed $env['policy'].
function diag_policy_context($role, $own_ips, $settings)
{
	return array(
		'role' => $role,
		'own_ips' => array_values(array_unique($own_ips)),
		'admin_allow_private' => !empty($settings['diag_admin_allow_private']) && $settings['diag_admin_allow_private'] !== '0',
	);
}
