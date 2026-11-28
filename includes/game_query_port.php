<?php
/*
 * The query port of a game server, from its game XML (the OS section read_server_config returns).
 *
 * The port the panel puts on the start command is the one the game answers queries on, so it comes
 * first: the QUERY_PORT / QUERYPORT entry of <reserve_ports> (game port + offset). Then the older
 * <query_port> tag. Without either, the game answers queries on its game port.
 */

if (!function_exists('xml_query_port_offset')) {
	/** The query port's offset from the game port set in the XML, or null when the XML sets none. */
	function xml_query_port_offset($server_xml)
	{
		if (isset($server_xml->reserve_ports->port)) {
			foreach ($server_xml->reserve_ports->port as $port) {
				if (preg_match('/^QUERY_?PORT$/i', (string)$port['id'])) {
					$value = (int)trim((string)$port);
					return (string)$port['type'] === 'subtract' ? -$value : $value;
				}
			}
		}
		if (isset($server_xml->query_port) && trim((string)$server_xml->query_port) !== '') {
			$value = (int)trim((string)$server_xml->query_port);
			return (string)$server_xml->query_port['type'] === 'subtract' ? -$value : $value;
		}
		return null;
	}
}

if (!function_exists('get_query_port')) {
	function get_query_port($server_xml, $server_port)
	{
		$offset = xml_query_port_offset($server_xml);
		return (int)$server_port + ($offset === null ? 0 : $offset);
	}
}
