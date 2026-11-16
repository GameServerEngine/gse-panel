<?php
// Checks which state the start page picks for each agent status.
chdir(dirname(__DIR__, 3));
function get_lang($key) { return $key; }
function is_agent_status_online($status) { return isset($status['status']) && $status['status'] === 'ONLINE'; }
function read_server_config() { return false; }
function with_game_root() { return false; }
function print_success() {}
function print_failure() {}
// Load only the helper functions of the start page, not the page itself.
$source = file_get_contents('modules/gamemanager/start_server.php');
$start = strpos($source, 'function start_status_answer');
$end = strpos($source, 'function start_status_print');
eval(substr($source, $start, $end - $start));

$failures = 0;
function check($name, $actual, $expected)
{
	global $failures;
	if ($actual !== $expected) { echo "  FAIL: $name (got $actual, want $expected)\n"; $failures++; }
}

check('online', start_status_answer(array('status' => 'ONLINE'), 0)['state'], 'online');
check('unknown stops', start_status_answer(array(), 0)['state'], 'stop');
check('crashed stops', start_status_answer(array('status' => 'crashed', 'last_error' => 'boom'), 0)['message'], 'boom');
check('starting waits', start_status_answer(array('status' => 'STARTING'), 3)['state'], 'waiting');
check('offline waits early', start_status_answer(array('status' => 'OFFLINE'), 19)['state'], 'waiting');
check('offline gives up at 20', start_status_answer(array('status' => 'OFFLINE'), 20)['state'], 'stop');

echo $failures === 0 ? "All tests passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
