<?php
/*
 * Administration > Map images: map pictures server owners uploaded for their own server.
 * Apply to all servers copies the picture to the folder every server of that game and mod uses
 * (and drops the server's own copy); Keep for this server only leaves it as it is; Remove deletes it.
 */

function exec_module()
{
	global $db;
	require_once 'modules/gamemanager/map_images_lib.php';
	require_once 'includes/diagnostics/csrf.php';
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };

	if (isset($_POST['upload_id'], $_POST['action']))
	{
		if (!diag_csrf_valid(diag_csrf_from_request()))
			echo "<div class='failure'>" . get_lang('unknown_errors') . "</div>";
		else
			echo map_images_review((int)$_POST['upload_id'], (string)$_POST['action']);
	}

	$rows = $db->resultQuery("SELECT * FROM DB_PREFIXmap_image_upload WHERE status IN ('pending', 'kept') ORDER BY status = 'pending' DESC, uploaded_at DESC");
	echo "<h2>" . get_lang('map_images') . "</h2>";
	echo "<p>" . get_lang('map_images_info') . "</p>";
	if (!$rows)
	{
		echo "<p>" . get_lang('map_images_none') . "</p>";
		return;
	}
	echo "<table class='center map-images'><tr><th></th><th>" . get_lang('map_images_col_map') . "</th><th>" . get_lang('map_images_col_server') . "</th><th>"
		. get_lang('map_images_uploaded_by') . "</th><th>" . get_lang('map_images_col_status') . "</th><th></th></tr>";
	foreach ($rows as $row)
	{
		$home = $db->getGameHome($row['home_id']);
		$user = $db->getUserById($row['user_id']);
		$file = map_image_home_dir($row['home_id']) . '/' . basename($row['file_name']);
		echo "<tr><td>" . (is_file($file) ? "<img src='" . $h($file) . "?" . (int)$row['uploaded_at'] . "' style='width:160px;height:120px;object-fit:cover;'>" : get_lang('map_images_file_missing')) . "</td>"
			. "<td>" . $h($row['map']) . "</td>"
			. "<td>" . ($home ? $h($home['home_name']) . " (#" . (int)$row['home_id'] . ")<br><small>" . $h($home['game_name']) . "</small>" : "#" . (int)$row['home_id']) . "</td>"
			. "<td>" . $h($user ? $user['users_login'] : '#' . $row['user_id']) . "<br><small>" . date('Y-m-d H:i', (int)$row['uploaded_at']) . "</small></td>"
			. "<td>" . ($row['status'] === 'pending' ? get_lang('map_images_pending') : get_lang('map_images_kept')) . "</td><td>";
		foreach (array('apply' => 'map_images_apply', 'keep' => 'map_images_keep', 'remove' => 'map_images_remove') as $action => $label)
		{
			if ($action === 'keep' && $row['status'] === 'kept')
				continue;
			echo "<form method='post' style='display:inline-block;margin:2px;'><input type='hidden' name='csrf' value='" . $h(diag_csrf_token()) . "'>"
				. "<input type='hidden' name='upload_id' value='" . (int)$row['id'] . "'><input type='hidden' name='action' value='$action'>"
				. "<button type='submit' class='btn btn-sm " . ($action === 'remove' ? 'btn-danger' : 'btn-primary') . "'>" . get_lang($label) . "</button></form>";
		}
		echo "</td></tr>";
	}
	echo "</table>";
}

/** Carries out one review action and returns the message to show. */
function map_images_review($id, $action)
{
	global $db;
	$rows = $db->resultQuery("SELECT * FROM DB_PREFIXmap_image_upload WHERE id = " . (int)$id);
	if (!$rows)
		return "<div class='failure'>" . get_lang('unknown_errors') . "</div>";
	$row = $rows[0];
	$dir = map_image_home_dir($row['home_id']);
	$file = $dir . '/' . basename($row['file_name']);
	$done = "status = '%s', reviewed_by = " . (int)$_SESSION['user_id'] . ", reviewed_at = " . time();

	if ($action === 'remove')
	{
		map_image_remove($dir, $row['map']);
		$db->query("DELETE FROM DB_PREFIXmap_image_upload WHERE id = " . (int)$id);
		return "<div class='success'>" . get_lang('map_images_removed') . "</div>";
	}
	if ($action === 'keep')
	{
		$db->query("UPDATE DB_PREFIXmap_image_upload SET " . sprintf($done, 'kept') . " WHERE id = " . (int)$id);
		return "<div class='success'>" . get_lang('map_images_kept_done') . "</div>";
	}
	if ($action === 'apply')
	{
		$home = $db->getGameHome($row['home_id']);
		if (!$home || !is_file($file))
			return "<div class='failure'>" . get_lang('map_images_file_missing') . "</div>";
		$mod_ids = array_keys((array)$home['mods']);
		$target = map_image_global_dir($home, $mod_ids[0]);
		if (!map_image_make_dir($target))
			return "<div class='failure'>" . get_lang_f('cant_create_folder', $target) . "</div>";
		map_image_remove($target, $row['map']);
		if (!@copy($file, $target . '/' . basename($file)))
			return "<div class='failure'>" . get_lang_f('cant_write_file', $target . '/' . basename($file)) . "</div>";
		map_image_remove($dir, $row['map']);
		$db->query("UPDATE DB_PREFIXmap_image_upload SET " . sprintf($done, 'applied') . " WHERE id = " . (int)$id);
		return "<div class='success'>" . get_lang('map_images_applied') . "</div>";
	}
	return "<div class='failure'>" . get_lang('unknown_errors') . "</div>";
}
