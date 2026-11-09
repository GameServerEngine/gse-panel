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
require_once("includes/lib_remote.php");
require_once("modules/config_games/server_config_parser.php");
require_once("modules/steam_workshop/functions.php");
require_once('includes/form_table_class.php');

function exec_module()
{
	Global $db,$view;
	echo '<h2>Steam Workshop</h2>';
	define('CONFIGS', "modules/steam_workshop/game_configs/");

	if(isset($_GET['home_id-mod_id-ip-port']) && $_GET['home_id-mod_id-ip-port'] != "")
		list($home_id, $mod_id, $ip, $port) = explode("-", $_GET['home_id-mod_id-ip-port']);
	else
	{
		print_failure(get_lang('no_game_servers_assigned'));
		return;
	}

	if(!isset($_POST['uninstall']))
	{
		echo "<ul>".
			 "<li><a href='?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."'>".get_lang('install_mods')."</a></li>".
			 "<li><a href='?m=gamemanager&p=game_monitor&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."'>".get_lang('back')."</a></li>".
			 "</ul>";
	}

	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );

	if($isAdmin)
		$home_cfg = $db->getGameHome($home_id);
	else
		$home_cfg = $db->getUserGameHome($_SESSION['user_id'],$home_id);

	if($home_cfg)
	{
		$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_cfg['home_cfg_file'], os_for_install_row($home_cfg));
		if($server_xml === FALSE)
		{
			print_failure(get_lang_f('failed_reading_xml_file', SERVER_CONFIG_LOCATION."/".$home_cfg['home_cfg_file']));
			return;
		}
		$path_error = '';
		$home_cfg = with_game_root($home_cfg, $server_xml, $path_error);
		if ($home_cfg === false) {
			print_failure('Invalid game directory for this server: ' . htmlentities($path_error));
			return;
		}

		if(!isset($home_cfg['mods'][$mod_id]['mod_key']))
		{
			print_failure(get_lang_f('mod_id_does_not_exists_in_home', $mod_id, $home_id));
			return;
		}

		$modkey = $home_cfg['mods'][$mod_id]['mod_key'];
		$mod_xml = xml_get_mod($server_xml, $modkey);

		if (!$mod_xml)
		{
			print_failure(get_lang_f('mod_key_not_found_from_xml', $modkey));
			return;
		}

		preg_match('/(linux|win)(32|64)?/i', $home_cfg['game_key'], $matches);

		if(!isset($matches[1]))
		{
			print_failure(get_lang_f('unable_to_get_os_from_game_key', $home_cfg['game_key']));
			return;
		}

		if(strtolower($matches[1]) == 'linux')
			$os = "Linux";
		elseif(strtolower($matches[1]) == 'win')
			$os = "Windows";

		if(!isset($os))
		{
			print_failure(get_lang_f('unable_to_get_os_from_game_key', $home_cfg['game_key']));
			return;
		}

		$xml_file = CONFIGS.$mod_xml->installer_name."_".$os.".xml";

		if(!file_exists($xml_file))
		{
			print_failure(get_lang('no_workshop_configuration_available_for_this_game'));
			return;
		}

		$dom = new DOMDocument();

		if ( @$dom->load($xml_file) === FALSE )
		{
			print_failure(get_lang('workshop_configuration_file_has_bad_format'));
			return;
		}

		$xml = simplexml_load_file($xml_file);

		if($xml !== false)
		{
			$remote = new RemoteLibrary($home_cfg['agent_ip'],$home_cfg['agent_port'],$home_cfg['encryption_key'], $home_cfg['timeout']);

			if($remote->status_chk() !== 1)
			{
				print_failure(get_lang('remote_server_offline'));
			}

			if(isset($_POST['uninstall']) and isset($_POST['mod_string']))
			{
				$output = "";
				foreach($_POST['mod_string'] as $mod_string)
				{
					$result = remove_mod($home_cfg, $remote, $xml, $mod_string);
					if($result !== FALSE)
						$output .= $result."\n";
					else
						$output .= get_lang_f('failed_uninstalling_mod', $mod_string)."\n";
				}
				echo "<pre>$output</pre>";
				echo "<a href='?m=steam_workshop&p=uninstall&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."'>".get_lang('back')."</a>";
			}
			else
			{
				$mods = get_installed_mods($home_cfg, $remote, $xml);

				if($mods and count($mods) > 0)
				{
					$mod_line = steam_workshop_build_mod_line($mods, $xml);
					$ft = new FormTable();
					$ft->start_form("?m=steam_workshop&p=uninstall&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port'], "post", "autocomplete=\"off\"");
					$ft->start_table();
					echo "<tr><td colspan='2'><strong>".get_lang('workshop_mod_line')."</strong><br>".
						 "<textarea readonly style='width:100%;min-height:70px;box-sizing:border-box;font-family:monospace;'>".steam_workshop_h($mod_line)."</textarea></td></tr>";
					echo '<tr><td colspan="2"><div id="uninstall_scrolling_checkbox">';
					echo "<table class='center' style='width:100%;'><tr><th></th><th>".get_lang('workshop_mod_title')."</th><th>".get_lang('workshop_id')."</th><th>".get_lang('installed_folder')."</th><th>".get_lang('install_status')."</th><th>".get_lang('install_time')."</th></tr>";
					foreach($mods as $mod_id => $mod_record)
					{
						if(is_array($mod_record))
						{
							$title = isset($mod_record['title']) && $mod_record['title'] !== '' ? $mod_record['title'] : $mod_record['mod_string'];
							$workshop_mod_id = isset($mod_record['workshop_mod_id']) ? $mod_record['workshop_mod_id'] : '';
							$installed_folder = isset($mod_record['installed_folder']) ? $mod_record['installed_folder'] : '';
							$installed_path = isset($mod_record['installed_path']) ? $mod_record['installed_path'] : '';
							$install_status = isset($mod_record['install_status']) ? $mod_record['install_status'] : '';
							$install_time = isset($mod_record['install_time']) ? $mod_record['install_time'] : '';
						}
						else
						{
							$title = $mod_record;
							$workshop_mod_id = preg_match('/^\d+$/', (string)$mod_id) ? $mod_id : '';
							$installed_folder = $mod_id;
							$installed_path = '';
							$install_status = 'installed';
							$install_time = '';
						}
						echo "<tr>".
							 "<td><input type='checkbox' id='select_mod_".steam_workshop_h($mod_id)."' name='mod_string[]' value='".steam_workshop_h($mod_id)."'></td>".
							 "<td><label for='select_mod_".steam_workshop_h($mod_id)."'>".steam_workshop_h($title)."</label></td>".
							 "<td>".steam_workshop_h($workshop_mod_id)."</td>".
							 "<td title='".steam_workshop_h($installed_path)."'>".steam_workshop_h($installed_folder)."</td>".
							 "<td>".steam_workshop_h($install_status)."</td>".
							 "<td>".steam_workshop_h($install_time)."</td>".
							 "</tr>";
					}
					echo '</table></div></td></tr>';
					$ft->end_table();
					$ft->add_button("submit", "uninstall", get_lang('uninstall_mods'));
					$ft->end_form();
				}
				else
				{
					print_failure(get_lang('there_are_no_mods_installed_on_this_game_server'));
					return;
				}
			}
		}
		else
		{
			print_failure(get_lang('workshop_configuration_file_has_bad_format'));
			return;
		}
	}
	else
	{
		print_failure(get_lang('game_home_not_found'));
		return;
	}
}
?>
