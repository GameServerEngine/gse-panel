<?php
/*
 * The Admin assistant: read-only help for diagnosing the panel, MySQL, the databases the
 * games use, the agents and every game server. It cannot change anything.
 */

function exec_module()
{
	global $db, $settings;
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_boot.php');
	require_once(__DIR__ . '/assistant_ui.php');

	if (!$db->isAdmin((int)$_SESSION['user_id'])) {
		echo '<p>Administrators only.</p>';
		return 0;
	}
	if (!isset($settings['assistant_enabled']) || $settings['assistant_enabled'] !== '1') {
		echo '<p>The assistant is not switched on. Set assistant_enabled to 1 in the panel settings.</p>';
		return 0;
	}
	assistant_render_chat(
		'admin',
		'Admin assistant',
		'Ask about the panel, MySQL, the game databases, the agents or any game server. It only reads; it cannot change anything.',
		'For example: is anything wrong with the agents or MySQL?'
	);
	return 1;
}
?>
