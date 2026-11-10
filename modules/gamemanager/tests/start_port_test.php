<?php
// Start and restart must send the configured game port. Port 0 or empty is refused.
// Run: php modules/gamemanager/tests/start_port_test.php   (exit 0 = all passed)
// The test never talks to an agent. The stand-ins below record the parameters that would
// be sent, and every call answers "1" (success). Real functions are used if the extension exists.
$GLOBALS['sent_params'] = array();
if (!function_exists('xmlrpc_encode_request')) {
	function xmlrpc_encode_request($method, $params)
	{
		$GLOBALS['sent_params'][] = array($method, $params);
		return $method;
	}
}
if (!function_exists('xmlrpc_decode')) {
	function xmlrpc_decode($xml) { return 1; }
}
if (!function_exists('xmlrpc_is_fault')) {
	function xmlrpc_is_fault($response) { return false; }
}
require_once(dirname(__DIR__, 3) . '/includes/lib_remote.php');

const TEST_KEY = 'test-key';

/** Port the agent would read: parameter number $index, decrypted. */
function sent_param($call, $index)
{
	$xxtea = new Crypt_XXTEA();
	$xxtea->setKey(TEST_KEY);
	return base64_decode($xxtea->decrypt(base64_decode($GLOBALS['sent_params'][$call][1][$index])));
}

function check($name, $ok)
{
	if (!$ok) {
		fwrite(STDERR, "FAIL: $name\n");
		exit(1);
	}
	echo "PASS: $name\n";
}

$remote = new RemoteLibrary('127.0.0.1', 1, TEST_KEY, 1);
foreach (array(0, '0', '', null, 'abc') as $bad) {
	$r = $remote->universal_start_result(1, '/h', 'bin', '', 'cmd', $bad, '192.0.2.1', 'NA', 0, '', '', 'key');
	check('start with port ' . var_export($bad, true) . ' is rejected', $r['success'] === 0 && $r['status'] === 'invalid_port');
	$r = $remote->remote_restart_server_result(1, '192.0.2.1', $bad, 'rcon', 'pw', 'tcp', '/h', 'bin', '', 'cmd', 'NA', 0, '', '', 'key');
	check('restart with port ' . var_export($bad, true) . ' is rejected', $r['success'] === 0 && $r['status'] === 'invalid_port');
}
check('nothing was sent for bad ports', count($GLOBALS['sent_params']) === 0);

$r = $remote->universal_start_result(1, '/h', 'bin', '', 'cmd', 2302, '192.0.2.1', 'NA', 0, '', '', 'key');
check('start with the configured port is sent', count($GLOBALS['sent_params']) === 1 && !empty($r['success']) && sent_param(0, 5) === '2302');
$r = $remote->remote_restart_server_result(1, '192.0.2.1', '2302', 'rcon', 'pw', 'tcp', '/h', 'bin', '', 'cmd', 'NA', 0, '', '', 'key');
check('restart with the configured port is sent', count($GLOBALS['sent_params']) === 2 && !empty($r['success']) && sent_param(1, 2) === '2302');

echo "All start port tests passed.\n";
