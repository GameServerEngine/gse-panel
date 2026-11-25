<?php
/*
 * The public demo account. Visitors sign in to it with one click (demo_login.php), from the
 * storefront's Try AI page. Every visitor shares the one account, so it may look around, use
 * its demo server's Start, Stop and Restart, and ask AI Assist, but not change the account or
 * any file. Settings: demo_login_user (the account's login; empty switches the demo off).
 */

// The demo account's login, or '' when the demo is switched off.
function demo_login_name($settings)
{
	return isset($settings['demo_login_user']) ? trim((string)$settings['demo_login_user']) : '';
}

// True when this session is the demo account (by one-click sign-in or a normal login).
function demo_is_session($settings)
{
	$name = demo_login_name($settings);
	return $name !== '' && isset($_SESSION['users_login']) && (string)$_SESSION['users_login'] === $name;
}

// Module pages the demo account may open. Everything else answers "not in the demo".
function demo_page_allowed($module, $page)
{
	$allowed = array(
		'dashboard' => array('dashboard', 'updateWidgets', 'query_ref'),
		'gamemanager' => array('game_monitor', 'start', 'stop', 'restart', 'log', 'ref_servermonitor'),
		'assistant' => array('chat', 'product'),
		'lgsl_with_img_mod' => array('lgsl', 'image', 'feed'),
		'news' => array('news'),
		'notifications' => array('my_notifications', 'inbox'),
	);
	return isset($allowed[$module]) && in_array((string)$page, $allowed[$module], true);
}

// The demo account's first game server, or 0.
function demo_home_id($db, $user_id)
{
	$homes = $db->getHomesFor('user', (int)$user_id);
	if (!is_array($homes) || empty($homes)) {
		return 0;
	}
	$first = reset($homes);
	return isset($first['home_id']) ? (int)$first['home_id'] : 0;
}
?>
