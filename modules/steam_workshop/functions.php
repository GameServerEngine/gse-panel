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
function create_drop_box_from_array_onchange($input_array,$listname,$current_value = "")
{
	$only_one = count($input_array) == 1;
	$disabled = $only_one? "disabled=disabled":"";
	$retval = "<select id=\"$listname\" name=\"$listname\" style=\"max-width:330px;\" onchange=\"this.form.submit()\" $disabled>\n";
    foreach($input_array as $key => $value)
    {
		// Make sure we don't allow HTML or script
		$key = trim(strip_tags($key));
        $value = trim(strip_tags($value));

        // We want to print lines with zeros, but not empty lines.
        if ( empty($value) and $value !="0" )
        {
            continue;
        }

		$sel = "";

        if ( $key == $current_value )
        {
            $sel .= "selected='selected'";
        }

		$retval .= "<option value='$key' $sel>$value</option>\n";
    }
    $retval .= "</select>\n";
    return $retval;
}

function steam_workshop_h($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function steam_workshop_has_config_file_path($filepath)
{
	return trim((string)$filepath) !== '';
}

function steam_workshop_resolve_config_filepath($home_path, $filepath)
{
	$filepath = trim((string)$filepath);
	if($filepath === '')
		return '';

	return clean_path(rtrim($home_path, '/').'/'.$filepath);
}

function steam_workshop_get_configs_dir()
{
	return dirname(__FILE__).'/game_configs';
}

function steam_workshop_list_existing_configs()
{
	$configs = array();
	$config_dir = steam_workshop_get_configs_dir();
	if(!is_dir($config_dir))
		return $configs;

	$files = glob($config_dir.'/*.xml');
	if($files === false)
		return $configs;

	sort($files, SORT_NATURAL | SORT_FLAG_CASE);
	foreach($files as $file)
	{
		$basename = basename($file);
		$xml = @simplexml_load_file($file);
		$configs[$basename] = array(
			'filename' => $basename,
			'workshop_id' => $xml !== false && isset($xml->workshop_id) ? (string)$xml->workshop_id : '',
			'download_method' => $xml !== false && isset($xml->download_method) ? (string)$xml->download_method : '',
			'mods_path' => $xml !== false && isset($xml->mods_path) ? (string)$xml->mods_path : '',
		);
	}

	return $configs;
}

function steam_workshop_get_config_display_label($filename, $config, $friendly_names = array())
{
	$label = isset($friendly_names[$filename]) && trim((string)$friendly_names[$filename]) !== '' ? (string)$friendly_names[$filename].' - '.$filename : $filename;
	$meta = array();
	if(isset($config['workshop_id']) && $config['workshop_id'] !== '')
		$meta[] = 'Workshop '.$config['workshop_id'];
	if(isset($config['download_method']) && $config['download_method'] !== '')
		$meta[] = $config['download_method'];
	if(isset($config['mods_path']) && $config['mods_path'] !== '')
		$meta[] = 'mods_path='.$config['mods_path'];
	if(!empty($meta))
		$label .= ' ['.implode(' | ', $meta).']';
	return $label;
}

function steam_workshop_get_existing_config_options($exclude_filename = '', $friendly_names = array())
{
	$options = array('' => get_lang('select_config_to_copy'));
	foreach(steam_workshop_list_existing_configs() as $basename => $config)
	{
		if($basename === $exclude_filename)
			continue;
		$options[$basename] = steam_workshop_get_config_display_label($basename, $config, $friendly_names);
	}
	return $options;
}

function steam_workshop_is_safe_config_filename($filename)
{
	$filename = (string)$filename;
	if($filename === '')
		return false;
	if(strpos($filename, '/') !== false || strpos($filename, '\\') !== false || strpos($filename, '..') !== false)
		return false;
	if(pathinfo($filename, PATHINFO_EXTENSION) !== 'xml')
		return false;
	return preg_match('/^[A-Za-z0-9._-]+\.xml$/', $filename) === 1;
}

function steam_workshop_get_safe_config_copy_paths($selected_source, $destination_file)
{
	$config_dir = realpath(steam_workshop_get_configs_dir());
	$selected_source = (string)$selected_source;
	$destination_file = (string)$destination_file;
	if($config_dir === false || !steam_workshop_is_safe_config_filename($selected_source) || !steam_workshop_is_safe_config_filename($destination_file))
		return array(false, false);

	$source_path = realpath($config_dir.'/'.$selected_source);
	$destination_path = $config_dir.'/'.$destination_file;
	if($source_path === false || strpos($source_path, $config_dir.DIRECTORY_SEPARATOR) !== 0)
		return array(false, false);
	if(file_exists($destination_path))
	{
		$destination_realpath = realpath($destination_path);
		if($destination_realpath === false || strpos($destination_realpath, $config_dir.DIRECTORY_SEPARATOR) !== 0)
			return array(false, false);
		$destination_path = $destination_realpath;
	}

	return array($source_path, $destination_path);
}

function steam_workshop_copy_config_settings_only($source_path, $destination_path)
{
	$source = new DOMDocument();
	$source->preserveWhiteSpace = false;
	if(!@$source->load($source_path) || !$source->documentElement)
		return false;

	$destination = new DOMDocument('1.0', 'UTF-8');
	$destination->formatOutput = true;
	$root = $destination->createElement('workshop_settings');
	$destination->appendChild($root);

	foreach($source->documentElement->childNodes as $child)
	{
		if($child->nodeType !== XML_ELEMENT_NODE)
			continue;
		if($child->nodeName === 'mods')
			continue;
		$root->appendChild($destination->importNode($child, true));
	}

	$root->appendChild($destination->createElement('mods'));
	return $destination->save($destination_path) !== false;
}

function steam_workshop_request($url, $context, &$error = "")
{
	$response = @file_get_contents($url, false, $context);
	if($response !== FALSE)
		return $response;

	$http_response = isset($http_response_header) ? implode(" | ", $http_response_header) : "";
	$error = $http_response != "" ? $http_response : "request_failed";
	return FALSE;
}

function steam_workshop_extract_ids($value)
{
	$ids = array();
	$value = trim((string)$value);
	if($value === "")
		return $ids;

	if(preg_match_all('/[?&]id=(\d+)/i', $value, $url_matches))
	{
		foreach($url_matches[1] as $url_id)
			$ids[] = $url_id;
		$value = preg_replace('/https?:\/\/\S+/i', ' ', $value);
	}

	$tokens = preg_split('/[\s,;]+/', $value);
	foreach($tokens as $token)
	{
		$token = trim($token);
		if($token !== "" && ctype_digit($token))
			$ids[] = $token;
	}

	$ids = array_values(array_unique($ids));
	return $ids;
}

function steam_workshop_normalize_checkbox_ids($ids)
{
	if(!is_array($ids))
		return array();

	$normalized = array();
	foreach($ids as $id)
	{
		$id = trim((string)$id);
		if($id !== "" && ctype_digit($id))
			$normalized[] = $id;
	}
	return array_values(array_unique($normalized));
}

function steam_workshop_collect_requested_ids($manual_ids, $xml_ids = array(), $search_ids = array())
{
	$collected = array_merge(
		steam_workshop_extract_ids($manual_ids),
		steam_workshop_normalize_checkbox_ids($xml_ids),
		steam_workshop_normalize_checkbox_ids($search_ids)
	);
	return array_values(array_unique($collected));
}

function steam_workshop_fetch_published_file_details($workshop_mod_ids)
{
	$details = array();
	$workshop_mod_ids = steam_workshop_normalize_checkbox_ids($workshop_mod_ids);
	if(empty($workshop_mod_ids))
		return $details;

	$request = array('itemcount' => count($workshop_mod_ids));
	foreach($workshop_mod_ids as $index => $workshop_mod_id)
		$request["publishedfileids[$index]"] = $workshop_mod_id;

	$context = stream_context_create(array('http' => array(
		'method' => "POST",
		'header' => "Content-type: application/x-www-form-urlencoded\r\nUser-Agent: GSP-Panel-SteamWorkshop/1.0\r\n",
		'content' => http_build_query($request),
		'timeout' => 10,
		'ignore_errors' => true
	)));

	$error = "";
	$json = steam_workshop_request('https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/', $context, $error);
	if($json === FALSE)
		$json = steam_workshop_request('http://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/', $context, $error);

	if($json === FALSE)
		return $details;

	$response_array = json_decode($json, true);
	if(!is_array($response_array) || !isset($response_array['response']['publishedfiledetails']))
		return $details;

	foreach($response_array['response']['publishedfiledetails'] as $app_info)
	{
		if(!is_array($app_info) || empty($app_info['publishedfileid']))
			continue;

		$publishedfileid = (string)$app_info['publishedfileid'];
		$details[$publishedfileid] = array(
			'publishedfileid' => $publishedfileid,
			'title' => isset($app_info['title']) ? $app_info['title'] : $publishedfileid,
			'description' => isset($app_info['description']) ? $app_info['description'] : '',
			'preview_url' => isset($app_info['preview_url']) ? $app_info['preview_url'] : '',
			'file_url' => isset($app_info['file_url']) ? $app_info['file_url'] : '',
			'filename' => isset($app_info['filename']) ? basename($app_info['filename']) : '',
			'file_size' => isset($app_info['file_size']) ? $app_info['file_size'] : '',
			'creator_app_id' => isset($app_info['creator_app_id']) ? (string)$app_info['creator_app_id'] : '',
			'consumer_app_id' => isset($app_info['consumer_app_id']) ? (string)$app_info['consumer_app_id'] : '',
			'time_updated' => isset($app_info['time_updated']) ? $app_info['time_updated'] : 0,
			'time_created' => isset($app_info['time_created']) ? $app_info['time_created'] : 0,
		);
	}

	return $details;
}

function get_mod_names_list($mods_list, $xml_mods)
{
	$mod_names = "";
	foreach(explode(',', $mods_list) as $workshop_mod_id)
	{
		foreach($xml_mods as $mod)
		{
			if($mod['id'] == $workshop_mod_id)
			{
				if($mod_names != "")
					$mod_names .= ",";
				$mod_names .= $mod->name;
			}
		}
	}
	return $mod_names;
}

function get_mod_info($workshop_mod_id)
{
	$details = steam_workshop_fetch_published_file_details(array($workshop_mod_id));
	if(!isset($details[$workshop_mod_id]))
		return array($workshop_mod_id, '', '', '', '', '');

	$app_info = $details[$workshop_mod_id];
	return array(
		$app_info['title'],
		$app_info['description'],
		$app_info['preview_url'],
		$app_info['file_url'],
		$app_info['filename'],
		$app_info['file_size']
	);
}

function belongs_to_workshop($workshop_mod_id, $workshop_id)
{
	$details = steam_workshop_fetch_published_file_details(array($workshop_mod_id));
	if(!isset($details[$workshop_mod_id]))
		return false;

	$app_info = $details[$workshop_mod_id];
	return ($app_info['creator_app_id'] == $workshop_id || $app_info['consumer_app_id'] == $workshop_id);
}

function steam_workshop_sync_xml_mods(&$xml, $xml_file, $workshop_id, $workshop_mod_ids, &$errors)
{
	$errors = array();
	$workshop_mod_ids = steam_workshop_normalize_checkbox_ids($workshop_mod_ids);
	if(empty($workshop_mod_ids))
		return true;

	$known_mods = array();
	if(isset($xml->mods) && isset($xml->mods->mod))
	{
		foreach($xml->mods->mod as $mod)
			$known_mods[(string)$mod['id']] = true;
	}

	$missing_ids = array();
	foreach($workshop_mod_ids as $workshop_mod_id)
	{
		if(!isset($known_mods[$workshop_mod_id]))
			$missing_ids[] = $workshop_mod_id;
	}

	if(empty($missing_ids))
		return true;

	$details = steam_workshop_fetch_published_file_details($missing_ids);
	$dom = dom_import_simplexml($xml)->ownerDocument;
	$dom->formatOutput = true;
	$mods = $dom->getElementsByTagName('mods')->item(0);
	if(!$mods)
	{
		$mods = $dom->createElement('mods');
		$dom->documentElement->appendChild($mods);
	}

	foreach($missing_ids as $workshop_mod_id)
	{
		if(!isset($details[$workshop_mod_id]))
		{
			$errors[] = get_lang_f('failed_to_fetch_workshop_item_details', $workshop_mod_id);
			continue;
		}

		$app_info = $details[$workshop_mod_id];
		if($app_info['creator_app_id'] != $workshop_id && $app_info['consumer_app_id'] != $workshop_id)
		{
			$errors[] = get_lang_f('mod_does_not_belong_to_workshop', $workshop_mod_id);
			continue;
		}

		$mod = new SimpleXMLElement('<mod/>');
		$mod->addAttribute('id', $workshop_mod_id);
		$mod->addChild('name', $app_info['title']);
		$mod->addChild('description', base64_encode($app_info['description']));
		$mod->addChild('image_url', $app_info['preview_url']);
		$mod->addChild('download_url', $app_info['file_url']);
		$mod->addChild('filename', $app_info['filename']);
		$mod->addChild('file_size', $app_info['file_size']);
		$moddom = dom_import_simplexml($mod)->ownerDocument;
		$moddom->formatOutput = true;
		$fragment = $dom->createDocumentFragment();
		$fragment->appendXML($moddom->saveXML($moddom->documentElement)."\n");
		$mods->appendChild($fragment);
	}

	file_put_contents($xml_file, $dom->saveXML());
	$xml = simplexml_load_file($xml_file);
	return count($errors) === 0;
}

function steam_workshop_search_items($workshop_id, $query, &$error = "")
{
	$error = "";
	$query = trim((string)$query);
	if($query === "")
		return array();

	$direct_ids = steam_workshop_extract_ids($query);
	if(!empty($direct_ids))
	{
		$details = steam_workshop_fetch_published_file_details($direct_ids);
		$results = array();
		foreach($direct_ids as $workshop_mod_id)
		{
			if(!isset($details[$workshop_mod_id]))
				continue;

			$app_info = $details[$workshop_mod_id];
			if($app_info['creator_app_id'] != $workshop_id && $app_info['consumer_app_id'] != $workshop_id)
				continue;

			$results[] = array(
				'id' => $workshop_mod_id,
				'title' => $app_info['title'],
				'description' => $app_info['description'],
				'preview_url' => $app_info['preview_url'],
				'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id='.$workshop_mod_id,
				'author' => '',
			);
		}
		return $results;
	}

	$url = 'https://steamcommunity.com/workshop/browse/?appid='.rawurlencode($workshop_id).
		   '&searchtext='.rawurlencode($query).
		   '&browsesort=textsearch&section=readytouseitems&actualsort=textsearch&p=1&days=-1';
	$context = stream_context_create(array('http' => array(
		'method' => "GET",
		'header' => "User-Agent: Mozilla/5.0 (compatible; GSP-Panel-SteamWorkshop/1.0)\r\n",
		'timeout' => 15,
		'ignore_errors' => true
	)));

	$html = steam_workshop_request($url, $context, $error);
	if($html === FALSE)
	{
		$error = get_lang('unable_to_contact_steam_workshop');
		return array();
	}

	libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	if(!@$dom->loadHTML($html))
	{
		$error = get_lang('unable_to_parse_steam_workshop_response');
		return array();
	}

	$xpath = new DOMXPath($dom);
	$anchors = $xpath->query('//a[contains(@href,"/sharedfiles/filedetails/?id=")]');
	$results = array();
	$seen_ids = array();
	foreach($anchors as $anchor)
	{
		$href = $anchor->getAttribute('href');
		if(!preg_match('/[?&]id=(\d+)/', $href, $matches))
			continue;

		$workshop_mod_id = $matches[1];
		if(isset($seen_ids[$workshop_mod_id]))
			continue;

		$title = trim($anchor->textContent);
		$image_nodes = $xpath->query('.//img', $anchor);
		$preview_url = '';
		if($image_nodes->length > 0)
		{
			$preview_url = $image_nodes->item(0)->getAttribute('src');
			if($title === '')
				$title = trim($image_nodes->item(0)->getAttribute('alt'));
		}

		$card = $anchor;
		for($depth = 0; $depth < 4 && $card->parentNode instanceof DOMElement; $depth++)
			$card = $card->parentNode;

		$author = '';
		if($card instanceof DOMElement)
		{
			$author_nodes = $xpath->query('.//a[contains(@href,"/myworkshopfiles/?appid=")]', $card);
			if($author_nodes->length > 0)
				$author = trim(preg_replace('/^By\s+/i', '', $author_nodes->item(0)->textContent));
		}

		if($title === '')
			continue;

		$seen_ids[$workshop_mod_id] = true;
		$results[$workshop_mod_id] = array(
			'id' => $workshop_mod_id,
			'title' => $title,
			'description' => '',
			'preview_url' => $preview_url,
			'url' => $href,
			'author' => $author,
		);

		if(count($results) >= 12)
			break;
	}

	if(empty($results))
		return array();

	$details = steam_workshop_fetch_published_file_details(array_keys($results));
	foreach($results as $workshop_mod_id => $result)
	{
		if(!isset($details[$workshop_mod_id]))
			continue;
		$app_info = $details[$workshop_mod_id];
		if($app_info['creator_app_id'] != $workshop_id && $app_info['consumer_app_id'] != $workshop_id)
		{
			unset($results[$workshop_mod_id]);
			continue;
		}
		$results[$workshop_mod_id]['description'] = $app_info['description'];
		if($results[$workshop_mod_id]['preview_url'] === '' && $app_info['preview_url'] !== '')
			$results[$workshop_mod_id]['preview_url'] = $app_info['preview_url'];
	}

	return array_values($results);
}

function get_installed_mods($home_cfg, $remote, $xml)
{
	$game_root = isset($home_cfg['game_root']) ? $home_cfg['game_root'] : $home_cfg['home_path'];
	$config = $xml->config;
	$regex = $config->regex;
	$mods_backreference_index = (int)$config->mods_backreference_index;
	$string_separator = stripcslashes($config->string_separator);
	$filepath = $config->filepath;
	$full_filepath = steam_workshop_resolve_config_filepath($game_root, $filepath);

	if(!steam_workshop_has_config_file_path($filepath))
	{
		$retval = $remote->get_workshop_mods_info($mod_info_array, $home_cfg['home_id']);
		if($retval != "1")
			$retval = $remote->get_workshop_mods_info($mod_info_array);
		return $retval == "1" && !empty($mod_info_array) ? steam_workshop_normalize_installed_mod_records($mod_info_array, $home_cfg, $xml) : False;
	}

	if($remote->rfile_exists($full_filepath) === 0)
		return False;

	if($remote->remote_readfile($full_filepath, $file_content) !== 1)
		return False;

	if(preg_match("/$regex/m", $file_content, $matches))
	{
		$full_regex_string = trim($matches[0]);
		$current_mods_string = trim($matches[$mods_backreference_index]);
		if($current_mods_string != '')
		{
			$retval = $remote->get_workshop_mods_info($mod_info_array, $home_cfg['home_id']);
			if($retval != "1")
				$retval = $remote->get_workshop_mods_info($mod_info_array);
			$current = explode($string_separator, $current_mods_string);
			$installed_mods = array();
			foreach($current as $c)
			{
				if($c != "")
				{
					$mod_string = trim($c);
					if($retval == "1" && isset($mod_info_array["$mod_string"]))
						$installed_mods["$mod_string"] = is_array($mod_info_array["$mod_string"]) ? $mod_info_array["$mod_string"] : steam_workshop_installed_record_from_title($mod_string, $mod_info_array["$mod_string"], $home_cfg, $xml);
					else
						$installed_mods["$mod_string"] = steam_workshop_installed_record_from_title($mod_string, $mod_string, $home_cfg, $xml);
				}
			}
			return $installed_mods;
		}
		else
			return False;
	}
	else
		return False;
}

function steam_workshop_installed_record_from_title($mod_string, $title, $home_cfg, $xml)
{
	$game_root = isset($home_cfg['game_root']) ? $home_cfg['game_root'] : $home_cfg['home_path'];
	$mods_full_path = clean_path($game_root.'/'.$xml->mods_path);
	$installed_folder = $mod_string;
	if(strpos($mod_string, '@') !== 0 && preg_match('/^\d+$/', $mod_string))
		$installed_folder = '@'.$mod_string;
	return array(
		'mod_string' => (string)$mod_string,
		'workshop_mod_id' => preg_match('/^\d+$/', (string)$mod_string) ? (string)$mod_string : '',
		'title' => (string)$title,
		'installed_folder' => $installed_folder,
		'installed_path' => clean_path($mods_full_path.'/'.$installed_folder),
		'workshop_app_id' => (string)$xml->workshop_id,
		'install_status' => 'installed',
		'install_time' => '',
		'last_update_time' => '',
	);
}

function steam_workshop_normalize_installed_mod_records($mods, $home_cfg, $xml)
{
	$records = array();
	foreach((array)$mods as $mod_string => $record)
	{
		if(is_array($record))
		{
			if(!isset($record['mod_string']) || $record['mod_string'] === '')
				$record['mod_string'] = $mod_string;
			$records[$record['mod_string']] = $record;
		}
		else
		{
			$records[$mod_string] = steam_workshop_installed_record_from_title($mod_string, $record, $home_cfg, $xml);
		}
	}
	return $records;
}

function steam_workshop_build_mod_line($mods, $xml)
{
	$parts = array();
	foreach((array)$mods as $record)
	{
		if(is_array($record))
		{
			$value = isset($record['installed_folder']) && $record['installed_folder'] !== '' ? $record['installed_folder'] : $record['mod_string'];
			$parts[] = $value;
		}
		else
		{
			$parts[] = $record;
		}
	}
	$separator = isset($xml->config->string_separator) && (string)$xml->config->string_separator !== '' ? stripcslashes($xml->config->string_separator) : ';';
	$mod_list = implode($separator, array_filter($parts));
	$format = isset($xml->config->variable) ? (string)$xml->config->variable : '';
	if($format !== '' && strpos($format, '{MOD_LIST}') !== false)
		return str_replace('{MOD_LIST}', $mod_list, $format);
	if($format !== '' && preg_match('/^-/', $format))
		return $format.$mod_list;
	return $mod_list;
}

function steam_workshop_log_is_terminal($log_txt)
{
	return preg_match('/WORKSHOP_INSTALL_(COMPLETE|FAILED)/', (string)$log_txt) === 1;
}

function remove_mod($home_cfg, $remote, $xml, $mod_string)
{
	$game_root = isset($home_cfg['game_root']) ? $home_cfg['game_root'] : $home_cfg['home_path'];
	$config = $xml->config;
	$regex = $config->regex;
	$mods_backreference_index = (int)$config->mods_backreference_index;
	$variable = $config->variable;
	$string_separator = stripcslashes($config->string_separator);
	$filepath = $config->filepath;

	$full_filepath = steam_workshop_resolve_config_filepath($game_root, $filepath);
	$mods_full_path = clean_path($game_root.'/'.$xml->mods_path);

	if(steam_workshop_has_config_file_path($filepath))
	{
		if($remote->rfile_exists($full_filepath) === 0)
			return False;

		$remote->remote_readfile($full_filepath, $file_content);

		if(preg_match("/$regex/m", $file_content, $matches))
		{
			$full_regex_string = trim($matches[0]);
			$current_mods_string = trim($matches[$mods_backreference_index]);
			if($current_mods_string != '')
			{
				$current = explode($string_separator, $current_mods_string);

				foreach($current as $index => $c)
				{
					if(trim($c) == $mod_string)
						unset($current[$index]);
				}
				$current = array_filter($current);
				$new_mods_string = implode($string_separator, $current);

				$replacement = $variable.$new_mods_string;
				$file_content = str_replace($full_regex_string, $replacement, $file_content);
			}
			else
				return False;
		}
		else
			return False;

		$remote->remote_writefile($full_filepath, $file_content);
	}

	$uninstall_filepath = clean_path($mods_full_path.'/postuninstall.sh');
	$uninstallcmd = str_replace('%mods_full_path%', $mods_full_path, $xml->uninstall);
	$uninstallcmd = str_replace('%mod_string%', $mod_string, $uninstallcmd);
	$uninstallcmd .= "\nrm -f $uninstall_filepath";
	$output = "";
	if($remote->remote_writefile($uninstall_filepath, $uninstallcmd) === 1)
		$output .= $remote->exec("bash $uninstall_filepath");
	return $output;
}

function get_blacklist()
{
	return array( "232330","90","294420","251570","17515","34120","302550","42750","489650","748090","232290",
				  "17585","739590","17555","232370","55280","17705","261140","222840","320850","317670","824360","381690",
				  "41005","208050","105600","556450","402370");
}
?>
