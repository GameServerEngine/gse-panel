<?php
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

function exec_gsp_module()
{
	require_once(MODULES."/litefm/litefm.php");

	$home_id = $_REQUEST['home_id'];

	if (empty($home_id))
		return;

	global $db;
	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );

	if($isAdmin)
		$home_cfg = $db->getGameHome($home_id);
	else
		$home_cfg = $db->getUserGameHome($_SESSION['user_id'],$home_id);
	$path_error = '';
	$home_cfg = is_array($home_cfg) ? litefm_attach_game_root($home_cfg, $path_error) : false;
	if ($home_cfg === false) {
		print_failure("Invalid server file root: " . litefm_escape_html($path_error));
		return;
	}

	if ($home_cfg === FALSE)
		return;

	if ( preg_match("/f/",$home_cfg['access_rights']) != 1 )
		return;

	// Validate and recover from bad cached paths early
	$cwd_session_key = 'fm_cwd_' . $home_id;
	if (isset($_SESSION[$cwd_session_key]) && $_SESSION[$cwd_session_key] !== '')
	{
	$testPath = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $_SESSION[$cwd_session_key]);
		if ($testPath === false)
		{
			$_SESSION[$cwd_session_key] = '';
		}
	}

	$downloads_folder = "modules/litefm/downloads";

	if(isset($_GET['remove_did']))
	{
		$did = $_GET['remove_did'];
		if(file_exists("$downloads_folder/$did"))
			unlink("$downloads_folder/$did");
		if(isset($_SESSION['download'][$did]))
			unset($_SESSION['download'][$did]);
		return;
	}

	if(!file_exists($downloads_folder))
	{
		if(!mkdir($downloads_folder))
			return;
	}
	else
	{
		if(!is_writable($downloads_folder))
			return;
	}

	$did = $_GET['did'];

	if(!isset($_SESSION['download'][$did]))
	{
		if (litefm_check($home_id) === FALSE)
			return;
		$_SESSION['download'][$did]['fileph'] = $_SESSION['fm_cwd_'.$home_id];
		$_SESSION['fm_cwd_'.$home_id] = litefm_parent_relative_path(isset($_SESSION['fm_cwd_'.$home_id]) ? $_SESSION['fm_cwd_'.$home_id] : '');
		$_SESSION['download'][$did]['offset'] = 0;
	}

	set_time_limit(0);
	$remote = new GSPRemoteLibrary($home_cfg['agent_ip'], $home_cfg['agent_port'], $home_cfg['encryption_key'], $home_cfg['timeout']);
	$fp = fopen("$downloads_folder/$did", "w");
	$filePath = litefm_safe_join_home_path(litefm_payload_root($home_cfg), isset($_SESSION['download'][$did]['fileph']) ? $_SESSION['download'][$did]['fileph'] : '');
	if ($filePath === false)
	{
		print_failure("Invalid file path");
		return;
	}
	$_SESSION['download'][$did]['offset'] = $remote->remote_get_file_part($filePath, $_SESSION['download'][$did]['offset'], $data);
	if($_SESSION['download'][$did]['offset'] != -1)
		fwrite($fp,$data);
	fclose($fp);
	header("Location: $downloads_folder/$did");
}
?>
