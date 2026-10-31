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
echo '<link rel="stylesheet" type="text/css" href="css/xbbcode/xbbcode.css">'."\n".
	 '<script type="text/javascript" src="js/xbbcode/xbbcode.js"></script>'."\n".
	 '<script type="text/javascript" src="js/modules/steam_workshop.js"></script>';

function steam_workshop_render_nav($home_key)
{
	echo "<div class='mb-3'>".
		 "<a class='btn btn-sm btn-danger mr-2' href='?m=steam_workshop&p=uninstall&home_id-mod_id-ip-port=".$home_key."'>".get_lang('uninstall_mods')."</a>".
		 "<a class='btn btn-sm btn-primary' href='?m=gamemanager&p=game_monitor&home_id-mod_id-ip-port=".$home_key."'>".get_lang('back')."</a>".
		 "</div>";
}

function exec_gsp_module()
{

	Global $db,$view,$settings;
	echo '<h2>Steam Workshop</h2>';
	define('CONFIGS', "modules/steam_workshop/game_configs/");

	if(isset($_GET['home_id-mod_id-ip-port']) && $_GET['home_id-mod_id-ip-port'] != "")
		list($home_id, $mod_id, $ip, $port) = explode("-", $_GET['home_id-mod_id-ip-port']);
	else
	{
		print_failure(get_lang('no_game_servers_assigned'));
		return;
	}

	if(!isset($_GET['show_log']))
		steam_workshop_render_nav($_GET['home_id-mod_id-ip-port']);

	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );

	if($isAdmin)
		$home_cfg = $db->getGameHome($home_id);
	else
		$home_cfg = $db->getUserGameHome($_SESSION['user_id'],$home_id);

	if($home_cfg)
	{
		$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_cfg['home_cfg_file'], gsp_os_for_install_row($home_cfg));
		if($server_xml === FALSE)
		{
			print_failure(get_lang_f('failed_reading_xml_file', SERVER_CONFIG_LOCATION."/".$home_cfg['home_cfg_file']));
			return;
		}
		$path_error = '';
		$home_cfg = gsp_with_game_root($home_cfg, $server_xml, $path_error);
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

		if(preg_match('/(linux|win)(32|64)?/i', $home_cfg['game_key'], $matches))
		{
			$os = "";
			if(strtolower($matches[1]) == 'linux')
				$os = "Linux";
			elseif(strtolower($matches[1]) == 'win')
				$os = "Windows";
		}
		else
		{
			print_failure(get_lang_f('unable_to_get_os_from_game_key', $home_cfg['game_key']));
			return;
		}

		$xml_file = CONFIGS.$mod_xml->installer_name."_".$os.".xml";

		if(!file_exists($xml_file))
		{
			print_failure(get_lang('workshop_configuration_not_found'));
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
			$workshop_id = (string)$xml->workshop_id;
			$search_query = isset($_GET['workshop_search']) ? trim((string)$_GET['workshop_search']) : '';
			$search_results = array();
			$search_error = '';
			if($search_query !== '')
				$search_results = steam_workshop_search_items($workshop_id, $search_query, $search_error);

			$remote = new GSPRemoteLibrary($home_cfg['agent_ip'],$home_cfg['agent_port'],$home_cfg['encryption_key'], $home_cfg['timeout']);

			if($remote->status_chk() !== 1)
			{
				print_failure(get_lang('remote_server_offline'));
			}

			if(isset($_GET['show_log']))
			{
				$update_active = $remote->get_log(GSP_SCREEN_TYPE_UPDATE,$home_id,clean_path($home_cfg['home_path']),$log_txt);
				if ( $update_active == 1 && !steam_workshop_log_is_terminal($log_txt) )
				{
					if(isset($_POST['sgc']))
					{
						$remote->send_steam_guard_code($home_id, $_POST['sgc']);
						return;
					}
					echo "<p class='note'>". get_lang("update_in_progress") ."</p>\n";
					echo "<pre>".$log_txt."</pre>\n</script>\n<div id='dialog' ></div>\n";
					if(preg_match('/Two-factor code:$/m', $log_txt) and !isset($_GET['get_sgc']))
					{
						$view->refresh("?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."&get_sgc=show&show_log",0);
						return;
					}
					if(isset($_GET['get_sgc']) && $_GET['get_sgc'] == 'show')
						return;

					echo "<p><a href=\"?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."&show_log\">";
					echo get_lang("refresh_steam_workshop_status") ."</a></p>";
					$view->refresh("?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."&show_log",5);
				}
				else
				{
					print_success( get_lang("update_completed") );
					echo "<pre>".$log_txt."</pre>\n";
					echo "<table class='center'><tr><td><a class='btn btn-sm btn-primary' href='?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."'><< ". get_lang("back") ."</a></td></tr></table>";
				}
			}
			else
			{
				if(isset($_POST['install']) || isset($_POST['show_info']))
				{
					$failure = false;
					$requested_ids = steam_workshop_collect_requested_ids(
						isset($_POST['manual_workshop_mod_id']) ? $_POST['manual_workshop_mod_id'] : '',
						isset($_POST['workshop_mod_id']) ? $_POST['workshop_mod_id'] : array(),
						isset($_POST['search_workshop_mod_id']) ? $_POST['search_workshop_mod_id'] : array()
					);
					if(empty($requested_ids))
					{
						print_failure(get_lang('select_at_least_one_mod_or_enter_mod_id'));
						$failure = true;
					}
					else
					{
						$sync_errors = array();
						if(!steam_workshop_sync_xml_mods($xml, $xml_file, $workshop_id, $requested_ids, $sync_errors))
						{
							foreach($sync_errors as $sync_error)
								print_failure($sync_error);
							$failure = true;
						}
						$mods_list = implode(',', $requested_ids);
					}

					if(isset($_POST['install']) and !$failure and isset($mods_list) and preg_match('/^([0-9]+,?)+$/', $mods_list))
					{
						$config = $xml->config;
						$anonymous_login = $xml->anonymous_login;
						$download_method = $xml->download_method;
						$user = $settings['steam_user'];
						$pass = $settings['steam_pass'];
						$regex = $config->regex;
						$mods_backreference_index = (int)$config->mods_backreference_index;
						$variable = $config->variable;
						$place_after = $config->place_after;
						$mod_string = $config->mod_string;
						$string_separator = $config->string_separator;
						$config_file_path = steam_workshop_resolve_config_filepath($home_cfg['game_root'], $config->filepath);
						$post_install = $xml->post_install;
						$mod_names_list = get_mod_names_list($mods_list, $xml->mods->mod);
						$mods_full_path = clean_path($home_cfg['game_root'].'/'.$xml->mods_path);
						$url_list = "";
						$filename_list = "";
						if($download_method == "steamapi")
						{
							foreach(explode(',', $mods_list) as $workshop_mod_id)
							{
								foreach($xml->mods->mod as $mod)
								{
									if($mod['id'] == $workshop_mod_id)
									{
										$separator = $url_list == ""?"":",";
										$url_list .= $separator.$mod->download_url;
										$filename_list .= $separator.$mod->filename;
									}
								}
							}
						}

						$steam_out = $remote->steam_workshop($home_id, $mods_full_path,
															 $workshop_id, $mods_list,
															 $regex, $mods_backreference_index,
															 $variable, $place_after, $mod_string,
															 $string_separator, $config_file_path,
															 $post_install, $mod_names_list,
															 $anonymous_login, $user, $pass,
															 $download_method, $url_list, $filename_list);
						if ( $steam_out === 1 )
						{
							print_success( get_lang("mod_installation_started") );
							$view->refresh("?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."&show_log", 2);
						}
						elseif( $steam_out === 0 )
						{
							print_failure( get_lang("failed_to_start_steam_workshop") );
							return;
						}
						elseif ( $steam_out === -1 )
						{
							print_failure( get_lang("connection_error") );
						}
					}

					if(isset($_POST['show_info']) and !$failure and isset($mods_list) and preg_match('/^([0-9]+,?)+$/', $mods_list))
					{
						$mod_id_array = explode(',', $mods_list);
						echo "<table>";
						foreach($xml->mods->mod as $mod)
						{
							if(in_array($mod['id'],$mod_id_array))
							{
								echo "<tr><td><h4>".$mod->name."</h4>".
									 "<div><img width='240px' style='float:left;' src='".$mod->image_url."'>".
									 "<div class='bbcode_container' style='padding-left:245px;'>".htmlentities(base64_decode($mod->description))."</div></div><td><tr>";
							}
						}
						echo "</table><a class='btn btn-sm btn-primary mt-2' href='?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port']."'>".get_lang('back')."</a>";
					}
				}
				else
				{
					if($workshop_id != "")
					{
						echo "<form action='' method='get' class='mb-3'>".
							 "<input type='hidden' name='m' value='steam_workshop' />".
							 "<input type='hidden' name='p' value='main' />".
							 "<input type='hidden' name='home_id-mod_id-ip-port' value='".steam_workshop_h($_GET['home_id-mod_id-ip-port'])."' />".
							 "<table class='center'>".
							 "<tr><td><strong>".get_lang('workshop_search')."</strong></td><td>".
							 "<input type='text' name='workshop_search' size='42' value='".steam_workshop_h($search_query)."' placeholder='".steam_workshop_h(get_lang('workshop_search_placeholder'))."' />".
							 "</td><td><button type='submit' class='btn btn-sm btn-primary'>".get_lang('search')."</button></td></tr>".
							 "<tr><td></td><td colspan='2'><small>".get_lang_f('workshop_search_info', steam_workshop_h($workshop_id))."</small></td></tr>".
							 "</table>".
							 "</form>";

						if($search_query !== '')
						{
							if($search_error !== '')
								print_failure($search_error);
							elseif(empty($search_results))
								echo "<p>".get_lang('no_workshop_items_found')."</p>";
						}
					}

					$ft = new FormTable();
					$ft->start_form("?m=steam_workshop&p=main&home_id-mod_id-ip-port=".$_GET['home_id-mod_id-ip-port'], "post", "onsubmit='return isValidForm(this)' data-form-error='".get_lang('select_at_least_one_mod_or_enter_mod_id')."'");
					$ft->start_table();
					if(!empty($search_results))
					{
						echo "<tr><td colspan='2'><div style='max-height:460px; overflow:auto; padding-right:6px;'>";
						foreach($search_results as $search_result)
						{
							$short_description = trim($search_result['description']);
							if(strlen($short_description) > 220)
								$short_description = substr($short_description, 0, 217).'...';

							echo "<div style='display:flex; gap:12px; padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.1); align-items:flex-start;'>".
								 "<div><input type='checkbox' id='search_mod_".$search_result['id']."' name='search_workshop_mod_id[]' value='".steam_workshop_h($search_result['id'])."' /></div>".
								 "<div style='min-width:96px;'>";
							if($search_result['preview_url'] !== '')
								echo "<label for='search_mod_".$search_result['id']."'><img src='".steam_workshop_h($search_result['preview_url'])."' alt='".steam_workshop_h($search_result['title'])."' style='width:96px; max-width:96px; height:auto; border-radius:4px;' /></label>";
							echo "</div><div style='flex:1; min-width:0; text-align:left;'>" .
								 "<label for='search_mod_".$search_result['id']."' style='display:block; font-weight:bold;'>".steam_workshop_h($search_result['title'])."</label>".
								 "<div><small>".get_lang('workshop_id').": ".steam_workshop_h($search_result['id'])."</small></div>";
							if($search_result['author'] !== '')
								echo "<div><small>Author: ".steam_workshop_h($search_result['author'])."</small></div>";
							if($short_description !== '')
								echo "<div style='margin-top:4px;'>".steam_workshop_h($short_description)."</div>";
							if($search_result['url'] !== '')
								echo "<div style='margin-top:6px;'><a target='_blank' rel='noopener' href='".steam_workshop_h($search_result['url'])."'>".get_lang('open_workshop_page')."</a></div>";
							echo "</div></div>";
						}
						echo "</div></td></tr>";
					}
					if(count($xml->mods->mod) > 0)
					{
						echo '<tr><td colspan=2><div id="uninstall_scrolling_checkbox">';
						foreach($xml->mods->mod as $mod)
							echo "<input type='checkbox' id='select_mod_$mod[id]' name='workshop_mod_id[]' value='$mod[id]'><label for='select_mod_$mod[id]'>".$mod->name."</label><br>";
						echo '</div></td></tr>';
					}
					$ft->add_field('string', 'manual_workshop_mod_id','');
					$ft->end_table();
					$ft->add_button("submit", "install", get_lang('install_mod'));
					$ft->add_button("submit", "show_info", get_lang('show_mod_info'));
					$ft->end_form();
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
