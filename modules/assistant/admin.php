<?php
/*
 * The Admin assistant page. One place for everything about the assistant:
 *  - use it (it reads the panel, agents, logs and any game server, and prepares fixes),
 *  - every setting: on/off, model, spend budget, per-customer and per-server caps, which tools it has,
 *  - what it has used this month, the patches it saved for you, and its recent activity.
 * Administrators only. The settings form and the buttons here use the page's CSRF token.
 */

function assistant_h($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// A database time shown in US Central.
function assistant_central($stamp)
{
	try {
		$when = new DateTime((string)$stamp);
		$when->setTimezone(new DateTimeZone('America/Chicago'));
		return $when->format('Y-m-d g:i a') . ' CT';
	} catch (Exception $e) {
		return (string)$stamp;
	}
}

function assistant_admin_settings_form($cfg, $csrf)
{
	echo '<h3>Settings</h3>';
	echo '<form method="post" action="?m=assistant&amp;p=admin">';
	echo '<input type="hidden" name="assistant_csrf" value="' . assistant_h($csrf) . '">';
	echo '<table class="center" style="max-width:900px">';
	foreach (assistant_setting_defs() as $key => $def) {
		list($type, $default, $label, $help) = $def;
		$value = $cfg[$key];
		echo '<tr><td style="vertical-align:top;padding:4px 10px 4px 0"><label for="' . assistant_h($key) . '"><b>' . assistant_h($label) . '</b></label></td><td style="padding:4px 0">';
		if ($type === 'bool') {
			echo '<input type="checkbox" id="' . assistant_h($key) . '" name="' . assistant_h($key) . '" value="1"' . ($value === '1' ? ' checked' : '') . '>';
		} elseif ($type === 'select') {
			echo '<select id="' . assistant_h($key) . '" name="' . assistant_h($key) . '">';
			foreach ($def['options'] as $option => $text) {
				echo '<option value="' . assistant_h($option) . '"' . ($option === $value ? ' selected' : '') . '>' . assistant_h($text) . '</option>';
			}
			echo '</select>';
		} elseif ($type === 'text') {
			echo '<input type="text" id="' . assistant_h($key) . '" name="' . assistant_h($key) . '" value="' . assistant_h($value) . '" size="50" maxlength="1000">';
		} else {
			echo '<input type="text" id="' . assistant_h($key) . '" name="' . assistant_h($key) . '" value="' . assistant_h($value) . '" size="' . ($type === 'path' ? 40 : 12) . '">';
		}
		echo '<div style="font-size:90%;opacity:.8">' . assistant_h($help) . '</div></td></tr>';
	}
	echo '</table><p><button type="submit" name="assistant_action" value="save_settings">Save settings</button></p></form>';
}

function assistant_admin_usage($db, $cfg)
{
	list($in, $out, $requests) = assistant_month_totals($db);
	$cost = assistant_cost_usd($in, $out, $cfg);
	$budget = (float)$cfg['assistant_monthly_budget_usd'];
	$percent = $budget > 0 ? min(100, round($cost / $budget * 100)) : 100;
	echo '<h3>This month (' . assistant_h(date('Y-m')) . ')</h3>';
	echo '<p>' . (int)$requests . ' questions, ' . number_format($in) . ' tokens in, ' . number_format($out) . ' tokens out. '
		. 'Estimated cost <b>$' . number_format($cost, 3) . '</b> of the $' . number_format($budget, 2) . ' budget (' . (int)$percent . '%). '
		. 'The estimate uses the prices in the settings; Anthropic\'s own usage page is the real bill. Set a spend limit there too.</p>';

	$users = $db->resultQuery('SELECT u.users_login AS name, a.input_tokens AS i, a.output_tokens AS o, a.requests AS r FROM ' . assistant_table('usage')
		. ' a LEFT JOIN ' . assistant_tn('users') . " u ON u.user_id = a.user_id WHERE a.month = '" . date('Y-m') . "' ORDER BY a.input_tokens + a.output_tokens DESC LIMIT 20");
	echo '<table class="center"><tr><th>Customer</th><th>Questions</th><th>Tokens</th><th>Cap</th></tr>';
	foreach ((array)$users as $row) {
		echo '<tr><td>' . assistant_h($row['name']) . '</td><td>' . (int)$row['r'] . '</td><td>' . number_format($row['i'] + $row['o']) . '</td><td>' . number_format($cfg['assistant_user_monthly_tokens']) . '</td></tr>';
	}
	if (!$users) {
		echo '<tr><td colspan="4">Nothing used yet.</td></tr>';
	}
	echo '</table>';

	$servers = $db->resultQuery('SELECT a.home_id AS id, h.home_name AS name, a.input_tokens + a.output_tokens AS t, a.requests AS r FROM ' . assistant_table('server_usage')
		. ' a LEFT JOIN ' . assistant_tn('server_homes') . " h ON h.home_id = a.home_id WHERE a.month = '" . date('Y-m') . "' ORDER BY t DESC LIMIT 20");
	echo '<table class="center" style="margin-top:8px"><tr><th>Server</th><th>Id</th><th>Questions</th><th>Tokens</th><th>Cap</th></tr>';
	foreach ((array)$servers as $row) {
		$cap = assistant_server_cap($cfg, $row['id']);
		echo '<tr><td>' . assistant_h($row['name']) . '</td><td>' . (int)$row['id'] . '</td><td>' . (int)$row['r'] . '</td><td>' . number_format($row['t']) . '</td><td>' . number_format($cap) . '</td></tr>';
	}
	if (!$servers) {
		echo '<tr><td colspan="5">Nothing used yet.</td></tr>';
	}
	echo '</table>';
}

function assistant_admin_patches($db, $csrf)
{
	echo '<h3>Patches the assistant saved</h3>';
	echo '<p>These are never applied by the assistant. Download one, review it, and apply it in the panel repository with <code>git apply</code> on the <code>working</code> branch. Then mark it applied here.</p>';
	$rows = $db->resultQuery('SELECT id, target, note, status, created FROM ' . assistant_table('proposals') . " WHERE kind = 'patch' ORDER BY id DESC LIMIT 30");
	if (!$rows) {
		echo '<p>None yet.</p>';
		return;
	}
	echo '<p><a href="modules/assistant/patch.php?all=1">Download all waiting patches</a></p>';
	echo '<table class="center"><tr><th>#</th><th>File</th><th>What it does</th><th>When</th><th>Status</th><th></th></tr>';
	foreach ($rows as $row) {
		echo '<tr><td>' . (int)$row['id'] . '</td><td>' . assistant_h($row['target']) . '</td><td>' . assistant_h($row['note']) . '</td><td>' . assistant_h(assistant_central($row['created'])) . '</td><td>' . assistant_h($row['status']) . '</td><td>';
		echo '<a href="modules/assistant/patch.php?id=' . (int)$row['id'] . '">Download</a>';
		if ($row['status'] === 'pending') {
			foreach (array('applied' => 'Mark applied', 'discarded' => 'Discard') as $status => $text) {
				echo ' <form method="post" action="?m=assistant&amp;p=admin" style="display:inline"><input type="hidden" name="assistant_csrf" value="' . assistant_h($csrf) . '">'
					. '<input type="hidden" name="patch_id" value="' . (int)$row['id'] . '"><input type="hidden" name="patch_status" value="' . $status . '">'
					. '<button type="submit" name="assistant_action" value="patch_status">' . $text . '</button></form>';
			}
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

function assistant_admin_activity($db)
{
	echo '<h3>Recent activity</h3>';
	$rows = $db->resultQuery('SELECT l.created AS created, l.question AS question, l.tools AS tools, l.input_tokens + l.output_tokens AS t, u.users_login AS name FROM ' . assistant_table('log')
		. ' l LEFT JOIN ' . assistant_tn('users') . ' u ON u.user_id = l.user_id ORDER BY l.id DESC LIMIT 25');
	echo '<table class="center"><tr><th>When</th><th>Who</th><th>Question or button</th><th>Tools</th><th>Tokens</th></tr>';
	foreach ((array)$rows as $row) {
		// Button presses are stored with a 1970 time; they show as "button".
		$when = strpos((string)$row['created'], '1970-') === 0 ? 'button' : assistant_central($row['created']);
		echo '<tr><td>' . assistant_h($when) . '</td><td>' . assistant_h($row['name']) . '</td><td>' . assistant_h(mb_substr((string)$row['question'], 0, 120)) . '</td><td>' . assistant_h($row['tools']) . '</td><td>' . (int)$row['t'] . '</td></tr>';
	}
	if (!$rows) {
		echo '<tr><td colspan="5">Nothing yet.</td></tr>';
	}
	echo '</table>';
}

function exec_module()
{
	global $db;
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_boot.php');
	require_once(__DIR__ . '/assistant_ui.php');
	require_once(__DIR__ . '/assistant_usage.php');
	require_once(__DIR__ . '/assistant_settings.php');
	require_once(__DIR__ . '/assistant_manage.php');

	if (!$db->isAdmin((int)$_SESSION['user_id'])) {
		echo '<p>Administrators only.</p>';
		return 0;
	}
	if (empty($_SESSION['assistant_csrf'])) {
		$_SESSION['assistant_csrf'] = bin2hex(random_bytes(16));
	}
	$csrf = $_SESSION['assistant_csrf'];
	assistant_ensure_tables($db);

	// Forms on this page.
	if (isset($_POST['assistant_action'])) {
		$sent = isset($_POST['assistant_csrf']) ? (string)$_POST['assistant_csrf'] : '';
		if (!hash_equals($csrf, $sent)) {
			echo '<p class="failure">The page expired. Reload it and try again.</p>';
		} elseif ($_POST['assistant_action'] === 'save_settings') {
			assistant_save_settings($db, $_POST);
			assistant_audit($db, (int)$_SESSION['user_id'], 'Settings saved', 'settings', 'saved by an administrator');
			echo '<p class="success">Settings saved.</p>';
		} elseif ($_POST['assistant_action'] === 'patch_status' && isset($_POST['patch_id'], $_POST['patch_status']) && in_array($_POST['patch_status'], array('applied', 'discarded'), true)) {
			$db->query('UPDATE ' . assistant_table('proposals') . " SET status = '" . $db->real_escape_string($_POST['patch_status']) . "', decided = NOW() WHERE kind = 'patch' AND status = 'pending' AND id = " . (int)$_POST['patch_id']);
			echo '<p class="success">Patch #' . (int)$_POST['patch_id'] . ' marked ' . assistant_h($_POST['patch_status']) . '.</p>';
		}
	}

	$cfg = assistant_config($db->getSettings());
	echo '<h2>Admin assistant</h2>';
	if ($cfg['assistant_enabled'] === '1') {
		$key_ok = assistant_read_key($cfg['assistant_key_file']) !== '';
		if (!$key_ok) {
			echo '<p class="failure">The API key file ' . assistant_h($cfg['assistant_key_file']) . ' is missing or has no key. The assistant cannot answer until it does.</p>';
		}
		assistant_render_chat(
			'admin',
			'Ask the assistant',
			'It can read the panel, the agents, their logs and files, and any game server. It looks for errors, logins and attacks and tells you what it found. '
				. 'It can prepare fixes: changes to a game server\'s files that you review and apply, buttons for start / stop / restart and new passwords, and patches for the panel or an agent that you apply through git. It never changes live panel files.',
			'For example: check the logs of server 3 for failed logins in the last day'
		);
	} else {
		echo '<p>The assistant is switched off. Tick "Assistant switched on" below and save.</p>';
	}
	assistant_admin_usage($db, $cfg);
	assistant_admin_patches($db, $csrf);
	assistant_admin_settings_form($cfg, $csrf);
	assistant_admin_activity($db);
	return 1;
}
?>
