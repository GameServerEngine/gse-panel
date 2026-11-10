<?php
// Bug report helpers. No database or session access here, so tests can run them alone.

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
