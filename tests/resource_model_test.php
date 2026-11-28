<?php
require_once dirname(__DIR__) . '/includes/resource_model.php';

function expect_resource($ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}

// Entirely synthetic operating envelopes. The CS2 footprint and available
// space are the operator's observations. No host benchmark is a real score.
function placement_fixture(int $now): array
{
    $gib = 1073741824;
    $host = ['version'=>2,'purpose'=>'public','platform'=>'linux','game_root'=>'/home/gameserver',
        'volume'=>'test-volume','fingerprint'=>'test-hardware','sampled_at'=>$now,'game_key'=>'cs2','release_build'=>'730:fixture','release_checked_at'=>$now,
        'install_floor'=>71106011864,'mem_total'=>8*$gib,'mem_available'=>7*$gib,'disk_total'=>150*$gib,
        'disk_available'=>129*$gib,'busy_cpus'=>0.3,'steal_fraction'=>0.01,'read_bps'=>1200,'write_bps'=>3200,
        'iops'=>4,'latency_ms'=>1];
    $c = ['version'=>2,'approved'=>true,'measured_at'=>$now,'method'=>'mock:paired-cpu-and-mixed-io',
        'evidence'=>'fixture','single_floor'=>100,'single_upper'=>110,'aggregate_floor'=>390,'aggregate_upper'=>400,
        'effective_cpus'=>6,'capacity_factor'=>0.9,'platform'=>'linux','volume'=>'test-volume','game_root'=>'/home/gameserver',
        'fingerprint'=>'test-hardware','io_headroom_pct'=>70,'read_bps'=>100000,'write_bps'=>80000,'iops'=>300,
        'latency_p99_ms'=>3,'max_steal_fraction'=>.05];
    $p = ['version'=>2,'approved'=>true,'backend'=>'native','confidence'=>'awaiting_workload_review','saturated_samples'=>0,
        'platform'=>'linux','game_key'=>'cs2','build'=>'730:fixture','method'=>$c['method'],'sampled_at'=>$now,
        'evidence'=>'fixture','active_samples'=>120,'idle_samples'=>45,'max_players_tested'=>16,
        'cpu_t'=>100,'cpu_s'=>44,'ram_bytes'=>2*$gib,'install_bytes'=>71106011864,'growth_bytes'=>2*$gib,
        'staging_bytes'=>4*$gib,'read_bps'=>10000,'write_bps'=>11000,'iops'=>20,'latency_budget_ms'=>5];
    return [$host,$c,$p,['cpu'=>70,'ram'=>70,'disk'=>85]];
}

function test_resource_model(): void
{
    $now = time();
    [$h,$c,$p,$limits] = placement_fixture($now);
    $why = fn($host=[], $cal=[], $profile=[], $held=[], $slots=16) =>
        resource_decision($host+$h, $cal+$c, $profile+$p, $held, $limits, $slots, $now)['reason'];
    expect_resource($why() === '', 'game6 disk and all synthetic gates pass');
    expect_resource($why(['disk_available'=>43*1073741824]) === 'insufficient disk_bytes', 'game5 independent install refused');
    expect_resource($why([], ['single_floor'=>50]) === 'single-thread capacity', 'only serial capacity fails');
    expect_resource($why([], ['aggregate_floor'=>100]) === 'insufficient cpu_t', 'aggregate throughput independently fails');
    foreach ([['approved'=>false],['measured_at'=>$now-31*86400],['measured_at'=>$now+1],
        ['fingerprint'=>'old'],['single_floor'=>null]] as $bad) expect_resource($why([], $bad) !== '', 'unknown calibration');
    expect_resource($why([],[],['method'=>'incompatible']) !== '', 'method mismatch');
    expect_resource($why(['purpose'=>'profiling']) !== '', 'profiling host cannot sell');
    expect_resource($why(['sampled_at'=>$now-181]) !== '', 'stale host');
    expect_resource($why(['steal_fraction'=>.3]) !== '', 'guest contention');
    expect_resource($why(['mem_available'=>1000]) === 'insufficient ram_bytes', 'RAM in bytes');
    expect_resource($why([],[],['staging_bytes'=>120*1073741824]) === 'insufficient disk_bytes', 'update staging');
    expect_resource($why([],[],['write_bps'=>90000]) === 'insufficient write_bps', 'sustainable write budget');
    expect_resource($why(['iops'=>null]) !== '' && $why(['latency_ms'=>10]) === 'storage latency','unknown IOPS and latency');
    expect_resource($why([],[],[],['ram_bytes'=>4*1073741824]) !== '', 'other reservations charged');
    expect_resource($why([],[],[],[],17) !== '' && $why([],[],['active_samples'=>1]) !== '', 'tested configuration and load');
    expect_resource(resource_disk_need($p,0,1000) === resource_disk_need($p,0)-1000, 'only verified shared extents can be credited');
    expect_resource(resource_disk_need($p,0,PHP_INT_MAX) === null, 'impossible shared credit fails');
    $raw = ['version'=>2,'elapsed'=>2,'cpu_seconds'=>1,'thread_rate'=>.4,'backend'=>'native','io_kind'=>'linux_storage_bytes',
        'build'=>'730:fixture','read_bytes'=>200,'write_bytes'=>550];
    $r = resource_observation($raw,$h,$c,$now,16);
    expect_resource($r['cpu_t'] == 55 && $r['cpu_s'] == 44 && $r['write_bps'] == 275,'raw seconds and byte deltas');
    $faster = ['single_upper'=>220]+$c;
    expect_resource(resource_normalize($raw,$faster,$h,$now)['cpu_t'] == 110,'equal CPU time on faster source has larger demand');
    expect_resource(resource_normalize(['elapsed'=>0]+$raw,$c,$h,$now) === [],'invalid interval');
    $row = ['home_id'=>956,'remote_server_id'=>8,'state'=>'RUNNING','sampled_at'=>$now,'game_key'=>'cs2',
        'disk_at'=>$now,'disk_b'=>71106011864,'ram_b'=>1000,'resource_json'=>json_encode($r)];
    $rows = [];
    for ($i=0;$i<30;$i++) $rows[] = ['sampled_at'=>$now-$i*60]+$row;
    $rows[] = ['resource_json'=>json_encode(['players'=>0]+$r),'ram_b'=>1500]+$row;
    $rows[] = ['resource_json'=>json_encode(['players'=>null]+$r)]+$row;
    $rows[] = ['resource_json'=>json_encode(['build'=>'different']+$r)]+$row;
    $profiles = resource_profiles($rows,$now);
    expect_resource(count($profiles)===2 && $profiles[0]['instances']===['8/956'],'source and build separation');
    expect_resource($profiles[0]['active_samples']===30 && $profiles[0]['idle_samples']===1 &&
        $profiles[0]['unknown_samples']===1 && $profiles[0]['max_players_tested']===16, 'unknown occupancy never counts as idle');
    expect_resource($profiles[0]['ram_bytes']==1500 && $profiles[0]['p95']['ram_bytes']==1000 && !$profiles[0]['approved'],
        'peak, percentile and review policy');
    expect_resource(resource_players(['date_timestamp'=>$now,'server_status_cache'=>'{"b":{"status":"1"},"s":{"players":"0"}}'],$now)===0,'known idle');
    expect_resource(resource_players(['date_timestamp'=>$now-90,'server_status_cache'=>'{}'],$now)===null,'stale query unknown');
}
// The report the Windows agent sends (tests/status_telemetry_windows_test.pl in gse-agent-windows builds the same
// text from the captured game3 inventory) must be accepted by the panel, and every missing figure must close the host.
function test_windows_host_report(): void
{
    $now = time();
    $json = file_get_contents(__DIR__ . '/fixtures/windows_resource_host.json');
    $window = resource_host_window($json);
    expect_resource($window !== [] && $window['platform'] === 'windows' && !empty($window['fingerprint']) && !isset($window['inventory']),
        'Windows host report accepted and fingerprinted');
    $broken = fn(array $change) => resource_host_window(json_encode($change + json_decode($json, true)));
    expect_resource($broken(['volume'=>'']) === [], 'no volume identity closes the host');
    expect_resource($broken(['limits'=>[]]) === [], 'no stated limits closes the host');
    expect_resource($broken(['platform'=>'linux']) === [], 'platform disagreeing with the inventory closes the host');

    [$h,$c,$p,$limits] = placement_fixture($now);
    $gib = 1073741824;
    $host = array_merge($window, ['purpose'=>'public','sampled_at'=>$now,'game_key'=>'cs2','release_build'=>'730:fixture',
        'release_checked_at'=>$now,'install_floor'=>71106011864,
        'latency_ms'=>2]); // the captured counters are synthetic (30 ms); this test is about the report's shape
    $cal = ['platform'=>'windows','volume'=>$window['volume'],'game_root'=>$window['game_root'],'fingerprint'=>$window['fingerprint'],
        'read_bps'=>20000000,'write_bps'=>20000000,'iops'=>2000] + $c;
    $profile = ['platform'=>'windows'] + $p;
    $why = fn(array $change = []) => resource_decision($change + $host, $cal, $profile, [], $limits, 16, $now)['reason'];
    expect_resource($why() === '', 'a calibrated Windows host with the real report can place: ' . $why());
    expect_resource($why(['steal_fraction'=>null]) === '', 'Windows is not judged on CPU steal, which a guest cannot see');
    foreach (['mem_available','disk_available','disk_total','mem_total','busy_cpus','read_bps','write_bps','iops'] as $key) {
        expect_resource($why([$key=>null]) !== '', "unknown $key closes the host");
    }
    expect_resource($why(['volume'=>'C:-FFFF0000']) !== '', 'a different drive serial does not match the calibration');
    $changedLimits = $window['limits']; $changedLimits['logical_cpus'] = 4;
    $other = resource_host_window(json_encode(['limits'=>$changedLimits] + json_decode($json, true)));
    expect_resource($other['fingerprint'] !== $window['fingerprint'] && $why(['fingerprint'=>$other['fingerprint']]) === 'uncalibrated or changed host',
        'a changed limit invalidates the calibration');
}
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    test_resource_model();
    test_windows_host_report();
    echo "Resource model passed\n";
}
