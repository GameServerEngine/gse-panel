<?php
// Tests for modules/status_history/includes/game_usage_lib.php and the reply parser (pure functions; no database needed).
require_once __DIR__ . '/../modules/status_history/includes/status_history_lib.php';

$failures = 0;
function gu_check($ok, $name)
{
    global $failures;
    echo ($ok ? 'ok' : 'FAIL') . ' - ' . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

// 1. The reply parser collects game servers and containers.
$reply = "1;\ncores=8\nproc_cpu.1=20344|DayZServer_x64|12.41\n"
    . "home.52=RUNNING|12.41|6665478144|5817261407|1795825562\n"
    . "home.49=STOPPED|0.00|0||\n"
    . "home.x=RUNNING|1|2|3|4\n"
    . "home.50=broken\n"
    . "container.1=valtest-leroy|ubuntu:24.04|5.98|1448652800|valheim_server.\n"
    . "container.2=|img|1|2|p\n";
$p = sh_parse_agent_reply($reply);
gu_check(count($p['homes']) === 2, 'two readable home lines are kept, the broken ones are dropped');
gu_check($p['homes'][0]['home_id'] === 52 && $p['homes'][0]['ram_b'] === 6665478144 && $p['homes'][0]['disk_b'] === 5817261407, 'home 52 values are read');
gu_check($p['homes'][1]['disk_b'] === null && $p['homes'][1]['disk_at'] === null, 'a home with no disk measurement yet has null disk');
gu_check(count($p['containers']) === 1 && $p['containers'][0]['name'] === 'valtest-leroy' && $p['containers'][0]['process'] === 'valheim_server.', 'the named container is kept, the nameless one is dropped');
gu_check(!isset($p['home.52']) && $p['cores'] === '8', 'home lines do not leak into the plain fields');
gu_check(count($p['top_cpu']) === 1, 'top processes are still read');

// 2. Rows: CPU in cores, game_key from the panel, containers as series with home_id 0.
$rows = gu_build_rows($p, 7, 1000, array(52 => 'dayz_win64'));
gu_check(count($rows) === 3, 'one row per home and container');
gu_check($rows[0]['cpu_cores'] === 0.993 && $rows[0]['game_key'] === 'dayz_win64', '12.41% of 8 cores is 0.993 cores; game_key is filled');
gu_check($rows[1]['game_key'] === '', 'a home the panel does not know has an empty game_key');
gu_check($rows[2]['home_id'] === 0 && $rows[2]['label'] === 'valtest-leroy' && $rows[2]['state'] === 'RUNNING', 'a container is a running series with home_id 0');
$noCores = gu_build_rows(array('homes' => $p['homes']), 7, 1000, array());
gu_check($noCores[0]['cpu_cores'] === null, 'unknown core count gives null cores, not zero');

// 3. Hour rollup: averages and peaks only over RUNNING samples; disk is the largest seen.
$base = array('remote_server_id' => 7, 'home_id' => 52, 'label' => '', 'game_key' => 'dayz_win64', 'process' => '');
$samples = array(
    $base + array('sampled_at' => 3700, 'state' => 'RUNNING', 'cpu_cores' => 1.0, 'ram_b' => 100, 'disk_b' => 50),
    $base + array('sampled_at' => 3600, 'state' => 'RUNNING', 'cpu_cores' => 3.0, 'ram_b' => 300, 'disk_b' => null),
    $base + array('sampled_at' => 3800, 'state' => 'STOPPED', 'cpu_cores' => 0.0, 'ram_b' => 0, 'disk_b' => 70),
);
$h = gu_rollup_samples($samples, 3600);
gu_check($h['samples'] === 3 && $h['running_samples'] === 2, 'three samples, two running');
gu_check($h['cpu_cores_avg'] == 2.0 && $h['cpu_cores_max'] == 3.0 && $h['cpu_max_at'] === 3600, 'CPU average and peak ignore the stopped sample');
gu_check($h['ram_avg_b'] === 200 && $h['ram_max_b'] === 300, 'RAM average and peak ignore the stopped sample');
gu_check($h['disk_max_b'] === 70, 'disk is the largest size seen, running or not');
gu_check($h['home_id'] === 52 && $h['bucket_start'] === 3600 && $h['game_key'] === 'dayz_win64', 'the hour row names its series');
$stopped = gu_rollup_samples(array($base + array('sampled_at' => 3600, 'state' => 'STOPPED', 'cpu_cores' => 0, 'ram_b' => 0, 'disk_b' => null)), 3600);
gu_check($stopped['running_samples'] === 0 && $stopped['cpu_cores_avg'] === null && $stopped['ram_max_b'] === null, 'an hour with no running sample has no CPU or RAM figures');

// 4. Day rollup: averages weighted by running samples, peaks from the peak hour.
$hours = array(
    $base + array('bucket_start' => 7200, 'samples' => 30, 'running_samples' => 30, 'cpu_cores_avg' => 1.0, 'cpu_cores_max' => 2.0, 'cpu_max_at' => 7300, 'ram_avg_b' => 100, 'ram_max_b' => 150, 'ram_max_at' => 7400, 'disk_max_b' => 10),
    $base + array('bucket_start' => 3600, 'samples' => 30, 'running_samples' => 10, 'cpu_cores_avg' => 3.0, 'cpu_cores_max' => 4.0, 'cpu_max_at' => 3700, 'ram_avg_b' => 500, 'ram_max_b' => 600, 'ram_max_at' => 3800, 'disk_max_b' => 20),
);
$d = gu_rollup_hours($hours, 0);
gu_check($d['samples'] === 60 && $d['running_samples'] === 40, 'samples and running samples add up');
gu_check($d['cpu_cores_avg'] == 1.5 && $d['ram_avg_b'] === 200, 'averages are weighted by running samples (30x1 + 10x3) / 40 = 1.5');
gu_check($d['cpu_cores_max'] == 4.0 && $d['cpu_max_at'] === 3700 && $d['ram_max_b'] === 600 && $d['disk_max_b'] === 20, 'peaks come from the hour that had them');

// 5. Series key separates game servers and containers on the same agent.
gu_check(gu_series_key($rows[0]) !== gu_series_key($rows[2]) && gu_series_key($rows[0]) === '7|52|', 'series keys are server|home|label');

echo $failures ? "\n$failures FAILED\n" : "\nall game usage tests passed\n";
exit($failures ? 1 : 0);
