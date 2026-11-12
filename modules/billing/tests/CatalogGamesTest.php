<?php
require_once __DIR__ . '/../includes/catalog_games.php';

if (!function_exists('assertCatalogSame')) {
    function assertCatalogSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
        }
    }
}

// --- text and picture cleaning ---
assertCatalogSame('Rust Server', catalog_clean_text('  Rust <b>Server</b> ', 255), 'tags are removed and spaces trimmed');
assertCatalogSame('abc', catalog_clean_text('abcdef', 3), 'text is cut to the maximum length');
assertCatalogSame('valheim.jpg', catalog_pick_image('valheim.jpg', ['valheim.jpg', 'rust.jpg']), 'a listed picture is accepted');
assertCatalogSame('', catalog_pick_image('../../etc/passwd', ['valheim.jpg']), 'a path is refused');
assertCatalogSame('', catalog_pick_image('other.jpg', ['valheim.jpg']), 'an unlisted picture is refused');

// --- daily and yearly prices come from the monthly price ---
$prices = catalog_period_prices(30.0);
assertCatalogSame(1.0, $prices['daily'], 'daily is monthly divided by 30');
assertCatalogSame(30.0, $prices['monthly'], 'monthly is unchanged');
assertCatalogSame(360.0, $prices['year'], 'yearly is monthly times 12');

// --- reading the form ---
$settings = catalog_read_settings([
    'pricing_mode' => 'per_slot',
    'period_default' => 'monthly',
    'slot_min_qty' => 2,
    'slot_max_qty' => 20,
    'per_slot_price_monthly' => '1.50',
]);
assertCatalogSame('per_slot', $settings['mode'], 'mode is read');
assertCatalogSame(1.5, $settings['per_slot_monthly'], 'per slot price is read');
assertCatalogSame(false, catalog_settings_are_free($settings), 'a priced game is not free');

$badMode = catalog_read_settings(['pricing_mode' => 'nonsense']);
assertCatalogSame('per_slot', $badMode['mode'], 'an unknown mode falls back to per_slot');

$free = catalog_read_settings(['pricing_mode' => 'per_slot', 'per_slot_price_monthly' => '0']);
assertCatalogSame(true, catalog_settings_are_free($free), 'a zero price is free');

// --- import plan ---
$legacy = [
    ['service_id' => 1, 'home_cfg_id' => 101, 'service_name' => 'Valheim', 'img_url' => 'valheim.jpg', 'slot_min_qty' => 2, 'slot_max_qty' => 32, 'price_monthly' => 6.0],
    ['service_id' => 2, 'home_cfg_id' => 102, 'service_name' => 'Minecraft', 'img_url' => '', 'slot_min_qty' => 5, 'slot_max_qty' => 100, 'price_monthly' => 0],
    ['service_id' => 3, 'home_cfg_id' => 999, 'service_name' => 'Gone', 'img_url' => '', 'slot_min_qty' => 1, 'slot_max_qty' => 10, 'price_monthly' => 3.0],
    ['service_id' => 4, 'home_cfg_id' => 103, 'service_name' => 'Rust', 'img_url' => '', 'slot_min_qty' => 5, 'slot_max_qty' => 50, 'price_monthly' => 10.0],
];
$gameIds = [101 => true, 102 => true, 103 => true];
$existing = ['103:1' => true];

$plan = catalog_plan_legacy_import($legacy, $gameIds, [1, 2], $existing, [], ['valheim.jpg']);
assertCatalogSame(1, $plan['counts']['no_config'], 'a game that no longer exists is counted');
assertCatalogSame(1, $plan['counts']['no_price'], 'a game with no price is counted');
assertCatalogSame(2, $plan['counts']['games'], 'two games can be imported');
assertCatalogSame(3, $plan['counts']['offers'], 'Valheim gets 2 offers and Rust gets the 1 that is missing');
assertCatalogSame(1, $plan['counts']['already_set_up'], 'the existing Rust offer is counted and kept');

echo "CatalogGamesTest passed\n";
