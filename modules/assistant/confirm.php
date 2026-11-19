<?php
/*
 * Runs a reset the customer asked for by pressing a button in the chat (new database password
 * or new FTP password). The chat's scope fixes the server. The model is not involved: the panel
 * makes the password, applies it, and returns it to the customer's screen once. It is not logged.
 */

require_once(__DIR__ . '/assistant_boot.php');

function assistant_confirm_main()
{
	global $db;
	$ctx = assistant_boot();
	if ($ctx === null) {
		return;
	}
	list($panel_root, $user_id) = $ctx;

	require_once(__DIR__ . '/assistant_lib.php');
	require_once(__DIR__ . '/assistant_tools.php');
	require_once(__DIR__ . '/assistant_usage.php');
	require_once(__DIR__ . '/assistant_manage.php');

	$scope = assistant_scope_resolve($db, $user_id, isset($_POST['scope']) ? (string)$_POST['scope'] : '');
	if ($scope === null || $scope['type'] !== 'server') {
		return assistant_json(403, array('ok' => false, 'message' => 'Open the assistant from your server page in Game Monitor.', 'password' => ''));
	}
	$what = isset($_POST['what']) ? (string)$_POST['what'] : '';
	assistant_ensure_tables($db);

	if ($what === 'database_password') {
		$result = assistant_reset_database_password($db, $user_id, $scope['home_id'], isset($_POST['db_id']) ? (int)$_POST['db_id'] : 0);
	} elseif ($what === 'ftp_password') {
		$result = assistant_reset_ftp_password($db, $user_id, $scope['home_id'], $panel_root);
	} else {
		return assistant_json(400, array('ok' => false, 'message' => 'Unknown request.', 'password' => ''));
	}
	// The log records that it happened, never the password.
	assistant_audit($db, $user_id, 'Button pressed: ' . $what . ' for server ' . $scope['home_id'], 'reset:' . $what, $result['ok'] ? 'done' : $result['message']);
	assistant_json(200, $result);
}

assistant_confirm_main();
?>
