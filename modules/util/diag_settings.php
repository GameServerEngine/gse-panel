<?php
/*
 * Admin page: diagnostic private-address override, limits, optional AI log analysis and the audit trail.
 * home.php?m=util&p=diag_settings  (admin only, see navigation.xml)
 */
require_once 'modules/util/diag_env.php';

function util_h($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function util_settings_save($db)
{
	if (!diag_csrf_valid(diag_csrf_from_request())) {
		print_failure('The page expired. Reload it and try again.');
		return;
	}
	$values = array('diag_admin_allow_private' => empty($_POST['diag_admin_allow_private']) ? '0' : '1');
	foreach (array('diag_rate_window_s', 'diag_rate_per_user', 'diag_rate_per_agent', 'diag_running_per_user', 'diag_running_per_agent') as $name) {
		$values[$name] = (string)diag_limit_settings(array($name => isset($_POST[$name]) ? $_POST[$name] : ''))[$name];
	}
	$values = array_merge($values, util_settings_ai_values($db->getSettings()));
	$db->setSettings($values);
	$db->logger('Admin saved diagnostics settings');
	print_success('Settings saved.');
}

// Optional AI log analysis settings. An empty key box keeps the stored key; the key is never printed back.
function util_settings_ai_values($old)
{
	$provider = isset($_POST['log_ai_provider']) ? $_POST['log_ai_provider'] : '';
	$key = isset($_POST['log_ai_key']) ? trim($_POST['log_ai_key']) : '';
	return array(
		'log_ai_provider' => in_array($provider, array('openai', 'anthropic'), true) ? $provider : '',
		'log_ai_endpoint' => isset($_POST['log_ai_endpoint']) ? trim($_POST['log_ai_endpoint']) : '',
		'log_ai_model' => isset($_POST['log_ai_model']) ? trim($_POST['log_ai_model']) : '',
		'log_ai_key' => $key !== '' ? $key : (isset($old['log_ai_key']) ? $old['log_ai_key'] : ''),
	);
}

function util_settings_ai_form($settings)
{
	$provider = isset($settings['log_ai_provider']) ? $settings['log_ai_provider'] : '';
	echo '<h3>Log Analyzer: optional AI analysis</h3><p>Off unless all four fields are filled in. When on, a File Manager user can press a button to send a redacted copy of a log to this service. The endpoint must be https.</p>';
	echo '<label for="log_ai_provider">Provider format</label><select id="log_ai_provider" name="log_ai_provider">';
	foreach (array('' => 'Off', 'openai' => 'OpenAI-compatible (chat completions)', 'anthropic' => 'Anthropic (messages)') as $value => $label) {
		echo '<option value="' . $value . '"' . ($provider === $value ? ' selected' : '') . '>' . util_h($label) . '</option>';
	}
	echo '</select>';
	echo '<label for="log_ai_endpoint">Endpoint URL</label><input type="text" id="log_ai_endpoint" name="log_ai_endpoint" value="' . util_h(isset($settings['log_ai_endpoint']) ? $settings['log_ai_endpoint'] : '') . '">';
	echo '<label for="log_ai_model">Model</label><input type="text" id="log_ai_model" name="log_ai_model" value="' . util_h(isset($settings['log_ai_model']) ? $settings['log_ai_model'] : '') . '">';
	echo '<label for="log_ai_key">API key ' . (empty($settings['log_ai_key']) ? '(not set)' : '(set; leave empty to keep it)') . '</label><input type="password" id="log_ai_key" name="log_ai_key" value="" autocomplete="new-password">';
}

function util_settings_audit($db)
{
	$rows = $db->resultQuery('SELECT * FROM `DB_PREFIXdiag_audit` ORDER BY `id` DESC LIMIT 50');
	echo '<h3>Recent diagnostic runs</h3><table class="util-table"><thead><tr><th>Time</th><th>User</th><th>Agent</th><th>Tool</th><th>Target</th><th>Port</th><th>Status</th><th>Outcome</th><th>ms</th><th>From</th></tr></thead><tbody>';
	foreach ((array)$rows as $row) {
		echo '<tr><td>' . util_h(date('Y-m-d H:i:s', (int)$row['created_at'])) . '</td><td>' . (int)$row['user_id'] . '</td><td>' . (int)$row['remote_server_id']
			. '</td><td>' . util_h($row['tool']) . '</td><td>' . util_h($row['target']) . '</td><td>' . (int)$row['port'] . '</td><td>' . util_h($row['status'])
			. '</td><td>' . util_h($row['outcome']) . '</td><td>' . (int)$row['duration_ms'] . '</td><td>' . util_h($row['client_ip']) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function util_settings_form($settings)
{
	$limits = diag_limit_settings($settings);
	echo '<form method="post" action="?m=util&amp;p=diag_settings" class="util-form">';
	echo '<input type="hidden" name="csrf" value="' . util_h(diag_csrf_token()) . '">';
	$checked = (!empty($settings['diag_admin_allow_private']) && $settings['diag_admin_allow_private'] !== '0') ? ' checked' : '';
	echo '<p><label><input type="checkbox" name="diag_admin_allow_private" value="1"' . $checked . '> Administrator override: admins may ping private network addresses (10.x, 172.16-31.x, 192.168.x). Loopback, link-local and reserved ranges stay blocked.</label></p>';
	$fields = array('diag_rate_window_s' => 'Rate window (seconds)', 'diag_rate_per_user' => 'Runs per user in the window', 'diag_rate_per_agent' => 'Runs per agent in the window',
		'diag_running_per_user' => 'Running tests per user', 'diag_running_per_agent' => 'Running tests per agent');
	foreach ($fields as $name => $label) {
		echo '<label for="' . $name . '">' . $label . '</label><input type="number" min="1" id="' . $name . '" name="' . $name . '" value="' . (int)$limits[$name] . '">';
	}
	util_settings_ai_form($settings);
	echo '<p><button type="submit">Save</button></p></form>';
}

function exec_module()
{
	global $db;
	echo '<h2>Diagnostics policy</h2><p><a href="?m=util">&laquo; Utilities</a></p>';
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		util_settings_save($db);
	}
	util_settings_form($db->getSettings());
	util_settings_audit($db);
}
