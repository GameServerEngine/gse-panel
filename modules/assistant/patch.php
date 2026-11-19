<?php
/*
 * Downloads a patch the admin assistant saved (?id=N), or every patch still waiting (?all=1).
 * Administrators only. The patch is plain text for `git apply` in the panel repository; the
 * assistant never applies it.
 */

require_once(__DIR__ . '/assistant_boot.php');

function assistant_patch_main()
{
	global $db;
	$ctx = assistant_boot(false);
	if ($ctx === null) {
		return;
	}
	list($panel_root, $user_id) = $ctx;
	if (!$db->isAdmin($user_id)) {
		return assistant_json(403, array('text' => 'Administrators only.'));
	}
	require_once(__DIR__ . '/assistant_lib.php');
	require_once(__DIR__ . '/assistant_usage.php');
	require_once(__DIR__ . '/assistant_admin_files.php');

	if (isset($_GET['all'])) {
		$rows = $db->resultQuery('SELECT id FROM ' . assistant_table('proposals') . " WHERE kind = 'patch' AND status = 'pending' ORDER BY id");
		$ids = array();
		foreach ((array)$rows as $row) {
			$ids[] = (int)$row['id'];
		}
		$name = 'assistant-patches.patch';
	} else {
		$ids = array(isset($_GET['id']) ? (int)$_GET['id'] : 0);
		$name = 'assistant-patch-' . $ids[0] . '.patch';
	}
	$text = assistant_patch_text($db, $ids);
	if ($text === '') {
		return assistant_json(404, array('text' => 'No such patch.'));
	}
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	echo $text;
}

assistant_patch_main();
?>
