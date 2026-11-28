<?php
require_once __DIR__ . '/placement_capacity.php';
require_once __DIR__ . '/storefront_locations.php';

function placement_offer(mysqli $db, string $prefix, int $id): ?array
{
    return $db->query("SELECT co.*, ch.game_key, rs.location_id, rs.server_os, rs.enabled AS host_enabled,
            rs.install_enabled, c.enabled AS city_enabled, c.parent_id, r.enabled AS region_enabled
        FROM `{$prefix}catalog_offer` co
        JOIN `{$prefix}config_homes` ch USING (home_cfg_id)
        JOIN `{$prefix}remote_servers` rs USING (remote_server_id)
        LEFT JOIN `{$prefix}billing_locations` c ON c.location_id=rs.location_id AND c.kind='city'
        LEFT JOIN `{$prefix}billing_locations` r ON r.location_id=c.parent_id AND r.kind='region'
        WHERE co.offer_id=$id")->fetch_assoc();
}

// Writers must already have a transaction. Lock the host row even for the first hold.
function placement_evaluate(mysqli $db, string $prefix, int $id, int $slots, int $invoiceId = 0,
    int $expires = 0, int $expectedHost = 0, int $expectedGame = 0): string
{
    try {
        $o = placement_offer($db, $prefix, $id);
        if (!$o || !$o['enabled'] || !$o['host_enabled'] || !$o['install_enabled'] ||
            ($o['location_id'] !== null && !$o['city_enabled']) || ($o['parent_id'] !== null && !$o['region_enabled']))
            return 'offer, machine or location disabled';
        $sid = (int)$o['remote_server_id'];
        if (($expectedHost && $sid !== $expectedHost) || ($expectedGame && (int)$o['home_cfg_id'] !== $expectedGame))
            return 'order no longer matches catalog host or game';
        if ($slots < max(1, (int)$o['slot_min_qty']) || $slots > (int)$o['slot_max_qty']) return 'slot range';
        if ($o['pricing_mode'] === 'tiers' && !$db->query("SELECT 1 FROM `{$prefix}catalog_tier`
                WHERE offer_id=$id AND slot_count=$slots LIMIT 1")->num_rows) return 'unknown tier';
        $lock = $expires ? ' FOR UPDATE' : '';
        $cal = $db->query("SELECT purpose, calibration_json FROM `{$prefix}placement_host`
            WHERE remote_server_id=$sid" . $lock)->fetch_assoc();
        if (!$cal) return 'host has no calibration';
        if ($cal['purpose'] === 'bootstrap') {
            return placement_bootstrap($db, $prefix, $o, $slots, $invoiceId, $expires, $cal);
        }
        $sample = $db->query("SELECT sampled_at, resource_json FROM `{$prefix}status_sample`
            WHERE remote_server_id=$sid ORDER BY sampled_at DESC LIMIT 1")->fetch_assoc();
        $h = json_decode($sample['resource_json'] ?? '', true) ?: [];
        $h['purpose'] = $cal['purpose'];
        $h['sampled_at'] = $sample['sampled_at'] ?? null;
        $h['game_key'] = $o['game_key'];
        $h['install_floor'] = $o['game_key'] === 'cs2' ? 71106011864 : 0;
        $root = $GLOBALS['billing_runtime_site_config']['game_server_root'] ?? '/home/gameserver';
        if (rtrim($root, '/') !== ($h['game_root'] ?? '') || ($h['platform'] ?? '') !== $o['server_os'])
            return 'target filesystem or platform mismatch';
        $prof = $db->query("SELECT profile_json,release_build,release_checked_at FROM `{$prefix}placement_profile` WHERE offer_id=$id")->fetch_assoc();
        $h['release_build'] = $prof['release_build'] ?? null;
        $h['release_checked_at'] = $prof['release_checked_at'] ?? null;
        $held = [];
        $res = $db->query("SELECT invoice_id,offer_id,slots,demand_json FROM `{$prefix}placement_hold`
            WHERE remote_server_id=$sid AND expires_at >= UNIX_TIMESTAMP()" . $lock);
        while ($row = $res->fetch_assoc()) {
            if ($invoiceId && (int)$row['invoice_id'] === $invoiceId) {
                if ((int)$row['offer_id'] !== $id || (int)$row['slots'] !== $slots) return 'reservation mismatch';
                continue;
            }
            $d = json_decode($row['demand_json'], true);
            foreach (resource_metrics() as $k) {
                if (!resource_number($d[$k] ?? null)) return 'unreadable reservation';
                $held[$k] = ($held[$k] ?? 0) + $d[$k];
            }
        }
        $result = resource_decision($h, json_decode($cal['calibration_json'], true) ?: [],
            json_decode($prof['profile_json'] ?? '', true) ?: [], $held, storefront_load_headroom($db, $prefix), $slots, time());
        if ($expires && $result['reason'] === '') {
            if ($invoiceId <= 0) return 'missing invoice';
            $prior = $db->query("SELECT remote_server_id,offer_id,slots FROM `{$prefix}placement_hold`
                WHERE invoice_id=$invoiceId FOR UPDATE")->fetch_assoc();
            if ($prior && ((int)$prior['remote_server_id'] !== $sid || (int)$prior['offer_id'] !== $id ||
                (int)$prior['slots'] !== $slots)) return 'invoice already bound to a different allocation';
            $demand = json_encode($result['demand'], JSON_THROW_ON_ERROR);
            $stmt = $db->prepare("INSERT INTO `{$prefix}placement_hold`
                (invoice_id,remote_server_id,offer_id,slots,expires_at,demand_json) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE expires_at=GREATEST(expires_at,VALUES(expires_at)),demand_json=VALUES(demand_json)");
            $stmt->bind_param('iiiiis', $invoiceId, $sid, $id, $slots, $expires, $demand);
            if (!$stmt->execute()) return 'reservation failed';
        }
        return $result['reason'];
    } catch (Throwable $e) {
        error_log('Placement lookup for offer ' . $id . ' failed: ' . $e->getMessage());
        return 'resource data unavailable';
    }
}

// Explicit, expiring pilot policy: not a GRU calibration or an approved workload profile.
// Only small legacy native Linux games, one outstanding pilot allocation per host.
function placement_bootstrap(mysqli $db, string $prefix, array $o, int $slots, int $invoiceId,
    int $expires, array $cal): string
{
    $policy = json_decode($cal['calibration_json'], true) ?: [];
    $now = time();
    $sid = (int)$o['remote_server_id'];
    $id = (int)$o['offer_id'];
    if (($policy['mode'] ?? '') !== 'legacy-small-pilot-v1' ||
        !resource_fresh($policy['verified_at'] ?? null, $now, 86400) ||
        ($o['server_os'] ?? '') !== 'linux' ||
        !in_array($o['game_key'], ['cstrike', 'urban_terror_4'], true) || $slots > 16)
        return 'configuration outside temporary pilot policy';
    $sample = $db->query("SELECT * FROM `{$prefix}status_sample`
        WHERE remote_server_id=$sid ORDER BY sampled_at DESC LIMIT 1")->fetch_assoc();
    if (!$sample || (int)$sample['is_up'] !== 1 ||
        !resource_fresh($sample['sampled_at'], $now, 180))
        return 'missing or stale pilot host sample';
    foreach (['cores','cpu_pct','mem_total_b','mem_used_b','disk_total_b','disk_used_b'] as $k)
        if (!resource_number($sample[$k] ?? null)) return 'unknown pilot ' . $k;
    if (!resource_number($policy['disk_total_b'] ?? null) ||
        !resource_number($policy['disk_reserved_b'] ?? null) ||
        (int)$policy['disk_total_b'] !== (int)$sample['disk_total_b'] ||
        ($policy['game_root'] ?? '') !== rtrim($GLOBALS['billing_runtime_site_config']['game_server_root'] ?? '/home/gameserver', '/'))
        return 'pilot filesystem changed';
    $limits = storefront_load_headroom($db, $prefix);
    // Conservative planning envelopes, not measured workload guarantees.
    $ram = 1073741824; $disk = 10737418240;
    if ($sample['cores'] < 2 || $sample['cpu_pct'] > 100 ||
        $sample['cpu_pct'] + 100 / $sample['cores'] > min(70, $limits['cpu']))
        return 'insufficient pilot CPU';
    if ($sample['mem_total_b'] <= 0 || $sample['mem_used_b'] + $ram > $sample['mem_total_b'] * min(70,$limits['ram']) / 100)
        return 'insufficient pilot RAM';
    if ($sample['disk_total_b'] <= 0 ||
        $sample['disk_used_b'] + $policy['disk_reserved_b'] + $disk > $sample['disk_total_b'] * min(85,$limits['disk']) / 100)
        return 'insufficient pilot disk';
    $lock = $expires ? ' FOR UPDATE' : '';
    $res = $db->query("SELECT invoice_id,offer_id,slots FROM `{$prefix}placement_hold`
        WHERE remote_server_id=$sid AND expires_at >= UNIX_TIMESTAMP()" . $lock);
    while ($row = $res->fetch_assoc()) {
        if (!$invoiceId || (int)$row['invoice_id'] !== $invoiceId) return 'pilot allocation already reserved';
        if ((int)$row['offer_id'] !== $id || (int)$row['slots'] !== $slots) return 'reservation mismatch';
    }
    if (!$expires) return '';
    if ($invoiceId <= 0) return 'missing invoice';
    $prior = $db->query("SELECT remote_server_id,offer_id,slots FROM `{$prefix}placement_hold`
        WHERE invoice_id=$invoiceId FOR UPDATE")->fetch_assoc();
    if ($prior && ((int)$prior['remote_server_id'] !== $sid ||
        (int)$prior['offer_id'] !== $id || (int)$prior['slots'] !== $slots))
        return 'invoice already bound to a different allocation';
    // Do not pretend provisional CPU/IO quantities are normalized GRU metrics.
    $demand = json_encode(['mode'=>'legacy-small-pilot-v1','ram_bytes'=>$ram,'disk_bytes'=>$disk,'cpu_cores'=>1], JSON_THROW_ON_ERROR);
    $stmt = $db->prepare("INSERT INTO `{$prefix}placement_hold`
        (invoice_id,remote_server_id,offer_id,slots,expires_at,demand_json) VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE expires_at=GREATEST(expires_at,VALUES(expires_at)),demand_json=VALUES(demand_json)");
    $stmt->bind_param('iiiiis', $invoiceId, $sid, $id, $slots, $expires, $demand);
    return $stmt->execute() ? '' : 'reservation failed';
}

function placement_invoice(mysqli $db, string $prefix, array $inv, int $expires): string
{
    if (($inv['invoice_kind'] ?? '') === 'service' || (int)($inv['home_id'] ?? 0) > 0) return '';
    $sid = (int)($inv['ip'] ?? 0);
    $service = (int)($inv['service_id'] ?? 0);
    $cfg = $db->query("SELECT home_cfg_id FROM `{$prefix}billing_services` WHERE service_id=$service")->fetch_assoc();
    $game = (int)($cfg['home_cfg_id'] ?? 0);
    if (!$game || !$sid) return 'unknown game or target on invoice';
    $snapshot = json_decode($inv['catalog_snapshot'] ?? '', true);
    $offer = (int)($snapshot['offer_id'] ?? 0);
    if (!$offer) {
        $old = $db->query("SELECT offer_id FROM `{$prefix}catalog_offer` WHERE home_cfg_id=$game
            AND remote_server_id=$sid AND enabled=1 LIMIT 1")->fetch_assoc();
        $offer = (int)($old['offer_id'] ?? 0);
    }
    return placement_evaluate($db, $prefix, $offer, (int)($inv['max_players'] ?? 0),
        (int)($inv['invoice_id'] ?? 0), $expires, $sid, $game);
}

function placement_reserve_invoices(mysqli $db, string $prefix, array $invoices, int $expires): bool
{
    if (!$invoices) return false;
    try {
        $db->begin_transaction();
        foreach ($invoices as $inv) {
            $why = placement_invoice($db, $prefix, $inv, $expires);
            if ($why !== '') throw new RuntimeException($why);
        }
        return $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Placement reservation declined: ' . $e->getMessage());
        return false;
    }
}
