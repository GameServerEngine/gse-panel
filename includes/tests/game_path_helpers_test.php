<?php

require_once(dirname(__DIR__) . '/game_path_helpers.php');

function gsp_game_path_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$error = '';
gsp_game_path_test_assert(
	gsp_resolve_game_root('/srv/games/42', 'arma3_linux64', $error) === '/srv/games/42/arma3_linux64',
	'derives game_root from management_home and game_key'
);
gsp_game_path_test_assert($error === '', 'valid game key leaves no error');

foreach (array('', '.', '..', '../arma3', 'arma3/other', 'arma3\\other', 'C:arma3', 'arma3:linux', "arma\n3", ' arma3', 'arma3 ', 'CON', 'NUL.txt', 'com1.txt') as $bad_key) {
	$error = '';
	gsp_game_path_test_assert(gsp_resolve_game_root('/srv/games/42', $bad_key, $error) === false, 'rejects unsafe game key ' . json_encode($bad_key));
	gsp_game_path_test_assert($error !== '', 'unsafe game key reports an error');
}

gsp_game_path_test_assert(
	gsp_remote_path_is_child_of('/srv/games/42', '/srv/games/42/arma3_linux64') === true,
	'accepts candidate inside management home'
);
gsp_game_path_test_assert(
	gsp_remote_path_is_child_of('/srv/games/42', '/srv/games/420/arma3_linux64') === false,
	'rejects sibling path that only shares a prefix'
);
gsp_game_path_test_assert(
	gsp_remote_path_is_child_of('/srv/games/42', '/srv/games/42') === false,
	'rejects management home itself as game_root'
);
gsp_game_path_test_assert(
	gsp_remote_path_is_child_of('/srv/games/42', '/srv/games/42/../secret') === false,
	'rejects parent segment escape beneath management home'
);
gsp_game_path_test_assert(
	gsp_remote_path_is_child_of('/cygdrive/c/GSE/Servers/42', '/cygdrive/c/GSE/Servers/42/dayz_win64') === true,
	'accepts Cygwin-style child game root'
);
gsp_game_path_test_assert(
	gsp_remote_path_is_child_of('/cygdrive/c/GSE/Servers/42', '/cygdrive/c/GSE/Servers/420/dayz_win64') === false,
	'rejects Cygwin-style sibling path that only shares a prefix'
);

$home_info = array(
	'home_path' => '/srv/games/42',
	'management_home' => '/srv/games/42',
	'game_root' => '/srv/games/42/arma3_linux64',
);
gsp_game_path_test_assert(
	gsp_resolve_game_payload_path($home_info, 'cfg/server.cfg', $error) === '/srv/games/42/arma3_linux64/cfg/server.cfg',
	'resolves relative payload paths beneath game_root'
);
gsp_game_path_test_assert(
	gsp_resolve_game_payload_path($home_info, '../bot.def', $error) === false,
	'rejects payload traversal above game_root'
);
gsp_game_path_test_assert(
	gsp_resolve_game_payload_path($home_info, '/srv/games/42/tools/bot.exe', $error, true) === '/srv/games/42/tools/bot.exe',
	'allows validated absolute payload path inside management home when requested'
);
gsp_game_path_test_assert(
	gsp_resolve_game_payload_path($home_info, '/srv/games/420/tools/bot.exe', $error, true) === false,
	'rejects absolute payload path outside management home'
);

echo "All game path helper tests passed.\n";

?>
