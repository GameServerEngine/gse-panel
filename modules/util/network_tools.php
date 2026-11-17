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
require_once 'modules/util/diag_env.php';

// The old Network Tools endpoint (command + remote_target + agent, plain text answer).
// It now runs through the same checks as the new page: role, agent permission, target policy,
// rate limits, audit. Ping runs as before; traceroute needs an agent with diagnostics support.
// A CSRF token (field "csrf") is required like for every diagnostic request.
function util_legacy_text($result)
{
	if (!$result['ok']) {
		return $result['message'];
	}
	if (isset($result['output'])) {
		return $result['output'];
	}
	$lines = array($result['summary']);
	foreach ((array)(isset($result['hops']) ? $result['hops'] : array()) as $hop) {
		$probe = $hop['probes'][0];
		$lines[] = $hop['hop'] . '  ' . ($probe['ip'] !== '' ? $probe['ip'] : '*') . '  ' . ($probe['rtt_ms'] !== null ? $probe['rtt_ms'] . ' ms' : '');
	}
	return implode("\n", $lines);
}

function exec_module()
{
	global $db;
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !diag_csrf_valid(diag_csrf_from_request())) {
		echo 'The page expired. Reload it and try again.';
		return;
	}
	$tools = array('ping' => 'ping', 'traceroute' => 'traceroute');
	$command = isset($_POST['command']) ? trim($_POST['command']) : '';
	if (!isset($tools[$command])) {
		echo get_lang('command_empty');
		return;
	}
	$input = array('agent' => isset($_POST['agent']) ? $_POST['agent'] : 0, 'host' => isset($_POST['remote_target']) ? $_POST['remote_target'] : '');
	$result = diag_execute($tools[$command], $input, util_diag_env($db));
	echo htmlentities(util_legacy_text($result));
}
?>