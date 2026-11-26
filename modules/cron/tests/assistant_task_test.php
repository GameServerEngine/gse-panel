<?php
// Tests for the Task Scheduler action "Ask the assistant": the cron line carries only a task id,
// and the id can be read back from the line. Run with: php modules/cron/tests/assistant_task_test.php

require_once(dirname(__DIR__) . '/shared_cron_functions.php');
require_once(dirname(__DIR__, 2) . '/assistant/assistant_scheduled.php');

function assistant_task_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$home = array('ip' => '10.0.0.5', 'port' => 27015, 'home_id' => 7, 'mod_key' => 'cstrike');
$line = build_cron_scheduler_command('https://panel.example', 'TOKEN', $home, 'ask_assistant', 12);
assistant_task_test_assert(strpos($line, 'wget -qO- "https://panel.example/api.php?assistant/run&token=TOKEN&home_id=7&task=12"') === 0, 'the cron line calls assistant/run with the task id');
assistant_task_test_assert(substr($line, -16) === '> /dev/null 2>&1', 'the cron line keeps the usual ending');
assistant_task_test_assert(build_cron_scheduler_command('https://panel.example', 'TOKEN', $home, 'ask_assistant') === false, 'no task id gives no cron line');
assistant_task_test_assert(build_cron_scheduler_command('https://panel.example', 'TOKEN', $home, 'stop') !== false, 'other actions still work');

// The same line as the agent stores it, with the time fields in front.
assistant_task_test_assert(assistant_task_id_from_cron_line('30 4 * * * ' . $line) === 12, 'the task id is read back from the stored line');
assistant_task_test_assert(assistant_task_id_from_cron_line('30 4 * * * wget -qO- "https://panel.example/api.php?gamemanager/stop&token=T"') === 0, 'other lines have no task');
assistant_task_test_assert(assistant_task_id_from_cron_line('') === 0, 'an empty line has no task');

$question = assistant_scheduled_question('check the crash log');
assistant_task_test_assert(strpos($question, 'Task: check the crash log') !== false && strpos($question, 'Nobody is watching') !== false, 'the scheduled question holds the text command');

// A task that does not exist is refused before anything is asked of the model.
class AssistantTaskStubDb
{
	function query($sql) { return true; }
	function resultQuery($sql) { return array(); }
	function getSettings() { return array(); }
	function isAdmin($id) { return false; }
	function real_escape_string($s) { return addslashes($s); }
}
$GLOBALS['table_prefix'] = 'ogp_';
list($ok, $message) = assistant_scheduled_run(new AssistantTaskStubDb(), dirname(__DIR__, 2), 1, 7, 99);
assistant_task_test_assert($ok === false && strpos($message, 'not found') !== false, 'an unknown task is refused');

echo "assistant_task_test passed\n";
