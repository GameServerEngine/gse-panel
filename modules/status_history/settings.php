<?php
/* Status history settings (admin only): reporting on or off, poll interval, and how long each table keeps rows. */

require_once __DIR__ . '/includes/status_history_lib.php';

function exec_module()
{
	global $db;

	if (isset($_POST['update_status_history'])) {
		$posted = array(
			'status_history_enabled' => isset($_POST['status_history_enabled']) ? '1' : '0',
			'status_history_interval_seconds' => isset($_POST['status_history_interval_seconds']) ? $_POST['status_history_interval_seconds'] : '',
			'status_history_raw_hours' => isset($_POST['status_history_raw_hours']) ? $_POST['status_history_raw_hours'] : '',
			'status_history_hourly_days' => isset($_POST['status_history_hourly_days']) ? $_POST['status_history_hourly_days'] : '',
			'status_history_daily_days' => isset($_POST['status_history_daily_days']) ? $_POST['status_history_daily_days'] : '',
		);
		$clean = sh_settings_from_rows($posted);
		$db->setSettings(array(
			'status_history_enabled' => (string)$clean['enabled'],
			'status_history_interval_seconds' => (string)$clean['interval_seconds'],
			'status_history_raw_hours' => (string)$clean['raw_hours'],
			'status_history_hourly_days' => (string)$clean['hourly_days'],
			'status_history_daily_days' => (string)$clean['daily_days'],
		));
		echo "<div class='success'>Status history settings saved.</div>";
	}

	$cfg = sh_settings_from_rows($db->getSettings());
	$e = function ($v) { return htmlspecialchars((string)$v); };
	echo "<h2>Status history settings</h2>";
	echo "<p>Reporting asks one server at a time, round robin, so each server is refreshed every "
		. "(number of servers x interval) seconds. Keep times apply to each table: raw samples are the "
		. "finest, then hourly and daily summaries. Pages never call agents; they read the stored rows.</p>";
	echo "<form method='post' action='index.php?m=status_history&amp;p=settings'><table>";
	echo "<tr><td>Reporting on</td><td><input type='checkbox' name='status_history_enabled' value='1'" . ($cfg['enabled'] ? " checked" : '') . "></td></tr>";
	echo "<tr><td>Seconds between polls (10 to 3600)</td><td><input type='number' min='10' max='3600' name='status_history_interval_seconds' value='" . $e($cfg['interval_seconds']) . "'></td></tr>";
	echo "<tr><td>Keep raw samples, hours (1 to 48)</td><td><input type='number' min='1' max='48' name='status_history_raw_hours' value='" . $e($cfg['raw_hours']) . "'></td></tr>";
	echo "<tr><td>Keep hourly summaries, days (1 to 90)</td><td><input type='number' min='1' max='90' name='status_history_hourly_days' value='" . $e($cfg['hourly_days']) . "'></td></tr>";
	echo "<tr><td>Keep daily summaries, days (30 to 3650)</td><td><input type='number' min='30' max='3650' name='status_history_daily_days' value='" . $e($cfg['daily_days']) . "'></td></tr>";
	echo "</table><p><button type='submit' name='update_status_history' value='1'>Save</button></p></form>";
}
