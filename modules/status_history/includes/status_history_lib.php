<?php
/*
 * Status history: pure helpers for the agent resource samples.
 * No database access in this file, so the poller and the tests use the same arithmetic.
 *
 * Every percentage is a share of the whole machine: CPU of all cores together, RAM of total
 * RAM, disk of the root filesystem's total size. Absolute numbers are kept in the sample rows
 * for admins only; the status pages show percentages.
 */

/** Settings with their defaults. Keys in the panel settings table are status_history_<key>. */
function sh_default_settings()
{
	return array(
		'enabled' => 1,
		'interval_seconds' => 30,
		'raw_hours' => 6,
		'hourly_days' => 7,
		'daily_days' => 365,
	);
}

function sh_clamp_int($value, $min, $max, $default)
{
	if ($value === null || $value === '' || !is_numeric($value)) {
		return $default;
	}
	return max($min, min($max, (int)$value));
}

/** Reads the status_history_* keys from the panel settings; anything missing or out of range takes the default. */
function sh_settings_from_rows(array $settings)
{
	$d = sh_default_settings();
	$get = function ($key) use ($settings) {
		$name = 'status_history_' . $key;
		return isset($settings[$name]) ? $settings[$name] : null;
	};
	return array(
		'enabled' => sh_clamp_int($get('enabled'), 0, 1, $d['enabled']),
		'interval_seconds' => sh_clamp_int($get('interval_seconds'), 10, 3600, $d['interval_seconds']),
		'raw_hours' => sh_clamp_int($get('raw_hours'), 1, 48, $d['raw_hours']),
		'hourly_days' => sh_clamp_int($get('hourly_days'), 1, 90, $d['hourly_days']),
		'daily_days' => sh_clamp_int($get('daily_days'), 30, 3650, $d['daily_days']),
	);
}

/** A used amount as a percent of its total. Null when the total is unknown. */
function sh_pct($used, $total)
{
	if ($used === null || $total === null || (float)$total <= 0) {
		return null;
	}
	return round(100 * (float)$used / (float)$total, 2);
}

/**
 * CPU percent of the whole machine between two cumulative /proc/stat readings.
 * Null on the first sample or when the counters went backwards (reboot).
 */
function sh_cpu_pct(array $prev, array $cur)
{
	foreach (array('cpu_total_j', 'cpu_idle_j') as $key) {
		if (!isset($prev[$key], $cur[$key])) {
			return null;
		}
	}
	$dTotal = (float)$cur['cpu_total_j'] - (float)$prev['cpu_total_j'];
	$dIdle = (float)$cur['cpu_idle_j'] - (float)$prev['cpu_idle_j'];
	if ($dTotal <= 0 || $dIdle < 0 || $dIdle > $dTotal) {
		return null;
	}
	return round(100 * ($dTotal - $dIdle) / $dTotal, 2);
}

/** Bytes per second from two cumulative byte counters. Null when a counter went backwards. */
function sh_rate_bytes_s($prev_total, $cur_total, $seconds)
{
	if ($prev_total === null || $cur_total === null || $seconds <= 0) {
		return null;
	}
	$delta = (float)$cur_total - (float)$prev_total;
	if ($delta < 0) {
		return null;
	}
	return (int)round($delta / $seconds);
}

/**
 * Reads the agent's status_sample reply.
 * Returns the fields as an array, or null when the reply is not a good sample.
 * Top processes come back as top_cpu and top_ram lists of array(pid, name, value).
 */
function sh_parse_agent_reply($reply)
{
	if (!is_string($reply) || $reply === '') {
		return null;
	}
	$semi = strpos($reply, ';');
	if ($semi === false || substr($reply, 0, $semi) !== '1') {
		return null;
	}
	$fields = array('top_cpu' => array(), 'top_ram' => array());
	$lines = preg_split('/\r?\n/', substr($reply, $semi + 1));
	foreach ($lines as $line) {
		$eq = strpos($line, '=');
		if ($eq === false) {
			continue;
		}
		$key = trim(substr($line, 0, $eq));
		$value = trim(substr($line, $eq + 1));
		if (preg_match('/^proc_(cpu|ram)\.([1-5])$/', $key, $m)) {
			$parts = explode('|', $value, 3);
			if (count($parts) === 3) {
				$name = preg_replace('/[^A-Za-z0-9._ -]/', '', $parts[1]);
				$fields['top_' . $m[1]][] = array(
					'pid' => (int)$parts[0],
					'name' => substr($name, 0, 60),
					'value' => (float)$parts[2],
				);
			}
			continue;
		}
		$fields[$key] = $value;
	}
	return $fields;
}

/** Number or null, for values read from the agent or the database. */
function sh_num($value)
{
	if ($value === null || $value === '' || !is_numeric($value)) {
		return null;
	}
	return $value + 0;
}

/** Columns of one sample row, in table order. */
function sh_sample_columns()
{
	return array(
		'remote_server_id', 'sampled_at', 'is_up', 'status_code', 'cores',
		'cpu_pct', 'ram_pct', 'disk_pct', 'net_in_bytes_s', 'net_out_bytes_s', 'load1', 'uptime_s',
		'cpu_total_j', 'cpu_idle_j', 'net_rx_total', 'net_tx_total',
		'mem_total_b', 'mem_used_b', 'disk_total_b', 'disk_used_b', 'top_procs',
	);
}

/** A sample row for a server that did not answer, or answered with something we cannot read. */
function sh_down_sample($now, $status_code)
{
	$row = array_fill_keys(sh_sample_columns(), null);
	$row['sampled_at'] = (int)$now;
	$row['is_up'] = 0;
	$row['status_code'] = $status_code;
	return $row;
}

/**
 * A sample row from a good agent reply.
 * $prev is the server's previous sample row (or null). Rates and CPU percent need it.
 * A previous sample that was down gives no rate, so the first sample after an outage is not a spike.
 */
function sh_build_sample(array $reply, $now, $prev)
{
	$row = array_fill_keys(sh_sample_columns(), null);
	$row['sampled_at'] = (int)$now;
	$row['is_up'] = 1;
	$row['status_code'] = 'ok';
	$row['cores'] = sh_num(isset($reply['cores']) ? $reply['cores'] : null);
	$row['uptime_s'] = sh_num(isset($reply['uptime']) ? $reply['uptime'] : null);
	$row['load1'] = sh_num(isset($reply['load1']) ? $reply['load1'] : null);
	$row['cpu_total_j'] = sh_num(isset($reply['cpu_total']) ? $reply['cpu_total'] : null);
	$row['cpu_idle_j'] = sh_num(isset($reply['cpu_idle']) ? $reply['cpu_idle'] : null);
	$row['net_rx_total'] = sh_num(isset($reply['net_rx']) ? $reply['net_rx'] : null);
	$row['net_tx_total'] = sh_num(isset($reply['net_tx']) ? $reply['net_tx'] : null);
	$row['mem_total_b'] = sh_num(isset($reply['mem_total']) ? $reply['mem_total'] : null);
	$row['mem_used_b'] = sh_num(isset($reply['mem_used']) ? $reply['mem_used'] : null);
	$row['disk_total_b'] = sh_num(isset($reply['disk_total']) ? $reply['disk_total'] : null);
	$row['disk_used_b'] = sh_num(isset($reply['disk_used']) ? $reply['disk_used'] : null);

	$usePrev = is_array($prev) && (int)$prev['is_up'] === 1;
	$seconds = $usePrev ? (int)$now - (int)$prev['sampled_at'] : 0;
	$row['cpu_pct'] = $usePrev ? sh_cpu_pct($prev, $row) : null;
	$row['net_in_bytes_s'] = $usePrev ? sh_rate_bytes_s($prev['net_rx_total'], $row['net_rx_total'], $seconds) : null;
	$row['net_out_bytes_s'] = $usePrev ? sh_rate_bytes_s($prev['net_tx_total'], $row['net_tx_total'], $seconds) : null;
	$row['ram_pct'] = sh_pct($row['mem_used_b'], $row['mem_total_b']);
	$row['disk_pct'] = sh_pct($row['disk_used_b'], $row['disk_total_b']);
	$row['top_procs'] = json_encode(array(
		'cpu' => isset($reply['top_cpu']) ? $reply['top_cpu'] : array(),
		'ram' => isset($reply['top_ram']) ? $reply['top_ram'] : array(),
	));
	return $row;
}

/** Columns of an hour or day rollup row, in table order. */
function sh_bucket_columns()
{
	return array(
		'remote_server_id', 'bucket_start', 'samples', 'up_samples',
		'cpu_avg', 'cpu_max', 'cpu_max_at',
		'ram_avg', 'ram_max', 'ram_max_at',
		'disk_avg', 'disk_max', 'disk_max_at',
		'net_in_avg', 'net_in_max', 'net_in_max_at',
		'net_out_avg', 'net_out_max', 'net_out_max_at',
		'load_max', 'load_max_at', 'uptime_end',
		'peak_cpu_procs', 'peak_ram_procs',
	);
}

function sh_empty_bucket($start)
{
	$row = array_fill_keys(sh_bucket_columns(), null);
	$row['bucket_start'] = (int)$start;
	$row['samples'] = 0;
	$row['up_samples'] = 0;
	return $row;
}

/** The metrics that get avg, max and time of max. */
function sh_metric_names()
{
	return array(
		'cpu' => 'cpu_pct',
		'ram' => 'ram_pct',
		'disk' => 'disk_pct',
		'net_in' => 'net_in_bytes_s',
		'net_out' => 'net_out_bytes_s',
	);
}

/**
 * Rolls one server's samples for one clock hour into an hour row.
 * Averages are over the up samples that have a value. Each max keeps the time it happened.
 * The top processes are kept for the sample where CPU peaked and the one where RAM peaked.
 */
function sh_rollup_samples(array $samples, $bucket_start)
{
	usort($samples, function ($a, $b) {
		return (int)$a['sampled_at'] - (int)$b['sampled_at'];
	});
	$row = sh_empty_bucket($bucket_start);
	$row['samples'] = count($samples);

	foreach ($samples as $s) {
		if ((int)$s['is_up'] === 1) {
			$row['up_samples']++;
		}
	}

	foreach (sh_metric_names() as $name => $column) {
		$sum = 0.0;
		$n = 0;
		$max = null;
		$maxAt = null;
		$maxSample = null;
		foreach ($samples as $s) {
			if ((int)$s['is_up'] !== 1 || $s[$column] === null) {
				continue;
			}
			$v = (float)$s[$column];
			$sum += $v;
			$n++;
			if ($max === null || $v > $max) {
				$max = $v;
				$maxAt = (int)$s['sampled_at'];
				$maxSample = $s;
			}
		}
		if ($n > 0) {
			$row[$name . '_avg'] = round($sum / $n, 2);
			$row[$name . '_max'] = round($max, 2);
			$row[$name . '_max_at'] = $maxAt;
		}
		if ($maxSample !== null && $name === 'cpu') {
			$row['peak_cpu_procs'] = $maxSample['top_procs'];
		}
		if ($maxSample !== null && $name === 'ram') {
			$row['peak_ram_procs'] = $maxSample['top_procs'];
		}
	}

	$loadMax = null;
	$loadAt = null;
	$last = null;
	foreach ($samples as $s) {
		if ((int)$s['is_up'] !== 1) {
			continue;
		}
		$last = $s;
		if ($s['load1'] !== null && ($loadMax === null || (float)$s['load1'] > $loadMax)) {
			$loadMax = (float)$s['load1'];
			$loadAt = (int)$s['sampled_at'];
		}
	}
	$row['load_max'] = $loadMax;
	$row['load_max_at'] = $loadAt;
	$row['uptime_end'] = $last !== null ? $last['uptime_s'] : null;
	return $row;
}

/**
 * Rolls one server's hour rows for one local day into a day row.
 * Averages are weighted by up samples. Maxes come from the hour that had them.
 */
function sh_rollup_hours(array $hours, $day_start)
{
	usort($hours, function ($a, $b) {
		return (int)$a['bucket_start'] - (int)$b['bucket_start'];
	});
	$row = sh_empty_bucket($day_start);
	foreach ($hours as $h) {
		$row['samples'] += (int)$h['samples'];
		$row['up_samples'] += (int)$h['up_samples'];
	}

	foreach (sh_metric_names() as $name => $column) {
		$sum = 0.0;
		$weight = 0;
		$max = null;
		$maxAt = null;
		$maxHour = null;
		foreach ($hours as $h) {
			$w = (int)$h['up_samples'];
			if ($h[$name . '_avg'] !== null && $w > 0) {
				$sum += (float)$h[$name . '_avg'] * $w;
				$weight += $w;
			}
			if ($h[$name . '_max'] !== null && ($max === null || (float)$h[$name . '_max'] > $max)) {
				$max = (float)$h[$name . '_max'];
				$maxAt = (int)$h[$name . '_max_at'];
				$maxHour = $h;
			}
		}
		if ($weight > 0) {
			$row[$name . '_avg'] = round($sum / $weight, 2);
		}
		if ($max !== null) {
			$row[$name . '_max'] = round($max, 2);
			$row[$name . '_max_at'] = $maxAt;
		}
		if ($maxHour !== null && $name === 'cpu') {
			$row['peak_cpu_procs'] = $maxHour['peak_cpu_procs'];
		}
		if ($maxHour !== null && $name === 'ram') {
			$row['peak_ram_procs'] = $maxHour['peak_ram_procs'];
		}
	}

	$loadMax = null;
	$loadAt = null;
	foreach ($hours as $h) {
		if ($h['load_max'] !== null && ($loadMax === null || (float)$h['load_max'] > $loadMax)) {
			$loadMax = (float)$h['load_max'];
			$loadAt = (int)$h['load_max_at'];
		}
	}
	$row['load_max'] = $loadMax;
	$row['load_max_at'] = $loadAt;
	$last = count($hours) > 0 ? $hours[count($hours) - 1] : null;
	$row['uptime_end'] = $last !== null ? $last['uptime_end'] : null;
	return $row;
}

/** Start of the clock hour (UTC-aligned) that holds $ts. */
function sh_hour_start($ts)
{
	return (int)floor(((int)$ts) / 3600) * 3600;
}

/** Start of the local day that holds $ts, in the panel's time zone. */
function sh_day_start($ts, $timezone)
{
	$dt = new DateTime('@' . (int)$ts);
	$dt->setTimezone(new DateTimeZone($timezone));
	$dt->setTime(0, 0, 0);
	return $dt->getTimestamp();
}

/** Start of the local day after the one that starts at $day_start. */
function sh_next_day_start($day_start, $timezone)
{
	return sh_day_start((int)$day_start + 90000, $timezone);
}

/** The panel's time zone from the settings value, which may carry a label like "America/Chicago[CST -06:00]". */
function sh_panel_timezone($value)
{
	$zone = trim(preg_replace('/\[.*$/', '', (string)$value));
	if ($zone !== '' && in_array($zone, timezone_identifiers_list(), true)) {
		return $zone;
	}
	return 'America/Chicago';
}
