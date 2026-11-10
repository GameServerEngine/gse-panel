<?php
// Tests for group start/stop/restart: selection parsing and result summary.
// Run: php modules/gamemanager/tests/group_operations_test.php   (exit 0 = all passed)
// The agent is never called. The runner below is a stand-in that answers from a table.
function get_lang($key) { return $key; }
require_once(dirname(__DIR__) . '/group_operations.php');

function check($name, $ok)
{
	if (!$ok) {
		fwrite(STDERR, "FAIL: $name\n");
		exit(1);
	}
	echo "PASS: $name\n";
}

// Stub runner: home 2 fails with a reason, home 3 throws, the rest succeed.
$calls = array();
function stub_runner($action, $target)
{
	global $calls;
	$calls[] = $action . ':' . $target['home_id'];
	if ($target['home_id'] == '2')
		return array(false, 'no saved start settings');
	if ($target['home_id'] == '3')
		throw new Exception('agent timeout');
	return array(true, '');
}

// Selection parsing
$sel = group_parse_selection(array('1-1-10.0.0.1-27015', 'junk', '2-1-10.0.0.1-27016', '1-1-10.0.0.1-27015', '3-x-1.1.1.1-5', 42));
check('parse keeps valid values only', count($sel) == 2);
check('parse removes duplicates', $sel[0]['home_id'] == '1' && $sel[1]['home_id'] == '2');
check('parse reads all four fields', $sel[0]['mod_id'] == '1' && $sel[0]['ip'] == '10.0.0.1' && $sel[0]['port'] == '27015');
check('parse of non-array is empty', group_parse_selection(NULL) === array());
check('action start allowed', group_parse_action('start') === 'start');
check('action delete refused', group_parse_action('delete') === '');
check('action array refused', group_parse_action(array('stop')) === '');
$row = group_parse_row_value('restart-950-1-10.0.0.1-27015');
check('row value parsed', $row[0] === 'restart' && $row[1]['home_id'] == '950');
check('row value with bad action refused', group_parse_row_value('format-1-1-10.0.0.1-5') === NULL);

// Running: one failure must not stop the others
$targets = group_parse_selection(array('1-1-10.0.0.1-1', '2-1-10.0.0.1-2', '3-1-10.0.0.1-3', '4-1-10.0.0.1-4'));
$results = group_run('start', $targets, 'stub_runner');
check('every server was tried', $calls == array('start:1', 'start:2', 'start:3', 'start:4'));
check('failure keeps its reason', !$results[1]['ok'] && $results[1]['reason'] == 'no saved start settings');
check('exception counts as failure with message', !$results[2]['ok'] && $results[2]['reason'] == 'agent timeout');
check('later server still succeeded', $results[3]['ok']);

// Summary
$sum = group_summary($results);
check('summary counts', $sum['total'] == 4 && $sum['succeeded'] == 2 && $sum['failed'] == 2);
check('summary of nothing', group_summary(array()) == array('total' => 0, 'succeeded' => 0, 'failed' => 0));

// Rendering
$html = group_render_results('start', $results, array('2' => 'BrainBread 2'));
check('html names failed server and reason', strpos($html, 'BrainBread 2 (10.0.0.1:2): no saved start settings') !== false);
check('html lists unnamed server by home id', strpos($html, 'Home 1 (10.0.0.1:1)') !== false);
check('html escapes reason', strpos(group_render_results('stop', array(array('target' => $targets[0], 'ok' => false, 'reason' => '<b>x')), array()), '<b>x') === false);

// POST handling
$calls = array();
check('non-admin cannot run a group', group_handle_post(array('group_action' => 'stop', 'group_select' => array('1-1-10.0.0.1-1')), false, array()) === '' && $calls === array());
check('no group post shows nothing', group_handle_post(array(), true, array()) === '');
check('empty selection shows message', strpos(group_handle_post(array('group_action' => 'stop', 'group_select' => array()), true, array()), 'group_select_none') !== false);
check('bad action shows message', strpos(group_handle_post(array('group_action' => 'wipe', 'group_select' => array('1-1-10.0.0.1-1')), true, array()), 'group_select_none') !== false);
echo "All tests passed\n";
