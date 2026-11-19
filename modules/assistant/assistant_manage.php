<?php
/*
 * Customer-side database and FTP tools. The first three only read. The two reset_*
 * functions change a password, and they run only from confirm.php after the customer
 * presses a button. The new password goes back to the customer's screen and nowhere else:
 * it is never given to the model and never written to the assistant log.
 */

require_once(__DIR__ . '/assistant_lib.php');
require_once(__DIR__ . '/assistant_tools.php');

function assistant_tn($name)
{
	global $table_prefix;
	return '`' . $table_prefix . $name . '`';
}

// A short-timeout MySQL connection, or false. Never throws.
function assistant_mysql_connect($host, $user, $pass, $dbname, $port)
{
	try {
		mysqli_report(MYSQLI_REPORT_OFF);
		$link = mysqli_init();
		mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
		if (!@mysqli_real_connect($link, $host, $user, $pass, $dbname, (int)$port)) {
			return false;
		}
		return $link;
	} catch (Throwable $e) {
		return false;
	}
}

function assistant_mysql_value($link, $sql)
{
	$res = @mysqli_query($link, $sql);
	if (!$res) {
		return null;
	}
	$row = mysqli_fetch_row($res);
	return $row ? $row[0] : null;
}

// This server's enabled databases with the MySQL server they live on.
function assistant_home_databases($db, $home_id)
{
	$rows = $db->resultQuery('SELECT d.db_id, d.db_name, d.db_user, d.db_passwd, s.mysql_name, s.mysql_ip, s.mysql_port, s.mysql_admin_user, s.mysql_root_passwd FROM '
		. assistant_tn('mysql_databases') . ' d JOIN ' . assistant_tn('mysql_servers') . ' s ON s.mysql_server_id = d.mysql_server_id WHERE d.enabled = 1 AND d.home_id = '
		. (int)$home_id . ' ORDER BY d.db_id');
	return is_array($rows) ? $rows : array();
}

function assistant_tool_database_info($db, $user_id, $home_id)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	$out = array();
	foreach (assistant_home_databases($db, $home['home_id']) as $row) {
		$link = assistant_mysql_connect($row['mysql_ip'], $row['db_user'], $row['db_passwd'], $row['db_name'], $row['mysql_port']);
		$entry = array(
			'db_id' => (int)$row['db_id'],
			'database' => $row['db_name'],
			'user' => $row['db_user'],
			'host' => $row['mysql_ip'] . ':' . $row['mysql_port'],
			'login_with_stored_password_works' => $link !== false,
		);
		if ($link) {
			$mb = assistant_mysql_value($link, "SELECT ROUND(COALESCE(SUM(data_length + index_length), 0) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()");
			$entry['size_mb'] = $mb === null ? 'unknown' : (float)$mb;
			mysqli_close($link);
		} else {
			$entry['problem'] = 'The panel could not log in. The database server may be down, or the stored password no longer matches.';
		}
		$out[] = $entry;
	}
	if (empty($out)) {
		return array('data' => array('databases' => array(), 'note' => 'This server has no database assigned.'));
	}
	return array('data' => array('databases' => $out));
}

function assistant_ftp_rights_ok($db, $user_id, $home_id)
{
	if ($db->isAdmin($user_id)) {
		return true;
	}
	$owned = $db->getUserGameHome($user_id, (int)$home_id);
	return is_array($owned) && isset($owned['access_rights']) && strpos($owned['access_rights'], 't') !== false;
}

function assistant_tool_ftp_info($db, $user_id, $home_id, $panel_root)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	require_once($panel_root . '/includes/lib_remote.php');
	$login = !empty($home['ftp_login']) ? $home['ftp_login'] : $home['home_id'];
	$host = !empty($home['ftp_ip']) ? $home['ftp_ip'] : $home['agent_ip'];
	$report = array(
		'login' => (string)$login,
		'host' => (string)$host,
		'port' => isset($home['ftp_port']) ? (int)$home['ftp_port'] : 21,
		'may_change_password' => assistant_ftp_rights_ok($db, $user_id, $home_id),
	);
	$remote = new RemoteLibrary($home['agent_ip'], $home['agent_port'], $home['encryption_key'], min(5, (int)$home['timeout'] ?: 5));
	if ($remote->status_chk() !== 1) {
		$report['machine_online'] = false;
		return array('data' => $report);
	}
	$report['machine_online'] = true;
	$report['account_exists'] = (bool)$remote->ftp_login_exists($login);
	return array('data' => $report);
}

function assistant_tool_propose_reset($db, $user_id, $home_id, $what, $db_id)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('data' => array('error' => $why));
	}
	if ($what === 'ftp_password') {
		if (!assistant_ftp_rights_ok($db, $user_id, $home_id)) {
			return array('data' => array('error' => 'This account does not have FTP access for the server.'));
		}
		return array(
			'data' => array('ok' => true, 'note' => 'A "New FTP password" button is shown to the customer. Nothing has changed yet.'),
			'button' => array('type' => 'reset', 'what' => 'ftp_password', 'db_id' => 0, 'label' => 'New FTP password'),
		);
	}
	if ($what === 'database_password') {
		$rows = assistant_home_databases($db, $home['home_id']);
		if (empty($rows)) {
			return array('data' => array('error' => 'This server has no database.'));
		}
		if ($db_id === 0 && count($rows) === 1) {
			$db_id = (int)$rows[0]['db_id'];
		}
		foreach (is_array($rows) ? $rows : array() as $row) {
			if ((int)$row['db_id'] === $db_id) {
				return array(
					'data' => array('ok' => true, 'note' => 'A "New password for database ' . $row['db_name'] . '" button is shown to the customer. Nothing has changed yet.'),
					'button' => array('type' => 'reset', 'what' => 'database_password', 'db_id' => $db_id, 'label' => 'New password for database ' . $row['db_name']),
				);
			}
		}
		return array('data' => array('error' => 'Which database? Run database_info and use one of its db_id values.'));
	}
	return array('data' => array('error' => 'Unknown reset.'));
}

// Called by confirm.php. Returns array('ok' => bool, 'message' => text, 'password' => text or '').
function assistant_reset_database_password($db, $user_id, $home_id, $db_id)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('ok' => false, 'message' => $why, 'password' => '');
	}
	foreach (assistant_home_databases($db, $home['home_id']) as $row) {
		if ((int)$row['db_id'] !== (int)$db_id) {
			continue;
		}
		$admin = assistant_mysql_connect($row['mysql_ip'], !empty($row['mysql_admin_user']) ? $row['mysql_admin_user'] : 'root', $row['mysql_root_passwd'], '', $row['mysql_port']);
		if (!$admin) {
			return array('ok' => false, 'message' => 'The database server did not answer, so nothing was changed.', 'password' => '');
		}
		$password = assistant_make_password();
		$sql = "ALTER USER '" . mysqli_real_escape_string($admin, $row['db_user']) . "'@'%' IDENTIFIED WITH mysql_native_password BY '" . mysqli_real_escape_string($admin, $password) . "'";
		if (!@mysqli_query($admin, $sql)) {
			mysqli_close($admin);
			return array('ok' => false, 'message' => 'The database server refused the change, so nothing was changed.', 'password' => '');
		}
		mysqli_close($admin);
		$db->query('UPDATE ' . assistant_tn('mysql_databases') . " SET db_passwd = '" . $db->real_escape_string($password) . "' WHERE db_id = " . (int)$db_id . ' AND home_id = ' . (int)$home['home_id']);
		return array('ok' => true, 'message' => 'The password for database ' . $row['db_name'] . ' is changed. Update it anywhere you use it (plugins, config files).', 'password' => $password);
	}
	return array('ok' => false, 'message' => 'That database is not on this server.', 'password' => '');
}

function assistant_reset_ftp_password($db, $user_id, $home_id, $panel_root)
{
	list($home, $why) = assistant_open_home($db, $user_id, $home_id);
	if ($home === null) {
		return array('ok' => false, 'message' => $why, 'password' => '');
	}
	if (!assistant_ftp_rights_ok($db, $user_id, $home_id)) {
		return array('ok' => false, 'message' => 'This account does not have FTP access for the server.', 'password' => '');
	}
	require_once($panel_root . '/includes/lib_remote.php');
	$login = !empty($home['ftp_login']) ? $home['ftp_login'] : $home['home_id'];
	$remote = new RemoteLibrary($home['agent_ip'], $home['agent_port'], $home['encryption_key'], min(10, (int)$home['timeout'] ?: 10));
	if ($remote->status_chk() !== 1) {
		return array('ok' => false, 'message' => 'The machine did not answer, so nothing was changed.', 'password' => '');
	}
	$password = assistant_make_password();
	if ($remote->ftp_login_exists($login)) {
		$done = $remote->ftp_mgr('passwd', $login, $password) !== 0;
	} else {
		$done = $remote->ftp_mgr('useradd', $login, $password, $home['home_path']) !== 0;
	}
	if (!$done) {
		return array('ok' => false, 'message' => 'The machine refused the change, so nothing was changed.', 'password' => '');
	}
	$db->changeFtpPassword($home['home_id'], $password);
	return array('ok' => true, 'message' => 'The FTP password for login ' . $login . ' is changed.', 'password' => $password);
}
?>
