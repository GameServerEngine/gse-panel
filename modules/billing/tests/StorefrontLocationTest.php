<?php
require_once __DIR__ . '/../includes/storefront_locations.php';

function storefrontExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Expected: ' . $message);
    }
}

$offers = array(
    array('offer_id' => 1, 'city_location_id' => 10, 'remote_server_id' => 101, 'remote_server_name' => 'alpha'),
    array('offer_id' => 2, 'city_location_id' => 10, 'remote_server_id' => 102, 'remote_server_name' => 'beta'),
    array('offer_id' => 3, 'city_location_id' => 0, 'remote_server_id' => 103, 'remote_server_name' => 'loose'),
    array('offer_id' => 4, 'city_location_id' => 99, 'remote_server_id' => 104, 'remote_server_name' => 'hidden-city'),
    array('offer_id' => 5, 'city_location_id' => 12, 'remote_server_id' => 105, 'remote_server_name' => 'gamma'),
);
$cities = array(
    array('location_id' => 10, 'name' => 'Columbus', 'region_name' => 'Ohio'),
    array('location_id' => 11, 'name' => 'Dayton', 'region_name' => 'Ohio'),
    array('location_id' => 12, 'name' => 'Boise', 'region_name' => 'Idaho'),
);

// --- basic grouping: cities without an offer are not sold there, not "unavailable" ---
$groups = storefront_build_location_groups($offers, $cities);
$byRegion = array();
$allOfferIds = array();
foreach ($groups as $group) {
    $byRegion[$group['region']] = $group['cities'];
    foreach ($group['cities'] as $entry) {
        if ($entry['offer'] !== null) {
            $allOfferIds[] = $entry['offer']['offer_id'];
        }
    }
}
storefrontExpect(count($groups) === 3, 'Ohio, Idaho and Other servers groups');
storefrontExpect(count($byRegion['Ohio']) === 2, 'Ohio lists two cities');
storefrontExpect($byRegion['Ohio'][0]['offer']['offer_id'] === 1, 'Columbus places on its first machine');
storefrontExpect($byRegion['Ohio'][0]['state'] === 'available', 'Columbus is available');
storefrontExpect($byRegion['Ohio'][1]['offer'] === null && $byRegion['Ohio'][1]['state'] === 'not_sold', 'Dayton has no offer for this game, so it is not sold there');
storefrontExpect($byRegion['Idaho'][0]['offer']['offer_id'] === 5, 'Boise places on its machine');
storefrontExpect($byRegion['Other servers'][0]['offer']['offer_id'] === 3, 'machine without a city goes to Other servers');
storefrontExpect(!in_array(4, $allOfferIds, true), 'machine in an unknown city is hidden');
storefrontExpect(storefront_place_offer(array()) === null, 'empty city has no placement');

// --- a switched-off city is shown as disabled, even though the game is sold there ---
$off = $cities;
$off[0]['enabled'] = 0;
$groups = storefront_build_location_groups($offers, $off);
$columbus = $groups[0]['cities'][0];
storefrontExpect($columbus['state'] === 'disabled' && $columbus['offer'] === null, 'a disabled city is listed as disabled with no offer');
storefrontExpect($columbus['reason'] !== '', 'a disabled city has a reason');

// --- a switched-off region disables its cities ---
$regionOff = $cities;
$regionOff[2]['region_enabled'] = 0;
$groups = storefront_build_location_groups($offers, $regionOff);
$idaho = null;
foreach ($groups as $g) { if ($g['region'] === 'Idaho') { $idaho = $g['cities'][0]; } }
storefrontExpect($idaho['state'] === 'disabled', 'a disabled region disables its cities');

// --- capacity: blocked machines are skipped, and a city with none left is out of capacity ---
$blocked = storefront_blocked_offers($offers, array(101 => 'No spare capacity (ram use is over 70%)'));
storefrontExpect($blocked === array(1 => 'No spare capacity (ram use is over 70%)'), 'blocked offers are keyed by offer id');
$groups = storefront_build_location_groups($offers, $cities, $blocked);
storefrontExpect($groups[0]['cities'][0]['offer']['offer_id'] === 2, 'Columbus falls back to its second machine');
$blocked = storefront_blocked_offers($offers, array(101 => 'full', 102 => 'not responding'));
$groups = storefront_build_location_groups($offers, $cities, $blocked);
storefrontExpect($groups[0]['cities'][0]['state'] === 'no_capacity' && $groups[0]['cities'][0]['offer'] === null, 'Columbus is out of capacity when every machine is blocked');
storefrontExpect($groups[0]['cities'][0]['reason'] === 'full', 'the first reason is kept');

// --- the machine rule ---
$now = 1000000;
$headroom = array('cpu' => 70, 'ram' => 70, 'disk' => 85);
$fresh = array('install_enabled' => 1, 'snapshot_status' => 'ok', 'last_error' => '', 'collected_at' => $now - 60,
    'cpu_percent' => '10.00', 'ram_percent' => '50.00', 'disk_percent' => '40.00');
storefrontExpect(storefront_agent_unavailable_reason($fresh, $headroom, $now) === '', 'a healthy machine is available');
storefrontExpect(storefront_agent_unavailable_reason(array('install_enabled' => 0) + $fresh, $headroom, $now) !== '', 'installs off blocks');
storefrontExpect(storefront_agent_unavailable_reason(array('ram_percent' => '71.00') + $fresh, $headroom, $now) !== '', 'ram over headroom blocks');
storefrontExpect(storefront_agent_unavailable_reason(array('ram_percent' => '70.00') + $fresh, $headroom, $now) === '', 'exactly at headroom is allowed');
storefrontExpect(storefront_agent_unavailable_reason(array('disk_percent' => '90.00') + $fresh, $headroom, $now) !== '', 'disk over headroom blocks');
storefrontExpect(storefront_agent_unavailable_reason(array('snapshot_status' => 'agent_unreachable', 'last_error' => 'No response from agent.') + $fresh, $headroom, $now) !== '', 'a fresh unreachable snapshot blocks');
storefrontExpect(storefront_agent_unavailable_reason(array('collected_at' => $now - 999999, 'snapshot_status' => 'agent_unreachable', 'last_error' => 'x', 'ram_percent' => '99') + $fresh, $headroom, $now) === '', 'an old snapshot never blocks');
storefrontExpect(storefront_agent_unavailable_reason(array('collected_at' => 0, 'ram_percent' => '99') + $fresh, $headroom, $now) === '', 'no snapshot never blocks');
storefrontExpect(storefront_agent_unavailable_reason(array('ram_percent' => null, 'cpu_percent' => '') + $fresh, $headroom, $now) === '', 'missing percentages do not block');

echo 'StorefrontLocationTest passed' . PHP_EOL;
