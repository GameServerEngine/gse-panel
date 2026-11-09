<?php
/*
 * API page: shows the logged-in user's API token, lets them regenerate it,
 * and shows ready-to-use API commands for one of their servers.
 * The command names come from the endpoint list at the top of api.php.
 */

// Commands that take a remote_server_id. Each entry: endpoint => extra parameters.
function api_page_server_commands()
{
	return array(
		'server/status' => array(),
		'server/restart' => array(),
		'server/list_ips' => array(),
		'server/add_ip' => array('ip'),
		'server/remove_ip' => array('ip'),
		'server/edit_ip' => array('old_ip', 'new_ip'),
		'user_games/create' => array('server_name', 'home_cfg_id', 'mod_cfg_id', 'ip', 'port', 'control_password', 'enable_ftp', 'ftp_password', 'slots', 'affinity', 'nice', 'assign_to_username'),
	);
}

// Commands that need only the token.
function api_page_general_commands()
{
	return array('server/list', 'user_games/list_servers');
}

// Admins can use every remote server. Other users can use the servers their game homes run on.
function api_page_servers_for_user($user_id, $is_admin)
{
	global $db;

	if ($is_admin)
		$rows = $db->getRemoteServers();
	else
		$rows = $db->getHomesFor('user_and_group', $user_id);

	$servers = array();
	foreach ((array)$rows as $row)
		$servers[$row['remote_server_id']] = $row['remote_server_name'];
	asort($servers);
	return $servers;
}

function api_page_base_url()
{
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
	$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
	return $scheme . '://' . $host . $dir . '/api.php';
}

function api_page_command_line($endpoint, $params, $remote_server_id)
{
	$line = 'curl -X POST "' . api_page_base_url() . '?' . $endpoint . '" -d "token=YOUR_TOKEN"';
	if ($remote_server_id !== null)
		$line .= ' -d "remote_server_id=' . (int)$remote_server_id . '"';
	foreach ($params as $param)
		$line .= ' -d "' . $param . '=..."';
	return $line;
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

function api_page_show_commands($servers)
{
	echo "<h3>" . get_lang('api_page_commands') . "</h3>";
	if (empty($servers))
	{
		echo "<p>" . get_lang('api_page_no_servers') . "</p>";
		return;
	}

	echo "<p>" . get_lang('api_page_commands_info') . "</p>";
	echo "<select id='api_server_select'><option value=''>" . get_lang('api_page_select_server') . "</option>";
	foreach ($servers as $id => $name)
		echo "<option value='" . (int)$id . "'>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</option>";
	echo "</select>";

	// One hidden block per server, so no extra request is needed when the selection changes.
	foreach ($servers as $id => $name)
	{
		echo "<div class='api_server_commands' id='api_server_" . (int)$id . "' style='display:none'><pre>";
		foreach (api_page_server_commands() as $endpoint => $params)
			echo htmlspecialchars(api_page_command_line($endpoint, $params, $id), ENT_QUOTES, 'UTF-8') . "\n";
		foreach (api_page_general_commands() as $endpoint)
			echo htmlspecialchars(api_page_command_line($endpoint, array(), null), ENT_QUOTES, 'UTF-8') . "\n";
		echo "</pre></div>";
	}

	echo "<script>
	$('#api_server_select').change(function() {
		$('.api_server_commands').hide();
		if ($(this).val() !== '')
			$('#api_server_' + $(this).val()).show();
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
	api_page_show_token($db->getApiToken($user_id));
	api_page_show_commands(api_page_servers_for_user($user_id, $db->isAdmin($user_id)));
}
?>
