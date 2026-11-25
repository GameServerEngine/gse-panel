<?php
require_once __DIR__ . '/../includes/catalog_games.php';
require_once __DIR__ . '/../includes/catalog_admin.php';

function gridExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Expected: ' . $message);
    }
}

$agents = array(
    array('remote_server_id' => 7, 'remote_server_name' => 'Game3', 'location_id' => 6, 'city_name' => 'Kansas City'),
    array('remote_server_id' => 8, 'remote_server_name' => 'game5', 'location_id' => 6, 'city_name' => 'Kansas City'),
    array('remote_server_id' => 9, 'remote_server_name' => 'game6', 'location_id' => 4, 'city_name' => 'NYC'),
    array('remote_server_id' => 10, 'remote_server_name' => 'demo', 'location_id' => null, 'city_name' => null),
    array('remote_server_id' => 11, 'remote_server_name' => 'area-only', 'location_id' => 2, 'city_name' => null),
);
$columns = catalog_admin_grid_columns($agents);
$byKey = array();
foreach ($columns as $col) {
    $byKey[$col['key']] = $col;
}

gridExpect(count($columns) === 4, 'servers in one city share a column, cityless servers get their own');
gridExpect($byKey['c6']['agent_ids'] === array(7, 8), 'Kansas City covers both of its servers');
gridExpect($byKey['c6']['label'] === 'Kansas City' && $byKey['c6']['city_id'] === 6, 'a city column is named after the city');
gridExpect($byKey['a10']['label'] === 'demo' && $byKey['a10']['city_id'] === 0, 'a server with no city is named after the server');
gridExpect(isset($byKey['a11']), 'a server in a location that is not a city is treated as having no city');
gridExpect(array_column($columns, 'label') === array('area-only', 'demo', 'Kansas City', 'NYC'), 'columns are sorted by name');
gridExpect(catalog_admin_grid_columns(array()) === array(), 'no servers, no columns');

echo 'CatalogAdminGridTest passed' . PHP_EOL;
