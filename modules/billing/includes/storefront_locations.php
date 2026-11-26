<?php
/**
 * Storefront location tree for the order page.
 *
 * Groups catalog offers under their city and region. Each city a game is sold in has a state:
 *   available    a machine in the city can take an order (the first such machine is used)
 *   disabled     the city is switched off in Game Catalog > Locations (shown red, "Unavailable")
 *   no_capacity  every machine in the city is out of room, not answering, or has installs off
 *                (shown red, "Unavailable")
 *   not_sold     the game has no enabled offer in the city (not shown at all)
 *
 * Capacity uses the agents' latest resource snapshot (percent used) against the placement
 * headroom on the Game Catalog page. A missing or old snapshot never blocks an order.
 */
if (!defined('STOREFRONT_SNAPSHOT_FRESH_SECONDS')) {
    define('STOREFRONT_SNAPSHOT_FRESH_SECONDS', 21600);
}

if (!function_exists('storefront_place_offer')) {
    function storefront_place_offer(array $cityOffers)
    {
        return $cityOffers ? array_values($cityOffers)[0] : null;
    }
}

if (!function_exists('storefront_agent_unavailable_reason')) {
    /**
     * Why one machine cannot take an order right now, or '' when it can.
     * $agent keys: install_enabled, snapshot_status, last_error, collected_at, cpu_percent, ram_percent, disk_percent.
     * $headroom keys: cpu, ram, disk (the most percent that may be in use).
     */
    function storefront_agent_unavailable_reason(array $agent, array $headroom, int $now): string
    {
        if ((int)($agent['install_enabled'] ?? 1) !== 1) {
            return 'Installs are turned off on this machine';
        }
        $collected = (int)($agent['collected_at'] ?? 0);
        if ($collected <= 0 || ($now - $collected) > STOREFRONT_SNAPSHOT_FRESH_SECONDS) {
            return '';
        }
        $status = (string)($agent['snapshot_status'] ?? '');
        $error = trim((string)($agent['last_error'] ?? ''));
        if ($error !== '' || ($status !== '' && $status !== 'ok')) {
            return 'The machine is not responding';
        }
        foreach (array('cpu_percent' => 'cpu', 'ram_percent' => 'ram', 'disk_percent' => 'disk') as $field => $key) {
            $used = $agent[$field] ?? null;
            if ($used === null || $used === '' || !is_numeric($used)) {
                continue;
            }
            $limit = (float)($headroom[$key] ?? 100);
            if ((float)$used > $limit) {
                return 'No spare capacity (' . $key . ' use is over ' . (int)$limit . '%)';
            }
        }
        return '';
    }
}

if (!function_exists('storefront_load_headroom')) {
    /** The placement headroom percentages. Defaults 70/70/85 when none are saved. */
    function storefront_load_headroom(mysqli $db, string $prefix): array
    {
        $out = array('cpu' => 70, 'ram' => 70, 'disk' => 85);
        try {
            $res = $db->query("SELECT headroom_cpu_pct, headroom_ram_pct, headroom_disk_pct FROM `{$prefix}billing_config` ORDER BY config_id ASC LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) {
                foreach (array('cpu' => 'headroom_cpu_pct', 'ram' => 'headroom_ram_pct', 'disk' => 'headroom_disk_pct') as $key => $col) {
                    $value = (int)($row[$col] ?? 0);
                    if ($value >= 1 && $value <= 100) {
                        $out[$key] = $value;
                    }
                }
            }
        } catch (Throwable $e) {
            // keep the defaults
        }
        return $out;
    }
}

if (!function_exists('storefront_agent_unavailable_reasons')) {
    /** Machines that cannot take an order right now, as agent id => reason. Others are not listed. */
    function storefront_agent_unavailable_reasons(mysqli $db, string $prefix, array $agentIds, ?int $now = null): array
    {
        $ids = array();
        foreach ($agentIds as $id) {
            if ((int)$id > 0) {
                $ids[(int)$id] = (int)$id;
            }
        }
        if (!$ids) {
            return array();
        }
        $now = $now === null ? time() : $now;
        $headroom = storefront_load_headroom($db, $prefix);
        $reasons = array();
        try {
            $res = $db->query(
                "SELECT rs.remote_server_id, rs.install_enabled, s.status AS snapshot_status, s.last_error, s.collected_at,
                        s.cpu_percent, s.ram_percent, s.disk_percent
                 FROM `{$prefix}remote_servers` rs
                 LEFT JOIN `{$prefix}agent_resource_snapshots` s ON s.remote_server_id = rs.remote_server_id
                 WHERE rs.remote_server_id IN (" . implode(',', $ids) . ')'
            );
            while ($res && ($row = $res->fetch_assoc())) {
                $reason = storefront_agent_unavailable_reason($row, $headroom, $now);
                if ($reason !== '') {
                    $reasons[(int)$row['remote_server_id']] = $reason;
                }
            }
        } catch (Throwable $e) {
            return array();
        }
        return $reasons;
    }
}

if (!function_exists('storefront_blocked_offers')) {
    /** Offers whose machine cannot take an order, as offer id => reason. */
    function storefront_blocked_offers(array $offers, array $agentReasons): array
    {
        $blocked = array();
        foreach ($offers as $offer) {
            $agentId = (int)($offer['remote_server_id'] ?? 0);
            if ($agentId > 0 && isset($agentReasons[$agentId])) {
                $blocked[(int)$offer['offer_id']] = $agentReasons[$agentId];
            }
        }
        return $blocked;
    }
}

if (!function_exists('storefront_location_state')) {
    /** State of one city for one game. Returns array(state, offer, reason). */
    function storefront_location_state(array $cityOffers, bool $cityEnabled, array $blocked): array
    {
        if (!$cityOffers) {
            return array('state' => 'not_sold', 'offer' => null, 'reason' => '');
        }
        if (!$cityEnabled) {
            return array('state' => 'disabled', 'offer' => null, 'reason' => 'This location is switched off');
        }
        $open = array();
        $reason = '';
        foreach ($cityOffers as $offer) {
            $id = (int)$offer['offer_id'];
            if (isset($blocked[$id])) {
                if ($reason === '') {
                    $reason = (string)$blocked[$id];
                }
            } else {
                $open[] = $offer;
            }
        }
        $placed = storefront_place_offer($open);
        if ($placed === null) {
            return array('state' => 'no_capacity', 'offer' => null, 'reason' => $reason);
        }
        return array('state' => 'available', 'offer' => $placed, 'reason' => '');
    }
}

if (!function_exists('storefront_build_location_groups')) {
    /**
     * $cities rows: location_id, name, region_name and optionally enabled and region_enabled.
     * $blocked is offer id => reason (see storefront_blocked_offers()).
     * Each entry: location_id, name, offer (or null), state, reason.
     */
    function storefront_build_location_groups(array $offers, array $cities, array $blocked = array())
    {
        $cityIds = array();
        foreach ($cities as $city) {
            $cityIds[(int)$city['location_id']] = true;
        }

        $offersByCity = array();
        $unassigned = array();
        foreach ($offers as $offer) {
            $cid = (int)($offer['city_location_id'] ?? 0);
            if ($cid === 0) {
                $unassigned[] = $offer;
            } elseif (isset($cityIds[$cid])) {
                $offersByCity[$cid][] = $offer;
            }
        }

        $groups = array();
        foreach ($cities as $city) {
            $cid = (int)$city['location_id'];
            $region = trim((string)($city['region_name'] ?? '')) !== '' ? (string)$city['region_name'] : 'Other';
            $cityOn = !isset($city['enabled']) || (int)$city['enabled'] === 1;
            $regionOn = !isset($city['region_enabled']) || $city['region_enabled'] === null || (int)$city['region_enabled'] === 1;
            $state = storefront_location_state($offersByCity[$cid] ?? array(), $cityOn && $regionOn, $blocked);
            $groups[$region]['region'] = $region;
            $groups[$region]['cities'][] = array(
                'location_id' => $cid,
                'name' => (string)$city['name'],
                'offer' => $state['offer'],
                'state' => $state['state'],
                'reason' => $state['reason'],
            );
        }
        if (!empty($unassigned)) {
            $state = storefront_location_state($unassigned, true, $blocked);
            $groups['Other servers']['region'] = 'Other servers';
            $groups['Other servers']['cities'][] = array(
                'location_id' => 0,
                'name' => 'Other servers',
                'offer' => $state['offer'],
                'state' => $state['state'],
                'reason' => $state['reason'],
            );
        }
        return array_values($groups);
    }
}

if (!function_exists('storefront_load_enabled_cities')) {
    function storefront_load_enabled_cities(mysqli $db, string $prefix)
    {
        $sql = "SELECT c.location_id, c.name, r.name AS region_name
                FROM `{$prefix}billing_locations` c
                LEFT JOIN `{$prefix}billing_locations` r
                       ON r.location_id = c.parent_id AND r.kind = 'region'
                WHERE c.kind = 'city' AND c.enabled = 1
                  AND (r.location_id IS NULL OR r.enabled = 1)
                ORDER BY region_name, c.name";
        $res = $db->query($sql);
        $rows = array();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('storefront_load_cities')) {
    /** Every city, switched on or off, with its region. Disabled ones show as Unavailable on the order page. */
    function storefront_load_cities(mysqli $db, string $prefix)
    {
        $sql = "SELECT c.location_id, c.name, c.enabled, r.name AS region_name, r.enabled AS region_enabled
                FROM `{$prefix}billing_locations` c
                LEFT JOIN `{$prefix}billing_locations` r
                       ON r.location_id = c.parent_id AND r.kind = 'region'
                WHERE c.kind = 'city'
                ORDER BY region_name, c.name";
        $res = $db->query($sql);
        $rows = array();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('storefront_offer_is_orderable')) {
    function storefront_offer_is_orderable(mysqli $db, string $prefix, int $offerId)
    {
        $sql = "SELECT co.offer_id, co.remote_server_id
                FROM `{$prefix}catalog_offer` co
                INNER JOIN `{$prefix}remote_servers` rs ON rs.remote_server_id = co.remote_server_id
                LEFT JOIN `{$prefix}billing_locations` c ON c.location_id = rs.location_id AND c.kind = 'city'
                WHERE co.offer_id = {$offerId} AND co.enabled = 1 AND NOT (co.slot_min_qty = 0 AND co.slot_max_qty = 0) AND rs.install_enabled = 1
                  AND (rs.location_id IS NULL OR (c.location_id IS NOT NULL AND c.enabled = 1))
                LIMIT 1";
        $res = $db->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return false;
        }
        $agentId = (int)$row['remote_server_id'];
        $reasons = storefront_agent_unavailable_reasons($db, $prefix, array($agentId));
        return !isset($reasons[$agentId]);
    }
}
