<?php
/*
 * The assistant for ONE game server. It is opened from that server in Game Monitor
 * (index: home.php?m=assistant&p=chat&home_id=N). The page checks the logged-in user owns
 * the server, then gives the browser a token for it. Without that, nothing here works.
 */

function exec_module()
{
	global $db, $settings;
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_boot.php');
	require_once(__DIR__ . '/assistant_ui.php');
	require_once(__DIR__ . '/assistant_suggestions.php');

	if (!isset($settings['assistant_enabled']) || $settings['assistant_enabled'] !== '1') {
		echo '<p>The assistant is not switched on for this panel.</p>';
		return 0;
	}
	$user_id = (int)$_SESSION['user_id'];
	if (isset($settings['assistant_customer_enabled']) && $settings['assistant_customer_enabled'] !== '1' && !$db->isAdmin($user_id)) {
		echo '<p>The assistant is only open to administrators right now.</p>';
		return 0;
	}
	$home_id = isset($_GET['home_id']) ? (int)$_GET['home_id'] : 0;
	if ($home_id < 1) {
		echo '<p>Open the assistant from a server in Game Monitor.</p>';
		return 0;
	}
	$token = assistant_scope_open_server($db, $user_id, $home_id);
	if ($token === '') {
		echo '<p>That server is not on this account.</p>';
		return 0;
	}
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	require_once(__DIR__ . '/assistant_settings.php');
	assistant_render_chat(
		$token,
		assistant_ask_label($settings) . ': ' . $home['home_name'],
		'Ask about this server: is it running, errors, logins or attacks in its logs, its files and scripts, its database or FTP login. '
			. 'It can offer a Start, Stop or Restart button, a new-password button, or a change to a file or a new script that you review and apply (and can undo). It never does any of these for you.',
		'For example: why did my server stop?',
		assistant_display_name($settings),
		array(
			'suggestions' => assistant_server_suggestions(),
			'ask' => isset($_GET['q']) ? (string)$_GET['q'] : '',
		)
	);
	echo '<p><a href="?m=gamemanager&amp;p=game_monitor&amp;home_id=' . (int)$home_id . '">Back to the server</a>'
		. ' &middot; <a href="?m=assistant&amp;p=product">Questions about GameServer Engine</a></p>';
	return 1;
}
?>
