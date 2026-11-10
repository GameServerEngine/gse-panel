<?php
// Admin bug report page: sets the GitHub token and creates an issue from the form.

require_once(dirname(__FILE__) . '/bugreport_lib.php');

function bugreport_h($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function bugreport_csrf_token()
{
	if (empty($_SESSION['bugreport_csrf'])) {
		$_SESSION['bugreport_csrf'] = bin2hex(random_bytes(16));
	}
	return $_SESSION['bugreport_csrf'];
}

function bugreport_csrf_ok()
{
	return isset($_POST['csrf']) && is_string($_POST['csrf'])
		&& hash_equals(bugreport_csrf_token(), $_POST['csrf']);
}

function bugreport_panel_version()
{
	require_once(dirname(__FILE__) . '/../../includes/panel_version.php');
	return PANEL_VERSION;
}

function bugreport_save_token($db)
{
	$token = isset($_POST['github_token']) && is_string($_POST['github_token']) ? trim($_POST['github_token']) : '';
	if ($token === '') {
		echo "<div class='error'>Token not saved: the field was empty.</div>";
		return;
	}
	$db->setSettings(array(BUGREPORT_TOKEN_SETTING => $token));
	$db->logger('Bug report: GitHub token set.');
	echo "<div class='success'>GitHub token saved.</div>";
}

function bugreport_clear_token($db)
{
	$db->setSettings(array(BUGREPORT_TOKEN_SETTING => ''));
	$db->logger('Bug report: GitHub token cleared.');
	echo "<div class='success'>GitHub token cleared.</div>";
}

function bugreport_send($db, $modules)
{
	return bugreport_send_with($db, $modules, 'bugreport_http_post');
}

// Same as bugreport_send, with the HTTP poster passed in so tests can replace it.
function bugreport_send_with($db, $modules, $poster)
{
	list($clean, $errors) = bugreport_validate($_POST, $modules);
	if ($errors) {
		echo "<div class='error'>" . implode('<br>', array_map('bugreport_h', $errors)) . "</div>";
		return $clean;
	}
	$token = $db->getSetting(BUGREPORT_TOKEN_SETTING);
	list($ok, $text) = bugreport_create_issue($clean, bugreport_panel_version(), $_SESSION['users_login'], $token, $poster);
	if ($ok) {
		$db->logger('Bug report created: ' . $text);
		echo "<div class='success'>Issue created: <a href='" . bugreport_h($text) . "' target='_blank' rel='noopener'>" . bugreport_h($text) . "</a></div>";
		return array();
	}
	$db->logger('Bug report failed: ' . $text);
	echo "<div class='error'>" . bugreport_h($text) . "</div>";
	return $clean;
}

function bugreport_show_form($values, $modules, $token_is_set)
{
	$csrf = bugreport_h(bugreport_csrf_token());
	$get = function ($key) use ($values) {
		return isset($values[$key]) ? $values[$key] : '';
	};

	echo "<h2>Bug report</h2>";
	echo "<form method='post'><input type='hidden' name='csrf' value='$csrf'>";

	echo "<p><label>Module<br><select name='module'>";
	foreach ($modules as $name) {
		$selected = $get('module') === $name ? ' selected' : '';
		echo "<option value='" . bugreport_h($name) . "'$selected>" . bugreport_h($name) . "</option>";
	}
	echo "</select></label></p>";

	echo "<p><label>Type<br><select name='type'>";
	foreach (bugreport_types() as $type) {
		$selected = $get('type') === $type ? ' selected' : '';
		echo "<option value='" . bugreport_h($type) . "'$selected>" . bugreport_h($type) . "</option>";
	}
	echo "</select></label></p>";

	$fields = bugreport_fields();
	echo "<p><label>" . bugreport_h($fields['title'][0]) . "<br><input type='text' name='title' size='80' maxlength='" . $fields['title'][1] . "' value='" . bugreport_h($get('title')) . "'></label></p>";
	foreach (array('bug', 'steps', 'expected') as $key) {
		echo "<p><label>" . bugreport_h($fields[$key][0]) . "<br><textarea name='$key' rows='6' cols='80' maxlength='" . $fields[$key][1] . "'>" . bugreport_h($get($key)) . "</textarea></label></p>";
	}
	echo "<p><button type='submit' name='action' value='send'>Send report</button></p></form>";

	// The saved token is never printed, only whether one is set.
	$state = $token_is_set ? 'set' : 'not set';
	echo "<h3>GitHub token</h3><p>Status: <strong>$state</strong></p>";
	if (!$token_is_set) {
		echo "<div class='error'>No GitHub token is set. Reports cannot be sent until one is saved.</div>";
	}
	echo "<form method='post' autocomplete='off'><input type='hidden' name='csrf' value='$csrf'>";
	echo "<p><label>GitHub token<br><input type='password' name='github_token' size='60' value='' autocomplete='new-password'></label></p>";
	echo "<button type='submit' name='action' value='save_token'>Save token</button> ";
	echo "<button type='submit' name='action' value='clear_token'>Clear token</button></form>";
}

function exec_module()
{
	global $db;
	if ($_SESSION['users_role'] !== 'admin') {
		print_failure(get_lang('no_access'));
		return;
	}
	$modules = bugreport_module_list(dirname(__FILE__) . '/..');
	$values = array();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!bugreport_csrf_ok()) {
			echo "<div class='error'>Form expired. Please try again.</div>";
		} elseif (($_POST['action'] ?? '') === 'save_token') {
			bugreport_save_token($db);
		} elseif (($_POST['action'] ?? '') === 'clear_token') {
			bugreport_clear_token($db);
		} elseif (($_POST['action'] ?? '') === 'send') {
			$values = bugreport_send($db, $modules);
		}
	}
	bugreport_show_form($values, $modules, (string)$db->getSetting(BUGREPORT_TOKEN_SETTING) !== '');
}
