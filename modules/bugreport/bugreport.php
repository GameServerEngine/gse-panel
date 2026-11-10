<?php
// Admin bug report page: sends the form to the bug report relay.

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
	list($ok, $text) = bugreport_send_to_relay($clean, bugreport_panel_version(), $_SESSION['users_login'], $poster);
	if ($ok) {
		$db->logger('Bug report created: ' . $text);
		echo "<div class='success'>Report sent: <a href='" . bugreport_h($text) . "' target='_blank' rel='noopener'>" . bugreport_h($text) . "</a></div>";
		return array();
	}
	$db->logger('Bug report failed: ' . $text);
	echo "<div class='error'>" . bugreport_h($text) . "</div>";
	return $clean;
}

function bugreport_show_form($values, $modules)
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
	echo "<p><strong>Do not paste customer data, passwords, keys or tokens into a bug report.</strong></p>";
	echo "<p><button type='submit' name='action' value='send'>Send report</button></p></form>";

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
		} elseif (($_POST['action'] ?? '') === 'send') {
			$values = bugreport_send($db, $modules);
		}
	}
	bugreport_show_form($values, $modules);
}
