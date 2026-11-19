<?php
/*
 * Every setting the assistant has, in one place. The Admin Assistant page shows and saves
 * them. Values live in the panel's settings table under the keys below.
 */

function assistant_models()
{
	return array(
		'claude-haiku-5-5' => 'Claude Haiku 5.5 (fast, cheapest)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (stronger, costs more)',
		'claude-opus-5-5' => 'Claude Opus 5.5 (strongest, costs most)',
	);
}

// key => array(type, default, label, help, and min / max / options where they apply)
function assistant_setting_defs()
{
	return array(
		'assistant_enabled' => array('bool', '0', 'Assistant switched on', 'Master switch. Off hides the buttons and stops every request.'),
		'assistant_customer_enabled' => array('bool', '1', 'Customers may use it', 'Off leaves the assistant to administrators only.'),
		'assistant_model' => array('select', 'claude-haiku-5-5', 'Model', 'Change the prices below too when you change the model.', 'options' => assistant_models()),
		'assistant_effort' => array('select', 'medium', 'Thinking effort', 'Higher is slower and costs more.', 'options' => array('low' => 'low', 'medium' => 'medium', 'high' => 'high')),
		'assistant_max_output_tokens' => array('int', '1200', 'Longest answer (tokens)', 'Stops one answer from running long.', 'min' => 200, 'max' => 4000),
		'assistant_max_tool_steps' => array('int', '8', 'Most tool steps per question', 'How many times it may look something up before it must answer.', 'min' => 1, 'max' => 15),
		'assistant_max_question_tokens' => array('int', '60000', 'Most tokens for one question', 'Stops one question from looping through many big files. Input plus output over all its steps.', 'min' => 5000, 'max' => 1000000),
		'assistant_per_minute' => array('int', '6', 'Questions per minute, per user', 'Slows down a script that hammers the chat.', 'min' => 1, 'max' => 60),
		'assistant_monthly_budget_usd' => array('float', '5', 'Monthly budget, all use (USD)', 'Hard stop for everyone, administrators too, when the estimated cost reaches it. 0 stops all use.', 'min' => 0, 'max' => 100000),
		'assistant_price_in' => array('float', '0.10', 'Price per million input tokens (USD)', 'Used only to estimate cost. Copy it from the Anthropic price list.', 'min' => 0, 'max' => 1000),
		'assistant_price_out' => array('float', '0.50', 'Price per million output tokens (USD)', 'Used only to estimate cost.', 'min' => 0, 'max' => 1000),
		'assistant_server_monthly_tokens' => array('int', '150000', 'Default cap per server per month (tokens)', 'Stops one server from using more than its share. Input plus output tokens.', 'min' => 1000, 'max' => 100000000),
		'assistant_server_caps' => array('text', '', 'Cap overrides for single servers', 'One per entry, like 12=400000, 15=50000 (server id = tokens per month). 0 blocks a server.'),
		'assistant_user_monthly_tokens' => array('int', '300000', 'Cap per customer per month (tokens)', 'Across all of that customer\'s servers. Administrators are not limited by this.', 'min' => 1000, 'max' => 100000000),
		'assistant_allow_files' => array('bool', '1', 'Read files and search logs', 'Lets it list and read files in the customer\'s game folder (secrets are hidden) and search them.'),
		'assistant_allow_edits' => array('bool', '1', 'Propose file edits and new scripts', 'It only proposes. The customer sees the change, presses Apply, and a backup is kept.'),
		'assistant_allow_db' => array('bool', '1', 'Read the server\'s database', 'Read-only queries on the databases assigned to the server.'),
		'assistant_allow_resets' => array('bool', '1', 'Offer new database / FTP passwords', 'Buttons only. The panel makes the password and shows it once.'),
		'assistant_admin_paths' => array('text', '', 'Extra folders the admin assistant may read', 'Absolute folders on the panel server, separated by commas, for example /var/log/apache2. Only list log folders. The panel folder is always readable; the panel config and key files never are.'),
		'assistant_agent_paths' => array('text', '', 'Folders it may read on agents', 'Absolute folders on the agent machines, separated by commas, for example /home/gameserver/GSP. Empty turns agent file access off.'),
		'assistant_key_file' => array('path', '/etc/gse-panel/assistant.env', 'API key file on the panel server', 'The key is never shown or stored in the database.'),
	);
}

// Reads the settings table values into a clean array. Missing or bad values fall back to the default.
function assistant_config($settings)
{
	$cfg = array();
	foreach (assistant_setting_defs() as $key => $def) {
		$raw = isset($settings[$key]) ? (string)$settings[$key] : null;
		if ($raw === null && $key === 'assistant_user_monthly_tokens' && isset($settings['assistant_monthly_tokens'])) {
			$raw = (string)$settings['assistant_monthly_tokens']; // the name used before 2.16.0
		}
		$cfg[$key] = assistant_clean_value($def, $raw === null ? $def[1] : $raw);
	}
	$cfg['server_caps'] = assistant_parse_server_caps($cfg['assistant_server_caps']);
	$cfg['admin_paths'] = assistant_parse_paths($cfg['assistant_admin_paths']);
	$cfg['agent_paths'] = assistant_parse_paths($cfg['assistant_agent_paths']);
	return $cfg;
}

function assistant_clean_value($def, $value)
{
	$type = $def[0];
	$value = trim((string)$value);
	if ($type === 'bool') {
		return $value === '1' ? '1' : '0';
	}
	if ($type === 'int' || $type === 'float') {
		$number = is_numeric($value) ? $value + 0 : $def[1] + 0;
		$number = max($def['min'], min($def['max'], $number));
		return (string)($type === 'int' ? (int)$number : round($number, 4));
	}
	if ($type === 'select') {
		return isset($def['options'][$value]) ? $value : $def[1];
	}
	if ($type === 'path') {
		return preg_match('#^/[A-Za-z0-9_./-]+$#', $value) && strpos($value, '..') === false ? $value : $def[1];
	}
	// text: the lists of numbers or paths are cleaned where they are used
	return substr(preg_replace('/[^\x20-\x7E]/', '', $value), 0, 1000);
}

// "12=400000, 15=50000" -> array(12 => 400000, 15 => 50000)
function assistant_parse_server_caps($text)
{
	$caps = array();
	foreach (preg_split('/[\s,;]+/', (string)$text, -1, PREG_SPLIT_NO_EMPTY) as $part) {
		if (preg_match('/^(\d+)=(\d+)$/', $part, $m)) {
			$caps[(int)$m[1]] = (int)$m[2];
		}
	}
	return $caps;
}

// "/var/log/apache2, /opt/x" -> list of clean absolute folders
function assistant_parse_paths($text)
{
	$paths = array();
	foreach (preg_split('/[,\n]+/', (string)$text, -1, PREG_SPLIT_NO_EMPTY) as $part) {
		$part = rtrim(trim($part), '/');
		if (preg_match('#^/[A-Za-z0-9_./-]+$#', $part) && strpos($part, '..') === false) {
			$paths[] = $part;
		}
	}
	return array_values(array_unique($paths));
}

// Saves what the admin form sent. Returns the cleaned values that were stored.
function assistant_save_settings($db, $post)
{
	$store = array();
	foreach (assistant_setting_defs() as $key => $def) {
		if ($def[0] === 'bool') {
			$store[$key] = isset($post[$key]) && $post[$key] === '1' ? '1' : '0';
		} elseif (isset($post[$key])) {
			$store[$key] = assistant_clean_value($def, $post[$key]);
		}
	}
	$db->setSettings($store);
	return $store;
}

function assistant_server_cap($cfg, $home_id)
{
	return isset($cfg['server_caps'][(int)$home_id]) ? $cfg['server_caps'][(int)$home_id] : (int)$cfg['assistant_server_monthly_tokens'];
}

function assistant_cost_usd($input_tokens, $output_tokens, $cfg)
{
	return ($input_tokens * (float)$cfg['assistant_price_in'] + $output_tokens * (float)$cfg['assistant_price_out']) / 1000000;
}
?>
