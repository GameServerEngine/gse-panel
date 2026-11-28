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

    // poll=1 (with type=cleared) returns one JSON status answer for the in-page script.
    $is_poll = isset($_GET['poll']);

    if (!$is_poll)
    {
        echo "<h2>";
        echo empty($home_info['home_name']) ? get_lang('not_available') : htmlentities($home_info['home_name']);
        echo "</h2>";
    }

    require_once('includes/lib_remote.php');
    $remote = new RemoteLibrary($home_info['agent_ip'],$home_info['agent_port'],$home_info['encryption_key'],$home_info['timeout']);

    $server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file'], os_for_install_row($home_info));

    if ( !$server_xml )
    {
        echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id-mod_id-ip-port=". $home_info['home_id'] . "-". $home_info['mod_id'] . "-" . $_REQUEST['ip'] . "-" . $_REQUEST['port'] . "'><< ".get_lang('back')."</a></td></tr></table>";
        return;
    }
	$path_error = '';
	$home_info = with_game_root($home_info, $server_xml, $path_error);
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

	if (!$is_poll)
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
		if (is_numeric($log_retval) && $log_retval > 0)
		{
			echo "<div id='server-log-output'><pre style='background:black;color:white;'>".htmlspecialchars($home_log)."</pre></div>";
		} else {
			echo "<div id='server-log-output'><pre style='background:black;color:white;'>Unable to get log: ".htmlspecialchars((string)$log_retval)."</pre></div>";
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
	
}

	$startup_timeout = isset($server_xml->startup_timeout) && is_numeric((string)$server_xml->startup_timeout) ? (int)$server_xml->startup_timeout : 180;
	$agent_status = get_agent_server_status($remote, $server_xml, $home_id, $ip, $port, "STARTING", $startup_timeout);
	$retry = isset($_GET['retry']) ? (int)$_GET['retry'] : 0;

	$monitor_url = "?m=gamemanager&p=game_monitor&home_id-mod_id-ip-port=$home_id-$mod_id-$ip-$port";
	$answer = start_status_answer($agent_status, $retry);

	if ($answer['state'] === 'online')
	{
		$ip_id = $db->getIpIdByIp($ip);
		$db->delServerStatusCache($ip_id, $port);
	}

	if ($is_poll)
	{
		header('Content-Type: application/json; charset=UTF-8');
		$answer['next_retry'] = $retry + 1;
		echo json_encode($answer);
		return;
	}

	start_status_print($answer);
	$poll_url = "?m=gamemanager&p=start&refresh&type=cleared&poll=1&ip=".rawurlencode($ip)."&port=".rawurlencode($port)."&home_id=".rawurlencode($home_id)."&mod_id=".rawurlencode($mod_id);
	gse_status_poll_script($answer, $poll_url, $monitor_url, $retry);
	// Without JavaScript the old page reload still works.
	if ($answer['state'] === 'waiting')
		$view->refreshNoscript("?m=gamemanager&amp;p=start&amp;refresh&amp;ip=$ip&amp;port=$port&amp;home_id=$home_id&amp;mod_id=$mod_id&amp;retry=".($retry + 1), 3);
	else
		$view->refreshNoscript(htmlspecialchars($monitor_url), $answer['delay']);
}

// Decides what the start page shows for one agent status.
// state: waiting (keep polling), online (started), stop (failed, go back to the monitor).
// html is false for plain text or true for the success wording; delay is seconds before leaving.
function start_status_answer($agent_status, $retry)
{
	$status_name = isset($agent_status['status']) ? strtoupper($agent_status['status']) : "UNKNOWN";
	$last_error = isset($agent_status['last_error']) ? $agent_status['last_error'] : "";

	if (agent_status_ready($agent_status))
		return array('state' => 'online', 'message' => get_lang('server_started'), 'delay' => 1);
	if ($status_name === "UNKNOWN")
		return array('state' => 'stop', 'message' => get_lang("agent_offline"), 'delay' => 3);
	if ($status_name === "UNRESPONSIVE")
		return array('state' => 'stop', 'message' => $last_error !== "" ? $last_error : "Server process exists but the game port is not ready.", 'delay' => 5);
	if ($status_name === "CRASHED")
		return array('state' => 'stop', 'message' => $last_error !== "" ? $last_error : "Server crashed.", 'delay' => 5);
	if ($status_name === "OFFLINE" && $retry >= 20)
		return array('state' => 'stop', 'message' => "The server did not start. Check the agent log.", 'delay' => 5);

	return array('state' => 'waiting',
		'message' => "Server status: ".$status_name.". Waiting for the game port to become ready.",
		'delay' => 3);
}

// Prints the status line. The page script replaces its text while polling.
function start_status_print($answer)
{
	$text = htmlentities($answer['message']);
	echo "<div id='gse-status-line'>";
	if ($answer['state'] === 'online')
		print_success($answer['message']);
	elseif ($answer['state'] === 'stop')
		print_failure($text);
	else
		echo "<p class='note'>".$text."</p><p class='note'>".get_lang('starting_server')."</p>";
	echo "</div>";
}
?>
