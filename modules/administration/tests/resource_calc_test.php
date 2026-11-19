<?php
// Bandwidth and storage calculator tests: formulas, limits, bad input, page rendering. Run: php modules/administration/tests/resource_calc_test.php
require_once dirname(__DIR__, 3) . '/includes/tests/diag_test_helper.php';
require_once dirname(__DIR__) . '/resource_calc.php';

function near($a, $b) { return abs($a - $b) < 0.01; }

// --- bandwidth by hand: 2 servers x 20 players x 40 kbps x 1.1 / 1000 = 1.76 Mbit/s peak out
list($v, $errors) = resource_read(array('servers' => '2', 'players' => '20', 'kbps' => '40', 'overhead' => '10', 'inbound' => '50', 'occupancy' => '50', 'hours' => '10', 'days' => '30', 'burst' => '2'), bandwidth_spec(resource_profile('generic')));
$r = bandwidth_calculate($v);
check('bandwidth: no input errors', $errors === array());
check('bandwidth: peak outbound 1.76 Mbit/s', near($r['peak_out_mbps'], 1.76));
check('bandwidth: peak inbound is the share of outbound', near($r['peak_in_mbps'], 0.88));
check('bandwidth: recommended = larger peak x burst', near($r['recommended_mbps'], 3.5));
// average out = 0.88 Mbit/s; GB = 0.88 * 3600 * 10 * 30 / 8 / 1000 = 118.8
check('bandwidth: monthly outbound GB', near($r['monthly_out_gb'], 118.8));
check('bandwidth: monthly total = out + in', near($r['monthly_total_gb'], 118.8 + 59.4));
check('bandwidth: formulas are shown with the numbers', strpos(implode("\n", $r['formulas']), '2 x 20 x 40 x 1.1 / 1000 = 1.76') !== false && count($r['formulas']) >= 5);

list($v) = resource_read(array('hours' => '0'), bandwidth_spec(resource_profile('generic')));
check('bandwidth: zero hours means zero monthly traffic', bandwidth_calculate($v)['monthly_total_gb'] === 0.0);
list($v) = resource_read(array(), bandwidth_spec(resource_profile('cstrike')));
check('bandwidth: game profile fills defaults (CS 1.6: 24 players, 20 kbps)', $v['players'] == 24 && $v['kbps'] == 20);
check('bandwidth: unknown game falls back to the generic profile', resource_profile('no_such_game')['label'] === 'Generic game server');

// --- storage by hand: data 4+1+0.5 = 5.5; logs 0.5*3 = 1.5; backups 5.5*0.6*3 = 9.9; per server 16.9; x2 = 33.8; +25% = 42.25
list($v, $errors) = resource_read(array('servers' => '2', 'install_gb' => '4', 'mods_gb' => '1', 'db_gb' => '0.5', 'log_gb_month' => '0.5', 'log_months' => '3', 'backups' => '3', 'compression' => '60', 'growth' => '25'), storage_spec(resource_profile('generic')));
$r = storage_calculate($v);
check('storage: live data per server', near($r['live_gb'], 7.0));
check('storage: backups per server', near($r['backups_gb'], 9.9));
check('storage: per server', near($r['per_server_gb'], 16.9));
check('storage: all servers', near($r['total_gb'], 33.8));
check('storage: with growth margin', near($r['with_growth_gb'], 42.3));
check('storage: formulas shown', strpos(implode("\n", $r['formulas']), '5.5 x 0.6 x 3 = 9.9') !== false);
list($v) = resource_read(array('backups' => '0', 'growth' => '0', 'servers' => '1', 'install_gb' => '10', 'mods_gb' => '0', 'db_gb' => '0', 'log_gb_month' => '0'), storage_spec(resource_profile('generic')));
check('storage: no backups and no growth gives just the install', near(storage_calculate($v)['with_growth_gb'], 10.0));

// --- bad input: out of range, text, negative, injection text. Defaults are used and an error is reported.
foreach (array('abc', '-5', '999999999', '1e99', "5; DROP TABLE", '<script>') as $bad) {
	list($v, $errors) = resource_read(array('players' => $bad), bandwidth_spec(resource_profile('generic')));
	check('bad input refused: ' . substr($bad, 0, 20), count($errors) === 1 && $v['players'] === 16 && is_numeric($v['players']));
}
list($v, $errors) = resource_read(array('players' => ''), bandwidth_spec(resource_profile('generic')));
check('blank input uses the default without an error', $errors === array() && $v['players'] === 16);
list($v, $errors) = resource_read(array('occupancy' => '0'), bandwidth_spec(resource_profile('generic')));
check('occupancy of 0 is out of range (min 1)', count($errors) === 1);
check('all calculator results are finite numbers', is_finite(bandwidth_calculate(resource_read(array('servers' => '1000', 'players' => '1000', 'kbps' => '5000', 'overhead' => '200', 'hours' => '24', 'days' => '31'), bandwidth_spec(resource_profile('generic')))[0])['monthly_total_gb']));
foreach (resource_profiles() as $key => $profile) {
	check("profile $key is complete and positive", $profile['kbps'] > 0 && $profile['install_gb'] > 0 && $profile['players'] > 0 && $profile['label'] !== '');
}

// --- the page
$cmd = 'php -r ' . escapeshellarg('
chdir(' . var_export(dirname(__DIR__, 3), true) . ');
class D { function getGameCfgs() { return array(array("game_key" => "cstrike", "game_name" => "Counter-Strike"), array("game_key" => "odd<game>", "game_name" => "Odd <b>Game</b>")); } }
$db = new D;
$_GET = array("m" => "administration", "p" => "resource_planner", "calc" => "bandwidth", "game" => "cstrike", "players" => "10", "kbps" => "20", "hours" => "abc");
include "modules/administration/resource_planner.php";
exec_module();') . ' 2>/dev/null';
$html = shell_exec($cmd);
check('page: both calculators and the not-measured warning', strpos($html, 'Bandwidth Calculator') !== false && strpos($html, 'Storage Calculator') !== false && strpos($html, 'not measured usage') !== false);
check('page: result and formulas shown', strpos($html, 'Estimated bandwidth') !== false && strpos($html, 'Formulas') !== false);
check('page: input error reported, default used', strpos($html, 'Hours per day with players must be a number') !== false);
check('page: game names from game configs, escaped', strpos($html, 'Counter-Strike') !== false && strpos($html, '<b>Game</b>') === false && strpos($html, 'Odd &lt;b&gt;Game') !== false);
check('page: storage section shows no result until submitted', strpos($html, 'Estimated storage') === false);
echo "All resource calculator tests passed\n";
