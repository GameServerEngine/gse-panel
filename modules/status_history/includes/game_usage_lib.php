<?php
/*
 * Game usage: CPU, RAM and disk of every game server, and of Docker containers the panel does not
 * manage, read from the agents' status_sample reply, plus their hourly and daily rollups.
 * Pure helpers: no database access, so the poller and the tests use the same arithmetic.
 *
 * A series is one game server (home_id > 0, label '') or one container (home_id 0, label = name).
 * CPU is kept as cores in use (percent of the machine x cores / 100), so the numbers for one game
 * can be compared between machines with different core counts. It is also kept in GRU (cores in use
 * x the machine's single-core score CPU-S, see agent_cpu_score), which does not depend on how fast the
 * machine's cores are. The busiest thread (main_thread_*) shows whether a slower core keeps up:
 * most game servers do their work on one main thread. See docs/hardware-neutral-game-usage.md.
 */

/** Columns of one game_sample row, in table order. */
function gu_sample_columns()
{
	return array(
		'remote_server_id', 'sampled_at', 'home_id', 'label', 'game_key', 'process', 'state',
		'cpu_pct', 'cpu_cores', 'ram_b', 'disk_b', 'disk_at',
		'main_thread_cores', 'cpu_gru', 'main_thread_gru',
	);
}

/** Columns of one game_hour or game_day row, in table order. */
function gu_bucket_columns()
{
	return array(
		'remote_server_id', 'home_id', 'label', 'bucket_start', 'game_key', 'process',
		'samples', 'running_samples',
		'cpu_cores_avg', 'cpu_cores_max', 'cpu_max_at',
		'ram_avg_b', 'ram_max_b', 'ram_max_at', 'disk_max_b',
		'main_thread_cores_max', 'cpu_gru_avg', 'cpu_gru_max', 'main_thread_gru_max',
	);
}

/** Text from the agent, kept to letters, digits and a few safe characters. */
function gu_text($value)
{
	return substr(preg_replace('/[^A-Za-z0-9._:\/ -]/', '', (string)$value), 0, 60);
}

/**
 * One home.<id> value: state|cpu|ram|disk|disk_at, and from newer agents |main_thread (the busiest
 * thread, in cores). Null when it cannot be read.
 */
function gu_parse_home($home_id, $value)
{
	$parts = explode('|', (string)$value);
	if ((count($parts) !== 5 && count($parts) !== 6) || !is_numeric($parts[1]) || !is_numeric($parts[2])) {
		return null;
	}
	return array(
		'home_id' => (int)$home_id,
		'state' => gu_text($parts[0]),
		'cpu_pct' => (float)$parts[1],
		'ram_b' => (int)$parts[2],
		'disk_b' => is_numeric($parts[3]) ? (int)$parts[3] : null,
		'disk_at' => is_numeric($parts[4]) ? (int)$parts[4] : null,
		'main_thread_cores' => (isset($parts[5]) && is_numeric($parts[5])) ? (float)$parts[5] : null,
	);
}

/**
 * One container.<n> value: name|image|cpu|ram|process. Null when it cannot be read.
 */
function gu_parse_container($value)
{
	$parts = explode('|', (string)$value);
	if (count($parts) !== 5 || gu_text($parts[0]) === '' || !is_numeric($parts[2]) || !is_numeric($parts[3])) {
		return null;
	}
	return array(
		'name' => gu_text($parts[0]),
		'image' => gu_text($parts[1]),
		'cpu_pct' => (float)$parts[2],
		'ram_b' => (int)$parts[3],
		'process' => gu_text($parts[4]),
	);
}

/** Cores in use from a percent of the machine. Null when the core count is unknown. */
function gu_cores($cpu_pct, $cores)
{
	return $cores > 0 ? round($cpu_pct * $cores / 100, 3) : null;
}

/** Cores in use as GRU: cores x the machine's single-core score. Null when either is unknown. */
function gu_gru($cores, $cpu_s)
{
	return ($cores !== null && $cpu_s !== null && $cpu_s > 0) ? round((float)$cores * (float)$cpu_s, 2) : null;
}

/**
 * The game_sample rows for one agent reply.
 * $game_keys maps home id => game_key, from the panel's server list.
 * $cpu_s is the agent's single-core score (agent_cpu_score), or null when it has not been measured.
 */
function gu_build_rows(array $reply, $remote_server_id, $now, array $game_keys, $cpu_s = null)
{
	$cores = (isset($reply['cores']) && is_numeric($reply['cores'])) ? (float)$reply['cores'] : 0;
	$rows = array();
	foreach ((array)(isset($reply['homes']) ? $reply['homes'] : array()) as $h) {
		$row = array_fill_keys(gu_sample_columns(), null);
		$row['remote_server_id'] = (int)$remote_server_id;
		$row['sampled_at'] = (int)$now;
		$row['home_id'] = $h['home_id'];
		$row['label'] = '';
		$row['game_key'] = isset($game_keys[$h['home_id']]) ? (string)$game_keys[$h['home_id']] : '';
		$row['process'] = '';
		$row['state'] = $h['state'];
		$row['cpu_pct'] = $h['cpu_pct'];
		$row['cpu_cores'] = gu_cores($h['cpu_pct'], $cores);
		$row['ram_b'] = $h['ram_b'];
		$row['disk_b'] = $h['disk_b'];
		$row['disk_at'] = $h['disk_at'];
		$row['main_thread_cores'] = isset($h['main_thread_cores']) ? $h['main_thread_cores'] : null;
		$row['cpu_gru'] = gu_gru($row['cpu_cores'], $cpu_s);
		$row['main_thread_gru'] = gu_gru($row['main_thread_cores'], $cpu_s);
		$rows[] = $row;
	}
	foreach ((array)(isset($reply['containers']) ? $reply['containers'] : array()) as $c) {
		$row = array_fill_keys(gu_sample_columns(), null);
		$row['remote_server_id'] = (int)$remote_server_id;
		$row['sampled_at'] = (int)$now;
		$row['home_id'] = 0;
		$row['label'] = $c['name'];
		$row['game_key'] = '';
		$row['process'] = $c['process'];
		// A container is only reported while it runs.
		$row['state'] = 'RUNNING';
		$row['cpu_pct'] = $c['cpu_pct'];
		$row['cpu_cores'] = gu_cores($c['cpu_pct'], $cores);
		$row['ram_b'] = $c['ram_b'];
		$row['cpu_gru'] = gu_gru($row['cpu_cores'], $cpu_s);
		$rows[] = $row;
	}
	return $rows;
}

/** The key of the series a sample or rollup row belongs to. */
function gu_series_key(array $row)
{
	return (int)$row['remote_server_id'] . '|' . (int)$row['home_id'] . '|' . (string)$row['label'];
}

/** An empty rollup row for one series and bucket, named from the newest row of the series. */
function gu_empty_bucket(array $newest, $bucket_start)
{
	$row = array_fill_keys(gu_bucket_columns(), null);
	$row['remote_server_id'] = (int)$newest['remote_server_id'];
	$row['home_id'] = (int)$newest['home_id'];
	$row['label'] = (string)$newest['label'];
	$row['bucket_start'] = (int)$bucket_start;
	$row['game_key'] = (string)$newest['game_key'];
	$row['process'] = (string)$newest['process'];
	$row['samples'] = 0;
	$row['running_samples'] = 0;
	return $row;
}

/**
 * Rolls the samples of one series for one clock hour into an hour row.
 * CPU and RAM average and peak are over the samples where the server was RUNNING: capacity planning
 * needs what a game uses while it runs, not an average that includes the hours it was stopped.
 * Disk is the largest size seen in the hour, running or not.
 */
function gu_rollup_samples(array $samples, $bucket_start)
{
	usort($samples, function ($a, $b) {
		return (int)$a['sampled_at'] - (int)$b['sampled_at'];
	});
	$row = gu_empty_bucket($samples[count($samples) - 1], $bucket_start);
	$row['samples'] = count($samples);
	$cpuSum = 0.0;
	$cpuN = 0;
	$ramSum = 0.0;
	$ramN = 0;
	$gruSum = 0.0;
	$gruN = 0;
	foreach ($samples as $s) {
		if ($s['disk_b'] !== null && ($row['disk_max_b'] === null || (int)$s['disk_b'] > $row['disk_max_b'])) {
			$row['disk_max_b'] = (int)$s['disk_b'];
		}
		if ($s['state'] !== 'RUNNING') {
			continue;
		}
		$row['running_samples']++;
		if ($s['cpu_cores'] !== null) {
			$v = (float)$s['cpu_cores'];
			$cpuSum += $v;
			$cpuN++;
			if ($row['cpu_cores_max'] === null || $v > $row['cpu_cores_max']) {
				$row['cpu_cores_max'] = $v;
				$row['cpu_max_at'] = (int)$s['sampled_at'];
			}
		}
		if ($s['ram_b'] !== null) {
			$v = (int)$s['ram_b'];
			$ramSum += $v;
			$ramN++;
			if ($row['ram_max_b'] === null || $v > $row['ram_max_b']) {
				$row['ram_max_b'] = $v;
				$row['ram_max_at'] = (int)$s['sampled_at'];
			}
		}
		if (isset($s['cpu_gru']) && $s['cpu_gru'] !== null) {
			$gruSum += (float)$s['cpu_gru'];
			$gruN++;
		}
		$row['cpu_gru_max'] = gu_max($row['cpu_gru_max'], isset($s['cpu_gru']) ? $s['cpu_gru'] : null);
		$row['main_thread_cores_max'] = gu_max($row['main_thread_cores_max'], isset($s['main_thread_cores']) ? $s['main_thread_cores'] : null);
		$row['main_thread_gru_max'] = gu_max($row['main_thread_gru_max'], isset($s['main_thread_gru']) ? $s['main_thread_gru'] : null);
	}
	$row['cpu_cores_avg'] = $cpuN > 0 ? round($cpuSum / $cpuN, 3) : null;
	$row['ram_avg_b'] = $ramN > 0 ? (int)round($ramSum / $ramN) : null;
	$row['cpu_gru_avg'] = $gruN > 0 ? round($gruSum / $gruN, 2) : null;
	return $row;
}

/**
 * Rolls the hour rows of one series for one local day into a day row.
 * Averages are weighted by running samples. Peaks come from the hour that had them.
 */
function gu_rollup_hours(array $hours, $day_start)
{
	usort($hours, function ($a, $b) {
		return (int)$a['bucket_start'] - (int)$b['bucket_start'];
	});
	$row = gu_empty_bucket($hours[count($hours) - 1], $day_start);
	$cpuSum = 0.0;
	$cpuW = 0;
	$ramSum = 0.0;
	$ramW = 0;
	$gruSum = 0.0;
	$gruW = 0;
	foreach ($hours as $h) {
		$row['samples'] += (int)$h['samples'];
		$running = (int)$h['running_samples'];
		$row['running_samples'] += $running;
		if ($h['cpu_cores_avg'] !== null && $running > 0) {
			$cpuSum += (float)$h['cpu_cores_avg'] * $running;
			$cpuW += $running;
		}
		if ($h['ram_avg_b'] !== null && $running > 0) {
			$ramSum += (float)$h['ram_avg_b'] * $running;
			$ramW += $running;
		}
		if ($h['cpu_cores_max'] !== null && ($row['cpu_cores_max'] === null || (float)$h['cpu_cores_max'] > $row['cpu_cores_max'])) {
			$row['cpu_cores_max'] = (float)$h['cpu_cores_max'];
			$row['cpu_max_at'] = (int)$h['cpu_max_at'];
		}
		if ($h['ram_max_b'] !== null && ($row['ram_max_b'] === null || (int)$h['ram_max_b'] > $row['ram_max_b'])) {
			$row['ram_max_b'] = (int)$h['ram_max_b'];
			$row['ram_max_at'] = (int)$h['ram_max_at'];
		}
		if ($h['disk_max_b'] !== null && ($row['disk_max_b'] === null || (int)$h['disk_max_b'] > $row['disk_max_b'])) {
			$row['disk_max_b'] = (int)$h['disk_max_b'];
		}
		if (isset($h['cpu_gru_avg']) && $h['cpu_gru_avg'] !== null && $running > 0) {
			$gruSum += (float)$h['cpu_gru_avg'] * $running;
			$gruW += $running;
		}
		foreach (array('cpu_gru_max', 'main_thread_cores_max', 'main_thread_gru_max') as $col) {
			$row[$col] = gu_max($row[$col], isset($h[$col]) ? $h[$col] : null);
		}
	}
	$row['cpu_cores_avg'] = $cpuW > 0 ? round($cpuSum / $cpuW, 3) : null;
	$row['ram_avg_b'] = $ramW > 0 ? (int)round($ramSum / $ramW) : null;
	$row['cpu_gru_avg'] = $gruW > 0 ? round($gruSum / $gruW, 2) : null;
	return $row;
}

/** The larger of two values where null means unknown. */
function gu_max($current, $value)
{
	if ($value === null) {
		return $current;
	}
	return ($current === null || (float)$value > (float)$current) ? (float)$value : $current;
}
