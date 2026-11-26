<?php
// Target policy: address classes, input parsing, who may test what, DNS rebinding answers.
require_once __DIR__ . '/diag_test_helper.php';
require_once dirname(__DIR__) . '/diagnostics/target_policy.php';

foreach (array(
	'8.8.8.8' => 'public', '1.1.1.1' => 'public', '2606:4700:4700::1111' => 'public',
	'127.0.0.1' => 'blocked', '127.255.255.254' => 'blocked', '0.0.0.0' => 'blocked', '169.254.169.254' => 'blocked',
	'224.0.0.1' => 'blocked', '255.255.255.255' => 'blocked', '240.0.0.1' => 'blocked', '203.0.113.9' => 'blocked',
	'::1' => 'blocked', '::' => 'blocked', 'fe80::1' => 'blocked', 'ff02::1' => 'blocked', '2001:db8::5' => 'blocked',
	'::ffff:127.0.0.1' => 'blocked', '::ffff:169.254.169.254' => 'blocked', '64:ff9b::7f00:1' => 'blocked',
	'10.1.2.3' => 'private', '172.16.0.1' => 'private', '172.31.255.255' => 'private', '192.168.1.1' => 'private',
	'100.64.0.1' => 'private', 'fd00::1' => 'private', '::ffff:10.0.0.1' => 'private',
	'172.32.0.1' => 'public', '172.15.255.255' => 'public', '100.128.0.1' => 'public', '11.0.0.1' => 'public',
	'not an ip' => 'invalid', '256.1.1.1' => 'invalid', '' => 'invalid',
) as $ip => $class) {
	check("ip class $ip is $class", diag_ip_class($ip) === $class);
}

// Host parsing: only clean names and IP literals.
foreach (array('example.com', 'game.example.co.uk', '8.8.8.8', '2606:4700::1111', 'EXAMPLE.com ') as $ok) {
	check("host accepted: $ok", diag_parse_host($ok)['ok']);
}
foreach (array('', 'localhost', 'http://example.com', 'example.com:27015', 'example.com/path', 'a b.com', '8.8.8.8; ls', '$(id).com',
	'a`b`.com', "ex\nample.com", '2130706433', '0x7f.1', '127.1', 'example.123', '-bad.com', str_repeat('a', 64) . '.com', '..com', '8.8.8.8 -c 100') as $bad) {
	check('host rejected: ' . json_encode($bad), !diag_parse_host($bad)['ok']);
}

// Ports and numbers
foreach (array('1', '80', '27015', '65535', 443) as $p) {
	check("port ok $p", diag_parse_port($p) !== null);
}
foreach (array('0', '65536', '-1', '80.5', '1e3', 'abc', '', ' ', '0x50', array(80), null, '99999999') as $p) {
	check('port rejected ' . json_encode($p), diag_parse_port($p) === null);
}
check('int in range', diag_parse_int('3000', 500, 10000) === 3000);
check('int below range', diag_parse_int('100', 500, 10000) === null);
check('int above range', diag_parse_int('10001', 500, 10000) === null);
check('int not number', diag_parse_int('12abc', 1, 100) === null);

// Who may test what
$dns = array('mygame.example.com' => array('198.51.101.7'), 'tests.example.org' => array('93.184.216.34'),
	'rebind.example.net' => array('93.184.216.34', '127.0.0.1'), 'internal.example.net' => array('10.0.0.8'),
	'v6.example.net' => array('2606:4700:4700::1111'), 'nothing.example.net' => array());
$resolver = function ($host) use ($dns) {
	return isset($dns[$host]) ? $dns[$host] : array();
};
function ctx($role, $resolver, $extra = array())
{
	return array_merge(array('role' => $role, 'own_ips' => array('198.51.101.7', '10.9.9.9'),
		'admin_allow_private' => false, 'resolver' => $resolver), $extra);
}

$r = diag_check_target('198.51.101.7', ctx('user', $resolver));
check('user: own public IP allowed', $r['ok'] && $r['ip'] === '198.51.101.7' && !$r['allow_private']);
$r = diag_check_target('mygame.example.com', ctx('user', $resolver));
check('user: name resolving to own IP allowed, IP pinned', $r['ok'] && $r['ip'] === '198.51.101.7');
$r = diag_check_target('10.9.9.9', ctx('user', $resolver));
check('user: own private IP allowed with allow_private', $r['ok'] && $r['allow_private']);
check('user: other private IP denied', diag_check_target('10.0.0.8', ctx('user', $resolver))['code'] === 'private_denied');
check('user: any public IP allowed', diag_check_target('8.8.8.8', ctx('user', $resolver))['ok']);
check('user: any public host name allowed, IP pinned', diag_check_target('tests.example.org', ctx('user', $resolver))['ip'] === '93.184.216.34');
check('user: loopback denied', diag_check_target('127.0.0.1', ctx('user', $resolver))['code'] === 'blocked');
check('user: metadata IP denied', diag_check_target('169.254.169.254', ctx('user', $resolver))['code'] === 'blocked');
check('user: DNS rebinding answer (public + loopback) denied', !diag_check_target('rebind.example.net', ctx('admin', $resolver))['ok']);
check('user: name resolving to internal denied', diag_check_target('internal.example.net', ctx('user', $resolver))['code'] === 'private_denied');
check('user: unresolvable name', diag_check_target('nothing.example.net', ctx('user', $resolver))['code'] === 'no_address');
check('user: bad input', diag_check_target('127.0.0.1; id', ctx('user', $resolver))['code'] === 'bad_target');

check('admin: any public IP allowed', diag_check_target('8.8.8.8', ctx('admin', $resolver))['ok']);
check('admin: public IPv6 allowed', diag_check_target('v6.example.net', ctx('admin', $resolver))['ok']);
check('admin: other private denied by default', diag_check_target('10.0.0.8', ctx('admin', $resolver))['code'] === 'private_denied');
$r = diag_check_target('internal.example.net', ctx('admin', $resolver, array('admin_allow_private' => true)));
check('admin: private allowed with override', $r['ok'] && $r['allow_private']);
check('admin: loopback denied even with override', diag_check_target('127.0.0.1', ctx('admin', $resolver, array('admin_allow_private' => true)))['code'] === 'blocked');
check('subuser: public IP allowed', diag_check_target('8.8.8.8', ctx('subuser', $resolver))['ok']);
check('override flag is ignored for users', diag_check_target('10.0.0.8', ctx('user', $resolver, array('admin_allow_private' => true)))['code'] === 'private_denied');

// IPv6 own IP written differently
$r = diag_check_target('2606:4700:4700:0:0:0:0:1111', ctx('user', $resolver, array('own_ips' => array('2606:4700:4700::1111'))));
check('IPv6 written long still matches own IP', $r['ok']);

// IPv4 preferred when both exist
check('pick prefers IPv4', diag_pick_address(array('2606:4700::1', '93.184.216.34')) === '93.184.216.34');

// Policy context from settings
$c = diag_policy_context('admin', array('1.2.3.4', '1.2.3.4'), array('diag_admin_allow_private' => '1'));
check('context: own IPs unique, override on', $c['own_ips'] === array('1.2.3.4') && $c['admin_allow_private'] && !isset($c['test_targets']));
echo "All policy tests passed\n";
