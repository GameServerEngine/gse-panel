<?php
/*
 * One-click sign-in to the public demo account, linked from the storefront's Try AI page.
 *   demo_login.php                      opens the demo server in Game Monitor
 *   demo_login.php?to=server&q=TEXT     opens AI Assist on the demo server and asks TEXT
 *   demo_login.php?to=product&q=TEXT    opens AI Assist for product questions and asks TEXT
 * The account is set in Settings (Demo account). It must be a normal user, never an administrator.
 */

define("IMAGES", "images/");
define("INCLUDES", "includes/");
define("MODULES", "modules/");
define("CONFIG_FILE", "includes/config.inc.php");

require_once('includes/functions.php');
require_once('includes/helpers.php');
require_once('includes/demo.php');
startSession();
if (!is_readable(CONFIG_FILE)) {
	header('Location: index.php');
	exit();
}
require_once(CONFIG_FILE);
$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : NULL);

function demo_login_stop($text)
{
	http_response_code(503);
	header('Content-Type: text/plain; charset=UTF-8');
	echo $text;
	exit();
}

if (!$db instanceof Database) {
	demo_login_stop('The demo is not available right now.');
}
$settings = $db->getSettings();
$name = demo_login_name($settings);
if ($name === '') {
	demo_login_stop('The demo is switched off.');
}
$user = $db->getUser($name);
if (!is_array($user) || empty($user['user_id']) || $user['users_role'] !== 'user') {
	demo_login_stop('The demo is not set up on this panel.');
}

// A fresh session for each visitor, so nothing from another login carries over.
$_SESSION = array();
session_regenerate_id(true);
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['users_login'] = $user['users_login'];
$_SESSION['users_passwd'] = $user['users_passwd'];
$_SESSION['users_role'] = $user['users_role'];
$_SESSION['users_lang'] = $user['users_lang'];
$_SESSION['users_theme'] = $user['users_theme'];

$to = isset($_GET['to']) ? (string)$_GET['to'] : '';
$question = isset($_GET['q']) ? mb_substr(trim((string)$_GET['q']), 0, 300) : '';
$home_id = demo_home_id($db, $user['user_id']);
if ($to === 'product') {
	$target = 'home.php?m=assistant&p=product';
} elseif ($to === 'server' && $home_id > 0) {
	$target = 'home.php?m=assistant&p=chat&home_id=' . $home_id;
} else {
	$target = 'home.php?m=gamemanager&p=game_monitor' . ($home_id > 0 ? '&home_id=' . $home_id : '');
}
if ($question !== '' && ($to === 'product' || $to === 'server')) {
	$target .= '&q=' . rawurlencode($question);
}
header('Location: ' . $target);
exit();
?>
