<?php
/* Server status: the list of servers, or one server's history. Read-only; visible to all users. */

require_once __DIR__ . '/includes/status_view.php';

function exec_module()
{
	global $db;
	$isAdmin = $db->isAdmin($_SESSION['user_id']);
	$cfg = sv_settings($db);

	echo "<h2>Server status</h2>";
	if (!$cfg['enabled']) {
		echo "<p>Status history reporting is turned off.</p>";
		return;
	}

	$sid = isset($_GET['remote_server_id']) ? (int)$_GET['remote_server_id'] : 0;
	$server = null;
	foreach (sv_servers($db) as $row) {
		if ((int)$row['remote_server_id'] === $sid) $server = $row;
	}
	if ($server === null) {
		echo sv_dashboard_html($db, $isAdmin);
		return;
	}

	$tz = sv_tz($db);
	$lookup_at = null;
	if (isset($_GET['at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_GET['at'])) {
		$d = DateTime::createFromFormat('Y-m-d\TH:i', $_GET['at'], new DateTimeZone($tz));
		if ($d !== false) $lookup_at = min($d->getTimestamp(), time());
	}
	echo sv_detail_html($db, $server, $isAdmin, $lookup_at);
}
