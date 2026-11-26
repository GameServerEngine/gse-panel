<?php
/*
 * Runs what the person chose by pressing a button in the chat: a new database or FTP password, or
 * Review / Apply / Discard / Undo for a file change the assistant proposed. The chat's scope fixes
 * the server (an administrator's chat names the server in the request, and the panel checks the
 * rights again). The model is not involved. Passwords are made by the panel, shown once, never logged.
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
	require_once(__DIR__ . '/assistant_files.php');
	require_once(__DIR__ . '/assistant_admin_fix.php');
	require_once(__DIR__ . '/assistant_settings.php');

	$scope = assistant_scope_resolve($db, $user_id, isset($_POST['scope']) ? (string)$_POST['scope'] : '');
	if ($scope === null) {
		return assistant_json(403, array('ok' => false, 'message' => 'Open the assistant from your server page in Game Monitor.', 'password' => ''));
	}
	$cfg = assistant_config($db->getSettings());
	if ($cfg['assistant_enabled'] !== '1' || (!$db->isAdmin($user_id) && $cfg['assistant_customer_enabled'] !== '1')) {
		return assistant_json(503, array('ok' => false, 'message' => 'The assistant is switched off.', 'password' => ''));
	}
	$what = isset($_POST['what']) ? (string)$_POST['what'] : '';
	require_once($panel_root . '/includes/demo.php');
	if (demo_is_session($db->getSettings()) && $what !== 'proposal_view' && $what !== 'proposal_discard') {
		return assistant_json(403, array('ok' => false, 'message' => 'Not in the demo: AI Assist shows the change, but only your own panel applies it.', 'password' => ''));
	}
	$home_id = $scope['type'] === 'server' ? (int)$scope['home_id'] : (isset($_POST['home_id']) ? (int)$_POST['home_id'] : 0);
	$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
	assistant_ensure_tables($db);

	if ($what === 'database_password' || $what === 'ftp_password') {
		if ($cfg['assistant_allow_resets'] !== '1' && $scope['type'] !== 'admin') {
			return assistant_json(403, array('ok' => false, 'message' => 'Password resets from the assistant are switched off.', 'password' => ''));
		}
		$result = $what === 'database_password'
			? assistant_reset_database_password($db, $user_id, $home_id, isset($_POST['db_id']) ? (int)$_POST['db_id'] : 0)
			: assistant_reset_ftp_password($db, $user_id, $home_id, $panel_root);
		// The log records that it happened, never the password.
		assistant_audit($db, $user_id, 'Button pressed: ' . $what . ' for server ' . $home_id, 'reset:' . $what, $result['ok'] ? 'done' : $result['message']);
		return assistant_json(200, $result);
	}

	if (in_array($what, array('proposal_view', 'proposal_apply', 'proposal_discard', 'proposal_undo'), true)) {
		if ($cfg['assistant_allow_edits'] !== '1' && $scope['type'] !== 'admin') {
			return assistant_json(403, array('ok' => false, 'message' => 'File changes from the assistant are switched off.'));
		}
		if ($what === 'proposal_view') {
			return assistant_json(200, assistant_proposal_view($db, $user_id, $scope, $id));
		}
		if ($what === 'proposal_apply') {
			$result = assistant_proposal_apply($db, $user_id, $scope, $id, $panel_root);
		} elseif ($what === 'proposal_undo') {
			$result = assistant_proposal_undo($db, $user_id, $scope, $id, $panel_root);
		} else {
			$result = assistant_proposal_discard($db, $user_id, $scope, $id);
		}
		assistant_audit($db, $user_id, 'Button pressed: ' . $what . ' #' . $id, 'proposal:' . substr($what, 9), $result['ok'] ? 'done' : $result['message']);
		return assistant_json(200, $result);
	}

	assistant_json(400, array('ok' => false, 'message' => 'Unknown request.', 'password' => ''));
}

assistant_confirm_main();
?>
