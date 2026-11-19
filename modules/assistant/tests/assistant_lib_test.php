<?php
// Plain checks for assistant_lib.php. Run: php modules/assistant/tests/assistant_lib_test.php
require_once(__DIR__ . '/../assistant_lib.php');

$failures = 0;
function check($name, $ok)
{
	global $failures;
	echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
	if (!$ok) {
		$failures++;
	}
}

// Log filtering
$log = "Server started\nERROR: could not load map\nPlayer Bob joined\nall fine\nFatal exception in plugin\n";
$errors = assistant_filter_log($log, 'errors');
check('errors keeps error lines', strpos($errors, 'could not load map') !== false && strpos($errors, 'Fatal exception') !== false);
check('errors drops normal lines', strpos($errors, 'all fine') === false);
check('logins keeps join lines', strpos(assistant_filter_log($log, 'logins'), 'Bob joined') !== false);
check('empty result is labelled', assistant_filter_log('nothing here', 'errors') === '(no matching lines)');
$big = str_repeat("error " . str_repeat('x', 500) . "\n", 200);
check('size is capped', strlen(assistant_filter_log($big, 'errors')) <= ASSISTANT_LOG_CHARS);

// Tool list: only the four known tools, none can change a server
$names = array_map(function ($t) { return $t['name']; }, assistant_tool_definitions());
check('exactly four tools', $names === array('list_servers', 'server_status', 'server_log', 'propose_action'));

// Conversation loop with a fake API: one tool call, then an answer
$calls = 0;
$fake_api = function ($key, $body) use (&$calls) {
	$calls++;
	if ($calls === 1) {
		return array(200, array('stop_reason' => 'tool_use', 'usage' => array('input_tokens' => 100, 'output_tokens' => 10),
			'content' => array(array('type' => 'tool_use', 'id' => 't1', 'name' => 'list_servers', 'input' => array()))));
	}
	return array(200, array('stop_reason' => 'end_turn', 'usage' => array('input_tokens' => 150, 'output_tokens' => 20),
		'content' => array(array('type' => 'text', 'text' => 'You have one server.'))));
};
$run = function ($name, $input) { return array('data' => array('servers' => array())); };
$result = assistant_ask('k', 'm', 'medium', array(), 'what servers do I have?', $run, $fake_api);
check('loop returns the final text', $result['text'] === 'You have one server.');
check('loop adds up tokens', $result['input_tokens'] === 250 && $result['output_tokens'] === 30);
check('loop records the tool used', $result['tools'] === array('list_servers'));

// API failure gives a friendly message and flags the error
$bad_api = function ($key, $body) { return array(500, null); };
$result = assistant_ask('k', 'm', '', array(), 'hi', $run, $bad_api);
check('api failure is flagged', $result['error'] === true);

// A model that never stops asking for tools is cut off
$loop_api = function ($key, $body) {
	return array(200, array('stop_reason' => 'tool_use', 'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
		'content' => array(array('type' => 'tool_use', 'id' => 'x', 'name' => 'list_servers', 'input' => array()))));
};
$result = assistant_ask('k', 'm', '', array(), 'hi', $run, $loop_api);
check('tool loop is limited', count($result['tools']) <= ASSISTANT_MAX_TOOL_STEPS + 1);

echo $failures === 0 ? "All passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
