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

// Tool lists: customers get server-bound tools with no server id to choose; admins get read-only diagnostics
$names = function ($scope) { return array_map(function ($t) { return $t['name']; }, assistant_tool_definitions($scope)); };
check('server tools', $names('server') === array('server_status', 'server_log', 'database_info', 'ftp_info', 'propose_action', 'propose_reset', 'list_files', 'read_file', 'db_query', 'propose_file_edit'));
check('admin has the panel diagnostics and the patch tool', count(array_diff(array('list_servers', 'panel_health', 'mysql_health', 'game_databases', 'agents_health', 'panel_list', 'panel_read', 'panel_search', 'agent_list', 'agent_read', 'propose_patch'), $names('admin'))) === 0);
check('admin has the repair proposals', count(array_diff(array('propose_changes', 'db_read'), $names('admin'))) === 0);
check('customers never get repair proposals', count(array_intersect($names('server'), array('propose_changes', 'db_read'))) === 0);
check('customers never get admin tools', count(array_intersect($names('server'), array('list_servers', 'panel_health', 'panel_read', 'panel_search', 'agent_read', 'propose_patch'))) === 0);
$admin_server_log = array();
foreach (assistant_tool_definitions('admin') as $t) {
	if ($t['name'] === 'server_log') {
		$admin_server_log = $t['input_schema'];
	}
}
check('admin server tools take a server id', isset($admin_server_log['properties']['home_id']) && in_array('home_id', $admin_server_log['required'], true));
$leak = false;
foreach (assistant_tool_definitions('server') as $t) {
	if (isset($t['input_schema']['properties']) && is_array($t['input_schema']['properties']) && isset($t['input_schema']['properties']['home_id'])) {
		$leak = true;
	}
}
check('customer tools never take a server id', !$leak);
check('customer prompt is limited to one server', strpos(assistant_system_prompt('server'), 'ONE game server') !== false);
check('admin prompt says it only proposes', strpos(assistant_system_prompt('admin'), 'cannot change anything yourself') !== false && strpos(assistant_system_prompt('admin'), 'propose_changes') !== false);

// Generated passwords: three plain words, one capital, symbols, easy to type
$ok = true;
$seen = array();
for ($i = 0; $i < 200; $i++) {
	$p = assistant_make_password();
	$seen[$p] = true;
	if (!preg_match('/^[A-Z][a-z]+[-_+=][a-z]+[-_+=][a-z]+$/', $p) || strlen($p) < 12 || strlen($p) > 40) {
		$ok = false;
	}
}
check('passwords are typeable words with a capital and symbols', $ok);
check('passwords differ', count($seen) > 190);

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
$result = assistant_ask('k', 'm', 'medium', array(), 'what servers do I have?', $run, 'server', $fake_api);
check('loop returns the final text', $result['text'] === 'You have one server.');
check('loop adds up tokens', $result['input_tokens'] === 250 && $result['output_tokens'] === 30);
check('loop records the tool used', $result['tools'] === array('list_servers'));

// API failure gives a friendly message and flags the error
$bad_api = function ($key, $body) { return array(500, null); };
$result = assistant_ask('k', 'm', '', array(), 'hi', $run, 'server', $bad_api);
check('api failure is flagged', $result['error'] === true);

// A model that never stops asking for tools is cut off
$loop_api = function ($key, $body) {
	return array(200, array('stop_reason' => 'tool_use', 'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
		'content' => array(array('type' => 'tool_use', 'id' => 'x', 'name' => 'list_servers', 'input' => array()))));
};
$result = assistant_ask('k', 'm', '', array(), 'hi', $run, 'server', $loop_api);
check('tool loop is limited', count($result['tools']) <= ASSISTANT_MAX_TOOL_STEPS + 1);

echo $failures === 0 ? "All passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
