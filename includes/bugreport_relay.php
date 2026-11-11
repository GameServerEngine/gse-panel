<?php
// Bug report relay helpers and form for the admin page. The panel holds no token.
// The functions down to bugreport_send_to_relay use no database or session, so tests can run them alone.

define('BUGREPORT_RELAY_URL', 'https://gameserverengine.com/bugreport/');

// Field name => array(label, max length). Used by the form and by validation.
function bugreport_fields()
{
	return array(
		'title'    => array('Title', 150),
		'bug'      => array('What the bug is', 5000),
		'steps'    => array('Steps to reproduce', 5000),
		'expected' => array('Expected fix or feature request', 5000),
	);
}

function bugreport_types()
{
	return array('Bug', 'Feature request');
}

// Module folders under $modules_dir. Skips tests, vendor, backup copies and folders without module.php.
function bugreport_module_list($modules_dir)
{
	$names = array();
	foreach (scandir($modules_dir) as $name) {
		if ($name[0] === '.' || $name === 'tests' || $name === 'vendor' || strpos($name, '.bak') !== false) {
			continue;
		}
		if (is_file($modules_dir . '/' . $name . '/module.php')) {
			$names[] = $name;
		}
	}
	sort($names);
	return $names;
}

// Returns array(clean values, error messages). Errors are empty when the input is good.
function bugreport_validate($input, $modules)
{
	$clean = array();
	$errors = array();

	foreach (bugreport_fields() as $key => $field) {
		$value = isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
		$clean[$key] = $value;
		if ($value === '') {
			$errors[] = $field[0] . ' is required.';
		} elseif (mb_strlen($value) > $field[1]) {
			$errors[] = $field[0] . ' must be at most ' . $field[1] . ' characters.';
		}
	}

	$clean['module'] = isset($input['module']) && is_string($input['module']) ? $input['module'] : '';
	if (!in_array($clean['module'], $modules, true)) {
		$errors[] = 'Module must be one from the list.';
	}

	$clean['type'] = isset($input['type']) && is_string($input['type']) ? $input['type'] : '';
	if (!in_array($clean['type'], bugreport_types(), true)) {
		$errors[] = 'Type must be Bug or Feature request.';
	}

	return array($clean, $errors);
}

function bugreport_build_body($clean, $version, $username)
{
	return "**Type:** " . $clean['type'] . "\n"
		. "**Module:** " . $clean['module'] . "\n\n"
		. "### What the bug is\n" . $clean['bug'] . "\n\n"
		. "### Steps to reproduce\n" . $clean['steps'] . "\n\n"
		. "### Expected fix or feature request\n" . $clean['expected'] . "\n\n"
		. "---\nReported from panel version " . $version . " by admin " . $username . ".";
}

// Real HTTP poster. Returns array(http status, response body). Status 0 means the request failed.
function bugreport_http_post($url, $headers, $json)
{
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	$response = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($status, is_string($response) ? $response : '');
}

// The relay wants 'bug' or 'feature'. The form uses 'Bug' and 'Feature request'.
function bugreport_relay_type($type)
{
	return $type === 'Bug' ? 'bug' : 'feature';
}

// JSON body for the relay. No token goes in it. "website" is the honeypot and stays empty.
function bugreport_relay_payload($clean, $version, $username)
{
	return json_encode(array(
		'type'        => bugreport_relay_type($clean['type']),
		'title'       => $clean['title'],
		'description' => bugreport_build_body($clean, $version, $username),
		'module'      => $clean['module'],
		'version'     => $version,
		'reporter'    => $username,
		'website'     => '',
	));
}

// Turns the relay answer into array(ok, issue url or error text).
function bugreport_read_relay_response($status, $response)
{
	$data = json_decode($response, true);
	if ($status === 200 && is_array($data) && !empty($data['ok']) && !empty($data['issue_url'])) {
		return array(true, $data['issue_url']);
	}
	if (is_array($data) && !empty($data['error']) && is_string($data['error'])) {
		return array(false, $data['error']);
	}
	if ($status === 0) {
		return array(false, 'Could not reach the bug report service. Please try again later.');
	}
	return array(false, 'The bug report service did not accept the report (HTTP ' . $status . '). Please try again later.');
}

// Sends the report to the relay. $poster is a callable(url, headers, json) so tests can replace it.
// Returns array(ok, issue url or error text).
function bugreport_send_to_relay($clean, $version, $username, $poster)
{
	$headers = array('Content-Type: application/json', 'Accept: application/json');
	$payload = bugreport_relay_payload($clean, $version, $username);
	list($status, $response) = call_user_func($poster, BUGREPORT_RELAY_URL, $headers, $payload);
	return bugreport_read_relay_response($status, $response);
}

// ---- Form, CSRF and submit handling (use the session) ----

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
	require_once(dirname(__FILE__) . '/panel_version.php');
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

function bugreport_option_list($name, $label, $choices, $selected_value)
{
	echo "<p><label>" . bugreport_h($label) . "<br><select name='" . bugreport_h($name) . "'>";
	foreach ($choices as $choice) {
		$selected = $selected_value === $choice ? ' selected' : '';
		echo "<option value='" . bugreport_h($choice) . "'$selected>" . bugreport_h($choice) . "</option>";
	}
	echo "</select></label></p>";
}

function bugreport_title_input($value)
{
	$field = bugreport_fields()['title'];
	echo "<p><label>" . bugreport_h($field[0]) . "<br><input type='text' name='title' size='80' maxlength='" . $field[1] . "' value='" . bugreport_h($value) . "'></label></p>";
}

function bugreport_textarea($key, $value)
{
	$field = bugreport_fields()[$key];
	echo "<p><label>" . bugreport_h($field[0]) . "<br><textarea name='$key' rows='6' cols='80' maxlength='" . $field[1] . "'>" . bugreport_h($value) . "</textarea></label></p>";
}

function bugreport_show_form($values, $modules)
{
	$get = function ($key) use ($values) {
		return isset($values[$key]) ? $values[$key] : '';
	};

	echo "<h2>Bug report</h2>";
	echo "<form method='post'><input type='hidden' name='csrf' value='" . bugreport_h(bugreport_csrf_token()) . "'>";
	bugreport_option_list('module', 'Module', $modules, $get('module'));
	bugreport_option_list('type', 'Type', bugreport_types(), $get('type'));
	bugreport_title_input($get('title'));
	foreach (array('bug', 'steps', 'expected') as $key) {
		bugreport_textarea($key, $get($key));
	}
	echo "<p><strong>Do not paste customer data, passwords, keys or tokens into a bug report.</strong></p>";
	echo "<p><button type='submit' name='action' value='send'>Send report</button></p></form>";
}

// Handles a submitted form (CSRF check, then send) and returns the values to refill the form with.
function bugreport_handle_post($db, $modules)
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		return array();
	}
	if (!bugreport_csrf_ok()) {
		echo "<div class='error'>Form expired. Please try again.</div>";
		return array();
	}
	if (($_POST['action'] ?? '') === 'send') {
		return bugreport_send($db, $modules);
	}
	return array();
}

// Shows the bug report section for admin users only.
function bugreport_render_section($db)
{
	if (($_SESSION['users_role'] ?? '') !== 'admin') {
		return;
	}
	$modules = bugreport_module_list(dirname(__FILE__) . '/../modules');
	$values = bugreport_handle_post($db, $modules);
	bugreport_show_form($values, $modules);
}
