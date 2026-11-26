<?php
/*
 * Scheduled assistant runs. The Task Scheduler (Cron) has an action "Ask the assistant": the person
 * types a text command, it is saved here as a task, and the cron line only carries the task id.
 * When the time comes the agent calls api.php?assistant/run, which lands in assistant_scheduled_run().
 *
 * Nobody is watching a scheduled run, so it can only read and notify: the answer is saved in the
 * assistant log, and anything that needs a person goes out as an ntfy notification or a saved
 * proposal. Buttons are dropped. Administrators' tasks use the admin tools, customers' tasks use
 * the tools of their one server, exactly like the chat.
 */

require_once(__DIR__ . '/assistant_usage.php');

// Seconds a task must wait before it may run again, so a cron line set to every minute cannot burn the budget.
if (!defined('ASSISTANT_TASK_MIN_GAP')) define('ASSISTANT_TASK_MIN_GAP', 300);

// Saves a task. With $task_id of an existing task of this user, that task is changed. Returns its id (0 on failure).
function assistant_task_save($db, $user_id, $home_id, $command, $task_id = 0)
{
	assistant_ensure_tables($db);
	$command = trim(substr(preg_replace('/[\x00-\x08\x0B-\x1F]/', '', (string)$command), 0, 600));
	if ($command === '') {
		return 0;
	}
	$task_id = (int)$task_id;
	if ($task_id > 0) {
		$old = assistant_task_get($db, $task_id);
		if ($old !== null && (int)$old['user_id'] === (int)$user_id) {
			$db->query('UPDATE ' . assistant_table('tasks') . " SET command = '" . $db->real_escape_string($command) . "', home_id = " . (int)$home_id
				. ' WHERE id = ' . $task_id);
			return $task_id;
		}
	}
	$id = $db->resultInsertId('assistant_tasks', array('user_id' => (int)$user_id, 'home_id' => (int)$home_id, 'command' => $command, 'created' => date('Y-m-d H:i:s')));
	return $id ? (int)$id : 0;
}

function assistant_task_get($db, $task_id)
{
	$rows = $db->resultQuery('SELECT * FROM ' . assistant_table('tasks') . ' WHERE id = ' . (int)$task_id);
	return isset($rows[0]) ? $rows[0] : null;
}

// For the Task Scheduler list: task id => array(command, last_run, last_result). Missing ids are left out.
function assistant_tasks_by_id($db, $ids)
{
	$ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
	if (!$ids) {
		return array();
	}
	assistant_ensure_tables($db);
	$map = array();
	foreach ((array)$db->resultQuery('SELECT * FROM ' . assistant_table('tasks') . ' WHERE id IN (' . implode(',', $ids) . ')') as $row) {
		$map[(int)$row['id']] = $row;
	}
	return $map;
}

// The task id inside a cron line made by build_cron_scheduler_command ("...assistant/run&token=..&task=12"), or 0.
function assistant_task_id_from_cron_line($line)
{
	return preg_match('/assistant\/run[^"]*[&?]task=(\d+)/', (string)$line, $m) ? (int)$m[1] : 0;
}

// The question the model gets for a scheduled task.
function assistant_scheduled_question($command)
{
	return "This is a scheduled task. Nobody is watching, so you cannot ask questions and buttons are not shown. "
		. "Do the checks below with your tools, then finish with a short report (what you checked, what you found, counts and times). "
		. "If something needs a person's attention, send a notification with notify_server (or notify_admins when you have it). If everything is fine, do not notify unless the task says so.\n"
		. "Task: " . $command;
}

// Runs one task. Returns array(ok, message). $panel_root is the panel folder.
function assistant_scheduled_run($db, $panel_root, $user_id, $home_id, $task_id)
{
	require_once(__DIR__ . '/assistant_lib.php');
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_settings.php');
	assistant_ensure_tables($db);

	$task = assistant_task_get($db, $task_id);
	if ($task === null || (int)$task['user_id'] !== (int)$user_id || (int)$task['home_id'] !== (int)$home_id) {
		return array(false, 'That task was not found for this account.');
	}
	$settings = $db->getSettings();
	$cfg = assistant_config($settings);
	$is_admin = $db->isAdmin($user_id);
	if ($cfg['assistant_enabled'] !== '1' || (!$is_admin && $cfg['assistant_customer_enabled'] !== '1')) {
		return array(false, 'The assistant is switched off.');
	}
	$key = assistant_resolve_key($cfg, $settings);
	if ($key === '') {
		return array(false, 'The assistant is not set up on this panel yet.');
	}
	if (!empty($task['last_run']) && time() - strtotime($task['last_run']) < ASSISTANT_TASK_MIN_GAP) {
		return array(false, 'This task ran less than ' . (int)(ASSISTANT_TASK_MIN_GAP / 60) . ' minutes ago. Skipped.');
	}
	list($month_in, $month_out, $month_requests) = assistant_month_totals($db);
	if (assistant_cost_usd($month_in, $month_out, $cfg) >= (float)$cfg['assistant_monthly_budget_usd']) {
		return array(false, 'The assistant has used its budget for this month.');
	}
	if (!$is_admin && assistant_tokens_this_month($db, $user_id) >= (int)$cfg['assistant_user_monthly_tokens']) {
		return array(false, 'This account has used its assistant allowance for the month.');
	}
	if (!$is_admin && assistant_server_tokens_this_month($db, $home_id) >= assistant_server_cap($cfg, $home_id)) {
		return array(false, 'This server has used its assistant allowance for the month.');
	}
	// The owner must still own the server.
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array(false, $why);
	}

	// Marked as started now, so a slow run is not started a second time by the next cron minute.
	$db->query('UPDATE ' . assistant_table('tasks') . ' SET last_run = NOW() WHERE id = ' . (int)$task_id);

	$scope = $is_admin ? array('type' => 'admin', 'key' => 'admin') : array('type' => 'server', 'home_id' => (int)$home_id, 'key' => 's' . (int)$home_id);
	$question = assistant_scheduled_question((string)$task['command']);
	if ($is_admin) {
		$question .= "\nThe server this task was set up on is home_id " . (int)$home_id . ' (' . $home['home_name'] . ').';
	}
	$run_tool = function ($name, $input) use ($db, $user_id, $panel_root, $scope, $cfg) {
		return assistant_run_tool($db, $user_id, $panel_root, $name, $input, $scope, $cfg);
	};
	$opts = array(
		'cfg' => $cfg,
		'max_steps' => (int)$cfg['assistant_max_tool_steps'],
		'max_tokens' => (int)$cfg['assistant_max_output_tokens'],
		'max_question_tokens' => (int)$cfg['assistant_max_question_tokens'],
	);
	$result = assistant_ask($key, $cfg['assistant_model'], $cfg['assistant_effort'], array(), $question, $run_tool, $scope['type'], 'assistant_api_post', $opts);
	assistant_record($db, $user_id, '[scheduled task ' . (int)$task_id . '] ' . $task['command'], $result, $scope['key'], $scope['type'] === 'server' ? (int)$home_id : 0);
	$db->query('UPDATE ' . assistant_table('tasks') . " SET last_result = '" . $db->real_escape_string(substr($result['text'], 0, 600))
		. "' WHERE id = " . (int)$task_id);
	return array(!$result['error'], $result['text']);
}
?>
