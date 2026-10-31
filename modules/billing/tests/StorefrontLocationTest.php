<?php
require_once __DIR__ . '/../includes/storefront_locations.php';

function storefrontExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Expected: ' . $message);
    }
}

$offers = array(
    array('offer_id' => 1, 'city_location_id' => 10, 'remote_server_name' => 'alpha'),
    array('offer_id' => 2, 'city_location_id' => 10, 'remote_server_name' => 'beta'),
    array('offer_id' => 3, 'city_location_id' => 0, 'remote_server_name' => 'loose'),
    array('offer_id' => 4, 'city_location_id' => 99, 'remote_server_name' => 'hidden-city'),
);
$cities = array(
    array('location_id' => 10, 'name' => 'Columbus', 'region_name' => 'Ohio'),
    array('location_id' => 11, 'name' => 'Dayton', 'region_name' => 'Ohio'),
    array('location_id' => 12, 'name' => 'Boise', 'region_name' => 'Idaho'),
);

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
storefrontExpect($byRegion['Ohio'][1]['offer'] === null, 'Dayton with no machines is unavailable');
storefrontExpect($byRegion['Idaho'][0]['offer'] === null, 'Boise with no machines is unavailable');
storefrontExpect($byRegion['Other servers'][0]['offer']['offer_id'] === 3, 'machine without a city goes to Other servers');
storefrontExpect(!in_array(4, $allOfferIds, true), 'machine in a disabled city is hidden');
storefrontExpect(storefront_place_offer(array()) === null, 'empty city has no placement');

echo 'StorefrontLocationTest passed' . PHP_EOL;
