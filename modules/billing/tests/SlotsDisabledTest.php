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

foreach (array('includes/catalog_storefront.php', 'includes/storefront_locations.php') as $file) {
    $code = file_get_contents(dirname(__DIR__) . '/' . $file);
    slots_disabled_assert(substr_count($code, 'slot_min_qty = 0 AND') >= 1, "{$file} skips offers with 0 and 0 slots");
}
echo "SlotsDisabledTest passed\n";
