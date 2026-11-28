<?php
/*
 * "combo" start parameters: a text box with a dropdown of known values (HTML datalist), used for maps
 * and gamemodes. You can pick a value or type a new one.
 *
 * Known values come from two places:
 *  - the <option> elements of the parameter in the game XML (the presets), and
 *  - game_param_options: values a server of the same game was RUNNING with although the XML does not
 *    list them. The status poller adds those (modules/status_history/poller.php).
 * Learned values are kept in the database, not written into the XML, because the XML files are
 * replaced from git on every update.
 */

/** Path of a game XML in the panel, or '' for a bad name. */
function gpo_xml_path($home_cfg_file)
{
	$file = basename((string)$home_cfg_file);
	return $file === '' ? '' : dirname(__DIR__) . '/modules/config_games/server_configs/' . $file;
}

/**
 * The combo parameters of a game XML, from every operating system section:
 * param key => list of preset values. Results are kept per file for the rest of the request.
 */
function gpo_combo_params($home_cfg_file)
{
	static $cache = array();
	$path = gpo_xml_path($home_cfg_file);
	if (isset($cache[$path])) {
		return $cache[$path];
	}
	$params = array();
	$xml = ($path !== '' && is_readable($path)) ? @simplexml_load_file($path) : false;
	if ($xml) {
		foreach ($xml->xpath('//server_params//param[@type="combo"]') ?: array() as $param) {
			$key = (string)$param['key'];
			if (!isset($params[$key])) {
				$params[$key] = array();
			}
			foreach ($param->option as $option) {
				$value = isset($option['value']) ? (string)$option['value'] : (string)$option;
				if ($value !== '' && !in_array($value, $params[$key], true)) {
					$params[$key][] = $value;
				}
			}
		}
	}
	return $cache[$path] = $params;
}

/**
 * The values a RUNNING server used for combo parameters that its XML does not list yet:
 * list of array(param_key, value). $last_param is the server's saved start parameters.
 */
function gpo_new_values(array $combo_params, array $last_param)
{
	$new = array();
	foreach ($combo_params as $key => $presets) {
		$value = isset($last_param[$key]) ? trim((string)$last_param[$key]) : '';
		if ($value === '' || strlen($value) > 128 || in_array($value, $presets, true)) {
			continue;
		}
		$new[] = array($key, $value);
	}
	return $new;
}

/** The dropdown values for one combo parameter: the XML presets, then the learned values in order. */
function gpo_merge_options(array $presets, array $learned)
{
	$all = $presets;
	foreach ($learned as $value) {
		if (!in_array($value, $all, true)) {
			$all[] = $value;
		}
	}
	return $all;
}
