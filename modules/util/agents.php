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
require_once 'includes/lib_remote.php';
require_once 'modules/util/diag_env.php';

// Lists the agents this user may use, with online status. An admin sees every agent.
// A user sees only agents that host one of their game servers (the old page listed every agent to everyone).
function exec_module()
{
	global $db;
	ini_set('display_errors', '0'); // an offline agent makes the XML-RPC decoder print a warning; keep it out of the JSON
	$env = util_diag_env($db);
	$all = util_diag_all_agents($db);
	$servers = array();

	foreach ($env['agent_ids'] as $agent_id){
		$server = $all[$agent_id];
		$remote = new RemoteLibrary($server['agent_ip'], $server['agent_port'], $server['encryption_key'], 1);
		$status = (int)$remote->status_chk();

		$servers[] = array(
			'id'		=>	$server['remote_server_id'],
			'name'		=>	$server['remote_server_name'] .' '. (($status) === 0 ? '('.get_lang('offline').')' : '('.get_lang('online').')'),
			'status'	=>	$status,
		);
	}
	echo json_encode($servers);
}
?>