<?php
/*
 * Bandwidth and storage planning calculators for the Administration area.
 * Pure functions: numbers in, numbers and the formula text out. No database, no network.
 * Every figure here is an ESTIMATE built from the assumptions the admin sees and can change.
 * It is not measured usage. Measure real servers (du, df, vnstat) before buying hardware.
 */

// Starting assumptions per game_key (the game_key of config_games). The admin can overwrite each number.
// kbps = typical server upload per connected player. install/mods in GB. These are rough planning
// values chosen for the form's defaults; they are not read from the game files.
function resource_profiles()
{
	$p = function ($label, $kbps, $install, $mods, $players) {
		return array('label' => $label, 'kbps' => $kbps, 'install_gb' => $install, 'mods_gb' => $mods, 'players' => $players);
	};
	return array(
		'generic' => $p('Generic game server', 30, 10, 2, 16),
		'cstrike' => $p('Counter-Strike 1.6 (GoldSrc)', 20, 1.5, 0.5, 24),
		'czero' => $p('Counter-Strike: Condition Zero (GoldSrc)', 20, 1.5, 0.5, 24),
		'counterstrike_source' => $p('Counter-Strike: Source', 45, 4, 1, 24),
		'csgo' => $p('Counter-Strike: Global Offensive / CS2', 60, 35, 3, 24),
		'garrysmod' => $p('Garry\'s Mod', 50, 6, 10, 24),
		'tf2' => $p('Team Fortress 2', 50, 18, 2, 24),
		'left4dead2' => $p('Left 4 Dead 2', 45, 13, 2, 8),
		'hl2dm' => $p('Half-Life 2: Deathmatch', 40, 3, 1, 16),
		'dods' => $p('Day of Defeat: Source', 40, 3, 1, 24),
	);
}

function resource_profile($game_key)
{
	$profiles = resource_profiles();
	return isset($profiles[$game_key]) ? $profiles[$game_key] : $profiles['generic'];
}

// Reads one number from the form with limits. Returns array(value, error message or '').
function resource_number($raw, $name, $min, $max, $default)
{
	if ($raw === null || trim((string)$raw) === '') {
		return array($default, '');
	}
	if (!is_numeric($raw) || (float)$raw < $min || (float)$raw > $max) {
		return array($default, $name . ' must be a number from ' . $min . ' to ' . $max . '.');
	}
	return array((float)$raw, '');
}

// Reads a list of fields: $spec name => array(label, min, max, default). Returns array(values, errors).
function resource_read($post, $spec)
{
	$values = array();
	$errors = array();
	foreach ($spec as $name => $rule) {
		list($values[$name], $error) = resource_number(isset($post[$name]) ? $post[$name] : null, $rule[0], $rule[1], $rule[2], $rule[3]);
		if ($error !== '') {
			$errors[] = $error;
		}
	}
	return array($values, $errors);
}

function bandwidth_spec($profile)
{
	return array(
		'servers' => array('Number of servers', 1, 1000, 1),
		'players' => array('Players per server (peak)', 1, 1000, $profile['players']),
		'kbps' => array('Upload per player (kbit/s)', 1, 5000, $profile['kbps']),
		'overhead' => array('Overhead (%)', 0, 200, 10),
		'inbound' => array('Inbound as % of outbound', 0, 200, 35),
		'occupancy' => array('Average players as % of peak', 1, 100, 50),
		'hours' => array('Hours per day with players', 0, 24, 12),
		'days' => array('Days per month', 1, 31, 30),
		'burst' => array('Burst margin (x)', 1, 10, 1.5),
	);
}

// $v: values from resource_read(). Returns array of results and the formulas used, with numbers filled in.
function bandwidth_calculate($v)
{
	$peak_out_mbps = $v['servers'] * $v['players'] * $v['kbps'] * (1 + $v['overhead'] / 100) / 1000;
	$peak_in_mbps = $peak_out_mbps * $v['inbound'] / 100;
	$avg_out_mbps = $peak_out_mbps * $v['occupancy'] / 100;
	$avg_in_mbps = $avg_out_mbps * $v['inbound'] / 100;
	$gb_out = $avg_out_mbps * 3600 * $v['hours'] * $v['days'] / 8 / 1000;
	$gb_in = $avg_in_mbps * 3600 * $v['hours'] * $v['days'] / 8 / 1000;
	return array(
		'peak_out_mbps' => round($peak_out_mbps, 2), 'peak_in_mbps' => round($peak_in_mbps, 2),
		'recommended_mbps' => round(max($peak_out_mbps, $peak_in_mbps) * $v['burst'], 1),
		'monthly_out_gb' => round($gb_out, 1), 'monthly_in_gb' => round($gb_in, 1), 'monthly_total_gb' => round($gb_out + $gb_in, 1),
		'formulas' => array(
			'Peak outbound (Mbit/s) = servers x players x kbit/s per player x (1 + overhead) / 1000 = '
				. $v['servers'] . ' x ' . $v['players'] . ' x ' . $v['kbps'] . ' x ' . (1 + $v['overhead'] / 100) . ' / 1000 = ' . round($peak_out_mbps, 2),
			'Peak inbound (Mbit/s) = peak outbound x inbound share = ' . round($peak_out_mbps, 2) . ' x ' . ($v['inbound'] / 100) . ' = ' . round($peak_in_mbps, 2),
			'Average outbound (Mbit/s) = peak outbound x average occupancy = ' . round($peak_out_mbps, 2) . ' x ' . ($v['occupancy'] / 100) . ' = ' . round($avg_out_mbps, 2),
			'Monthly outbound (GB) = average Mbit/s x 3600 x hours per day x days / 8 / 1000 = ' . round($avg_out_mbps, 2) . ' x 3600 x ' . $v['hours'] . ' x ' . $v['days'] . ' / 8 / 1000 = ' . round($gb_out, 1),
			'Recommended port speed (Mbit/s) = larger of peak outbound and inbound x burst margin = ' . round(max($peak_out_mbps, $peak_in_mbps), 2) . ' x ' . $v['burst'],
			'GB here are decimal gigabytes (1 GB = 1,000,000,000 bytes); 1 month = the days entered.',
		),
	);
}

function storage_spec($profile)
{
	return array(
		'servers' => array('Number of servers', 1, 1000, 1),
		'install_gb' => array('Game installation per server (GB)', 0, 100000, $profile['install_gb']),
		'mods_gb' => array('Mods, plugins and maps per server (GB)', 0, 100000, $profile['mods_gb']),
		'log_gb_month' => array('Logs per server per month (GB)', 0, 100000, 0.5),
		'log_months' => array('Months of logs kept', 0, 120, 3),
		'db_gb' => array('Databases per server (GB)', 0, 100000, 0.2),
		'backups' => array('Backups kept per server', 0, 365, 3),
		'compression' => array('Backup size as % of the data it covers', 1, 100, 60),
		'growth' => array('Growth margin (%)', 0, 500, 25),
	);
}

function storage_calculate($v)
{
	$data = $v['install_gb'] + $v['mods_gb'] + $v['db_gb'];
	$logs = $v['log_gb_month'] * $v['log_months'];
	$live = $data + $logs;
	$backup_one = $data * $v['compression'] / 100;
	$backups = $backup_one * $v['backups'];
	$per_server = $live + $backups;
	$total = $per_server * $v['servers'];
	$with_growth = $total * (1 + $v['growth'] / 100);
	return array(
		'live_gb' => round($live, 2), 'backups_gb' => round($backups, 2), 'per_server_gb' => round($per_server, 2),
		'total_gb' => round($total, 1), 'with_growth_gb' => round($with_growth, 1),
		'formulas' => array(
			'Data per server (GB) = installation + mods and maps + databases = ' . $v['install_gb'] . ' + ' . $v['mods_gb'] . ' + ' . $v['db_gb'] . ' = ' . round($data, 2),
			'Logs per server (GB) = GB per month x months kept = ' . $v['log_gb_month'] . ' x ' . $v['log_months'] . ' = ' . round($logs, 2),
			'Backups per server (GB) = data x backup size share x backups kept = ' . round($data, 2) . ' x ' . ($v['compression'] / 100) . ' x ' . $v['backups'] . ' = ' . round($backups, 2),
			'Per server (GB) = data + logs + backups = ' . round($per_server, 2),
			'Total with growth (GB) = per server x servers x (1 + growth margin) = ' . round($per_server, 2) . ' x ' . $v['servers'] . ' x ' . (1 + $v['growth'] / 100) . ' = ' . round($with_growth, 1),
			'GB here are decimal gigabytes. Game installs shared between servers (hard links, one shared install) are not modeled; lower "installation" to reflect that.',
		),
	);
}
