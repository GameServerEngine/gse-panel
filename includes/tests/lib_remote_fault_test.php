<?php

// lib_remote.php stops when the xmlrpc extension is missing. The fake below never
// touches the network, so stand-in functions are enough to run this test anywhere.
if (!function_exists('xmlrpc_encode_request')) {
	function xmlrpc_encode_request($method, $params) { return $method; }
}
if (!function_exists('xmlrpc_decode')) {
	function xmlrpc_decode($xml) { return $xml; }
}

require_once(dirname(__DIR__) . '/lib_remote.php');

// Feeds canned replies to get_log without any network.
class FaultFakeRemote extends RemoteLibrary
{
	private $reply;

	public function __construct($reply)
	{
		parent::__construct('127.0.0.1', 12679, 'test-key', 1);
		$this->reply = $reply;
	}

	protected function postRequest($request)
	{
		return $this->reply;
	}
}

function fault_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

function fault_test_get_log($reply, &$data)
{
	$remote = new FaultFakeRemote($reply);
	return $remote->get_log(SCREEN_TYPE_HOME, 1, '/srv/home', $data);
}

$fault = array('faultCode' => 1, 'faultString' => "Can't locate object method at agent.pl line 5.");

fault_test_assert(remote_reply_is_fault($fault), 'fault array is detected');
fault_test_assert(!remote_reply_is_fault('1;abc'), 'string is not a fault');
fault_test_assert(!remote_reply_is_fault(-10), 'number is not a fault');
fault_test_assert(!remote_reply_is_fault(array('a')), 'plain array is not a fault');
fault_test_assert(
	remote_reply_fault_text($fault) === "Agent error: Can't locate object method at agent.pl line 5.",
	'fault text is readable'
);

// error_log writes to stderr in CLI; keep test output clean.
ini_set('error_log', '/dev/null');

$data = '';
$result = fault_test_get_log($fault, $data);
fault_test_assert(is_string($result) && strpos($result, 'Agent error: ') === 0, 'get_log returns the fault text, no fatal');
fault_test_assert($data === '', 'fault leaves log data empty');

$data = '';
fault_test_assert(fault_test_get_log('1;' . base64_encode('hello'), $data) == 1 && $data === "hello\n", 'get_log parses a "1;..." reply');

$data = '';
fault_test_assert(fault_test_get_log('2;' . base64_encode('bye'), $data) == 2 && $data === "bye\n", 'get_log parses a "2;..." reply');

$data = '';
fault_test_assert(fault_test_get_log(-10, $data) === 'Agent Returned: -10. Home not found.', 'get_log reports -10');

$data = '';
fault_test_assert(fault_test_get_log('-8;' . base64_encode('missing'), $data) == -8 && $data === 'missing', 'get_log parses a "-8;..." reply');
$data = '';
fault_test_assert(fault_test_get_log(-8, $data) == -8, 'get_log handles a bare -8');

$data = '';
fault_test_assert(fault_test_get_log(NULL, $data) === 0, 'unreachable agent still returns 0');
