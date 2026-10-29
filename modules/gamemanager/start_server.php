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

function exec_gsp_module()
{
    global $db;
    global $view;

    $home_id = $_REQUEST['home_id'];
	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );
	if($isAdmin)
		$home_info = $db->getGameHome($home_id);
	else
		$home_info = $db->getUserGameHome($_SESSION['user_id'],$home_id);

    if ( $home_info == FALSE )
    {
        print_failure(get_lang('no_rights_to_start_server'));
        echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_info['home_id'] . "-". $home_info['mod_id'] . "-" . $_REQUEST['ip'] . "-" . $_REQUEST['port'] . "'><< ".get_lang('back')."</a></td></tr></table>";
        return;
    }

    $mod_id = $_REQUEST['mod_id'];

    if ( !array_key_exists($mod_id, (array)$home_info['mods']) )
    {
        print_failure("Unable to retrieve mod information from database.");
        return;
    }

    echo "<h2>";
    echo empty($home_info['home_name']) ? get_lang('not_available') : htmlentities($home_info['home_name']);
    echo "</h2>";

    require_once('includes/lib_remote.php');
    $remote = new GSPRemoteLibrary($home_info['agent_ip'],$home_info['agent_port'],$home_info['encryption_key'],$home_info['timeout']);

    $server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);

    if ( !$server_xml )
    {
        echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_info['home_id'] . "-". $home_info['mod_id'] . "-" . $_REQUEST['ip'] . "-" . $_REQUEST['port'] . "'><< ".get_lang('back')."</a></td></tr></table>";
        return;
    }
	$path_error = '';
	$home_info = gsp_with_game_root($home_info, $server_xml, $path_error);
	if ($home_info === false) {
		print_failure('Invalid game directory for this server: ' . htmlentities($path_error));
		return;
	}

	// It compares ip and port on POST with the pair on DB for security reasons (URL HACKING)
	$home_id = $home_info['home_id'];

	$ip_info = $db->getHomeIpPorts($home_id);
	if (!is_array($ip_info)) {
		$ip_info = [];
	}

	foreach ((array)$ip_info as $ip_ports_row)

	{

		if($ip_ports_row['ip'] == $_REQUEST['ip'] && $ip_ports_row['port'] == $_REQUEST['port'])

		{

			$ip = $_REQUEST['ip'];

			$port = $ip_ports_row['port'];

		}

	}

	If (!isset($ip) OR !isset($port))

	{

		echo "<h2>" . get_lang_f('ip_port_pair_not_owned') . "</h2>";

		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port'><< ".get_lang('back')."</a></td></tr></table>";

		return;

	}

	if( isset( $server_xml->console_log ) )
	{
		$log_path = preg_replace("/%mod%/i", $home_info['mods'][$mod_id]['mod_key'], (string)$server_xml->console_log);
		$log_retval = $remote->get_log(GSP_SCREEN_TYPE_HOME,
			$home_info['home_id'],
			clean_path($home_info['game_root']),
			$home_log, 100, $log_path);
	}
	else
	{
		$log_retval = $remote->get_log(GSP_SCREEN_TYPE_HOME,
			$home_info['home_id'],
			clean_path($home_info['game_root']."/".$server_xml->exe_location),
			$home_log);
	}

	function getLastLines($string, $n = 1) {
		$lines = explode("\n", (string)$string);
		$lines = array_slice($lines, -$n);
		return implode("\n", $lines);
	}
	//PUT STARTUP MESSAGES HERE

		if (!isset($_GET['retry']))
			$retry = 0;
		else
			$retry = $_GET['retry'];

			if($retry == 1)
			{
				echo "Starting the server ...";
			}
			if($retry==2)
			{
				echo "... Starting the server ...";
			}

			if($retry==3)
			{
				echo "...... Starting the server ...";
			}
/*
			if($retry > 3)
			{

			echo "<p>Still Starting Server .. </br>
			         Something <b>MIGHT</b> be wrong .. </br>
					 or it just might need more time<br>
					 You can return to the <a href='?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port'>Game Monitor</a></br>
					 Or you can <a href='?m=gamemanager&amp;p=stop&amp;home_id=$home_id&amp;mod_id=$mod_id&amp;ip=$ip&amp;port=$port' >Stop the Server</a></p>";

			}
*/



	$home_log = getLastLines($home_log, 40);

	// Replace hardcoded log output with AJAX container
	if ($log_retval > 0)
	{
		echo "<div id='server-log-output'><pre style='background:black;color:white;'>".htmlspecialchars($home_log)."</pre></div>";
	} else {
		echo "<div id='server-log-output'><pre style='background:black;color:white;'>Unable to get log: $log_retval</pre></div>";
	}
	// Add AJAX refresh script
	?>
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script>
	(function() {
	  var logUrl = 'modules/gamemanager/get_server_log.php?home_id=<?php echo rawurlencode((string)$home_id); ?>&mod_id=<?php echo rawurlencode((string)$mod_id); ?>';
	  window.setInterval(function() {
	    $.ajax({url: logUrl, cache: false}).done(function(html) {
	      $('#server-log-output').html(html);
	    });
	  }, 2000);
	})();
	</script>
	<?php

	$startup_timeout = isset($server_xml->startup_timeout) && is_numeric((string)$server_xml->startup_timeout) ? (int)$server_xml->startup_timeout : 180;
	$agent_status = get_agent_server_status($remote, $server_xml, $home_id, $ip, $port, "STARTING", $startup_timeout);
	$status_name = isset($agent_status['status']) ? strtoupper($agent_status['status']) : "UNKNOWN";

	if( !is_agent_status_online($agent_status) )
	{
		if (!isset($_GET['retry']))
			$retry = 0;
		else
			$retry = $_GET['retry'];

		if ($status_name === "UNKNOWN")
		{
			print_failure(get_lang("agent_offline"));
			$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port",3);
			return;
		}

		if ($status_name === "UNRESPONSIVE")
		{
			$message = isset($agent_status['last_error']) && $agent_status['last_error'] !== "" ? $agent_status['last_error'] : "Server process exists but the game port is not ready.";
			print_failure(htmlentities($message));
			$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port",5);
			return;
		}

		if ($status_name === "CRASHED")
		{
			$message = isset($agent_status['last_error']) && $agent_status['last_error'] !== "" ? $agent_status['last_error'] : "Server crashed.";
			print_failure(htmlentities($message));
			$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port",5);
			return;
		}
		if ($status_name === "OFFLINE" && (int)$retry >= 20)
		{
			print_failure("The server did not start. Check the agent log.");
			$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port",5);
			return;
		}
		echo "<p class='note'>Server status: ".htmlentities($status_name).". Waiting for the game port to become ready.</p>";
		$retry++;
		print("<p class='note'>".get_lang('starting_server')."</p>");
		$view->refresh("?m=gamemanager&amp;p=start&amp;refresh&amp;ip=$ip&amp;port=$port&amp;home_id=$home_id&amp;mod_id=$mod_id&amp;retry=".$retry,3);
		return;
	}


	print_success(get_lang('server_started'));
	$ip_id = $db->getIpIdByIp($ip);
	$db->delServerStatusCache($ip_id,$port);
	$view->refresh("?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port" );
	return;
}
?>
