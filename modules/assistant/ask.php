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

	$settings = $db->getSettings();
	if (!isset($settings['assistant_enabled']) || $settings['assistant_enabled'] !== '1') {
		return assistant_json(503, array('text' => 'The assistant is switched off.'));
	}
	$model = !empty($settings['assistant_model']) ? $settings['assistant_model'] : ASSISTANT_DEFAULT_MODEL;
	$effort = isset($settings['assistant_effort']) ? $settings['assistant_effort'] : 'medium';
	$cap = isset($settings['assistant_monthly_tokens']) ? (int)$settings['assistant_monthly_tokens'] : 200000;
	$key_file = !empty($settings['assistant_key_file']) ? $settings['assistant_key_file'] : '/etc/gse-panel/assistant.env';
	$key = assistant_read_key($key_file);
	if ($key === '') {
		return assistant_json(503, array('text' => 'The assistant is not set up on this panel yet.'));
	}

	$question = trim(isset($_POST['question']) ? (string)$_POST['question'] : '');
	if ($question === '') {
		return assistant_json(400, array('text' => 'Type a question first.'));
	}
	$question = mb_substr($question, 0, ASSISTANT_MAX_QUESTION_CHARS);

	assistant_ensure_tables($db);
	if (assistant_requests_last_minute($db, $user_id) >= 6) {
		return assistant_json(429, array('text' => 'Slow down a little. Try again in a minute.'));
	}
	$is_admin = $db->isAdmin($user_id);
	if (!$is_admin && assistant_tokens_this_month($db, $user_id) >= $cap) {
		return assistant_json(429, array('text' => 'You have used this month\'s assistant allowance.'));
	}

	// Each chat (each server, and the admin chat) keeps its own short history.
	$all_history = isset($_SESSION['assistant_history']) && is_array($_SESSION['assistant_history']) ? $_SESSION['assistant_history'] : array();
	$history = isset($all_history[$scope['key']]) ? $all_history[$scope['key']] : array();
	$run_tool = function ($name, $input) use ($db, $user_id, $panel_root, $scope) {
		return assistant_run_tool($db, $user_id, $panel_root, $name, $input, $scope);
	};
	$result = assistant_ask($key, $model, $effort, $history, $question, $run_tool, $scope['type']);

	assistant_record($db, $user_id, $question, $result, $scope['key']);
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
