<?php
/*
 * Shared start-up for the two endpoints (ask.php and confirm.php), and the "scope" of a chat.
 *
 * A scope says what a chat may talk about. A customer's chat is tied to ONE server: chat.php
 * stores the server in the PHP session under a random token after checking the customer owns it,
 * and the browser only ever sends the token back. The model never chooses the server.
 * The admin chat uses the fixed token 'admin' and is accepted only for administrators.
 * The product chat (questions about GameServer Engine, no tools) uses the fixed token 'product'.
 */

function assistant_json($status, $data)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	// File and log text can hold bytes that are not UTF-8; swap them instead of losing the whole reply.
	echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

// Starts the panel the same way modules/gamemanager/get_server_log.php does, checks the login
// and (unless $need_csrf is false, for read-only downloads) the page's CSRF token. Returns array(panel_root, user_id) or null after answering the error.
function assistant_boot($need_csrf = true)
{
	global $db, $db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, $db_port;

	$panel_root = realpath(__DIR__ . '/../..');
	chdir($panel_root);
	require_once($panel_root . '/includes/functions.php');
	require_once($panel_root . '/includes/helpers.php');
	startSession();

	foreach (array('IMAGES' => 'images/', 'INCLUDES' => 'includes/', 'MODULES' => 'modules/') as $name => $value) {
		if (!defined($name)) {
			define($name, $value);
		}
	}
	if (!defined('CONFIG_FILE')) {
		define('CONFIG_FILE', $panel_root . '/includes/config.inc.php');
	}
	// The config file sets plain variables, so they have to be global before it is loaded in here.
	require_once(CONFIG_FILE);

	if (empty($_SESSION['users_login']) || empty($_SESSION['user_id'])) {
		assistant_json(401, array('text' => 'Please sign in again.'));
		return null;
	}
	$sent_token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
	$real_token = isset($_SESSION['assistant_csrf']) ? (string)$_SESSION['assistant_csrf'] : '';
	if ($need_csrf && ($real_token === '' || !hash_equals($real_token, $sent_token))) {
		assistant_json(403, array('text' => 'The page expired. Reload it and try again.'));
		return null;
	}
	if (!isset($db) || !is_object($db)) {
		$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : null);
	}
	return array($panel_root, (int)$_SESSION['user_id']);
}

// chat.php calls this for a server page. Returns the token, or '' when this user may not use that server.
function assistant_scope_open_server($db, $user_id, $home_id)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return '';
	}
	if (empty($_SESSION['assistant_scopes']) || !is_array($_SESSION['assistant_scopes'])) {
		$_SESSION['assistant_scopes'] = array();
	}
	$token = array_search((int)$home['home_id'], $_SESSION['assistant_scopes'], true);
	if ($token === false) {
		$token = bin2hex(random_bytes(8));
		$_SESSION['assistant_scopes'][$token] = (int)$home['home_id'];
	}
	return $token;
}

// Turns the token the browser sent into a scope, checking the rights again. Null when refused.
function assistant_scope_resolve($db, $user_id, $token)
{
	if ($token === 'admin') {
		return $db->isAdmin($user_id) ? array('type' => 'admin', 'key' => 'admin') : null;
	}
	// Questions about GameServer Engine itself. No tools, so it sees no server and no file.
	if ($token === 'product') {
		return array('type' => 'product', 'key' => 'product');
	}
	if (empty($_SESSION['assistant_scopes'][$token])) {
		return null;
	}
	$home_id = (int)$_SESSION['assistant_scopes'][$token];
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	return $home === null ? null : array('type' => 'server', 'home_id' => $home_id, 'key' => 's' . $home_id);
}
?>
