<?php
/*
 * Administration > Resource Planner: measured usage per game, Bandwidth Calculator and Storage Calculator.
 * Capacity estimates for planning agents and game offers. Read-only: nothing is saved, so the forms use GET.
 * The math is in resource_calc.php. Game names come from the panel's game configs (config_games).
 */
require_once 'modules/administration/resource_calc.php';

function planner_h($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// Game choices: every game config the panel has, plus "generic".
function planner_games($db)
{
	$games = array('generic' => 'Generic game server');
	foreach ((array)$db->getGameCfgs() as $cfg) {
		if (!empty($cfg['game_key']) && !isset($games[$cfg['game_key']])) {
			$games[$cfg['game_key']] = $cfg['game_name'];
		}
	}
	return $games;
}

function planner_game_select($games, $selected)
{
	echo '<div class="planner-field"><label for="game">Game type</label><select id="game" name="game">';
	foreach ($games as $key => $name) {
		$note = isset(resource_profiles()[$key]) ? '' : ' (generic values)';
		echo '<option value="' . planner_h($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . planner_h($name . $note) . '</option>';
	}
	echo '</select><small>Picking a game fills in starting values. Change any number to match your servers.</small></div>';
}

// The number inputs. Values already typed stay in the form.
function planner_fields($spec, $values, $submitted)
{
	foreach ($spec as $name => $rule) {
		$value = $submitted && isset($_GET[$name]) ? $_GET[$name] : $rule[3];
		echo '<div class="planner-field"><label for="' . $name . '">' . planner_h($rule[0]) . '</label>'
			. '<input type="number" step="any" min="' . $rule[1] . '" max="' . $rule[2] . '" id="' . $name . '" name="' . $name . '" value="' . planner_h($value) . '"></div>';
	}
}

function planner_results($title, $rows, $formulas)
{
	echo '<div class="success"><strong>' . planner_h($title) . '</strong><table class="planner-table">';
	foreach ($rows as $label => $value) {
		echo '<tr><th scope="row">' . planner_h($label) . '</th><td>' . planner_h($value) . '</td></tr>';
	}
	echo '</table></div><h4>Formulas</h4><ul>';
	foreach ($formulas as $formula) {
		echo '<li><code>' . planner_h($formula) . '</code></li>';
	}
	echo '</ul>';
}

function planner_section($id, $title, $spec_fn, $calc_fn, $games, $render_fn)
{
	// "Load game defaults" re-fills the numbers from the chosen game and shows no result.
	$submitted = isset($_GET['calc']) && $_GET['calc'] === $id && !isset($_GET['load_defaults']);
	$game = isset($_GET['calc']) && $_GET['calc'] === $id && isset($_GET['game']) ? (string)$_GET['game'] : 'generic';
	$spec = $spec_fn(resource_profile($game));
	echo '<section class="planner-section" id="' . $id . '"><h3>' . planner_h($title) . '</h3>';
	echo '<form method="get" action="" class="planner-form"><input type="hidden" name="m" value="administration"><input type="hidden" name="p" value="resource_planner"><input type="hidden" name="calc" value="' . $id . '">';
	planner_game_select($games, $game);
	planner_fields($spec, array(), $submitted);
	echo '<p><button type="submit">Calculate</button> <button type="submit" name="load_defaults" value="1">Load game defaults</button></p></form>';
	if ($submitted) {
		list($values, $errors) = resource_read($_GET, $spec);
		foreach ($errors as $error) {
			echo '<p class="failure">' . planner_h($error) . ' The default was used instead.</p>';
		}
		$result = $calc_fn($values);
		$render_fn($result);
	}
	echo '</section>';
}

function planner_render_bandwidth($r)
{
	planner_results('Estimated bandwidth', array(
		'Peak outbound' => $r['peak_out_mbps'] . ' Mbit/s', 'Peak inbound' => $r['peak_in_mbps'] . ' Mbit/s',
		'Recommended port speed' => $r['recommended_mbps'] . ' Mbit/s',
		'Monthly outbound' => $r['monthly_out_gb'] . ' GB', 'Monthly inbound' => $r['monthly_in_gb'] . ' GB', 'Monthly total' => $r['monthly_total_gb'] . ' GB',
	), $r['formulas']);
}

function planner_render_storage($r)
{
	planner_results('Estimated storage', array(
		'Live data per server (install, mods, databases, logs)' => $r['live_gb'] . ' GB', 'Backups per server' => $r['backups_gb'] . ' GB',
		'Per server' => $r['per_server_gb'] . ' GB', 'All servers' => $r['total_gb'] . ' GB', 'All servers with growth margin' => $r['with_growth_gb'] . ' GB',
	), $r['formulas']);
}

// Bytes as GB with one decimal, or a dash when unknown.
function planner_gb($bytes)
{
	return $bytes === null || $bytes === '' ? '-' : number_format($bytes / 1073741824, 1) . ' GB';
}

// Cores with two decimals, or a dash when unknown.
function planner_cores($cores)
{
	return $cores === null || $cores === '' ? '-' : number_format((float)$cores, 2);
}

// Measured usage of the last 7 days from the status history poller (game_hour), one row per game type
// and one per container the panel does not manage. Only hours in which the server was running count.
function planner_measured_rows($db)
{
	$since = time() - 7 * 86400;
	$rows = $db->resultQuery(
		"SELECT IF(g.home_id > 0, g.game_key, '') AS game_key, IF(g.home_id > 0, '', g.label) AS label, MAX(g.process) AS process,
		        COUNT(DISTINCT g.remote_server_id, g.home_id, g.label) AS servers, SUM(g.running_samples) AS running,
		        SUM(g.cpu_cores_avg * g.running_samples) / NULLIF(SUM(IF(g.cpu_cores_avg IS NULL, 0, g.running_samples)), 0) AS cpu_avg,
		        MAX(g.cpu_cores_max) AS cpu_max,
		        SUM(g.ram_avg_b * g.running_samples) / NULLIF(SUM(IF(g.ram_avg_b IS NULL, 0, g.running_samples)), 0) AS ram_avg,
		        MAX(g.ram_max_b) AS ram_max, MAX(g.disk_max_b) AS disk_max
		   FROM `DB_PREFIXgame_hour` g
		  WHERE g.bucket_start >= " . (int)$since . " AND g.running_samples > 0
		  GROUP BY IF(g.home_id > 0, g.game_key, ''), IF(g.home_id > 0, '', g.label)
		  ORDER BY game_key, label"
	);
	return is_array($rows) ? $rows : array();
}

// The billing minimum per game_key (Billing > services), to compare with what was measured.
function planner_minimums($db)
{
	$out = array();
	$rows = $db->resultQuery(
		"SELECT c.game_key, r.min_cpu_cores, r.min_ram_mb, r.min_disk_gb
		   FROM `DB_PREFIXconfig_homes` c JOIN `DB_PREFIXbilling_service_requirements` r ON r.home_cfg_id = c.home_cfg_id"
	);
	foreach ((array)$rows as $r) {
		$out[$r['game_key']] = $r;
	}
	return $out;
}

function planner_measured_usage($db, $games)
{
	echo '<section class="planner-section" id="measured"><h3>Measured usage (last 7 days)</h3>';
	echo '<p>What the game servers really used while they were running, from the status history samples. '
		. 'CPU is in cores, so a machine with more cores can host more of the same game. '
		. 'Numbers appear one hour after the agents start reporting.</p>';
	$rows = planner_measured_rows($db);
	if (count($rows) === 0) {
		echo '<p>No measurements yet.</p></section>';
		return;
	}
	$minimums = planner_minimums($db);
	echo '<div class="table-responsive"><table class="center"><tr><th>Game</th><th>Servers</th><th>Samples</th>'
		. '<th>CPU cores avg / peak</th><th>RAM avg / peak</th><th>Disk</th><th>Billing minimum CPU / RAM / disk</th></tr>';
	foreach ($rows as $r) {
		if ($r['game_key'] !== '') {
			$name = isset($games[$r['game_key']]) ? $games[$r['game_key']] : $r['game_key'];
			$min = isset($minimums[$r['game_key']]) ? $minimums[$r['game_key']] : null;
			$minText = $min === null ? 'not set' : planner_cores($min['min_cpu_cores']) . ' / ' . (int)$min['min_ram_mb'] . ' MB / ' . (int)$min['min_disk_gb'] . ' GB';
		} else {
			$name = 'Container ' . $r['label'] . ($r['process'] !== '' ? ' (' . $r['process'] . ')' : '');
			$minText = 'not a panel server';
		}
		echo '<tr><td>' . planner_h($name) . '</td><td>' . (int)$r['servers'] . '</td><td>' . (int)$r['running'] . '</td>'
			. '<td>' . planner_cores($r['cpu_avg']) . ' / ' . planner_cores($r['cpu_max']) . '</td>'
			. '<td>' . planner_gb($r['ram_avg']) . ' / ' . planner_gb($r['ram_max']) . '</td>'
			. '<td>' . planner_gb($r['disk_max']) . '</td><td>' . planner_h($minText) . '</td></tr>';
	}
	echo '</table></div></section>';
}

function exec_module()
{
	global $db;
	$games = planner_games($db);
	echo '<h2>Resource Planner</h2><p><a href="?m=administration">&laquo; Administration</a></p>';
	planner_measured_usage($db, $games);
	echo '<div class="warning"><strong>These are estimates, not measured usage.</strong> The starting numbers are rough planning values for each game. '
		. 'Replace them with what you measure on your own servers (for example with vnstat for traffic and du for disk space).</div>';
	planner_section('bandwidth', 'Bandwidth Calculator', 'bandwidth_spec', 'bandwidth_calculate', $games, 'planner_render_bandwidth');
	planner_section('storage', 'Storage Calculator', 'storage_spec', 'storage_calculate', $games, 'planner_render_storage');
}
