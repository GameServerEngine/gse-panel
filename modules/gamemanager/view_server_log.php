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

function exec_module()
{
    global $db;
    global $view;


	list($home_id, $mod_id, $ip, $port) = explode("-", $_GET['home_id-mod_id-ip-port']);
	$user_id = $_SESSION['user_id'];


	$isAdmin = $db->isAdmin( $user_id );
	if($isAdmin)
		$home_info = $db->getGameHome($home_id);
	else
		$home_info = $db->getUserGameHome($user_id,$home_id);

	$current_mod_info = $home_info['mods'][$mod_id];
	$home_cfg_id = $current_mod_info['home_cfg_id'];
	$mod_cfg_id = $current_mod_info['mod_cfg_id'];

	if($home_cfg_id === null && $mod_cfg_id === null){
		print_failure(get_lang('invalid_game_mod_id'));
		return;
	}

    if ( $home_info === FALSE )
    {
        print_failure( get_lang("no_access_to_home") );
        return;
    }

    $server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file'], os_for_install_row($home_info));

    if ( !$server_xml )
    {
        echo create_back_button( $_GET['m'], 'game_monitor&home_id-mod_id-ip-port='.$_GET['home_id-mod_id-ip-port'] );
        return;
    }
	$path_error = '';
	$home_info = with_game_root($home_info, $server_xml, $path_error);
	if ($home_info === false) {
		print_failure('Invalid game directory for this server: ' . htmlentities($path_error));
		return;
	}

    require_once('includes/lib_remote.php');
    $remote = new RemoteLibrary($home_info['agent_ip'],$home_info['agent_port'],$home_info['encryption_key'],$home_info['timeout']);

    $home_log = "";

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
			$home_info['home_id'],
			clean_path($home_info['game_root']."/".$server_xml->exe_location),
			$home_log);
	}

    if ($log_retval == 0)
    {
        print_failure( get_lang("agent_offline") );
		echo create_back_button( $_GET['m'], 'game_monitor&home_id-mod_id-ip-port='.$_GET['home_id-mod_id-ip-port'] );
    }
    elseif ($log_retval == 1 || $log_retval == 2)
    {
		// Force log file contents to be UTF-8 (fixes forum/viewthread.php?thread_id=5379)
		if(hasValue($home_log)){
			if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
				if (!mb_check_encoding($home_log, 'UTF-8')) {
					$home_log = mb_convert_encoding($home_log, 'UTF-8', 'ISO-8859-1');
				}
			} elseif (function_exists('iconv')) {
				$converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $home_log);
				if ($converted !== false) {
					$home_log = $converted;
				}
			}
		}

			// Using the refreshed class
			if( isset($_GET['refreshed']) )
			{
				if (!headers_sent()) {
					header('Content-Type: text/plain; charset=UTF-8');
				}
				echo $home_log;
				return;
			}
			else
			{
				echo "<h2>".htmlentities($home_info['home_name'])."</h2>";
				if($log_retval == 1)
				{
					$log_url = "home.php?m=gamemanager&p=log&type=cleared&refreshed&home_id-mod_id-ip-port=".rawurlencode($_GET['home_id-mod_id-ip-port']);
					?>
					<style>
					.gsp-live-log-panel {
						display: block;
						width: 100%;
						max-width: 1600px;
						min-height: 500px;
						height: 55vh;
						box-sizing: border-box;
						padding: 12px;
						border: 1px solid #36475f;
						border-radius: 4px;
						background: #050a12;
						color: #d7dde8;
						font-family: Consolas, Menlo, Monaco, "Courier New", monospace;
						font-size: 13px;
						line-height: 1.45;
						white-space: pre-wrap;
						word-break: break-word;
						overflow-y: auto;
						overflow-x: auto;
						-webkit-overflow-scrolling: touch;
					}
					@media (max-width: 700px) {
						.gsp-live-log-panel {
							min-height: 45vh;
							height: 45vh;
							font-size: 12px;
						}
					}
					</style>
					<?php
					echo '<pre id="live-server-log" class="gsp-live-log-panel" tabindex="0">'.htmlentities($home_log, ENT_QUOTES, "UTF-8").'</pre>';
					?>
					<script type="text/javascript">
					(function(){
						var logBox = document.getElementById('live-server-log');
						if (!logBox) return;
						function isAtBottom() {
							return (logBox.scrollTop + logBox.clientHeight) >= (logBox.scrollHeight - 24);
						}
						function refreshLog() {
							var shouldScroll = isAtBottom();
							fetch('<?php echo $log_url; ?>', {cache: 'no-store'})
								.then(function(response){ return response.text(); })
								.then(function(text){
									logBox.textContent = text;
									if (shouldScroll) {
										logBox.scrollTop = logBox.scrollHeight;
									}
								})
								.catch(function(){});
						}
						logBox.scrollTop = logBox.scrollHeight;
						window.setInterval(refreshLog, 4000);
					})();
					</script>
					<?php
					if(	($server_xml->control_protocol and preg_match("/^r?l?con2?$/", $server_xml->control_protocol)) OR
						($server_xml->gameq_query_name and $server_xml->gameq_query_name == "minecraft") OR
					($server_xml->lgsl_query_name  and $server_xml->lgsl_query_name == "7dtd") )
					require('modules/gamemanager/rcon.php');
			}
			else
			{
				echo "<pre class='log'>" . htmlentities($home_log) . "</pre>";
				print_failure( get_lang("server_not_running") );
			}
			echo create_back_button( $_GET['m'], 'game_monitor&home_id-mod_id-ip-port='.$_GET['home_id-mod_id-ip-port'] );
		}
    }
    else
    {
        print_failure(get_lang_f('unable_to_get_log',$log_retval));
		echo create_back_button( $_GET['m'], 'game_monitor&home_id-mod_id-ip-port='.$_GET['home_id-mod_id-ip-port'] );
    }
}
?>
