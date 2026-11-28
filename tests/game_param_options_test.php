<?php
// Tests for includes/game_param_options.php (pure functions; reads the real garrysmod.xml).
require_once __DIR__ . '/../includes/game_param_options.php';

$failures = 0;
function gpo_check($ok, $name)
{
    global $failures;
    echo ($ok ? 'ok' : 'FAIL') . ' - ' . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

$combo = gpo_combo_params('garrysmod.xml');
gpo_check(isset($combo['+map']) && $combo['+map'] === array('gm_construct', 'gm_flatgrass'), 'the Garry\'s Mod map presets are read from the XML');
gpo_check(isset($combo['+gamemode']) && $combo['+gamemode'] === array('sandbox', 'terrortown'), 'the gamemode presets are read, once across the Windows and Linux sections');
gpo_check(!isset($combo['-insecure']), 'only combo parameters are listed');
gpo_check(gpo_combo_params('no_such_game.xml') === array(), 'a missing XML has no combo parameters');
gpo_check(gpo_combo_params('../../etc/passwd') === array(), 'a path outside the game configs is not read');

$new = gpo_new_values($combo, array('+map' => 'ttt_minecraft_b5', '+gamemode' => 'terrortown', 'players' => '64'));
gpo_check($new === array(array('+map', 'ttt_minecraft_b5')), 'a map the XML does not list is new; a preset gamemode is not');
gpo_check(gpo_new_values($combo, array('+map' => '  ')) === array(), 'an empty value is never learned');
gpo_check(gpo_new_values($combo, array('+map' => str_repeat('a', 129))) === array(), 'a value longer than the column is not learned');

gpo_check(gpo_merge_options(array('gm_construct', 'gm_flatgrass'), array('ttt_67th_way', 'gm_construct')) === array('gm_construct', 'gm_flatgrass', 'ttt_67th_way'), 'presets come first, learned values follow without repeats');

$dom = new DOMDocument();
$dom->load(__DIR__ . '/../modules/config_games/server_configs/garrysmod.xml');
gpo_check(@$dom->schemaValidate(__DIR__ . '/../modules/config_games/schema_server_config.xml'), 'garrysmod.xml still matches the game config schema');

echo $failures ? "\n$failures FAILED\n" : "\nall game param option tests passed\n";
exit($failures ? 1 : 0);
