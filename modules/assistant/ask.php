<?php
/*
 * Endpoint for the Assistant chat box. Receives one question (POST), asks the model,
 * and answers with JSON. Needs a logged-in panel session and the page's CSRF token.
 * Mirrors the way modules/gamemanager/get_server_log.php starts up.
 */

function assistant_json($status, $data)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($data);
}

function assistant_main()
{
	$panel_root = realpath(__DIR__ . '/../..');
	chdir($panel_root);
	require_once($panel_root . '/includes/functions.php');
	require_once($panel_root . '/includes/helpers.php');
	startSession();

	foreach (array('IMAGES' => 'images/', 'INCLUDES' => 'includes/', 'MODULES' => 'modules/') as $name => $value) {
		if (!defined($name)) {
			define($name, $value);
		}
	}
	if (!defined('CONFIG_FILE')) {
		define('CONFIG_FILE', $panel_root . '/includes/config.inc.php');
	}
	global $db, $db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, $db_port;
	require_once(CONFIG_FILE);

	if (empty($_SESSION['users_login']) || empty($_SESSION['user_id'])) {
		return assistant_json(401, array('text' => 'Please sign in again.'));
	}
	$user_id = (int)$_SESSION['user_id'];
	$sent_token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
	$real_token = isset($_SESSION['assistant_csrf']) ? (string)$_SESSION['assistant_csrf'] : '';
	if ($real_token === '' || !hash_equals($real_token, $sent_token)) {
		return assistant_json(403, array('text' => 'The page expired. Reload it and try again.'));
	}

	if (!isset($db) || !is_object($db)) {
		$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : null);
	}
	require_once(__DIR__ . '/assistant_lib.php');
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_usage.php');

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

	$history = isset($_SESSION['assistant_history']) && is_array($_SESSION['assistant_history']) ? $_SESSION['assistant_history'] : array();
	$run_tool = function ($name, $input) use ($db, $user_id, $panel_root) {
		return assistant_run_tool($db, $user_id, $panel_root, $name, $input);
	};
	$result = assistant_ask($key, $model, $effort, $history, $question, $run_tool);

	assistant_record($db, $user_id, $question, $result);
	if (!$result['error']) {
		// Keep only the plain words of each turn, not the tool blocks.
		$history[] = array('role' => 'user', 'content' => $question);
		$history[] = array('role' => 'assistant', 'content' => $result['text']);
		$_SESSION['assistant_history'] = array_slice($history, -ASSISTANT_MAX_HISTORY);
	}
	assistant_json(200, array('text' => $result['text'], 'buttons' => $result['buttons']));
}

assistant_main();
?>
