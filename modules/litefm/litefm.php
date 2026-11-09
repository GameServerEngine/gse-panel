<?php
if (!function_exists('with_game_root')) {
	require_once dirname(__DIR__, 2) . '/includes/game_path_helpers.php';
}

/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

require_once('includes/lib_remote.php');
require_once('modules/config_games/server_config_parser.php');

function litefm_decode_name_param($value)
{
	return rawurldecode((string)$value);
}

function litefm_escape_html($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function litefm_is_valid_path_component($name)
{
	if (!is_string($name) || $name === '' || $name === '.' || $name === '..') {
		return false;
	}
	if (strpos($name, "\0") !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
		return false;
	}
	return true;
}

function litefm_normalize_relative_path($relativePath)
{
	$relativePath = str_replace('\\', '/', (string)$relativePath);
	$relativePath = preg_replace('#/+#', '/', $relativePath);
	$relativePath = trim($relativePath, '/');
	if ($relativePath === '') {
		return '';
	}
	if (strpos($relativePath, "\0") !== false) {
		return false;
	}
	if (preg_match('#(^|/)\.{1,2}(/|$)#', $relativePath)) {
		return false;
	}
	foreach (explode('/', $relativePath) as $segment) {
		if (!litefm_is_valid_path_component($segment)) {
			return false;
		}
	}
	return $relativePath;
}

function litefm_path_within_home($homePath, $candidatePath)
{
	$homeNorm = str_replace('\\', '/', clean_path((string)$homePath));
	$candidateNorm = str_replace('\\', '/', clean_path((string)$candidatePath));
	$homeCmp = strtolower(rtrim($homeNorm, '/'));
	$candidateCmp = strtolower($candidateNorm);
	if ($candidateCmp === $homeCmp) {
		return true;
	}
	return strpos($candidateCmp, $homeCmp . '/') === 0;
}

function litefm_payload_root(array $homeCfg)
{
	return isset($homeCfg['game_root']) && $homeCfg['game_root'] !== '' ? $homeCfg['game_root'] : $homeCfg['home_path'];
}

function litefm_attach_game_root(array $homeCfg, &$error = '')
{
	$error = '';
	if (empty($homeCfg['home_cfg_file'])) {
		$error = 'Server XML configuration is missing.';
		return false;
	}
	$serverXml = read_server_config(SERVER_CONFIG_LOCATION . '/' . $homeCfg['home_cfg_file'], os_for_install_row($homeCfg));
	if ($serverXml === false) {
		$error = 'Server XML configuration could not be loaded.';
		return false;
	}
	return with_game_root($homeCfg, $serverXml, $error);
}

function litefm_safe_join_home_path($homePath, $relativePath)
{
	$normalizedRel = litefm_normalize_relative_path($relativePath);
	if ($normalizedRel === false) {
		return false;
	}
	$fullPath = clean_path(rtrim((string)$homePath, '/') . '/' . $normalizedRel);
	if (!litefm_path_within_home($homePath, $fullPath)) {
		return false;
	}
	return $fullPath;
}

function litefm_display_home_path($homePath, $relativePath)
{
	// Shown relative to the jailed game folder: nothing at the root, /AddOns for a subfolder.
	$normalizedRel = litefm_normalize_relative_path($relativePath);
	if ($normalizedRel === false) {
		return false;
	}
	return $normalizedRel === '' ? '' : '/' . $normalizedRel;
}

function litefm_parent_relative_path($relativePath)
{
	$normalizedRel = litefm_normalize_relative_path($relativePath);
	if ($normalizedRel === false || $normalizedRel === '') {
		return '';
	}
	$lastSlash = strrpos($normalizedRel, '/');
	if ($lastSlash === false) {
		return '';
	}
	return substr($normalizedRel, 0, $lastSlash);
}

function do_progress($kbytes,$totalsize)
{
	if( $totalsize != 0 )
	{
		$mbytes = round($kbytes / 1024, 2);

		if($kbytes > 0)
		{
			$pct = round(( $kbytes / $totalsize ) * 100, 2);
		}
		else
		{
			$pct = get_lang("unavailable");
		}
		#echo "Percent is $pct";
		return "$totalsize;$mbytes;$pct";
	}
	return "0;0;0";
}

function show_back($home_id)
{
    if( isset($_SESSION['fm_cwd_'.$home_id]) && preg_match("/^\/*$/",$_SESSION['fm_cwd_'.$home_id]) == 0 )
        return "<tr><td colspan='5' ><a class='btn btn-sm btn-success' href=\"?m=litefm&amp;home_id=$home_id&amp;back\"> ..&nbsp;&nbsp;".get_lang("level_up")."</a></td></tr>";
}

function litefm_check($home_id)
{
	if (isset($_GET['item']) and !isset($_GET['upload']) and !isset( $_POST['delete'] ) and !isset( $_POST['create_folder'] ) and !isset( $_POST['secureButton'] ) and !isset( $_POST['delete_check'] ) and !isset( $_POST['secure_check'] ))
    {
		$fileName = !empty($_POST['name']) ? litefm_decode_name_param($_POST['name']) : litefm_decode_name_param(isset($_GET['name']) ? $_GET['name'] : '');
		if (!litefm_is_valid_path_component($fileName))
		{
			print_failure("Path decode failed");
			return FALSE;
		}
		if(isset($_GET['type'])){
			$type = $_GET['type'];
		}else{
			$type = "file";
		}

        if(!isset($_SESSION['fm_files_'.$home_id][$_GET['item']]))
			return FALSE;

		$path = $_SESSION['fm_files_'.$home_id][$_GET['item']];
		if($path == $fileName){
			if($type != "file"){
				$nextPath = trim((string)@$_SESSION['fm_cwd_'.$home_id], '/');
				$nextPath = $nextPath === '' ? $path : $nextPath . '/' . $path;
				$normalizedNext = litefm_normalize_relative_path($nextPath);
				if($normalizedNext === false)
				{
					print_failure(get_lang("unallowed_char"));
					$_SESSION['fm_cwd_'.$home_id] = NULL;
					return FALSE;
				}
				$_SESSION['fm_cwd_'.$home_id] = $normalizedNext;
			}else{
				if((isset($_SESSION['fm_cwd_'.$home_id]) and !endsWith($_SESSION['fm_cwd_'.$home_id], $path)) or !isset($_SESSION['fm_cwd_'.$home_id])){
					$nextPath = trim((string)@$_SESSION['fm_cwd_'.$home_id], '/');
					$nextPath = $nextPath === '' ? $path : $nextPath . '/' . $path;
					$normalizedNext = litefm_normalize_relative_path($nextPath);
					if($normalizedNext === false)
					{
						print_failure(get_lang("unallowed_char"));
						$_SESSION['fm_cwd_'.$home_id] = NULL;
						return FALSE;
					}
					$_SESSION['fm_cwd_'.$home_id] = $normalizedNext;
				}
			}
		}
    }

    // To go back a dir, we just use dirname to strip the last directory or file off the path
    if (isset($_GET['back']) and !isset($_GET['upload']) and !isset( $_POST['delete'] ) and !isset( $_POST['create_folder'] ) and !isset( $_POST['secureButton'] ) and !isset( $_POST['delete_check'] ) and !isset( $_POST['secure_check'] ))
    {
		$_SESSION['fm_cwd_'.$home_id] = litefm_parent_relative_path(isset($_SESSION['fm_cwd_'.$home_id]) ? $_SESSION['fm_cwd_'.$home_id] : '');
    }

    return TRUE;
}
?>
