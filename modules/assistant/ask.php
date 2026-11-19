<?php
/*
 * Endpoint for the Assistant chat box. Receives one question (POST) and the chat's scope token,
 * asks the model, and answers with JSON. Needs a logged-in panel session and the page's CSRF token.
 */

require_once(__DIR__ . '/assistant_boot.php');

function assistant_main()
{
	global $db;
	$ctx = assistant_boot();
	if ($ctx === null) {
		return;
	}
	list($panel_root, $user_id) = $ctx;

	require_once(__DIR__ . '/assistant_lib.php');
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_usage.php');

	$scope = assistant_scope_resolve($db, $user_id, isset($_POST['scope']) ? (string)$_POST['scope'] : '');
	if ($scope === null) {
		return assistant_json(403, array('text' => 'Open the assistant from your server page in Game Monitor.'));
	}

	require_once(__DIR__ . '/assistant_settings.php');
	$settings = $db->getSettings();
	$cfg = assistant_config($settings);
	if ($cfg['assistant_enabled'] !== '1') {
		return assistant_json(503, array('text' => 'The assistant is switched off.'));
	}
	$is_admin = $db->isAdmin($user_id);
	if (!$is_admin && $cfg['assistant_customer_enabled'] !== '1') {
		return assistant_json(503, array('text' => 'The assistant is only open to administrators right now.'));
	}
	$key = assistant_resolve_key($cfg, $settings);
	if ($key === '') {
		return assistant_json(503, array('text' => 'The assistant is not set up on this panel yet.'));
	}

	$question = trim(isset($_POST['question']) ? (string)$_POST['question'] : '');
	if ($question === '') {
		return assistant_json(400, array('text' => 'Type a question first.'));
	}
	$question = mb_substr($question, 0, ASSISTANT_MAX_QUESTION_CHARS);

	// Limits, cheapest check first. The budget stops everyone; the other caps are for customers.
	assistant_ensure_tables($db);
	if (assistant_requests_last_minute($db, $user_id) >= (int)$cfg['assistant_per_minute']) {
		return assistant_json(429, array('text' => 'Slow down a little. Try again in a minute.'));
	}
	list($month_in, $month_out, $month_requests) = assistant_month_totals($db);
	if (assistant_cost_usd($month_in, $month_out, $cfg) >= (float)$cfg['assistant_monthly_budget_usd']) {
		return assistant_json(429, array('text' => 'The assistant has used its budget for this month. It will be back next month, or an administrator can raise the budget.'));
	}
	if (!$is_admin) {
		if (assistant_tokens_this_month($db, $user_id) >= (int)$cfg['assistant_user_monthly_tokens']) {
			return assistant_json(429, array('text' => 'You have used this month\'s assistant allowance.'));
		}
		if ($scope['type'] === 'server' && assistant_server_tokens_this_month($db, $scope['home_id']) >= assistant_server_cap($cfg, $scope['home_id'])) {
			return assistant_json(429, array('text' => 'This server has used its assistant allowance for the month.'));
		}
	}
	$model = $cfg['assistant_model'];
	$effort = $cfg['assistant_effort'];

	// Each chat (each server, and the admin chat) keeps its own short history.
	$all_history = isset($_SESSION['assistant_history']) && is_array($_SESSION['assistant_history']) ? $_SESSION['assistant_history'] : array();
	$history = isset($all_history[$scope['key']]) ? $all_history[$scope['key']] : array();
	$run_tool = function ($name, $input) use ($db, $user_id, $panel_root, $scope, $cfg) {
		return assistant_run_tool($db, $user_id, $panel_root, $name, $input, $scope, $cfg);
	};
	$opts = array(
		'cfg' => $cfg,
		'max_steps' => (int)$cfg['assistant_max_tool_steps'],
		'max_tokens' => (int)$cfg['assistant_max_output_tokens'],
		'max_question_tokens' => (int)$cfg['assistant_max_question_tokens'],
	);
	$result = assistant_ask($key, $model, $effort, $history, $question, $run_tool, $scope['type'], 'assistant_api_post', $opts);

	assistant_record($db, $user_id, $question, $result, $scope['key'], $scope['type'] === 'server' ? $scope['home_id'] : 0);
	if (!$result['error']) {
		// Keep only the plain words of each turn, not the tool blocks.
		$history[] = array('role' => 'user', 'content' => $question);
		$history[] = array('role' => 'assistant', 'content' => $result['text']);
		$all_history[$scope['key']] = array_slice($history, -ASSISTANT_MAX_HISTORY);
		$_SESSION['assistant_history'] = $all_history;
	}
	assistant_json(200, array('text' => $result['text'], 'buttons' => $result['buttons']));
}

assistant_main();
?>
