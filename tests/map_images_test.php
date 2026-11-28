<?php
// php tests/map_images_test.php - map picture names and where get_map_path() looks first.
chdir(__DIR__ . '/..');
require_once 'includes/functions.php';
require_once 'includes/helpers.php';
require_once 'modules/gamemanager/map_images_lib.php';
$fail = 0;
function check($what, $got, $want) { global $fail; if ($got !== $want) { $fail++; echo "FAIL $what: got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"; } }

check('name lower case', map_image_name('De_Dust2'), 'de_dust2');
check('name path cleaned', map_image_name('../../x.php'), '______x_php');
check('dash is no map', map_image_name_ok(map_image_name('-')), false);
check('empty is no map', map_image_name_ok(''), false);
check('real map ok', map_image_name_ok('chernarus'), true);
check('too long', map_image_name_ok(str_repeat('a', 129)), false);
check('home dir', map_image_home_dir('49abc'), 'uploads/map_images/49');
check('types', array_keys(map_image_types()), array('jpg', 'png', 'gif'));

// A server's own upload comes before everything else; other servers do not see it.
$dir = 'uploads/map_images/999999';
@mkdir($dir, 0750, true);
file_put_contents("$dir/testmap_xyz.png", 'x');
check('own picture first', get_map_path('source', 'none', 'TestMap_XYZ', array('home_id' => 999999, 'home_cfg_id' => 0, 'home_cfg_file' => '')), "$dir/testmap_xyz.png");
check('other server', get_map_path('source', 'none', 'testmap_xyz', array('home_id' => 999998, 'home_cfg_id' => 0, 'home_cfg_file' => '')), 'images/map_none.png');
unlink("$dir/testmap_xyz.png"); @rmdir($dir); @rmdir('uploads/map_images'); @rmdir('uploads');

echo $fail ? "$fail map image tests failed\n" : "all map image tests passed\n";
exit($fail ? 1 : 0);
