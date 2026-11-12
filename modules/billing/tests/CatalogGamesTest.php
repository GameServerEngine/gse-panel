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

// --- import using the agents saved on the old rows ---
assertCatalogSame([1, 3], catalog_saved_agent_ids('1, 3,x,99,3', [1, 2, 3]), 'saved agents keep real agents, once each');
assertCatalogSame([], catalog_saved_agent_ids('', [1, 2]), 'an empty list gives no agents');

$legacySaved = [
    ['service_id' => 1, 'home_cfg_id' => 101, 'service_name' => 'Valheim', 'img_url' => '', 'slot_min_qty' => 2, 'slot_max_qty' => 32, 'price_monthly' => 6.0, 'remote_server_id' => '1,2'],
    ['service_id' => 4, 'home_cfg_id' => 103, 'service_name' => 'Rust', 'img_url' => '', 'slot_min_qty' => 5, 'slot_max_qty' => 50, 'price_monthly' => 10.0, 'remote_server_id' => '1,9'],
    ['service_id' => 5, 'home_cfg_id' => 102, 'service_name' => 'Minecraft', 'img_url' => '', 'slot_min_qty' => 5, 'slot_max_qty' => 50, 'price_monthly' => 4.0, 'remote_server_id' => ''],
];
$planSaved = catalog_plan_legacy_import($legacySaved, $gameIds, [], $existing, [], [], true, [1, 2]);
assertCatalogSame(1, $planSaved['counts']['games'], 'a game with no saved agents, and a game whose only real agent is already set up, are left out');
assertCatalogSame(2, $planSaved['counts']['offers'], 'Valheim gets agents 1 and 2; the unknown agent 9 on Rust is ignored');
assertCatalogSame(1, $planSaved['counts']['already_set_up'], 'the existing Rust offer on agent 1 is kept');
assertCatalogSame([1, 2], $planSaved['games'][0]['agent_ids'], 'Valheim agents come from its saved list');

$planBoth = catalog_plan_legacy_import($legacySaved, $gameIds, [2], [], [], [], true, [1, 2]);
assertCatalogSame([1, 2], $planBoth['games'][0]['agent_ids'], 'ticked and saved agents are joined without repeats');
assertCatalogSame([2], $planBoth['games'][2]['agent_ids'], 'a game with no saved agents gets the ticked ones');

// --- placement headroom form ---
assertCatalogSame(['cpu' => 70, 'ram' => 60, 'disk' => 85], catalog_read_headroom(['headroom_cpu_pct' => '70', 'headroom_ram_pct' => ' 60 ', 'headroom_disk_pct' => '85']), 'valid headroom is read');
assertCatalogSame(null, catalog_read_headroom(['headroom_cpu_pct' => '0', 'headroom_ram_pct' => '60', 'headroom_disk_pct' => '85']), 'zero is refused');
assertCatalogSame(null, catalog_read_headroom(['headroom_cpu_pct' => '101', 'headroom_ram_pct' => '60', 'headroom_disk_pct' => '85']), 'over 100 is refused');
assertCatalogSame(null, catalog_read_headroom(['headroom_cpu_pct' => '5.5', 'headroom_ram_pct' => '60', 'headroom_disk_pct' => '85']), 'a decimal is refused');
assertCatalogSame(null, catalog_read_headroom(['headroom_cpu_pct' => '5', 'headroom_ram_pct' => '60']), 'a missing value is refused');

// --- one row per game config file ---
assertCatalogSame('7daystodie.jpg', catalog_guess_image('7daystodie.xml', ['7dtd.jpg', '7daystodie.jpg']), 'a picture named after the config file is found');
assertCatalogSame('', catalog_guess_image('arkse.xml', ['7daystodie.jpg']), 'no picture for an unrelated config');
$adminGroups = catalog_group_admin_games(array(
    array('home_cfg_id' => 1, 'home_cfg_file' => 'a.xml', 'game_name' => 'A', 'published_agents' => 0, 'from_price' => null),
    array('home_cfg_id' => 2, 'home_cfg_file' => 'a.xml', 'game_name' => 'A', 'published_agents' => 2, 'from_price' => 5.0),
    array('home_cfg_id' => 3, 'home_cfg_file' => 'b.xml', 'game_name' => 'B', 'published_agents' => 0, 'from_price' => null),
));
assertCatalogSame(2, count($adminGroups), 'admin list has one row per config file');
assertCatalogSame(2, (int)$adminGroups[0]['home_cfg_id'], 'the variant with agents is the row shown');
assertCatalogSame(2, (int)$adminGroups[0]['published_agents'], 'agent counts are added up');
assertCatalogSame(5.0, $adminGroups[0]['from_price'], 'the lowest price is kept');
assertCatalogSame(array(1, 2), $adminGroups[0]['variant_ids'], 'every variant id is kept');
$storeGroups = catalog_group_storefront_games(array(
    array('home_cfg_id' => 4, 'home_cfg_file' => 'c.xml', 'game_name' => 'C', 'starting_price_monthly' => 10.0, 'slot_min_qty' => 1, 'slot_max_qty' => 8, 'img_url' => ''),
    array('home_cfg_id' => 5, 'home_cfg_file' => 'c.xml', 'game_name' => 'C', 'starting_price_monthly' => 7.5, 'slot_min_qty' => 2, 'slot_max_qty' => 16, 'img_url' => 'c.jpg'),
), array('c.jpg'));
assertCatalogSame(1, count($storeGroups), 'storefront has one card per config file');
assertCatalogSame(5, (int)$storeGroups[0]['home_cfg_id'], 'the card links to the cheapest variant');
assertCatalogSame(7.5, $storeGroups[0]['starting_price_monthly'], 'the card shows the lowest price');
assertCatalogSame(1, (int)$storeGroups[0]['slot_min_qty'], 'slot range covers every variant (min)');
assertCatalogSame(16, (int)$storeGroups[0]['slot_max_qty'], 'slot range covers every variant (max)');
assertCatalogSame('c.jpg', $storeGroups[0]['img_url'], 'the chosen picture is kept');

echo "CatalogGamesTest passed\n";
