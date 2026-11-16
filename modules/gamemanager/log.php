<?php

require_once('home_handling_functions.php');
require_once("modules/config_games/server_config_parser.php");

// Turns the agent get_log answer into what the page and the live box show.
// state: running, stopped (not running yet), error (no log to show).
if (!function_exists('log_view_state')) {
	function log_view_state($log_retval, $home_log, $has_wildcard, $log_path)
	{
		$retval = (string)$log_retval;
		if ($retval === '1' || $retval === '2')
		{
			// Force log file contents to be UTF-8 (fixes forum/viewthread.php?thread_id=5379)
			if (hasValue($home_log)) {
				$home_log = utf8_encode($home_log);
			}
			return array('state' => $retval === '1' ? 'running' : 'stopped', 'text' => (string)$home_log);
		}
		if ($retval === '0')
			return array('state' => 'error', 'text' => get_lang("agent_offline"));
		if ($retval === '-8' && $has_wildcard)
		{
			// The game makes this file when it starts, so keep the live box and wait.
			$text = !empty($home_log) ? $home_log : "No log files matched pattern: " . $log_path;
			return array('state' => 'stopped', 'text' => $text);
		}
		return array('state' => 'error', 'text' => get_lang_f('unable_to_get_log', $log_retval));
	}
}

// The agent does not tell which file a pattern resolved to, so show the pattern.
if (!function_exists('log_file_caption')) {
	function log_file_caption($log_path, $has_wildcard)
	{
		if ($log_path === '')
			return '';
		return $has_wildcard ? $log_path . ' (newest match)' : $log_path;
	}
}

    global $view;


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
	       $home_cfg_id = 67;
               $mod_cfg_id = 68;
               //print_failure(get_lang('invalid_game_mod_id'));
		//return;
	}

    if ( $home_info === FALSE )
    {
        //print_failure( get_lang("no_access_to_home") );
        //return;
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

    $has_wildcard = isset($log_path) && preg_match('/[*?\[]/', $log_path);
    $log_view = log_view_state($log_retval, $home_log, $has_wildcard, isset($log_path) ? $log_path : '');

    // Reply for the live box script: plain text, state in a header.
    if ( isset($_GET['refreshed']) )
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Log-State: ' . $log_view['state']);
            header('X-Log-File: ' . rawurlencode(log_file_caption($log_path ?? '', $has_wildcard)));
        }
        echo $log_view['text'];
        return;
    }

    if ($log_view['state'] === 'error')
    {
        print_failure(htmlentities($log_view['text'], ENT_QUOTES, "UTF-8"));
        echo create_back_button( $_GET['m'], 'game_monitor&home_id-mod_id-ip-port='.$_GET['home_id-mod_id-ip-port'] );
        return;
    }

    $log_url = "home.php?m=gamemanager&p=log&type=cleared&refreshed&home_id-mod_id-ip-port=".rawurlencode($_GET['home_id-mod_id-ip-port']);
    $caption = log_file_caption($log_path ?? '', $has_wildcard);
    echo "<h2>".htmlentities($home_info['home_name'])."</h2>";
    echo '<div><span id="live-server-status" class="gse-badge"></span></div>';
    echo '<div id="live-server-file" class="gse-log-caption"></div>';
    echo '<pre id="live-server-log" class="gsp-live-log-panel" tabindex="0"></pre>';
    $options = array(
        'url' => $log_url,
        'initialText' => $log_view['text'],
        'initialState' => $log_view['state'],
        'initialFile' => $caption,
    );
    $json = json_encode($options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    echo "<script type='text/javascript' src='js/gse_live.js'></script>\n";
    echo "<script type='text/javascript'>(function(){ var o = ".$json."; o.box = document.getElementById('live-server-log'); o.statusEl = document.getElementById('live-server-status'); o.fileEl = document.getElementById('live-server-file'); GseLiveLog(o); })();</script>\n";
    // Without JavaScript the box shows the log from this page load.
    echo '<noscript><pre class="log">'.htmlentities($log_view['text'], ENT_QUOTES, "UTF-8").'</pre></noscript>';

    if ($log_view['state'] === 'running' AND
        (($server_xml->control_protocol and preg_match("/^r?l?con2?$/", $server_xml->control_protocol)) OR
        ($server_xml->gameq_query_name and $server_xml->gameq_query_name == "minecraft") OR
        ($server_xml->lgsl_query_name  and $server_xml->lgsl_query_name == "7dtd")) )
    {
        require('modules/gamemanager/rcon.php');
    }
    echo create_back_button( $_GET['m'], 'game_monitor&home_id-mod_id-ip-port='.$_GET['home_id-mod_id-ip-port'] );
?>
