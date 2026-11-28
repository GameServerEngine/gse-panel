<?php
require_once __DIR__ . '/catalog_storefront.php';
require_once __DIR__ . '/placement_store.php';

function storefront_quote(mysqli $db, string $prefix, array $offers, int $slots, array $cities): array
{
    $prices = [];
    $blocked = [];
    foreach ($offers as $o) {
        $id = (int)$o['offer_id'];
        $tiers = $o['pricing_mode'] === 'tiers' ? catalog_load_tiers_for_offer($db, $prefix, $id) : [];
        $tierKey = '';
        foreach ($tiers as $t) {
            if ((int)$t['slot_count'] === $slots) { $tierKey = $t['tier_key']; break; }
        }
        $price = catalog_calculate_price($o, 'monthly', 1, ['slots'=>$slots, 'tier_key'=>$tierKey], $tiers);
        if (isset($price['error'])) {
            $blocked[$id] = $price['error'];
            continue;
        }
        $prices[$id] = ['monthly'=>$price['unit_price'], 'tier'=>$tierKey, 'host'=>(int)$o['remote_server_id']];
        $reason = placement_evaluate($db, $prefix, $id, $slots);
        if ($reason !== '') $blocked[$id] = $reason;
    }
    $locations = [];
    foreach (storefront_build_location_groups($offers, $cities, $blocked) as $group) {
        foreach ($group['cities'] as $city) {
            $locations[$city['location_id']] = (int)($city['offer']['offer_id'] ?? 0);
        }
    }
    return ['locations'=>$locations, 'prices'=>$prices, 'preview'=>$prices ? min(array_column($prices, 'monthly')) : null];
}

function storefront_min_slots(mysqli $db, string $prefix, array $o): int
{
    if ($o['pricing_mode'] !== 'tiers') return max(1, (int)$o['slot_min_qty']);
    $tiers = catalog_load_tiers_for_offer($db, $prefix, (int)$o['offer_id']);
    return $tiers ? (int)min(array_column($tiers, 'slot_count')) : 0;
}

function storefront_available_variant(mysqli $db, string $prefix, array $ids): ?int
{
    foreach ($ids as $cfg) {
        foreach (catalog_load_offers_for_game($db, $prefix, (int)$cfg) as $o) {
            $slots = storefront_min_slots($db, $prefix, $o);
            if ($slots && placement_evaluate($db, $prefix, (int)$o['offer_id'], $slots) === '') return (int)$cfg;
        }
    }
    return null;
}
