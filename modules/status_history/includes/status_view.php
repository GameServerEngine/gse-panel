<?php
/*
 * Status history views. Read-only: these pages never call an agent; they read the stored rows.
 * Percentages are shown to every user. Absolute values (network rates, process names) are for admins.
 */

require_once __DIR__ . '/status_history_lib.php';

define('SV_ALERT_PCT', 90);

function sv_tz($db)
{
	$s = $db->getSettings();
	return sh_panel_timezone(isset($s['time_zone']) ? $s['time_zone'] : '');
}

function sv_settings($db)
{
	return sh_settings_from_rows($db->getSettings());
}

function sv_prefix($db)
{
	return $db->getTablePrefix();
}

/** Every registered server. The live remote_servers table has no per-server enable flag, so all are reported. */
function sv_servers($db)
{
	return (array)$db->getRemoteServers();
}

function sv_fmt_time($ts, $tz)
{
	if ($ts === null || $ts === '') return '-';
	$d = new DateTime('@' . (int)$ts);
	$d->setTimezone(new DateTimeZone($tz));
	return $d->format('M j, Y g:i A T');
}

function sv_fmt_duration($seconds)
{
	if ($seconds === null || $seconds === '') return '-';
	$s = (int)$seconds;
	$d = (int)floor($s / 86400);
	$h = (int)floor(($s % 86400) / 3600);
	$m = (int)floor(($s % 3600) / 60);
	return ($d > 0 ? $d . 'd ' : '') . (($d > 0 || $h > 0) ? $h . 'h ' : '') . $m . 'm';
}

function sv_fmt_pct($value)
{
	// Whole numbers: 8.7 and 87 looked alike at a glance.
	return ($value === null) ? '-' : number_format(round((float)$value), 0) . '%';
}

function sv_fmt_ago($seconds)
{
	if ($seconds < 60) return $seconds . 's ago';
	if ($seconds < 3600) return floor($seconds / 60) . 'm ago';
	return floor($seconds / 3600) . 'h ago';
}

/** Label, background and text colour for a status. Anything that is not 'ok' or 'down' is UNKNOWN. */
function sv_status_label($code)
{
	if ($code === 'ok') return array('UP', '#2e7d32', '#ffffff');
	if ($code === 'down') return array('DOWN', '#c62828', '#ffffff');
	return array('UNKNOWN', '#f9a825', '#000000');
}

/** A server needs attention when it is not UP or a resource is at or above the alert level. */
function sv_needs_attention($row, $effective)
{
	if ($effective !== 'UP') return true;
	foreach (array('cpu_pct', 'ram_pct', 'disk_pct') as $col) {
		if ($row[$col] !== null && (float)$row[$col] >= SV_ALERT_PCT) return true;
	}
	return false;
}

function sv_badge($label, $attention)
{
	$l = sv_status_label($label === 'UP' ? 'ok' : ($label === 'DOWN' ? 'down' : 'unknown'));
	$icon = $attention ? " <span title='Needs attention' style='font-weight:bold;color:#c62828;'>&#9888;</span>" : '';
	return "<span style='display:inline-block;padding:2px 8px;border-radius:4px;font-weight:bold;background:{$l[1]};color:{$l[2]};'>{$l[0]}</span>" . $icon;
}

/** Latest stored sample per server, keyed by server id. */
function sv_latest_samples($db)
{
	$p = sv_prefix($db);
	$rows = $db->statusHistoryRows("SELECT s.* FROM `{$p}status_sample` s JOIN (SELECT remote_server_id, MAX(sampled_at) AS m FROM `{$p}status_sample` GROUP BY remote_server_id) t ON s.remote_server_id = t.remote_server_id AND s.sampled_at = t.m");
	$out = array();
	foreach ($rows as $r) $out[(int)$r['remote_server_id']] = $r;
	return $out;
}

/**
 * Effective status for the page: the stored code, but UNKNOWN when the last report is older than
 * two full poll cycles (the poller has stopped, so the stored code no longer says anything).
 */
function sv_effective_status($sample, $now, $cycle_seconds)
{
	if ($sample === null) return array('UNKNOWN', null);
	$age = $now - (int)$sample['sampled_at'];
	if ($age > 2 * $cycle_seconds + 60) return array('UNKNOWN', $age);
	return array(sv_status_label($sample['status_code'])[0], $age);
}

// ---- Windowed statistics -------------------------------------------------------------------

function sv_new_acc()
{
	return array(
		'samples' => 0, 'up' => 0,
		'cpu_sum' => 0.0, 'cpu_n' => 0, 'cpu_max' => null, 'cpu_at' => null,
		'ram_sum' => 0.0, 'ram_n' => 0, 'ram_max' => null, 'ram_at' => null,
		'disk_sum' => 0.0, 'disk_n' => 0, 'disk_max' => null, 'disk_at' => null,
		'in_max' => null, 'in_at' => null, 'out_max' => null, 'out_at' => null,
		'uptime' => null,
	);
}

function sv_acc_peak(&$acc, $key, $value, $at)
{
	if ($value === null || $value === '') return;
	$value = (float)$value;
	if ($acc[$key . '_max'] === null || $value > $acc[$key . '_max']) {
		$acc[$key . '_max'] = $value;
		$acc[$key . '_at'] = (int)$at;
	}
}

/** Adds one hour or day rollup row. Averages are weighted by the up samples in the bucket. */
function sv_acc_bucket(&$acc, array $r)
{
	$up = (int)$r['up_samples'];
	$acc['samples'] += (int)$r['samples'];
	$acc['up'] += $up;
	foreach (array('cpu', 'ram', 'disk') as $m) {
		if ($r[$m . '_avg'] !== null && $up > 0) {
			$acc[$m . '_sum'] += (float)$r[$m . '_avg'] * $up;
			$acc[$m . '_n'] += $up;
		}
		sv_acc_peak($acc, $m, $r[$m . '_max'], $r[$m . '_max_at']);
	}
	sv_acc_peak($acc, 'in', $r['net_in_max'], $r['net_in_max_at']);
	sv_acc_peak($acc, 'out', $r['net_out_max'], $r['net_out_max_at']);
	if ($r['uptime_end'] !== null) $acc['uptime'] = (int)$r['uptime_end'];
}

function sv_acc_sample(&$acc, array $r)
{
	$acc['samples'] += 1;
	if ((int)$r['is_up'] !== 1) return;
	$acc['up'] += 1;
	foreach (array('cpu', 'ram', 'disk') as $m) {
		$v = $r[$m . '_pct'];
		if ($v !== null) {
			$acc[$m . '_sum'] += (float)$v;
			$acc[$m . '_n'] += 1;
		}
		sv_acc_peak($acc, $m, $v, $r['sampled_at']);
	}
	sv_acc_peak($acc, 'in', $r['net_in_bytes_s'], $r['sampled_at']);
	sv_acc_peak($acc, 'out', $r['net_out_bytes_s'], $r['sampled_at']);
	if ($r['uptime_s'] !== null) $acc['uptime'] = (int)$r['uptime_s'];
}

function sv_acc_result(array $acc)
{
	foreach (array('cpu', 'ram', 'disk') as $m) {
		$acc[$m . '_avg'] = $acc[$m . '_n'] > 0 ? $acc[$m . '_sum'] / $acc[$m . '_n'] : null;
	}
	$acc['availability'] = $acc['samples'] > 0 ? 100 * $acc['up'] / $acc['samples'] : null;
	return $acc;
}

/**
 * Statistics for one server from $from to $now, using the table that holds each part:
 *  - raw samples for the newest part (never older than the raw window),
 *  - whole hour rollups for the part before that,
 *  - daily rollups for everything before today when $useDays is set (all-time view).
 * Parts never overlap, so no sample is counted twice.
 */
function sv_window_stats($db, $sid, $from, $now, $tz, $raw_hours, $useDays)
{
	$p = sv_prefix($db);
	$sid = (int)$sid;
	$from = max(0, (int)$from);
	$acc = sv_new_acc();
	$hourNow = sh_hour_start($now);

	if ($useDays) {
		$todayStart = sh_day_start($now, $tz);
		foreach ($db->statusHistoryRows("SELECT * FROM `{$p}status_day` WHERE remote_server_id = $sid AND bucket_start < $todayStart ORDER BY bucket_start") as $r) {
			sv_acc_bucket($acc, $r);
		}
		$hourFrom = $todayStart;
		$hourTo = $hourNow;
		$rawFrom = $hourNow;
	} else {
		$rawFrom = max($from, $now - $raw_hours * 3600);
		$hourTo = sh_hour_start($rawFrom);
		// Whole hours only: the first hour counts only if the window starts exactly on its start.
		$hourFrom = ($from % 3600 === 0) ? $from : (sh_hour_start($from) + 3600);
	}

	if ($hourFrom < $hourTo) {
		foreach ($db->statusHistoryRows("SELECT * FROM `{$p}status_hour` WHERE remote_server_id = $sid AND bucket_start >= $hourFrom AND bucket_start < $hourTo ORDER BY bucket_start") as $r) {
			sv_acc_bucket($acc, $r);
		}
	}
	foreach ($db->statusHistoryRows("SELECT * FROM `{$p}status_sample` WHERE remote_server_id = $sid AND sampled_at >= $rawFrom AND sampled_at <= $now ORDER BY sampled_at") as $r) {
		sv_acc_sample($acc, $r);
	}
	return sv_acc_result($acc);
}

// ---- Dashboard table -----------------------------------------------------------------------

function sv_dashboard_html($db, $isAdmin)
{
	$cfg = sv_settings($db);
	$now = time();
	$html = "<div style='margin-top:20px;'><div class='bloc rounded'><h4>Server status</h4><div style='padding:10px;'>";

	if (!$cfg['enabled']) {
		$html .= "<p>Status history reporting is turned off.</p>";
		if ($isAdmin) $html .= "<p><a href='home.php?m=status_history&amp;p=history_settings'>Status history settings</a></p>";
		return $html . "</div></div></div>";
	}

	$servers = sv_servers($db);
	$cycle = count($servers) * $cfg['interval_seconds'];
	$latest = sv_latest_samples($db);

	$html .= "<table class='sv_table' style='width:100%;border-collapse:collapse;'><tr>"
		. "<th style='text-align:left;'>Server</th><th>IP</th><th>Status</th><th>Uptime</th></tr>";

	if (count($servers) === 0) {
		$html .= "<tr><td colspan='4'>No servers are set up yet.</td></tr>";
	}
	foreach ($servers as $server) {
		$sid = (int)$server['remote_server_id'];
		$sample = isset($latest[$sid]) ? $latest[$sid] : null;
		list($status) = sv_effective_status($sample, $now, $cycle);
		$attention = $status !== 'UP' || ($sample !== null && sv_needs_attention($sample, $status));
		$name = htmlspecialchars($server['remote_server_name']);
		$ip = htmlspecialchars($server['agent_ip']);
		$link = "home.php?m=status_history&amp;p=detail&amp;remote_server_id=$sid";
		$html .= "<tr style='border-top:1px solid #ddd;'>"
			. "<td><a href='$link'>$name</a></td>"
			. "<td style='text-align:center;'>$ip</td>"
			. "<td style='text-align:center;'>" . sv_badge($status, $attention) . "</td>"
			. "<td style='text-align:center;'>" . ($sample && $status === 'UP' ? sv_fmt_duration($sample['uptime_s']) : '-') . "</td>"
			. "</tr>";
	}
	$html .= "</table>";
	return $html . "</div></div></div>";
}

function sv_fmt_rate($bytes_per_s)
{
	if ($bytes_per_s === null) return '-';
	$v = (float)$bytes_per_s;
	if ($v >= 1048576) return number_format($v / 1048576, 2) . ' MB/s';
	if ($v >= 1024) return number_format($v / 1024, 1) . ' KB/s';
	return number_format($v, 0) . ' B/s';
}

function sv_peak_cell($value, $at, $tz)
{
	if ($value === null) return '-';
	return sv_fmt_pct($value) . "<br><small>" . sv_fmt_time($at, $tz) . "</small>";
}

/** Server detail: the four windows, then the complaint lookup. */
function sv_detail_html($db, $server, $isAdmin, $lookup_at)
{
	$cfg = sv_settings($db);
	$tz = sv_tz($db);
	$now = time();
	$sid = (int)$server['remote_server_id'];
	$name = htmlspecialchars($server['remote_server_name']);
	$cycle = count(sv_servers($db)) * $cfg['interval_seconds'];

	$latest = sv_latest_samples($db);
	$sample = isset($latest[$sid]) ? $latest[$sid] : null;
	list($status, $age) = sv_effective_status($sample, $now, $cycle);
	$attention = $status !== 'UP' || ($sample !== null && sv_needs_attention($sample, $status));

	$html = "<p><a href='home.php?m=status_history&amp;p=detail'>&larr; All servers</a> &middot; <a href='home.php?m=dashboard&amp;p=dashboard'>Dashboard</a></p>";
	$html .= "<h2>$name</h2>";
	$html .= "<p>Status " . sv_badge($status, $attention)
		. " &nbsp; Uptime " . ($sample && $status === 'UP' ? sv_fmt_duration($sample['uptime_s']) : '-')
		. " &nbsp; Last report " . ($age === null ? 'never' : sv_fmt_ago($age))
		. "</p>";

	$windows = array(
		'Last hour' => array($now - 3600, false),
		'Last 24 hours' => array($now - 86400, false),
		'Last 7 days' => array($now - 7 * 86400, false),
		'All time' => array(0, true),
	);

	$html .= "<table style='width:100%;border-collapse:collapse;'><tr>"
		. "<th style='text-align:left;'>Window</th><th>Availability</th><th>CPU average</th><th>CPU peak</th>"
		. "<th>RAM average</th><th>RAM peak</th><th>Disk average</th><th>Disk peak</th>"
		. ($isAdmin ? "<th>Network in peak</th><th>Network out peak</th>" : '') . "</tr>";
	foreach ($windows as $label => $w) {
		$s = sv_window_stats($db, $sid, $w[0], $now, $tz, $cfg['raw_hours'], $w[1]);
		$html .= "<tr style='border-top:1px solid #ddd;'><td>$label</td>"
			. "<td style='text-align:center;'>" . sv_fmt_pct($s['availability']) . "</td>"
			. "<td style='text-align:center;'>" . sv_fmt_pct($s['cpu_avg']) . "</td>"
			. "<td style='text-align:center;'>" . sv_peak_cell($s['cpu_max'], $s['cpu_at'], $tz) . "</td>"
			. "<td style='text-align:center;'>" . sv_fmt_pct($s['ram_avg']) . "</td>"
			. "<td style='text-align:center;'>" . sv_peak_cell($s['ram_max'], $s['ram_at'], $tz) . "</td>"
			. "<td style='text-align:center;'>" . sv_fmt_pct($s['disk_avg']) . "</td>"
			. "<td style='text-align:center;'>" . sv_peak_cell($s['disk_max'], $s['disk_at'], $tz) . "</td>"
			. ($isAdmin ? "<td style='text-align:center;'>" . ($s['in_max'] === null ? '-' : sv_fmt_rate($s['in_max']) . "<br><small>" . sv_fmt_time($s['in_at'], $tz) . "</small>") . "</td>"
				. "<td style='text-align:center;'>" . ($s['out_max'] === null ? '-' : sv_fmt_rate($s['out_max']) . "<br><small>" . sv_fmt_time($s['out_at'], $tz) . "</small>") . "</td>" : '')
			. "</tr>";
	}
	$html .= "</table>";
	$html .= "<p><small>CPU, RAM and disk are percentages of the whole machine. Peaks show the time they happened (US Central).</small></p>";

	// Complaint lookup: what the stored data says about a moment, plus or minus 30 minutes.
	$default = new DateTime('@' . $now);
	$default->setTimezone(new DateTimeZone($tz));
	$html .= "<h3>Look up a complaint time</h3>"
		. "<form method='get' action='home.php'>"
		. "<input type='hidden' name='m' value='status_history'><input type='hidden' name='p' value='detail'>"
		. "<input type='hidden' name='remote_server_id' value='$sid'>"
		. "<input type='datetime-local' name='at' value='" . $default->format('Y-m-d\TH:i') . "'> "
		. "<button type='submit'>Look up</button></form>";

	if ($lookup_at !== null) {
		$html .= sv_lookup_html($db, $sid, $lookup_at, $now, $tz, $cfg, $isAdmin);
	}
	return $html;
}

/** Stored rows within 30 minutes of $at. Raw samples when the moment is still in the raw window. */
function sv_lookup_html($db, $sid, $at, $now, $tz, $cfg, $isAdmin)
{
	$p = sv_prefix($db);
	$from = $at - 1800;
	$to = min($at + 1800, $now);
	$html = "<h4>" . htmlspecialchars(sv_fmt_time($at, $tz)) . " (30 minutes either side)</h4>";

	if ($from >= $now - $cfg['raw_hours'] * 3600) {
		$rows = $db->statusHistoryRows("SELECT * FROM `{$p}status_sample` WHERE remote_server_id = $sid AND sampled_at >= $from AND sampled_at <= $to ORDER BY sampled_at");
		if (count($rows) === 0) return $html . "<p>No stored reports in this window.</p>";
		$html .= "<table style='width:100%;border-collapse:collapse;'><tr><th>Time</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th>"
			. ($isAdmin ? "<th>Network in</th><th>Network out</th><th>Top CPU</th><th>Top RAM</th>" : '') . "</tr>";
		foreach ($rows as $r) {
			$code = sv_status_label($r['status_code']);
			$html .= "<tr style='border-top:1px solid #ddd;'><td>" . sv_fmt_time($r['sampled_at'], $tz) . "</td>"
				. "<td style='text-align:center;'>" . $code[0] . "</td>"
				. "<td style='text-align:center;'>" . sv_fmt_pct($r['cpu_pct']) . "</td>"
				. "<td style='text-align:center;'>" . sv_fmt_pct($r['ram_pct']) . "</td>"
				. "<td style='text-align:center;'>" . sv_fmt_pct($r['disk_pct']) . "</td>";
			if ($isAdmin) {
				$procs = json_decode((string)$r['top_procs'], true);
				$html .= "<td style='text-align:center;'>" . sv_fmt_rate($r['net_in_bytes_s']) . "</td>"
					. "<td style='text-align:center;'>" . sv_fmt_rate($r['net_out_bytes_s']) . "</td>"
					. "<td>" . sv_proc_list(isset($procs['cpu']) ? $procs['cpu'] : array(), true) . "</td>"
					. "<td>" . sv_proc_list(isset($procs['ram']) ? $procs['ram'] : array(), false) . "</td>";
			}
			$html .= "</tr>";
		}
		return $html . "</table>";
	}

	// Older than the raw window: whole-hour rollups that overlap the window.
	$rows = $db->statusHistoryRows("SELECT * FROM `{$p}status_hour` WHERE remote_server_id = $sid AND bucket_start < $to AND bucket_start + 3600 > $from ORDER BY bucket_start");
	if (count($rows) === 0) return $html . "<p>No stored reports in this window.</p>";
	$html .= "<p><small>This moment is older than the raw window, so hourly summaries are shown.</small></p>"
		. "<table style='width:100%;border-collapse:collapse;'><tr><th>Hour starting</th><th>Availability</th><th>CPU peak</th><th>RAM peak</th><th>Disk peak</th>"
		. ($isAdmin ? "<th>Top CPU at peak</th><th>Top RAM at peak</th>" : '') . "</tr>";
	foreach ($rows as $r) {
		$avail = (int)$r['samples'] > 0 ? 100 * (int)$r['up_samples'] / (int)$r['samples'] : null;
		$html .= "<tr style='border-top:1px solid #ddd;'><td>" . sv_fmt_time($r['bucket_start'], $tz) . "</td>"
			. "<td style='text-align:center;'>" . sv_fmt_pct($avail) . "</td>"
			. "<td style='text-align:center;'>" . sv_peak_cell($r['cpu_max'], $r['cpu_max_at'], $tz) . "</td>"
			. "<td style='text-align:center;'>" . sv_peak_cell($r['ram_max'], $r['ram_max_at'], $tz) . "</td>"
			. "<td style='text-align:center;'>" . sv_peak_cell($r['disk_max'], $r['disk_max_at'], $tz) . "</td>";
		if ($isAdmin) {
			$cpu = json_decode((string)(isset($r['peak_cpu_procs']) ? $r['peak_cpu_procs'] : ''), true);
			$ram = json_decode((string)(isset($r['peak_ram_procs']) ? $r['peak_ram_procs'] : ''), true);
			$html .= "<td>" . sv_proc_list(isset($cpu['cpu']) ? $cpu['cpu'] : array(), true) . "</td>"
				. "<td>" . sv_proc_list(isset($ram['ram']) ? $ram['ram'] : array(), false) . "</td>";
		}
		$html .= "</tr>";
	}
	return $html . "</table>";
}

/** Admin only: the top processes stored at a moment. CPU values are percentages; RAM values are bytes. */
function sv_proc_list($list, $is_cpu)
{
	if (!is_array($list) || count($list) === 0) return '-';
	$parts = array();
	foreach (array_slice($list, 0, 5) as $proc) {
		$name = htmlspecialchars((string)$proc['name']);
		$value = $is_cpu ? number_format(round((float)$proc['value']), 0) . '%' : number_format((float)$proc['value'] / 1048576, 0) . ' MB';
		$parts[] = "$name ($value)";
	}
	return implode('<br>', $parts);
}
