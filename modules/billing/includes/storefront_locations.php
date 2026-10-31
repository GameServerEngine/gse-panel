<?php
/**
 * Storefront location tree for the order page.
 *
 * Groups catalog offers under their city and region. A city is available when one of its
 * machines has an enabled offer; automatic placement picks the first orderable machine in
 * that city. Capacity checks are added once agents report resources.
 */
if (!function_exists('storefront_place_offer')) {
    function storefront_place_offer(array $cityOffers)
    {
        return $cityOffers ? $cityOffers[0] : null;
    }
}

if (!function_exists('storefront_build_location_groups')) {
    function storefront_build_location_groups(array $offers, array $cities)
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
            $groups[$region]['region'] = $region;
            $groups[$region]['cities'][] = array(
                'location_id' => $cid,
                'name' => (string)$city['name'],
                'offer' => storefront_place_offer($offersByCity[$cid] ?? array()),
            );
        }
        if (!empty($unassigned)) {
            $groups['Other servers']['region'] = 'Other servers';
            $groups['Other servers']['cities'][] = array(
                'location_id' => 0,
                'name' => 'Other servers',
                'offer' => storefront_place_offer($unassigned),
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

if (!function_exists('storefront_offer_is_orderable')) {
    function storefront_offer_is_orderable(mysqli $db, string $prefix, int $offerId)
    {
        $sql = "SELECT co.offer_id
                FROM `{$prefix}catalog_offer` co
                INNER JOIN `{$prefix}remote_servers` rs ON rs.remote_server_id = co.remote_server_id
                LEFT JOIN `{$prefix}billing_locations` c ON c.location_id = rs.location_id AND c.kind = 'city'
                WHERE co.offer_id = {$offerId} AND co.enabled = 1 AND rs.install_enabled = 1
                  AND (rs.location_id IS NULL OR (c.location_id IS NOT NULL AND c.enabled = 1))
                LIMIT 1";
        $res = $db->query($sql);
        return (bool)($res && $res->fetch_assoc());
    }
}
