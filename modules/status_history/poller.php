<?php
/*
 * Status poller (CLI only). Started by install/gse-status-poller.service.
 *
 * Each loop it asks one agent for status_sample, round robin, and stores the answer. So each
 * server is refreshed every (servers x interval) seconds. It also rolls finished clock hours into
 * status_hour, finished local days into status_day, and prunes rows past their keep time.
 * No web page runs this file or waits for it.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$panelRoot = dirname(__DIR__, 2);
chdir($panelRoot);
require $panelRoot . '/includes/config.inc.php';
require_once $panelRoot . '/includes/functions.php';
require_once $panelRoot . '/includes/helpers.php';
require_once $panelRoot . '/includes/lib_remote.php';
require_once __DIR__ . '/includes/status_history_lib.php';

$prefix = isset($table_prefix) ? (string)$table_prefix : '';
$db = new mysqli($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : 3306);
if ($db->connect_errno) {
	fwrite(STDERR, "status poller: cannot connect to the panel database\n");
	exit(1);
}
$db->set_charset('utf8mb4');

function sh_log($message)
{
	fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $message . "\n");
}

/** All status_history_* settings and the time zone, as stored. */
function sh_read_settings(mysqli $db, $prefix)
{
	$out = array();
	$res = $db->query('SELECT `setting`, `value` FROM `' . $prefix . 'settings`');
	if ($res) {
		while ($row = $res->fetch_assoc()) {
			if (strpos($row['setting'], 'status_history_') === 0 || $row['setting'] === 'time_zone') {
				$out[$row['setting']] = $row['value'];
			}
		}
		$res->free();
	}
	return $out;
}

function sh_read_servers(mysqli $db, $prefix)
{
	$servers = array();
	$res = $db->query('SELECT `remote_server_id`, `agent_ip`, `agent_port`, `encryption_key`, `timeout` FROM `' . $prefix . 'remote_servers` WHERE `enabled` = 1 ORDER BY `remote_server_id`');
	if ($res) {
		while ($row = $res->fetch_assoc()) {
			$servers[] = $row;
		}
		$res->free();
	}
	return $servers;
}

/** One value from a single-value query, or null when there is no row. */
function sh_scalar(mysqli $db, $sql)
{
	$res = $db->query($sql);
	if (!$res) {
		return null;
	}
	$row = $res->fetch_row();
	$res->free();
	return ($row === null || $row[0] === null) ? null : $row[0];
}

/** Inserts one row. With $upsert, an existing row with the same key is replaced. */
function sh_insert_row(mysqli $db, $table, array $row, $upsert = false)
{
	$cols = array_keys($row);
	$sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
	if ($upsert) {
		$parts = array();
		foreach ($cols as $col) {
			if ($col !== 'remote_server_id' && $col !== 'bucket_start') {
				$parts[] = '`' . $col . '` = VALUES(`' . $col . '`)';
			}
		}
		$sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $parts);
	}
	$stmt = $db->prepare($sql);
	if (!$stmt) {
		return false;
	}
	$values = array_values($row);
	$params = array(str_repeat('s', count($values)));
	foreach ($values as $i => $value) {
		$params[] = &$values[$i];
	}
	call_user_func_array(array($stmt, 'bind_param'), $params);
	$ok = $stmt->execute();
	$stmt->close();
	return $ok;
}

/** The server's previous sample, or null. */
function sh_last_sample(mysqli $db, $prefix, $sid)
{
	$stmt = $db->prepare('SELECT * FROM `' . $prefix . 'status_sample` WHERE `remote_server_id` = ? ORDER BY `sampled_at` DESC LIMIT 1');
	$stmt->bind_param('i', $sid);
	$stmt->execute();
	$res = $stmt->get_result();
	$row = $res ? $res->fetch_assoc() : null;
	$stmt->close();
	return $row ? $row : null;
}

/** Asks one agent and stores one sample row. Never throws: a failure is stored as DOWN or UNKNOWN. */
function sh_poll_server(mysqli $db, $prefix, array $server, $now)
{
	$sid = (int)$server['remote_server_id'];
	try {
		$remote = new RemoteLibrary($server['agent_ip'], $server['agent_port'], $server['encryption_key'], 5);
		$reply = $remote->status_sample();
	} catch (Throwable $e) {
		$reply = null;
	}

	if ($reply === null || $reply === false || $reply === '') {
		$row = sh_down_sample($now, 'down');
	} elseif (is_string($reply) && strpos($reply, 'FAULT:') === 0) {
		// The agent refused the call: an older agent without status_sample, or a changed key.
		$row = sh_down_sample($now, 'unknown_agent');
	} else {
		$parsed = sh_parse_agent_reply($reply);
		if ($parsed === null) {
			$row = sh_down_sample($now, stripos((string)$reply, 'Bad Encryption') !== false ? 'unknown_key' : 'unknown_reply');
		} else {
			$row = sh_build_sample($parsed, $now, sh_last_sample($db, $prefix, $sid));
		}
	}
	$row['remote_server_id'] = $sid;
	sh_insert_row($db, $prefix . 'status_sample', $row);
}

/** Samples in [from, to) grouped by server id. */
function sh_samples_between(mysqli $db, $prefix, $from, $to)
{
	return sh_rows_between($db, $prefix . 'status_sample', 'sampled_at', $from, $to);
}

/** Hour rows in [from, to) grouped by server id. */
function sh_hours_between(mysqli $db, $prefix, $from, $to)
{
	return sh_rows_between($db, $prefix . 'status_hour', 'bucket_start', $from, $to);
}

function sh_rows_between(mysqli $db, $table, $timeColumn, $from, $to)
{
	$out = array();
	$stmt = $db->prepare('SELECT * FROM `' . $table . '` WHERE `' . $timeColumn . '` >= ? AND `' . $timeColumn . '` < ? ORDER BY `remote_server_id`, `' . $timeColumn . '`');
	$stmt->bind_param('ii', $from, $to);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($res && ($row = $res->fetch_assoc())) {
		$out[(int)$row['remote_server_id']][] = $row;
	}
	$stmt->close();
	return $out;
}

/** Rolls every finished clock hour since the last hour row (at most 24 per loop). */
function sh_rollup_hours_pending(mysqli $db, $prefix, $now)
{
	$last = sh_scalar($db, 'SELECT MAX(`bucket_start`) FROM `' . $prefix . 'status_hour`');
	if ($last !== null) {
		$from = (int)$last + 3600;
	} else {
		$first = sh_scalar($db, 'SELECT MIN(`sampled_at`) FROM `' . $prefix . 'status_sample`');
		if ($first === null) {
			return;
		}
		$from = sh_hour_start($first);
	}
	$lastComplete = sh_hour_start($now) - 3600;
	$count = 0;
	for ($h = $from; $h <= $lastComplete && $count < 24; $h += 3600, $count++) {
		foreach (sh_samples_between($db, $prefix, $h, $h + 3600) as $sid => $samples) {
			$row = sh_rollup_samples($samples, $h);
			$row['remote_server_id'] = $sid;
			sh_insert_row($db, $prefix . 'status_hour', $row, true);
		}
	}
}

/** Rolls every finished local day since the last day row (at most 3 per loop). */
function sh_rollup_days_pending(mysqli $db, $prefix, $timezone, $now)
{
	$last = sh_scalar($db, 'SELECT MAX(`bucket_start`) FROM `' . $prefix . 'status_day`');
	if ($last !== null) {
		$from = sh_next_day_start((int)$last, $timezone);
	} else {
		$first = sh_scalar($db, 'SELECT MIN(`bucket_start`) FROM `' . $prefix . 'status_hour`');
		if ($first === null) {
			return;
		}
		$from = sh_day_start($first, $timezone);
	}
	$today = sh_day_start($now, $timezone);
	$count = 0;
	for ($d = $from; $d < $today && $count < 3; $d = sh_next_day_start($d, $timezone), $count++) {
		$next = sh_next_day_start($d, $timezone);
		foreach (sh_hours_between($db, $prefix, $d, $next) as $sid => $hours) {
			$row = sh_rollup_hours($hours, $d);
			$row['remote_server_id'] = $sid;
			sh_insert_row($db, $prefix . 'status_day', $row, true);
		}
	}
}

/** Deletes rows past their keep time. Each table has its own window from the settings. */
function sh_prune(mysqli $db, $prefix, array $settings, $now)
{
	$db->query('DELETE FROM `' . $prefix . 'status_sample` WHERE `sampled_at` < ' . ((int)$now - $settings['raw_hours'] * 3600));
	$db->query('DELETE FROM `' . $prefix . 'status_hour` WHERE `bucket_start` < ' . ((int)$now - $settings['hourly_days'] * 86400));
	$db->query('DELETE FROM `' . $prefix . 'status_day` WHERE `bucket_start` < ' . ((int)$now - $settings['daily_days'] * 86400));
}

sh_log('status poller started');
$next = 0;
$lastPrune = 0;
while (true) {
	$now = time();
	$raw = sh_read_settings($db, $prefix);
	$settings = sh_settings_from_rows($raw);
	if ((int)$settings['enabled'] !== 1) {
		sleep(30);
		continue;
	}

	$servers = sh_read_servers($db, $prefix);
	if (count($servers) > 0) {
		$index = $next % count($servers);
		$next = $index + 1;
		sh_poll_server($db, $prefix, $servers[$index], $now);
	}

	$timezone = sh_panel_timezone(isset($raw['time_zone']) ? $raw['time_zone'] : '');
	sh_rollup_hours_pending($db, $prefix, $now);
	sh_rollup_days_pending($db, $prefix, $timezone, $now);
	if ($now - $lastPrune >= 600) {
		sh_prune($db, $prefix, $settings, $now);
		$lastPrune = $now;
	}
	sleep($settings['interval_seconds']);
}
