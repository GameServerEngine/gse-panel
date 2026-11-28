<?php
/*
 * Map pictures.
 * - An admin's upload is the picture for every server of that game and mod:
 *   protocol/lgsl/maps/<query name>/<mod>/<map>.<jpg|png|gif>, where get_map_path() looks.
 * - A server owner's upload is the picture for that server only:
 *   uploads/map_images/<home_id>/<map>.<ext>, recorded in map_image_upload as "pending".
 *   On Administration > Map images an admin applies it to all servers, keeps it for that
 *   server only, or removes it.
 */

require_once __DIR__ . '/../config_games/server_config_parser.php';

/** File types accepted, by extension. */
function map_image_types()
{
	return array('jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF);
}

/** The file name get_map_path() looks for: lower case, anything but a-z 0-9 _ becomes _. */
function map_image_name($map)
{
	return preg_replace("/[^a-z0-9_]/", "_", strtolower((string)$map));
}

/** A real map name: not empty, not only underscores (Arma and others report "-"), at most 128 characters. */
function map_image_name_ok($name)
{
	return $name !== '' && strlen($name) <= 128 && !preg_match('/^_+$/', $name);
}

/** Folder of one server's own map pictures, relative to the panel root. */
function map_image_home_dir($home_id)
{
	return 'uploads/map_images/' . (int)$home_id;
}

/** Folder of the map pictures for every server of this server's game and mod. */
function map_image_global_dir(array $home_info, $mod_id)
{
	$mod_info = $home_info['mods'][$mod_id];
	$mod = strtolower($mod_info['mod_name']) == "none" ? $mod_info['mod_key'] : $mod_info['mod_name'];
	$server_xml = read_server_config(SERVER_CONFIG_LOCATION . "/" . $home_info['home_cfg_file'], os_for_install_row($home_info));
	if ($server_xml && $server_xml->protocol == "gameq")
		$query_name = $server_xml->gameq_query_name;
	elseif ($server_xml && $server_xml->protocol == "lgsl")
		$query_name = $server_xml->lgsl_query_name;
	else
		$query_name = $mod; // No query name: the mod key, as the monitor does.
	$query_name = preg_replace("/[^a-z0-9_]/i", "", (string)$query_name);
	$mod = preg_replace("/[^a-z0-9_]/i", "", (string)$mod);
	return "protocol/lgsl/maps/$query_name/$mod";
}

/** The picture file of $name in $dir (any accepted type), or ''. */
function map_image_find($dir, $name)
{
	foreach (array_keys(map_image_types()) as $ext)
		if (is_file("$dir/$name.$ext"))
			return "$dir/$name.$ext";
	return '';
}

/** Removes every accepted type of $name from $dir, so a new png replaces an old jpg. */
function map_image_remove($dir, $name)
{
	foreach (array_keys(map_image_types()) as $ext)
		if (is_file("$dir/$name.$ext"))
			@unlink("$dir/$name.$ext");
}

function map_image_make_dir($dir)
{
	return is_dir($dir) || @mkdir($dir, 0750, true);
}
