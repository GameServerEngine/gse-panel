<?php
/*
 * API page: shows the logged-in user's API token, lets them regenerate it,
 * and shows ready-to-copy API commands filled in from the database.
 * The command names come from the endpoint list at the top of api.php.
 */

// Machine-level commands (admin only). Each entry: endpoint => input fields the user fills in.
// Argument names match get_function_args() in includes/api_functions.php.
function api_page_machine_commands()
{
	return array(
		'server/status' => array(),
		'server/restart' => array(),
		'server/list_ips' => array(),
		'server/add_ip' => array('ip'),
		'server/remove_ip' => array('ip'),
		'server/edit_ip' => array('old_ip', 'new_ip'),
	);
}

// Game-server commands. Each one needs token, ip, port and mod_key.
function api_page_game_commands()
{
	return array('gamemanager/start', 'gamemanager/stop', 'gamemanager/restart');
}

// Returns array(machine_id => array('name' => ..., 'games' => array(...))).
// Admins get every machine. Other users get only the machines that host one of their game servers.
// Ownership uses getHomesFor('user_and_group'), the same call the Game Monitor uses.
function api_page_machines_for_user($user_id, $is_admin)
{
	global $db;

	$machines = array();
	if ($is_admin)
	{
		foreach ((array)$db->getRemoteServers() as $row)
			$machines[$row['remote_server_id']] = array('name' => $row['remote_server_name'], 'games' => array());
		$homes = $db->getHomesFor('admin', $user_id);
	}
	else
		$homes = $db->getHomesFor('user_and_group', $user_id);

	foreach ((array)$homes as $home)
	{
		$machine_id = $home['remote_server_id'];
		if (!isset($machines[$machine_id]))
			$machines[$machine_id] = array('name' => $home['remote_server_name'], 'games' => array());
		// A game server with no IP and port yet cannot be controlled through the API.
		if (empty($home['ip']) || empty($home['port']))
			continue;
		$machines[$machine_id]['games'][] = array(
			'name' => $home['home_name'],
			'ip' => $home['ip'],
			'port' => $home['port'],
			'mod_key' => isset($home['mod_key']) ? $home['mod_key'] : ''
		);
	}
	uasort($machines, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
	return $machines;
}

function api_page_base_url()
{
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
	$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
	return $scheme . '://' . $host . $dir . '/api.php';
}

// Builds one curl line. $values is an ordered list of name => value pairs.
function api_page_command_line($endpoint, $values)
{
	$line = 'curl -X POST "' . api_page_base_url() . '?' . $endpoint . '"';
	foreach ($values as $name => $value)
		$line .= ' -d "' . $name . '=' . $value . '"';
	return $line;
}

// Prints one command in a box with its own Copy button.
function api_page_show_command($label, $line)
{
	echo "<div class='api_command' style='margin-bottom:8px'>";
	echo "<strong>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</strong>";
	echo "<pre class='api_command_text' style='margin:2px 0'>" . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "</pre>";
	echo "<button type='button' class='btn btn-default btn-xs api_command_copy'>" . get_lang('api_page_copy') . "</button>";
	echo "</div>";
}

// Handles the Regenerate button. Returns a message to show, or '' when nothing was submitted.
function api_page_handle_regenerate($user_id)
{
	global $db;

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['regenerate_token']))
		return '';

	$sent = isset($_POST['api_csrf']) ? (string)$_POST['api_csrf'] : '';
	if (!hash_equals($_SESSION['api_csrf'], $sent))
		return "<div class='failure'>" . get_lang('api_page_bad_form_token') . "</div>";

	if ($db->regenerateApiToken($user_id) === false)
		return "<div class='failure'>" . get_lang('api_page_regenerate_failed') . "</div>";

	return "<div class='success'>" . get_lang('api_page_token_regenerated') . "</div>";
}

function api_page_show_token($token)
{
	$token_html = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
	$confirm_js = htmlspecialchars(json_encode(get_lang('api_page_confirm_regenerate')), ENT_QUOTES, 'UTF-8');

	echo "<h3>" . get_lang('api_token') . "</h3>";
	echo "<input type='text' id='api_token_value' value='" . $token_html . "' size='30' readonly='readonly'> ";
	echo "<button type='button' id='api_token_copy' class='btn btn-default'>" . get_lang('api_page_copy') . "</button> ";
	echo "<form method='post' action='?m=user_admin&amp;p=api_page' style='display:inline' onsubmit='return confirm(" . $confirm_js . ");'>";
	echo "<input type='hidden' name='api_csrf' value='" . htmlspecialchars($_SESSION['api_csrf'], ENT_QUOTES, 'UTF-8') . "'>";
	echo "<button type='submit' name='regenerate_token' value='1' class='btn btn-warning'>" . get_lang('api_page_regenerate') . "</button>";
	echo "</form>";
	echo "<script>
	$('#api_token_copy').click(function() {
		var box = document.getElementById('api_token_value');
		box.select();
		document.execCommand('copy');
	});
	</script>";
}

// Machine-level commands, admin only. Fields the user must fill in are shown as YOUR_<NAME> labels.
function api_page_show_machine_commands($token, $machine_id)
{
	echo "<h4>" . get_lang('api_page_machine_commands') . "</h4>";
	echo "<p>" . get_lang('api_page_machine_commands_info') . "</p>";
	foreach (api_page_machine_commands() as $endpoint => $inputs)
	{
		$values = array('token' => $token, 'remote_server_id' => (int)$machine_id);
		foreach ($inputs as $input)
			$values[$input] = 'ENTER_' . strtoupper($input);
		api_page_show_command($endpoint, api_page_command_line($endpoint, $values));
	}
}

function api_page_show_game_commands($token, $game)
{
	echo "<div class='box box-default'><div class='box-header with-border'>";
	echo "<h3 class='box-title'>" . htmlspecialchars($game['name'], ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars($game['ip'] . ':' . $game['port'], ENT_QUOTES, 'UTF-8') . ")</h3>";
	echo "</div><div class='box-body'>";
	foreach (api_page_game_commands() as $endpoint)
	{
		$values = array('token' => $token, 'ip' => $game['ip'], 'port' => (int)$game['port'], 'mod_key' => $game['mod_key']);
		api_page_show_command($endpoint, api_page_command_line($endpoint, $values));
	}
	echo "</div></div>";
}

function api_page_show_commands($token, $machines, $is_admin)
{
	echo "<h3>" . get_lang('api_page_commands') . "</h3>";
	if (empty($machines))
	{
		echo "<p>" . get_lang('api_page_no_servers') . "</p>";
		return;
	}

	echo "<p>" . get_lang('api_page_commands_info') . "</p>";
	echo "<select id='api_machine_select' class='form-control' style='width:auto'><option value=''>" . get_lang('api_page_select_machine') . "</option>";
	foreach ($machines as $id => $machine)
		echo "<option value='" . (int)$id . "'>" . htmlspecialchars($machine['name'], ENT_QUOTES, 'UTF-8') . "</option>";
	echo "</select>";

	// One hidden block per machine, so no extra request is needed when the selection changes.
	foreach ($machines as $id => $machine)
	{
		echo "<div class='api_machine_commands' id='api_machine_" . (int)$id . "' style='display:none'>";
		if ($is_admin)
			api_page_show_machine_commands($token, $id);
		echo "<h4>" . get_lang('api_page_game_commands') . "</h4>";
		if (empty($machine['games']))
			echo "<p>" . get_lang('api_page_no_games') . "</p>";
		foreach ($machine['games'] as $game)
			api_page_show_game_commands($token, $game);
		echo "</div>";
	}

	echo "<script>
	$('#api_machine_select').change(function() {
		$('.api_machine_commands').hide();
		if ($(this).val() !== '')
			$('#api_machine_' + $(this).val()).show();
	});
	$('.api_command_copy').click(function() {
		var text = $(this).siblings('.api_command_text')[0];
		var range = document.createRange();
		range.selectNodeContents(text);
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange(range);
		document.execCommand('copy');
	});
	</script>";
}

function exec_module()
{
	global $db;

	$user_id = $_SESSION['user_id'];
	if (empty($_SESSION['api_csrf']))
		$_SESSION['api_csrf'] = bin2hex(random_bytes(16));

	echo "<h2>" . get_lang('api_page_title') . "</h2>";
	echo api_page_handle_regenerate($user_id);

	// Read the token after any regenerate, so the page shows the new one.
	$token = $db->getApiToken($user_id);
	api_page_show_token($token);
	$is_admin = $db->isAdmin($user_id);
	api_page_show_commands($token, api_page_machines_for_user($user_id, $is_admin), $is_admin);
}
?>
