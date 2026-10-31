
<div class="info" style="margin:10px 0;padding:10px;">
<strong>Simple Workshop Setup:</strong><br>
Most Workshop games only require Workshop App ID, Download Method, Anonymous Login, Mods Path, Post Install Script, and Uninstall Script.<br>
Fields marked <strong>(Optional)</strong> are only needed when GSP must automatically edit a game's mod list or configuration file.
</div>
<?php
require_once(dirname(__FILE__) . '/simple_admin_helper.php');

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
require_once('includes/form_table_class.php');
require_once("modules/steam_workshop/functions.php");

function steam_workshop_admin_save_xml($xml_file, $post, $removed_ids = array())
{
	$xml = new SimpleXMLElement('<workshop_settings/>');
	$xml->addChild('workshop_id', trim((string)$post['workshop_id']));
	$xml->addChild('download_method', trim((string)$post['download_method']));
	$xml->addChild('anonymous_login', trim((string)$post['anonymous_login']));
	$xml->addChild('mods_path', trim((string)$post['mods_path']));
	$mods = $xml->addChild('mods');
	if(file_exists($xml_file))
	{
		$file_xml = simplexml_load_file($xml_file);
		if($file_xml !== false && isset($file_xml->mods))
		{
			foreach($file_xml->mods->mod as $xml_mod)
			{
				if(in_array((string)$xml_mod['id'], $removed_ids, true))
					continue;
				$mod = $mods->addChild('mod');
				$mod->addAttribute('id', (string)$xml_mod['id']);
				$mod->addChild('name', (string)$xml_mod->name);
				$mod->addChild('description', (string)$xml_mod->description);
				$mod->addChild('image_url', (string)$xml_mod->image_url);
				$mod->addChild('download_url', (string)$xml_mod->download_url);
				$mod->addChild('filename', (string)$xml_mod->filename);
				$mod->addChild('file_size', (string)$xml_mod->file_size);
			}
		}
	}

	$config = $xml->addChild('config');
	$config->addChild('regex', trim((string)$post['regex']));
	$config->addChild('mods_backreference_index', trim((string)$post['mods_backreference_index']));
	$config->addChild('variable', (string)$post['variable']);
	$config->addChild('place_after', (string)$post['place_after']);
	$config->addChild('mod_string', (string)$post['mod_string']);
	$config->addChild('string_separator', (string)$post['string_separator']);
	$config->addChild('filepath', trim((string)$post['filepath']));
	$xml->addChild('post_install', str_replace('&','&amp;', (string)$post['post_install']));
	$xml->addChild('uninstall', str_replace('&','&amp;', (string)$post['uninstall']));

	$dom = dom_import_simplexml($xml)->ownerDocument;
	$dom->formatOutput = true;
	file_put_contents($xml_file, $dom->saveXML());
}

function steam_workshop_admin_hidden_state($values)
{
	$fields = array(
		'workshop_id','download_method','anonymous_login','mods_path','regex',
		'mods_backreference_index','variable','place_after','mod_string',
		'string_separator','filepath','post_install','uninstall'
	);
	$html = '';
	foreach($fields as $field)
	{
		$value = isset($values[$field]) ? (string)$values[$field] : '';
		$html .= '<input type="hidden" name="'.steam_workshop_h($field).'" value="'.steam_workshop_h($value).'" />'."\n";
	}
	return $html;
}

function steam_workshop_admin_get_game_options($db, &$workshop_config_labels)
{
	$games = array(0 => get_lang('select_game'));
	$workshop_config_labels = array();
	$game_cfgs = $db->getGameCfgs();
	foreach($game_cfgs as $game_cfg)
	{
		$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$game_cfg['home_cfg_file'], gsp_os_for_install_row($game_cfg));
		if(isset($server_xml->installer) and $server_xml->installer == "steamcmd")
		{
			$cfgMods = $db->getCfgMods($game_cfg['home_cfg_id']);
			foreach($cfgMods as $cfgMod)
			{
				$mod_xml = xml_get_mod($server_xml, $cfgMod['mod_key']);
				if(isset($mod_xml->installer_name) and !in_array((string)$mod_xml->installer_name, get_blacklist()))
				{
					preg_match('/(linux|win)(32|64)?/i', $game_cfg['game_key'], $matches);
					if(empty($matches[1]))
						continue;

					if(strtolower($matches[1]) == 'linux')
						$os = "Linux";
					elseif(strtolower($matches[1]) == 'win')
						$os = "Windows";
					else
						continue;

					if(isset($matches[2]) and strtolower($matches[2]) == '64')
						$arch = "64";
					else
						$arch = "32";

					$modname = strtolower($cfgMod['mod_name']) == "none"? "":" [MOD:" . $cfgMod['mod_name']."]";
					$friendly_name = $game_cfg['game_name'] . " (" . $os . " " . $arch . "bits)$modname";
					$games[$game_cfg['home_cfg_id'].'-'.$cfgMod['mod_cfg_id'].'-'.$os] = $friendly_name;
					$workshop_config_labels[(string)$mod_xml->installer_name."_".$os.".xml"] = $friendly_name;
				}
			}
		}
	}
	return $games;
}

function exec_gsp_module()
{

	Global $db,$view;
	echo '<h2>Steam Workshop</h2>';
	define('CONFIGS', "modules/steam_workshop/game_configs/");
	$workshop_config_labels = array();
	$games = steam_workshop_admin_get_game_options($db, $workshop_config_labels);

	if(isset($_REQUEST['home_cfg_id-mod_cfg_id-os']))
		list($home_cfg_id, $mod_cfg_id, $os) = explode('-', $_REQUEST['home_cfg_id-mod_cfg_id-os']);

	if(isset($home_cfg_id) and isset($mod_cfg_id))
	{
		$gameCfg = $db->getGameCfg($home_cfg_id);
		$cfgMods = $db->getCfgMods($home_cfg_id);

		foreach($cfgMods as $cfgMod)
		{
			if($cfgMod['mod_cfg_id'] == $mod_cfg_id)
				$modkey = $cfgMod['mod_key'];
		}

		$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$gameCfg['home_cfg_file'], gsp_os_for_install_row($gameCfg));

		$mod_xml = xml_get_mod($server_xml, $modkey);

		if (!$mod_xml)
		{
			print_failure(get_lang_f('mod_key_not_found_from_xml',$modkey));
			return;
		}
		$xml_file = CONFIGS.$mod_xml->installer_name."_".$os.".xml";
		$workshop_id = '';
		$download_method = 'steamcmd';
		$anonymous_login = 1;
		$mods_path = '';
		$regex = '';
		$mods_backreference_index = '';
		$variable = '';
		$place_after = '';
		$mod_string = '';
		$string_separator = '';
		$filepath = '';
		$post_install = '';
		$uninstall = '';

		if(isset($_POST['save_config']))
		{
			steam_workshop_admin_save_xml($xml_file, $_POST);
		}

		if(isset($_POST['remove_mods']))
		{
			$removed_ids = isset($_POST['workshop_mod_id']) && is_array($_POST['workshop_mod_id']) ? array_map('strval', $_POST['workshop_mod_id']) : array();
			steam_workshop_admin_save_xml($xml_file, $_POST, $removed_ids);
		}

		if(isset($_POST['copy_selected_config']))
		{
			$copy_source_filename = isset($_POST['copy_source_config']) ? (string)$_POST['copy_source_config'] : '';
			$destination_filename = basename($xml_file);
			list($source_path, $destination_path) = steam_workshop_get_safe_config_copy_paths(
				$copy_source_filename,
				$destination_filename
			);
			if($source_path === false || $destination_path === false)
			{
				print_failure(get_lang('invalid_workshop_config_copy_source'));
			}
			elseif(realpath($source_path) === realpath($destination_path))
			{
				print_failure(get_lang('cannot_copy_workshop_config_to_itself'));
			}
			elseif(steam_workshop_copy_config_settings_only($source_path, $destination_path))
			{
				$configs = steam_workshop_list_existing_configs();
				$source_filename = basename($source_path);
				$source_label = isset($configs[$source_filename]) ? steam_workshop_get_config_display_label($source_filename, $configs[$source_filename], $workshop_config_labels) : $source_filename;
				$destination_label = isset($configs[$destination_filename]) ? steam_workshop_get_config_display_label($destination_filename, $configs[$destination_filename], $workshop_config_labels) : (isset($workshop_config_labels[$destination_filename]) ? $workshop_config_labels[$destination_filename].' - '.$destination_filename : $destination_filename);
				print_success(get_lang_f('workshop_config_copied', steam_workshop_h($source_label), steam_workshop_h($destination_label)));
			}
			else
			{
				print_failure(get_lang('failed_to_copy_workshop_config'));
			}
		}

		if(file_exists($xml_file))
		{
			$xml = simplexml_load_file($xml_file);

			$workshop_id = $xml->workshop_id;
			$download_method = $xml->download_method;
			$anonymous_login = $xml->anonymous_login;
			$mods_path = $xml->mods_path;
			$regex = $xml->config->regex;
			$mods_backreference_index = $xml->config->mods_backreference_index;
			$variable = $xml->config->variable;
			$place_after = $xml->config->place_after;
			$mod_string = $xml->config->mod_string;
			$string_separator = $xml->config->string_separator;
			$filepath = $xml->config->filepath;
			$post_install = $xml->post_install;
			$uninstall = $xml->uninstall;
		}

	}

	$current_values = array(
		'workshop_id' => isset($workshop_id) ? $workshop_id : '',
		'download_method' => isset($download_method) ? $download_method : '',
		'anonymous_login' => isset($anonymous_login) ? $anonymous_login : '',
		'mods_path' => isset($mods_path) ? $mods_path : '',
		'regex' => isset($regex) ? $regex : '',
		'mods_backreference_index' => isset($mods_backreference_index) ? $mods_backreference_index : '',
		'variable' => isset($variable) ? $variable : '',
		'place_after' => isset($place_after) ? $place_after : '',
		'mod_string' => isset($mod_string) ? $mod_string : '',
		'string_separator' => isset($string_separator) ? $string_separator : '',
		'filepath' => isset($filepath) ? $filepath : '',
		'post_install' => isset($post_install) ? $post_install : '',
		'uninstall' => isset($uninstall) ? $uninstall : '',
	);

	$download_methods = array("steamcmd", "steamapi");

	$ft = new FormTable();
	$ft->start_form("?m=steam_workshop&p=workshop_admin", "post", "autocomplete=\"off\"");
	$ft->start_table();
	$ft->add_custom_field('game', create_drop_box_from_array_onchange($games, "home_cfg_id-mod_cfg_id-os", @$_REQUEST['home_cfg_id-mod_cfg_id-os']));
	if(isset($home_cfg_id) and isset($mod_cfg_id))
	{
		$copy_options = steam_workshop_get_existing_config_options(isset($xml_file) ? basename($xml_file) : '', $workshop_config_labels);
		echo "<div class='info' style='margin:10px 0;padding:10px;'><strong>".get_lang('copy_from_existing_workshop_config')."</strong><br>".
			 get_lang('copy_from_existing_workshop_config_info').
			 "<form method='post' action='?m=steam_workshop&p=workshop_admin' style='margin-top:8px;' autocomplete='off'>".
			 "<input type='hidden' name='home_cfg_id-mod_cfg_id-os' value='".steam_workshop_h($_REQUEST['home_cfg_id-mod_cfg_id-os'])."' />".
			 create_drop_box_from_array($copy_options, "copy_source_config", '', false).
			 " <button type='submit' class='btn btn-sm btn-secondary' name='copy_selected_config' value='1'>".get_lang('copy_selected_config')."</button>".
			 "</form></div>";

		$ft->add_field('string','workshop_id',@$workshop_id);
		$ft->add_custom_field('download_method',create_drop_box_from_array($download_methods, "download_method", @$download_method));
		$ft->add_field('on_off','anonymous_login',@$anonymous_login);
		$ft->add_field('string','mods_path',@$mods_path);
		$ft->add_field('string','regex',@$regex);
		$ft->add_field('string','mods_backreference_index',@$mods_backreference_index);
		$ft->add_field('string','variable',@$variable);
		$ft->add_field('string','place_after',@$place_after);
		$ft->add_field('string','mod_string',@$mod_string);
		$ft->add_field('string','string_separator',@$string_separator);
		$ft->add_field('string','filepath',@$filepath);
		$ft->add_field('text','post_install',@$post_install);
		$ft->add_field('text','uninstall',@$uninstall);


		$ft->end_table();
		$ft->add_button("submit","save_config",get_lang('save_config'));
		$ft->end_form();
	}
	else
	{
		$ft->end_table();
		$ft->end_form();
	}

	if(isset($xml) and count($xml->mods->mod) > 0)
	{
		$ft = new FormTable();
		$ft->start_form("?m=steam_workshop&p=workshop_admin&home_cfg_id-mod_cfg_id-os=".$_REQUEST['home_cfg_id-mod_cfg_id-os'], "post", "autocomplete=\"off\"");
		$ft->start_table();
		echo "<tr><td>";
		echo steam_workshop_admin_hidden_state($current_values);
		echo "</td></tr>".
			 '<tr><td colspan=2><div id="scrolling_checkbox">';
		foreach($xml->mods->mod as $mod)
			echo "<input type='checkbox' id='select_mod_$mod[id]' name='workshop_mod_id[]' value='$mod[id]'><label for='select_mod_$mod[id]'>".$mod->name."</label><br>";
		echo '</div></td></tr>';
		$ft->end_table();
		$ft->add_button("submit","remove_mods",get_lang('remove_mods'));
		$ft->end_form();
	}
}

if (function_exists('gsp_steam_workshop_simple_admin_script')) { gsp_steam_workshop_simple_admin_script(); }
?>
