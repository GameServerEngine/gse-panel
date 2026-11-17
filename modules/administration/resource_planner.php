<?php
/*
 * Administration > Resource Planner: Bandwidth Calculator and Storage Calculator.
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

function exec_module()
{
	global $db;
	$games = planner_games($db);
	echo '<h2>Resource Planner</h2><p><a href="?m=administration">&laquo; Administration</a></p>';
	echo '<div class="warning"><strong>These are estimates, not measured usage.</strong> The starting numbers are rough planning values for each game. '
		. 'Replace them with what you measure on your own servers (for example with vnstat for traffic and du for disk space).</div>';
	planner_section('bandwidth', 'Bandwidth Calculator', 'bandwidth_spec', 'bandwidth_calculate', $games, 'planner_render_bandwidth');
	planner_section('storage', 'Storage Calculator', 'storage_spec', 'storage_calculate', $games, 'planner_render_storage');
}
