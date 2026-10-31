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

define("SERVER_CONFIG_LOCATION","modules/config_games/server_configs/");
define("XML_SCHEMA","modules/config_games/schema_server_config.xml");

require_once(dirname(__DIR__, 2) . "/includes/game_path_helpers.php");

if (!function_exists('gsp_render_config_error')) {
	function gsp_render_config_error($title, $details = "")
	{
		$log_message = "[GSP] $title" . (empty($details) ? "" : " Details: $details");
		error_log($log_message);

		if (PHP_SAPI === 'cli') {
			fwrite(STDERR, $log_message . PHP_EOL);
			exit(1);
		}

		if (!headers_sent()) {
			header('Content-Type: text/html; charset=utf-8', true, 500);
		}

		echo "<div style=\"font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:4rem auto;padding:2rem;border:1px solid #c0392b;border-radius:6px;background:#fff7f7;color:#333;\">";
		echo "<h1 style=\"margin-top:0;color:#c0392b;\">GSP</h1>";
		echo "<h2 style=\"color:#c0392b;\">$title</h2>";
		if (!empty($details)) {
			echo "<p>" . nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8')) . "</p>";
		}
		echo "<p>Please install the missing PHP requirements or restore the referenced configuration file, then reload this page.</p>";
		echo "</div>";
		exit;
	}
}

/**
 * Collect a non-fatal XML parse/validation error for a game config file.
 * Errors are stored in a static array and can be retrieved via
 * gsp_get_xml_errors().  Does NOT stop page execution.
 */
if (!function_exists('gsp_collect_xml_error')) {
	function gsp_collect_xml_error(string $file, string $title, string $details = ''): void
	{
		$log_message = "[GSP] $title" . (empty($details) ? '' : ' Details: ' . $details);
		error_log($log_message);

		$GLOBALS['_gsp_xml_errors'][] = array(
			'file'    => $file,
			'title'   => $title,
			'details' => $details,
		);
	}
}

if (!function_exists('gsp_get_xml_errors')) {
	function gsp_get_xml_errors(): array
	{
		return isset($GLOBALS['_gsp_xml_errors']) ? $GLOBALS['_gsp_xml_errors'] : array();
	}
}

if (!function_exists('gsp_render_missing_xml_extensions')) {
	function gsp_render_missing_xml_extensions($missing_extensions)
	{
		$pretty = implode(', ', $missing_extensions);
		$details = "Missing PHP extension(s): {$pretty}\n"
				 . "Install the php-xml package and restart Apache/PHP-FPM.\n"
				 . "Debian/Ubuntu: sudo apt install php-xml\n"
				 . "RHEL/CentOS: sudo dnf install php-xml";
		gsp_render_config_error("Required PHP XML extensions are not available.", $details);
	}
}

if (!function_exists('gsp_format_libxml_errors')) {
	function gsp_format_libxml_errors()
	{
		$errors = libxml_get_errors();
		if (empty($errors)) {
			return "No additional libxml details are available.";
		}
		$messages = array();
		foreach ((array)$errors as $error) {
			$messages[] = trim($error->message) . " (Line {$error->line}, Column {$error->column})";
		}
		libxml_clear_errors();
		return implode("\n", $messages);
	}
}

if (!function_exists('gsp_ensure_xml_support')) {
	function gsp_ensure_xml_support()
	{
		static $checked = false;
		if ($checked) {
			return;
		}

		$missing = array();
		if (!extension_loaded('libxml')) {
			$missing[] = 'libxml';
		}
		if (!class_exists('DOMDocument')) {
			$missing[] = 'dom';
		}
		if (!function_exists('simplexml_load_file')) {
			$missing[] = 'simplexml';
		}

		if (!empty($missing)) {
			gsp_render_missing_xml_extensions($missing);
		}

		$checked = true;
	}
}

/// \return FALSE in case of failure in parsing.
/// \return array containing the elements on success.
/* OS blocks: a game file may hold <os><windows> and/or <os><linux> blocks. The chosen OS's
 * values are copied to the top level so callers that read top-level tags keep working. */
function gsp_config_os_names(DOMDocument $dom)
{
	$names = array();
	foreach ($dom->documentElement->childNodes as $c) {
		if ($c->nodeType === XML_ELEMENT_NODE && $c->nodeName === 'os') {
			foreach ($c->childNodes as $p) {
				if ($p->nodeType === XML_ELEMENT_NODE) {
					$names[] = $p->nodeName;
				}
			}
		}
	}
	return $names;
}

function gsp_resolve_config_os(DOMDocument $dom, $os)
{
	$names = gsp_config_os_names($dom);
	if ($os !== null) {
		return in_array($os, $names, true) ? $os : null;
	}
	if (count($names) === 1) {
		return $names[0];
	}
	return in_array('linux', $names, true) ? 'linux' : null;
}

function gsp_apply_os_block(DOMDocument $dom, $os)
{
	if ($os === null) {
		return;
	}
	$root = $dom->documentElement;
	$osEl = null;
	foreach ($root->childNodes as $c) {
		if ($c->nodeType === XML_ELEMENT_NODE && $c->nodeName === 'os') {
			$osEl = $c;
		}
	}
	if ($osEl === null) {
		return;
	}
	$plat = null;
	foreach ($osEl->childNodes as $p) {
		if ($p->nodeType === XML_ELEMENT_NODE && $p->nodeName === $os) {
			$plat = $p;
		}
	}
	if ($plat === null) {
		return;
	}
	foreach (iterator_to_array($plat->childNodes) as $child) {
		if ($child->nodeType !== XML_ELEMENT_NODE) {
			continue;
		}
		$target = ($child->nodeName === 'exe') ? 'server_exec_name' : $child->nodeName;
		foreach (iterator_to_array($root->childNodes) as $existing) {
			if ($existing->nodeType === XML_ELEMENT_NODE && $existing->nodeName === $target) {
				$root->removeChild($existing);
			}
		}
		if ($child->nodeName === 'exe') {
			$el = $dom->createElement('server_exec_name');
			$el->appendChild($dom->createTextNode(trim($child->textContent)));
			$root->appendChild($el);
		} else {
			$root->appendChild($dom->importNode($child, true));
		}
	}
}

/* OS of the agent a game is installed on. Returns 'unknown' when it cannot be determined, so the
 * read fails visibly instead of loading the wrong block. */
function gsp_os_for_install_row($row)
{
	global $db;
	if (is_array($row) && !empty($row['server_os'])) {
		return $row['server_os'];
	}
	$homeId = is_array($row) && isset($row['home_id']) ? (int)$row['home_id'] : 0;
	if ($homeId > 0 && isset($db) && $db) {
		$rows = $db->resultQuery("SELECT rs.server_os FROM GSP_DB_PREFIXserver_homes sh INNER JOIN GSP_DB_PREFIXremote_servers rs ON rs.remote_server_id = sh.remote_server_id WHERE sh.home_id = {$homeId} LIMIT 1");
		if (!empty($rows[0]['server_os'])) {
			return $rows[0]['server_os'];
		}
	}
	return 'unknown';
}
function read_server_config( $filename, $os = null )
{
	gsp_ensure_xml_support();

	if (!is_readable($filename)) {
		gsp_collect_xml_error(
			$filename,
			"Game configuration file is missing or unreadable.",
			"Expected at: {$filename}"
		);
		return FALSE;
	}

	$previous_error_state = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	if ($dom->load($filename) === FALSE)
	{
		gsp_collect_xml_error(
			$filename,
			"Unable to load XML configuration.",
			"File: {$filename}\n".gsp_format_libxml_errors()
		);
		libxml_use_internal_errors($previous_error_state);
		return FALSE;
	}

	if ( $dom->schemaValidate(XML_SCHEMA) !== TRUE )
	{
		gsp_collect_xml_error(
			$filename,
			"XML configuration failed schema validation.",
			"File: {$filename}\nSchema: ".XML_SCHEMA."\n".gsp_format_libxml_errors()
		);
		libxml_use_internal_errors($previous_error_state);
		return FALSE;
	}

	$resolvedOs = gsp_resolve_config_os($dom, $os);
	gsp_apply_os_block($dom, $resolvedOs);
	$xml = simplexml_import_dom($dom);
	if($xml === false){
		gsp_collect_xml_error(
			$filename,
			"Failed to parse XML configuration.",
			"File: {$filename}\n".gsp_format_libxml_errors()
		);
		libxml_use_internal_errors($previous_error_state);
		return FALSE;
	}

	$xml->addChild('home_cfg_file',basename($filename));
	libxml_use_internal_errors($previous_error_state);
	return $xml;
}

function xml_get_mod( $server_xml, $mod_key )
{
    foreach ( $server_xml->mods->mod as $xml_mod_tmp )
    {
        if ($xml_mod_tmp['key'] == $mod_key)
        {
            return $xml_mod_tmp;
        }
    }
    return FALSE;
}

?>
