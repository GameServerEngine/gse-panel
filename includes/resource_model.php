<?php
// Schema 2 uses CPU seconds per elapsed second and measured benchmark ratings.
// A calibration describes one execution envelope, including the actual game filesystem.
function resource_number($value): bool
{
    return is_numeric($value) && is_finite((float)$value) && $value >= 0;
}

function resource_fresh($timestamp, int $now, int $ttl): bool
{
    return resource_number($timestamp) && $timestamp > 0 && $timestamp <= $now && $now - $timestamp <= $ttl;
}

function resource_calibrated(array $c, array $host, int $now): bool
{
    if (($c['version'] ?? null) !== 2 || ($c['approved'] ?? false) !== true ||
        empty($c['method']) || empty($c['evidence']) || empty($c['fingerprint']) ||
        $c['fingerprint'] !== ($host['fingerprint'] ?? null) ||
        !resource_fresh($c['measured_at'] ?? null, $now, 30 * 86400)) {
        return false;
    }
    foreach (['single_floor','single_upper','aggregate_floor','aggregate_upper','effective_cpus','capacity_factor'] as $k) {
        if (!resource_number($c[$k] ?? null) || $c[$k] <= 0) return false;
    }
    return $c['single_floor'] <= $c['single_upper'] && $c['aggregate_floor'] <= $c['aggregate_upper']
        && $c['capacity_factor'] <= 1;
}

function resource_normalize(array $raw, array $c, array $host, int $now): array
{
    if (!resource_calibrated($c, $host, $now) || !resource_number($raw['cpu_seconds'] ?? null)
        || !resource_number($raw['elapsed'] ?? null) || $raw['elapsed'] <= 0
        || !resource_number($raw['thread_rate'] ?? null) || $raw['thread_rate'] > 1) return [];
    $rate = $raw['cpu_seconds'] / $raw['elapsed'];
    // A lightly threaded job can get the single-thread turbo rate. Charging the
    // larger source rate avoids discounting it by the SMT throughput average.
    $unit = max($c['single_upper'], $c['aggregate_upper'] / $c['effective_cpus']);
    return ['cpu_t' => $rate * $unit, 'cpu_s' => $raw['thread_rate'] * $c['single_upper']];
}

function resource_disk_need(array $profile, int $measuredFloor, int $verifiedShared = 0): ?float
{
    foreach (['install_bytes','staging_bytes','growth_bytes'] as $key) {
        if (!resource_number($profile[$key] ?? null)) return null;
    }
    if ($profile['install_bytes'] <= 0 || $profile['staging_bytes'] <= 0 || $verifiedShared < 0
        || $verifiedShared > $profile['install_bytes']) return null;
    return max($measuredFloor, $profile['install_bytes']) - $verifiedShared
        + $profile['staging_bytes'] + $profile['growth_bytes'];
}

function resource_metrics(): array
{
    return ['cpu_t','ram_bytes','disk_bytes','read_bps','write_bps','iops'];
}

// All quantities are for one instance at its highest approved slot count.
// The reason is internal. A missing quantity or unit is never treated as spare capacity.
function resource_decision(array $h, array $c, array $p, array $held, array $limits, int $slots, int $now): array
{
    $reject = static fn($why) => ['reason' => $why, 'demand' => []];
    if (!resource_fresh($h['sampled_at'] ?? null, $now, 180) || ($h['version'] ?? null) !== 2)
        return $reject('missing or stale host window');
    if (($h['purpose'] ?? '') !== 'public') return $reject('host reserved for profiling or disabled');
    if (!resource_calibrated($c, $h, $now)) return $reject('uncalibrated or changed host');
    foreach (['platform','volume','game_root'] as $k) {
        if (empty($h[$k]) || $h[$k] !== ($c[$k] ?? null)) return $reject('calibration mismatch: ' . $k);
    }
    if (($p['version'] ?? null) !== 2 || ($p['approved'] ?? false) !== true ||
        empty($p['build']) || empty($p['evidence']) || ($p['platform'] ?? '') !== $h['platform'] ||
        ($p['method'] ?? '') !== $c['method'] || ($p['game_key'] ?? '') !== $h['game_key'] ||
        !resource_fresh($p['sampled_at'] ?? null, $now, 30 * 86400) ||
        !resource_number($p['active_samples'] ?? null) || $p['active_samples'] < 30 ||
        !resource_number($p['idle_samples'] ?? null) || $p['idle_samples'] < 1 ||
        $slots < 1 || $slots > ($p['max_players_tested'] ?? 0)) return $reject('configuration lacks a representative approved profile');
    if (empty($h['release_build']) || $p['build'] !== $h['release_build'] ||
        !resource_fresh($h['release_checked_at'] ?? null, $now, 86400)) return $reject('unverified install build');
    if (($p['backend'] ?? '') !== 'native' || ($p['confidence'] ?? '') !== 'awaiting_workload_review' ||
        ($p['saturated_samples'] ?? null) !== 0) return $reject('unverified runtime or censored source load');
    if ($h['platform'] === 'linux' && (!resource_number($h['steal_fraction'] ?? null) ||
        !resource_number($c['max_steal_fraction'] ?? null) || $c['max_steal_fraction'] > 1 ||
        $h['steal_fraction'] > $c['max_steal_fraction'])) return $reject('unknown or excessive CPU steal');
    if (!resource_number($h['busy_cpus'] ?? null) || !resource_number($p['cpu_s'] ?? null) || $p['cpu_s'] <= 0)
        return $reject('unknown CPU demand or use');
    $factor = $c['capacity_factor'];
    if ($p['cpu_s'] > $c['single_floor'] * $factor * $limits['cpu'] / 100) return $reject('single-thread capacity');
    $need = $p;
    // Every billing install currently copies its own files. No shared-file credit.
    $need['disk_bytes'] = resource_disk_need($p, (int)($h['install_floor'] ?? 0));
    $total = ['cpu_t' => $c['aggregate_floor'] * $factor, 'ram_bytes' => $h['mem_total'] ?? null,
        'disk_bytes' => $h['disk_total'] ?? null];
    $used = ['cpu_t' => $total['cpu_t'] * $h['busy_cpus'] / $c['effective_cpus']];
    foreach (['ram_bytes'=>'mem_available', 'disk_bytes'=>'disk_available'] as $k=>$free) {
        if (!resource_number($total[$k]) || !resource_number($h[$free] ?? null) || $h[$free] > $total[$k])
            return $reject('unknown free ' . $k);
        $used[$k] = $total[$k] - $h[$free];
    }
    foreach (['read_bps','write_bps','iops'] as $k) {
        $total[$k] = $c[$k] ?? null;
        $used[$k] = $h[$k] ?? null;
    }
    if (!resource_number($c['latency_p99_ms'] ?? null) || $c['latency_p99_ms'] <= 0 ||
        !resource_number($p['latency_budget_ms'] ?? null) || $p['latency_budget_ms'] <= 0 ||
        $c['latency_p99_ms'] > $p['latency_budget_ms'] ||
        (isset($h['latency_ms']) && $h['latency_ms'] > $p['latency_budget_ms'])) return $reject('storage latency');
    $pct = ['cpu_t'=>$limits['cpu'], 'ram_bytes'=>$limits['ram'], 'disk_bytes'=>$limits['disk']];
    $demand = [];
    foreach (resource_metrics() as $k) {
        $budget = $pct[$k] ?? ($c['io_headroom_pct'] ?? 0);
        if (!resource_number($need[$k] ?? null) || !resource_number($total[$k]) || $total[$k] <= 0 ||
            !resource_number($used[$k]) || !resource_number($budget) || $budget <= 0 || $budget > 100 ||
            !resource_number($held[$k] ?? 0)) return $reject('unknown ' . $k);
        if ($used[$k] + $need[$k] + ($held[$k] ?? 0) > $total[$k] * $budget / 100) return $reject('insufficient ' . $k);
        $demand[$k] = $need[$k];
    }
    if ($demand['cpu_t'] <= 0 || $demand['ram_bytes'] <= 0) return $reject('empty operating envelope');
    return ['reason'=>'', 'demand'=>$demand];
}

function resource_host_window($json): array
{
    require_once __DIR__ . '/agent_inventory.php';
    $h = json_decode((string)$json, true);
    if (!is_array($h) || ($h['version'] ?? null) !== 2 || empty($h['inventory']) || empty($h['limits'])) return [];
    $i = $h['inventory'];
    if (empty($i['cpu']['model']) || empty($i['cpu']['logical_cpus_allowed']) || empty($h['volume']) ||
        ($i['platform'] ?? '') !== ($h['platform'] ?? null)) return [];
    $h['fingerprint'] = hash('sha256', json_encode(agent_inventory_ksort_recursive([
        agent_inventory_fingerprint($i), $i['cpu'], $h['limits'], $h['volume'], $h['game_root'], $i['kernel']
    ])));
    unset($h['inventory']);
    return $h;
}

function resource_players(?array $cache, int $now): ?int
{
    if (!$cache || !resource_fresh($cache['date_timestamp'] ?? null, $now, 60)) return null;
    $q = json_decode($cache['server_status_cache'] ?? '', true);
    if (($q['server']['gq_online'] ?? 0) == 1) $n = $q['server']['gq_numplayers'] ?? null;
    elseif (($q['b']['status'] ?? 0) == 1) $n = $q['s']['players'] ?? null;
    elseif (($q['status'] ?? '') === 'online') $n = $q['players'] ?? null;
    else return null;
    return filter_var($n, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) === false ? null : (int)$n;
}

// Keep all raw counters and calibration evidence with the observation, even if
// the operator replaces the host calibration before history is aggregated.
function resource_observation(array $raw, array $host, array $cal, int $now, ?int $players): array
{
    if (($raw['version'] ?? null) !== 2) return [];
    $raw['platform'] = $host['platform'] ?? null;
    $raw['fingerprint'] = $host['fingerprint'] ?? null;
    $raw['source_calibration'] = $cal ?: null;
    $raw['players'] = $players;
    $raw['ram_kind'] = $raw['platform'] === 'windows' ? 'process_tree_working_set' : 'process_tree_rss';
    $raw['volume_read_bps'] = $host['read_bps'] ?? null;
    $raw['volume_write_bps'] = $host['write_bps'] ?? null;
    $raw['volume_iops'] = $host['iops'] ?? null;
    $raw['volume_latency_ms'] = $host['latency_ms'] ?? null;
    foreach (['read','write'] as $dir) {
        $bytes = $raw[$dir . '_bytes'] ?? null;
        $raw[$dir . '_bps'] = resource_number($bytes) && resource_number($raw['elapsed'] ?? null) && $raw['elapsed'] > 0
            ? $bytes / $raw['elapsed'] : null;
    }
    unset($raw['cpu_s'], $raw['cpu_t']);
    return $raw + resource_normalize($raw, $cal, $host, $now);
}

// Each input is a game_sample row. Unknown occupancy, changing trees and
// unidentified builds cannot qualify a public profile. Keep their counts visible.
function resource_profiles(iterable $rows, int $now): array
{
    $groups = [];
    foreach ($rows as $row) {
        $r = json_decode($row['resource_json'] ?? '', true);
        if (!is_array($r) || empty($row['home_id']) || empty($row['game_key']) || empty($r['build'])) continue;
        $identity = ['game_key'=>$row['game_key'], 'build'=>$r['build'], 'platform'=>$r['platform'],
            'backend'=>$r['backend'], 'method'=>$r['source_calibration']['method'] ?? ''];
        $key = json_encode($identity);
        if (!isset($groups[$key])) $groups[$key] = $identity + ['version'=>2, 'approved'=>false,
            'samples'=>0, 'idle_samples'=>0, 'active_samples'=>0, 'unknown_samples'=>0, 'saturated_samples'=>0,
            'instances'=>[], 'occupancy'=>[], 'sampled_at'=>0, 'install_bytes'=>null, 'peak'=>[], 'series'=>[]];
        unset($g);
        $g = &$groups[$key];
        $g['samples']++;
        if (!resource_fresh($row['sampled_at'], $now, 30 * 86400)) continue;
        if (resource_fresh($row['disk_at'] ?? null, $now, 86400) && resource_number($row['disk_b'] ?? null))
            $g['install_bytes'] = max($g['install_bytes'] ?? 0, $row['disk_b']);
        if ($row['state'] !== 'RUNNING') continue;
        $m = ['ram_bytes'=>$row['ram_b'], 'cpu_t'=>$r['cpu_t'] ?? null, 'cpu_s'=>$r['cpu_s'] ?? null,
            'iops'=>$r['volume_iops'] ?? null];
        foreach (['read_bps','write_bps'] as $k) {
            // Windows process transfers include pipe/network I/O. The volume
            // is a conservative bound even where per-process disk IOPS are hidden.
            $m[$k] = ($r['io_kind'] ?? '') === 'linux_storage_bytes' ? ($r[$k] ?? null) : ($r['volume_'.$k] ?? null);
        }
        if (!resource_number($r['players'] ?? null) || count(array_filter($m, 'resource_number')) !== count($m)) {
            $g['unknown_samples']++;
            continue;
        }
        $g['instances'][(int)$row['remote_server_id'] . '/' . (int)$row['home_id']] = true;
        $g['sampled_at'] = max($g['sampled_at'], (int)$row['sampled_at']);
        $g[$r['players'] > 0 ? 'active_samples' : 'idle_samples']++;
        $g['occupancy'][(int)$r['players']] = ($g['occupancy'][(int)$r['players']] ?? 0) + 1;
        if ($r['thread_rate'] >= .95) $g['saturated_samples']++;
        foreach ($m as $k=>$value) {
            $g['series'][$k][] = (float)$value;
            $g['peak'][$k] = max($g['peak'][$k] ?? 0, $value);
        }
        unset($g);
    }
    foreach ($groups as &$group) {
        $group['max_players_tested'] = 0;
        foreach ($group['occupancy'] as $n=>$count) {
            if ($count >= 30) $group['max_players_tested'] = max($group['max_players_tested'], $n);
        }
        $group['instances'] = array_keys($group['instances']);
        foreach ($group['series'] as $k=>$values) {
            sort($values, SORT_NUMERIC);
            $group['p95'][$k] = $values[(int)ceil(count($values) * .95) - 1];
            $group[$k] = $group['peak'][$k];
        }
        $group['confidence'] = $group['max_players_tested'] && $group['idle_samples'] && !$group['saturated_samples']
            ? 'awaiting_workload_review' : 'insufficient';
        unset($group['series']);
    }
    return array_values($groups);
}
