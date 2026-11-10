<?php
// Bug report helpers. No database or session access here, so tests can run them alone.

define('BUGREPORT_REPO', 'GameServerEngine/gse-panel');
define('BUGREPORT_TOKEN_SETTING', 'bugreport_github_token');

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

function bugreport_issue_title($clean)
{
	return '[' . $clean['type'] . '] ' . $clean['title'];
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

// Creates the issue. $poster is a callable(url, headers, json) so tests can replace it.
// Returns array(ok, issue url or error text). Never includes the token in the result.
function bugreport_create_issue($clean, $version, $username, $token, $poster)
{
	if ($token === '' || $token === false || $token === null) {
		return array(false, 'No GitHub token is set. Set one below, then send the report again.');
	}
	$payload = json_encode(array(
		'title' => bugreport_issue_title($clean),
		'body'  => bugreport_build_body($clean, $version, $username),
	));
	$headers = array(
		'Authorization: Bearer ' . $token,
		'Accept: application/vnd.github+json',
		'Content-Type: application/json',
		'User-Agent: gse-panel-bugreport',
	);
	list($status, $response) = call_user_func($poster, 'https://api.github.com/repos/' . BUGREPORT_REPO . '/issues', $headers, $payload);

	$data = json_decode($response, true);
	if ($status === 201 && is_array($data) && !empty($data['html_url'])) {
		return array(true, $data['html_url']);
	}
	if ($status === 0) {
		return array(false, 'Could not reach GitHub.');
	}
	$reason = is_array($data) && isset($data['message']) ? ': ' . $data['message'] : '';
	return array(false, 'GitHub refused the report (HTTP ' . $status . ')' . $reason);
}
