<?php
require_once __DIR__ . '/../includes/catalog_pricing.php';

function assertCatalogSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

// --- per_slot mode ---
$perSlotOffer = [
    'pricing_mode' => 'per_slot',
    'slot_min_qty' => 2,
    'slot_max_qty' => 32,
    'per_slot_price_daily' => 0.50,
    'per_slot_price_monthly' => 5.00,
    'per_slot_price_year' => 50.00,
];

$r = catalog_calculate_price($perSlotOffer, 'monthly', 1, ['slots' => 2]);
assertCatalogSame(10.00, $r['total_price'], 'per_slot minimum slots, monthly, qty 1');

$r = catalog_calculate_price($perSlotOffer, 'monthly', 1, ['slots' => 16]);
assertCatalogSame(80.00, $r['total_price'], 'per_slot middle slots, monthly, qty 1');

$r = catalog_calculate_price($perSlotOffer, 'monthly', 1, ['slots' => 32]);
assertCatalogSame(160.00, $r['total_price'], 'per_slot maximum slots, monthly, qty 1');

$r = catalog_calculate_price($perSlotOffer, 'monthly', 4, ['slots' => 32]);
assertCatalogSame(640.00, $r['total_price'], 'per_slot maximum slots, monthly, qty 4 multiplies total');

$r = catalog_calculate_price($perSlotOffer, 'daily', 1, ['slots' => 32]);
assertCatalogSame(16.00, $r['total_price'], 'per_slot maximum slots, daily period');

$r = catalog_calculate_price($perSlotOffer, 'yearly', 1, ['slots' => 32]);
assertCatalogSame(1600.00, $r['total_price'], 'per_slot maximum slots, yearly period');

$r = catalog_calculate_price($perSlotOffer, 'monthly', 1, ['slots' => 1]);
assertCatalogSame('slots_out_of_range', $r['error'] ?? null, 'per_slot below minimum rejected');

$r = catalog_calculate_price($perSlotOffer, 'monthly', 1, ['slots' => 33]);
assertCatalogSame('slots_out_of_range', $r['error'] ?? null, 'per_slot above maximum rejected');

// --- tiers mode ---
$tiersOffer = [
    'pricing_mode' => 'tiers',
    'slot_min_qty' => 1,
    'slot_max_qty' => 32,
];
$tiers = [
    ['tier_key' => 'small',  'slot_count' => 8,  'price_daily' => 1.00, 'price_monthly' => 10.00, 'price_year' => 100.00],
    ['tier_key' => 'medium', 'slot_count' => 16, 'price_daily' => 1.80, 'price_monthly' => 18.00, 'price_year' => 180.00],
    ['tier_key' => 'large',  'slot_count' => 24, 'price_daily' => 2.50, 'price_monthly' => 25.00, 'price_year' => 250.00],
    ['tier_key' => 'full',   'slot_count' => 32, 'price_daily' => 3.00, 'price_monthly' => 30.00, 'price_year' => 300.00],
];

$r = catalog_calculate_price($tiersOffer, 'monthly', 1, ['tier_key' => 'small'], $tiers);
assertCatalogSame(10.00, $r['total_price'], 'tiers small tier monthly');
assertCatalogSame(8, $r['slots'], 'tiers small tier slot count');

$r = catalog_calculate_price($tiersOffer, 'monthly', 1, ['tier_key' => 'full'], $tiers);
assertCatalogSame(30.00, $r['total_price'], 'tiers full tier monthly');

$r = catalog_calculate_price($tiersOffer, 'monthly', 3, ['tier_key' => 'medium'], $tiers);
assertCatalogSame(54.00, $r['total_price'], 'tiers medium tier, qty 3 multiplies total');

$r = catalog_calculate_price($tiersOffer, 'monthly', 1, ['tier_key' => 'xlarge'], $tiers);
assertCatalogSame('invalid_tier', $r['error'] ?? null, 'tiers out-of-range tier key rejected');

$r = catalog_calculate_price($tiersOffer, 'monthly', 1, ['tier_key' => 'small'], []);
assertCatalogSame('tier_not_found', $r['error'] ?? null, 'tiers missing tier data rejected');

// --- base_plus_extra mode ---
$baseOffer = [
    'pricing_mode' => 'base_plus_extra',
    'slot_min_qty' => 1,
    'slot_max_qty' => 32,
    'base_slots' => 10,
    'base_price_daily' => 1.00,
    'base_price_monthly' => 10.00,
    'base_price_year' => 100.00,
    'extra_price_per_slot_daily' => 0.10,
    'extra_price_per_slot_monthly' => 1.00,
    'extra_price_per_slot_year' => 10.00,
];

$r = catalog_calculate_price($baseOffer, 'monthly', 1, ['slots' => 1]);
assertCatalogSame(10.00, $r['total_price'], 'base_plus_extra minimum slots, below base, charges base only');

$r = catalog_calculate_price($baseOffer, 'monthly', 1, ['slots' => 10]);
assertCatalogSame(10.00, $r['total_price'], 'base_plus_extra exactly at base slots');

$r = catalog_calculate_price($baseOffer, 'monthly', 1, ['slots' => 16]);
assertCatalogSame(16.00, $r['total_price'], 'base_plus_extra middle slots, base + 6 extra');

$r = catalog_calculate_price($baseOffer, 'monthly', 1, ['slots' => 32]);
assertCatalogSame(32.00, $r['total_price'], 'base_plus_extra maximum slots, base + 22 extra');

$r = catalog_calculate_price($baseOffer, 'monthly', 1, ['slots' => 0]);
assertCatalogSame('slots_out_of_range', $r['error'] ?? null, 'base_plus_extra below minimum rejected');

$r = catalog_calculate_price($baseOffer, 'monthly', 1, ['slots' => 33]);
assertCatalogSame('slots_out_of_range', $r['error'] ?? null, 'base_plus_extra above maximum rejected');

// base = 0 slots degenerates to pure per-slot pricing
$pureExtraOffer = [
    'pricing_mode' => 'base_plus_extra',
    'slot_min_qty' => 1,
    'slot_max_qty' => 32,
    'base_slots' => 0,
    'base_price_monthly' => 0,
    'extra_price_per_slot_monthly' => 2.00,
];
$r = catalog_calculate_price($pureExtraOffer, 'monthly', 1, ['slots' => 5]);
assertCatalogSame(10.00, $r['total_price'], 'base_plus_extra with 0 base slots behaves like per-slot pricing');

// --- quantity x period sanity across modes ---
$r = catalog_calculate_price($perSlotOffer, 'daily', 7, ['slots' => 2]);
assertCatalogSame(7.00, $r['total_price'], 'per_slot daily x7 quantity (one week)');

// --- invalid input handling ---
$r = catalog_calculate_price($perSlotOffer, 'weekly', 1, ['slots' => 2]);
assertCatalogSame('invalid_period', $r['error'] ?? null, 'unknown period rejected');

$r = catalog_calculate_price($perSlotOffer, 'monthly', 0, ['slots' => 2]);
assertCatalogSame('invalid_quantity', $r['error'] ?? null, 'zero quantity rejected');

$badModeOffer = ['pricing_mode' => 'unknown_mode'];
$r = catalog_calculate_price($badModeOffer, 'monthly', 1, []);
assertCatalogSame('invalid_pricing_mode', $r['error'] ?? null, 'unknown pricing mode rejected');

// --- default tier slot helper ---
$defaults = catalog_default_tier_slots(32);
assertCatalogSame(8, $defaults['small'], 'default small tier is 25% of max slots');
assertCatalogSame(16, $defaults['medium'], 'default medium tier is 50% of max slots');
assertCatalogSame(24, $defaults['large'], 'default large tier is 75% of max slots');
assertCatalogSame(32, $defaults['full'], 'default full tier is 100% of max slots');

echo "CatalogPricingTest passed\n";
