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

require_once('home_handling_functions.php');
require_once("modules/config_games/server_config_parser.php");

function exec_module() {
    global $view,$db;

    $ip = $_REQUEST['ip'];
    $port = $_REQUEST['port'];
    $home_id = $_REQUEST['home_id'];
	$mod_id = $_REQUEST['mod_id'];
    $user_id = $_SESSION['user_id'];

	$isAdmin = $db->isAdmin($user_id);
	if($isAdmin)
		$home_info = $db->getGameHome($home_id);
	else
		$home_info = $db->getUserGameHome($user_id,$home_id);

	if (is_array($home_info['mods'][$mod_id])) {
		foreach($home_info['mods'][$mod_id] as $key => $value)
		{
			$home_info[$key] = $value;
		}
	}

	require_once('includes/lib_remote.php');
	$remote = new RemoteLibrary($home_info['agent_ip'],$home_info['agent_port'],$home_info['encryption_key'],$home_info['timeout']);

	$os = $remote->what_os();

    if ( $home_info === FALSE )
    {
        print_failure(get_lang('no_rights_to_stop_server'));
        return;
    }

    echo "<h2>";
    echo empty($home_info['home_name']) ? get_lang('not_available') : htmlentities($home_info['home_name']);
    echo "</h2>";

    $server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file'], os_for_install_row($home_info));

    if ( !$server_xml )
    {
        echo create_back_button("gamemanager","game_monitor");
        return;
    }
	$path_error = '';
	$home_info = with_game_root($home_info, $server_xml, $path_error);
	if ($home_info === false) {
		print_failure('Invalid game directory for this server: ' . htmlentities($path_error));
		return;
	}

    $rserver = $db->getRemoteServerById($home_info['remote_server_id']);

    if ( empty($rserver) )
    {
        print_failure("".get_lang('not_found_server')." ".$home_info['remote_server_id'].".");
    }
    else
    {
		if(isset($_REQUEST['refresh']))
		{
			if( isset( $server_xml->console_log ) )
			{
				$log_path = preg_replace("/%mod%/i", $home_info['mods'][$mod_id]['mod_key'], (string)$server_xml->console_log);
				$log_retval = $remote->get_log(SCREEN_TYPE_HOME,
					$home_info['home_id'],
					clean_path($home_info['game_root']),
					$home_log, 100, $log_path);
			}
			else
			{
				$log_retval = $remote->get_log(SCREEN_TYPE_HOME,
					$home_id,
					clean_path($home_info['game_root']."/".$server_xml->exe_location),
					$home_log);
			}

			function getLastLines($string, $n = 1) {
				$lines = explode("\n", $string);
				$lines = array_slice($lines, -$n);
				return implode("\n", $lines);
			}

			$home_log = getLastLines($home_log, 40);

			if (is_numeric($log_retval) && $log_retval > 0)
			{
				if ( $log_retval == 2 )
					print_failure(get_lang('server_not_running_log_found'));
				echo "<pre style='background:black;color:white;'>".$home_log."</pre>";
				if ($log_retval == 2)
					return;
			}
			else
			{
				print_failure(get_lang_f('unable_to_get_log',$log_retval));
			}

			$startup_timeout = isset($server_xml->startup_timeout) && is_numeric((string)$server_xml->startup_timeout) ? (int)$server_xml->startup_timeout : 180;
			$agent_status = get_agent_server_status($remote, $server_xml, $home_id, $ip, $port, "STARTING", $startup_timeout);
			$status_name = isset($agent_status['status']) ? strtoupper($agent_status['status']) : "UNKNOWN";

			if( !is_agent_status_online($agent_status) )
			{
				if (!isset($_GET['retry']))
					$retry = 0;
				else
					$retry = $_GET['retry'];

				if ($retry < 25 && in_array($status_name, array("UNKNOWN", "STOPPING", "OFFLINE", "STARTING"), true))
				{
					$retry++;
					echo "<p class='note'>".htmlentities("Restart waiting 60 seconds. Current status: $status_name")."</p>";
					$view->refresh("?m=gamemanager&amp;p=restart&amp;refresh&amp;ip=$ip&amp;port=$port&amp;home_id=$home_id&amp;mod_id=$mod_id&amp;retry=".$retry,3);
					return;
				}

				if ($status_name === "UNRESPONSIVE")
				{
					$message = isset($agent_status['last_error']) && $agent_status['last_error'] !== "" ? $agent_status['last_error'] : "Server process exists but the game port is not ready.";
					print_failure(htmlentities($message));
					$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port",5);
					return;
				}

				echo "<p class='note'>Server status: ".htmlentities($status_name).". Waiting for the game port to become ready.</p>";
				$retry++;
				print("<p class='note'>".get_lang('starting_server')."</p>");
				$view->refresh("?m=gamemanager&amp;p=start&amp;refresh&amp;ip=$ip&amp;port=$port&amp;home_id=$home_id&amp;mod_id=$mod_id&amp;retry=".$retry,3);
				return;
			}
			print_success(get_lang_f('server_restarted',htmlentities($home_info['home_name'])));
			$ip_id = $db->getIpIdByIp($ip);
			$db->delServerStatusCache($ip_id,$port);
			$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_id . "-". $mod_id . "-" . $ip . "-" . $port);
			echo "<p>".get_lang('follow_server_status')." <a href='?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=".
			$home_id . "-". $mod_id . "-" . $ip . "-" . $port . "'>".get_lang('game_monitor')."</a> ".get_lang('page').".</p>";

			return;
		}
		else
		{
			$server_home = $home_info;
			$replace_text_results = array();
			if( $server_xml->replace_texts )
			{
				if(	isset($server_xml->lgsl_query_name) )
					require_once('protocol/lgsl/lgsl_protocol.php');
				lifecycle_begin_replace_text_tracking();
				require_once("modules/gamemanager/cfg_text_replace.php");
				$replace_text_results = lifecycle_replace_text_results();
			}

			$control_type = isset($server_xml->control_protocol_type) ? $server_xml->control_protocol_type : "";
			$run_dir = clean_path(isset($server_xml->exe_location) ? (string)$server_xml->exe_location : "");

			$start_cmd = get_start_cmd($remote, $server_xml, $home_info, $mod_id, $ip, $port, $db);
			if ($start_cmd === false) {
				print_failure('Invalid game directory for this server.');
				return;
			}

			// Run pre-start commands
			if(isset($server_xml->pre_start) && !empty($server_xml->pre_start)){
				$preStart = trim($server_xml->pre_start);
			}else{
				$preStart = "";
			}

			// Environment variables
			if(isset($server_xml->environment_variables) && !empty($server_xml->environment_variables)){
				$envVars = trim($server_xml->environment_variables);
			}else{
				$envVars = "";
			}

			// Additional files to lock
			if(isset($server_xml->lock_files) && !empty($server_xml->lock_files)){
				$lockFiles = trim($server_xml->lock_files);
			}else{
				$lockFiles = "";
			}

			if(!empty($lockFiles)){
				// Linux only call
				if(preg_match("/Linux/", $os)){
					$lockedFilesStatus = $remote->lock_additional_home_files($home_info['game_root'], $lockFiles, "lock");
				}
			}

			$lifecycle_payload = lifecycle_start_payload($db, $remote, $home_info, $server_xml, $mod_id, $ip, $port, $start_cmd, $preStart, $envVars, $replace_text_results, 'panel_restart');
			if (isset($lifecycle_payload['error'])) {
				print_failure(htmlentities($lifecycle_payload['error']));
				return;
			}

			$restart_result = $remote->remote_restart_server_result($home_id,$ip,$port,$server_xml->control_protocol,
															$home_info['control_password'],$control_type,$home_info['home_path'],
															$server_xml->server_exec_name,$run_dir,$start_cmd,
															$home_info['cpu_affinity'],$home_info['nice'],$preStart,$envVars, $server_xml->game_key,
															(isset( $server_xml->console_log ) ? $server_xml->console_log : ""),
															'panel_restart',
															$lifecycle_payload);
			if (isset($restart_result['status']) && $restart_result['status'] === 'invalid_port')
			{
				print_failure(htmlentities($restart_result['message']));
				return;
			}
			$remote_retval = isset($restart_result['raw_response']) ? $restart_result['raw_response'] : (!empty($restart_result['success']) ? 1 : 0);

			if ( !empty($restart_result['success']) )
			{
				$db->logger(get_lang_f('server_restarted', $home_info['home_name']) . "($ip:$port)");
				print("<p class='note'>".get_lang('restarting_server')."</p>");
				echo "<p class='note'>Restart waiting 60 seconds before start.</p>";
                $view->refresh("?m=gamemanager&amp;p=restart&amp;refresh&amp;ip=$ip&amp;port=$port&amp;home_id=$home_id&amp;mod_id=$mod_id",3);
                return;
            }
			else if ( $remote_retval === -1 )
			{
				print_failure(get_lang('server_cant_start'));
				$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_id . "-". $mod_id . "-" . $ip . "-" . $port,3);
			}
			else if ( $remote_retval === -2 )
			{
				print_failure(get_lang('server_cant_stop'));
				$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_id . "-". $mod_id . "-" . $ip . "-" . $port,3);
			}
				else
				{
					$agent_status = get_agent_server_status($remote, $server_xml, $home_id, $ip, $port, "STARTING");
					if ( is_agent_status_active($agent_status) )
					{
						print("<p class='note'>".get_lang('restarting_server')."</p>");
					$view->refresh("?m=gamemanager&amp;p=restart&amp;refresh&amp;ip=$ip&amp;port=$port&amp;home_id=$home_id&amp;mod_id=$mod_id",3);
					return;
				}
				else
				{
					print_failure(htmlentities(lifecycle_result_message($restart_result, get_lang('error_occured_remote_host').".$remote_retval")));
					$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_id . "-". $mod_id . "-" . $ip . "-" . $port,3);
				}
			}
		}
    }
}
?>
