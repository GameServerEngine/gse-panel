<?php
// 0 and 0 slots means "not offered": the clamp keeps it and every storefront query skips it.
// Run with: php modules/billing/tests/SlotsDisabledTest.php
require_once dirname(__DIR__) . '/includes/catalog_games.php';

function slots_disabled_assert($ok, $message)
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

slots_disabled_assert(catalog_clamp_slots(0, 0) === array(0, 0), '0 and 0 stays 0 and 0');
slots_disabled_assert(catalog_clamp_slots(-3, 0) === array(0, 0), 'negative and 0 also means not offered');
slots_disabled_assert(catalog_clamp_slots(0, 10) === array(1, 10), 'only the minimum at 0 still gives a normal range');
slots_disabled_assert(catalog_clamp_slots(5, 2) === array(5, 5), 'the maximum is never below the minimum');

$catalog = file_get_contents(dirname(__DIR__) . '/includes/catalog_storefront.php');
slots_disabled_assert(substr_count($catalog, 'slot_min_qty = 0 AND') >= 1, 'catalog_storefront.php skips offers with 0 and 0 slots');

// storefront_locations.php no longer has its own query. It loads the offer through the catalog
// (which skips 0 and 0 slots) and placement_evaluate refuses any slot count above a maximum of 0.
$locations = file_get_contents(dirname(__DIR__) . '/includes/storefront_locations.php');
slots_disabled_assert(strpos($locations, 'catalog_load_offer_by_id') !== false, 'storefront_locations.php loads offers through the catalog');
$placement = file_get_contents(dirname(__DIR__) . '/includes/placement_store.php');
slots_disabled_assert(strpos($placement, "\$slots > (int)\$o['slot_max_qty']) return 'slot range'") !== false, 'placement refuses slots above the maximum, so 0 and 0 is never orderable');
echo "SlotsDisabledTest passed\n";
