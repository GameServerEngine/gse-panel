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
		'assistant_enabled' => array('bool', '0', 'AI Assist switched on', 'Master switch. Off hides the buttons and stops every request.'),
		'assistant_name' => array('name', '', 'AI name', 'What your customers call it. Empty gives "Ask AI". Enter a name such as Dave and the button, the chat and the admin page say "Ask Dave".'),
		'assistant_customer_enabled' => array('bool', '1', 'Customers may use it', 'Off leaves the assistant to administrators only.'),
		'assistant_model' => array('select', 'claude-haiku-5-5', 'Model', 'Change the prices below too when you change the model.', 'options' => assistant_models()),
		'assistant_effort' => array('select', 'medium', 'Thinking effort', 'Higher is slower and costs more.', 'options' => array('low' => 'low', 'medium' => 'medium', 'high' => 'high')),
		'assistant_max_output_tokens' => array('int', '16000', 'Longest answer (tokens)', 'Thinking and the answer share this limit, so keep it high (16000). A low limit can use up the room before any answer is written, and that request is still billed. Only the tokens actually written are charged.', 'min' => 200, 'max' => 32000),
		'assistant_max_tool_steps' => array('int', '8', 'Most tool steps per question', 'How many times it may look something up before it must answer.', 'min' => 1, 'max' => 15),
		'assistant_max_question_tokens' => array('int', '60000', 'Most tokens for one question', 'Stops one question from looping through many big files. Input plus output over all its steps.', 'min' => 5000, 'max' => 1000000),
		'assistant_seconds_between' => array('int', '30', 'Seconds between questions, per user', 'A person needs about this long to read an answer and type the next question. Anything faster is a script.', 'min' => 1, 'max' => 600),
		'assistant_per_hour' => array('int', '60', 'Questions per hour, per user', 'Stops a script that asks slowly but all day.', 'min' => 1, 'max' => 1000),
		'assistant_monthly_budget_usd' => array('float', '5', 'Monthly budget, all use (USD)', 'Hard stop for everyone, administrators too, when the estimated cost reaches it. 0 stops all use.', 'min' => 0, 'max' => 100000),
		'assistant_price_in' => array('float', '0.10', 'Price per million input tokens (USD)', 'Used only to estimate cost. Copy it from the Anthropic price list.', 'min' => 0, 'max' => 1000),
		'assistant_price_out' => array('float', '0.50', 'Price per million output tokens (USD)', 'Used only to estimate cost.', 'min' => 0, 'max' => 1000),
		'assistant_server_monthly_tokens' => array('int', '2000000', 'Default cap per server per month (tokens)', 'Stops one server from using more than its share. Input plus output tokens.', 'min' => 1000, 'max' => 100000000),
		'assistant_server_caps' => array('text', '', 'Cap overrides for single servers', 'One per entry, like 12=400000, 15=50000 (server id = tokens per month). 0 blocks a server.'),
		'assistant_user_monthly_tokens' => array('int', '100000000', 'Cap per customer per month (tokens)', 'Across all of that customer\'s servers. The limit is per server (above), so this is left at its maximum, which means no per-customer limit. Administrators are not limited by this.', 'min' => 1000, 'max' => 100000000),
		'assistant_allow_files' => array('bool', '1', 'Read files and search logs', 'Lets it list and read files in the customer\'s game folder (secrets are hidden) and search them.'),
		'assistant_allow_edits' => array('bool', '1', 'Propose file edits and new scripts', 'It only proposes. The customer sees the change, presses Apply, and a backup is kept.'),
		'assistant_allow_db' => array('bool', '1', 'Read the server\'s database', 'Read-only queries on the databases assigned to the server.'),
		'assistant_allow_resets' => array('bool', '1', 'Offer new database / FTP passwords', 'Buttons only. The panel makes the password and shows it once.'),
		'assistant_admin_paths' => array('text', '', 'Extra folders the admin assistant may read', 'Absolute folders on the panel server, separated by commas, for example /var/log/apache2. Only list log folders. The panel folder is always readable; the panel config and key files never are.'),
		'assistant_agent_paths' => array('text', '', 'Folders it may read on agents', 'Absolute folders on the agent machines, separated by commas, for example /home/gameserver/GSP. Empty turns agent file access off.'),
		'assistant_key_file' => array('path', '/etc/gse-panel/assistant.env', 'API key file on the panel server', 'Used first if it holds a key. Otherwise the key saved on this page is used.'),
	);
}

// What people call the assistant: the name the admin chose, or "AI". Takes the raw settings or the cleaned config.
function assistant_display_name($settings)
{
	$name = isset($settings['assistant_name']) ? trim((string)$settings['assistant_name']) : '';
	return $name === '' ? 'AI' : $name;
}

function assistant_ask_label($settings)
{
	return 'Ask ' . assistant_display_name($settings);
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
	if ($type === 'name') {
		return trim(substr(preg_replace("/[^A-Za-z0-9 .'-]/", '', strip_tags($value)), 0, 24));
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

// The API key can come from the key file (preferred) or from the box on the admin page.
function assistant_resolve_key($cfg, $settings)
{
	$key = assistant_read_key($cfg['assistant_key_file']);
	if ($key === '' && !empty($settings['assistant_api_key'])) {
		$key = trim((string)$settings['assistant_api_key']);
	}
	return $key;
}

function assistant_key_valid($key)
{
	return (bool)preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,300}$/', $key);
}

function assistant_key_hint($key)
{
	return $key === '' ? '' : 'ends in ' . substr($key, -4);
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
