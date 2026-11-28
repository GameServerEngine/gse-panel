<?php
require_once(dirname(__DIR__, 2) . '/includes/game_ip.php');
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

/**
 * Checks an address before it is saved as a game IP of an agent. A public address is saved.
 * A private (LAN or VPN) address on an agent that is not marked "Use NAT" is not refused
 * outright: the page asks whether the agent is behind NAT, and only when the admin answers
 * yes is Use NAT turned on and the address saved. Returns TRUE when the address may be saved;
 * sets $GLOBALS['server_ip_asking'] when the question is on the page instead.
 */
function server_ip_checked($rhost_id, &$remote_server, $ip, $action, $ip_id)
{
	global $db;
	if ( filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false )
	{
		print_failure( htmlentities($ip) . " is not a valid IPv4 address." );
		return FALSE;
	}
	if ( game_ip_allowed($ip, $remote_server['use_nat']) )
		return TRUE;
	if ( !empty($_POST['confirm_nat']) )
	{
		if ( $db->query("UPDATE `DB_PREFIXremote_servers` SET use_nat='1' WHERE remote_server_id=" . (int)$rhost_id) === FALSE )
		{
			print_failure( "Could not turn on Use NAT for this agent." );
			return FALSE;
		}
		$remote_server['use_nat'] = 1;
		print_success( "Use NAT is now on for " . htmlentities($remote_server['remote_server_name']) . "." );
		return TRUE;
	}
	$GLOBALS['server_ip_asking'] = TRUE;
	$h = htmlentities($ip);
	echo "<div class='failure'><p><b>" . $h . " is a private address.</b> It is a LAN or VPN address (such as the WireGuard 10.255.0.x network), "
		. "and this agent is not marked as being behind NAT, so players could not reach a game server bound to it.</p>"
		. "<p>Is this agent behind NAT, with a router that forwards the game ports from a public address to " . $h . "?</p>"
		. "<form method='post' action='?m=server&amp;p=edit&amp;rhost_id=" . (int)$rhost_id . "&amp;edit'>"
		. "<input type='hidden' name='rhost_id' value='" . (int)$rhost_id . "'/>"
		. ($action === 'add_ip'
			? "<input type='hidden' name='remote_ip' value='" . $h . "'/>"
			: "<input type='hidden' name='ip' value='" . $h . "'/><input type='hidden' name='ip_id' value='" . (int)$ip_id . "'/>")
		. "<input type='hidden' name='confirm_nat' value='1'/>"
		. "<input type='submit' name='" . $action . "' value='Yes, it is behind NAT: turn on Use NAT and save " . $h . "'/> "
		. "<a href='?m=server&amp;p=edit&amp;rhost_id=" . (int)$rhost_id . "&amp;edit'>No, do not use this address</a>"
		. "</form></div>";
	return FALSE;
}

function exec_module() {

    global $view;
    global $db;

    $rhost_id = @$_REQUEST['rhost_id'];
    $remote_server = $db->getRemoteServer($rhost_id);
    if ( $remote_server === FALSE )
    {
        print_failure(get_lang_f('invalid_remote_host_id',$rhost_id));
        $view->refresh("?m=server");
        return;
    }

    echo "<h2>".get_lang_f('editing_remote_server',$remote_server['remote_server_name'])."</h2>";

    #this allows you to define upto 4 IPs for each remote host.  The IPs are for binding games to
	if ( isset($_REQUEST['add_ip']) )
    {
        $remote_ip = preg_replace("/[^0-9\.]/", "", $_POST['remote_ip']);
		if ( server_ip_checked($rhost_id, $remote_server, $remote_ip, 'add_ip', 0) )
		{
			if ( $db->addRemoteServerIp($rhost_id, $remote_ip) === FALSE )
				print_failure( get_lang("could_not_add_ip") );
			else
				print_success(get_lang_f('ips_set_for_server',$remote_server['remote_server_name']));
		}
		else if ( isset($GLOBALS['server_ip_asking']) )
			return;
		$view->refresh("?m=server&amp;p=edit&amp;rhost_id=".$rhost_id."&amp;edit");
    }

    else if ( isset($_REQUEST['remove_ip']) )
    {
		$ip_id = $_POST['ip_id'];

        if ( $db->removeRemoteServerIps($ip_id) === FALSE )
		{
            print_failure( get_lang("could_not_remove_ip") );
		}
		else
		{
			print_success(get_lang_f('ips_set_for_server',$remote_server['remote_server_name']));
		}
		$view->refresh("?m=server&amp;p=edit&amp;rhost_id=".$rhost_id."&amp;edit");
    }

	else if ( isset($_REQUEST['edit_ip']) )
    {
		$ip_id = (int)$_POST['ip_id'];
		$ip = preg_replace("/[^0-9\.]/", "", $_POST['ip']);
		if ( server_ip_checked($rhost_id, $remote_server, $ip, 'edit_ip', $ip_id) )
		{
			if ( $db->editRemoteServerIps($ip_id,$ip) === FALSE )
				print_failure( get_lang("could_not_edit_ip") );
			else
				print_success(get_lang_f('ips_set_for_server',$remote_server['remote_server_name']));
		}
		else if ( isset($GLOBALS['server_ip_asking']) )
			return;
		$view->refresh("?m=server&amp;p=edit&amp;rhost_id=".$rhost_id."&amp;edit");
    }

    #This removes and rhost from the list
    else if ( isset($_REQUEST['delete']) )
    {
        if ( !isset($_REQUEST['y'] ) )
        {
            echo "<p>". get_lang("areyousure_removeagent") ." <b>\"".
                $remote_server['remote_server_name']."\"</b> ". get_lang("areyousure_removeagent2") ."</p>
                <p><a href='?m=server&amp;p=edit&amp;rhost_id=".$rhost_id."&amp;delete&amp;y=y'>".
                 get_lang("yes") ."</a> <a href='?m=server'>".
                 get_lang("no") ."</a></p>";
            return;
        }

        else if ( $db->removeRemoteServer($rhost_id) === FALSE )
            print_failure( get_lang("error_while_remove") );

        else
            print_success(get_lang_f('remote_host_removed',$remote_server['remote_server_name']));

        $view->refresh("?m=server");
        return;
    }
    else if ( isset($_REQUEST['save_settings']) )
    {
        if ( strlen(trim($_REQUEST['remote_encryption_key'])) > 16 )
        {
            print_failure( 'The agent encryption key must be 16 characters or fewer.' );
            $view->refresh("?m=server&amp;p=edit&amp;rhost_id=".$rhost_id."&amp;edit");
            return;
        }
        $db->changeRemoteServerSettings($remote_server['remote_server_id'],
            $_REQUEST['remote_host'],
            $_REQUEST['remote_host_port'],
            $_REQUEST['remote_host_name'],
			$_REQUEST['user'],
			$_REQUEST['remote_host_ftp_ip'],
			$_REQUEST['remote_host_ftp_port'],
            $_REQUEST['remote_encryption_key'],
			$_REQUEST['timeout'],
			$_REQUEST['use_nat'],
			$_REQUEST['display_public_ip']);
        print_success(get_lang_f('remote_server_settings_changed',$remote_server['remote_server_name']));
        $view->refresh("?m=server&amp;p=edit&amp;rhost_id=".$rhost_id."&amp;edit");
    }
    require_once(__DIR__ . '/location_path.php');
    if ( isset($_POST['save_placement']) )
    {
        global $table_prefix;
        $token = isset($_SESSION['placement_csrf']) ? (string)$_SESSION['placement_csrf'] : '';
        $given = isset($_POST['placement_csrf']) ? (string)$_POST['placement_csrf'] : '';
        if ( $token === '' || !hash_equals($token, $given) )
        {
            print_failure('Invalid security token. Reload the page and try again.');
        }
        else
        {
            $locationId = resolve_location_path($db, $table_prefix, (string)($_POST['location_path'] ?? ''));
            $osIn = (string)($_POST['server_os'] ?? 'linux');
            $os = in_array($osIn, array('linux', 'windows', 'any'), true) ? $osIn : 'linux';
            $install = isset($_POST['install_enabled']) ? 1 : 0;
            $locSql = $locationId > 0 ? (string)$locationId : 'NULL';
            $db->query('UPDATE ' . $table_prefix . 'remote_servers SET location_id = ' . $locSql . ', server_os = ' . chr(39) . $os . chr(39) . ', install_enabled = ' . $install . ' WHERE remote_server_id = ' . (int)$rhost_id);
            print_success('Placement settings saved.');
        }
        $view->refresh('?m=server&amp;p=edit&amp;rhost_id=' . $rhost_id . '&amp;edit');
    }
    if ( isset($_REQUEST['edit']) )
    {
		$remote_server = $db->getRemoteServer($rhost_id);
		$ftp_ip = empty($remote_server['ftp_ip']) ? $remote_server['agent_ip'] : $remote_server['ftp_ip'];
        require_once('includes/form_table_class.php');
        $ft = new FormTable();
        $ft->start_form('?m=server&amp;p=edit&amp;rhost_id='.$rhost_id.'&amp;edit');
        $ft->add_field_hidden('rhost_id',$remote_server['remote_server_id']);
        $ft->start_table();
        $ft->add_field('string','remote_host',$remote_server['agent_ip']);
        $ft->add_field('string','remote_host_port',$remote_server['agent_port']);
        $ft->add_field('string','remote_host_name',$remote_server['remote_server_name']);
		$ft->add_field('string','user',isset($remote_server['user']) ? $remote_server['user'] : (isset($remote_server['user']) ? $remote_server['user'] : ''));
		$ft->add_field('string','remote_host_ftp_ip',$ftp_ip);
		$ft->add_field('string','remote_host_ftp_port',$remote_server['ftp_port']);
        $ft->add_field('string','remote_encryption_key',$remote_server['encryption_key']);
		$ft->add_field('string','timeout',$remote_server['timeout']);
		$ft->add_field('on_off','use_nat',$remote_server['use_nat']);
		$ft->add_field('string','display_public_ip',$remote_server['display_public_ip']);
        $ft->end_table();
        $ft->add_button('submit','save_settings', get_lang("save_settings") );
        $ft->end_form();
        echo create_back_button('server');
        if ( empty($_SESSION['placement_csrf']) ) { $_SESSION['placement_csrf'] = bin2hex(random_bytes(16)); }
        $pathRows = location_tree_rows($db, $table_prefix);
        $pathMap = location_paths((array)$pathRows);
        $curLoc = (int)($remote_server['location_id'] ?? 0);
        $curOs = (string)($remote_server['server_os'] ?? 'linux');
        $curInstall = (int)($remote_server['install_enabled'] ?? 1);
        echo '<h2>Placement</h2>';
        echo '<form method="post" action="?m=server&amp;p=edit&amp;rhost_id=' . $rhost_id . '&amp;edit">';
        echo '<input type="hidden" name="save_placement" value="1">';
        echo '<input type="hidden" name="placement_csrf" value="' . htmlspecialchars((string)$_SESSION['placement_csrf']) . '">';
        $curPath = isset($pathMap[$curLoc]) ? $pathMap[$curLoc] : '';
        echo 'Location: <input type="text" name="location_path" list="loc-paths" size="40" value="' . htmlspecialchars($curPath) . '" placeholder="USA / Central / Kansas City"> <datalist id="loc-paths">';
        foreach ($pathMap as $pid => $pp) { echo '<option value="' . htmlspecialchars($pp) . '">'; }
        echo '</datalist>';
        echo ' OS: <select name="server_os">';
        foreach (array('linux', 'windows', 'any') as $o) { echo '<option value="' . $o . '"' . ($o === $curOs ? ' selected' : '') . '>' . $o . '</option>'; }
        echo '</select> <label><input type="checkbox" name="install_enabled" value="1"' . ($curInstall === 1 ? ' checked' : '') . '> Accept new installs</label> ';
        echo '<input type="submit" value="Save placement"></form>';

        echo "<h2>".get_lang_f('remote_ips_for',$remote_server['remote_server_name'])."</h2>";
        echo "<p class='info'>". get_lang("hint") .": ". get_lang("add_more_ips") ."</p>";
        echo "<p class='info'>" . get_lang("ips_can_be_internal_external") . "</p>";

        $remote_server_ips = $db->getRemoteServerIPs($remote_server['remote_server_id']);
        if ( !empty($remote_server_ips) )
        {
			echo "<h1>". get_lang("edit_ip") ."s</h1>";

			include_once('includes/lib_remote.php');

            foreach ((array)$remote_server_ips as $ip_row)
            {
				$servers_match_ip_id = $db->getIpPorts( $ip_row['ip_id'] );
				$servers_running = FALSE;
				if($servers_match_ip_id)
				{
					foreach ((array)$servers_match_ip_id as $home_info)
					{
						$remote = new RemoteLibrary($home_info['agent_ip'],$home_info['agent_port'],$home_info['encryption_key'],$home_info['timeout']);
						$screen_running = $remote->is_screen_running(SCREEN_TYPE_HOME,$home_info['home_id']) === 1;
						if( $screen_running )
						{
							$servers_running = TRUE;
							break;
						}
					}
				}

				if ( game_ip_is_public($ip_row['ip']) )
					$ip_kind = "<b class='success'>Public</b>";
				else if ( (int)$remote_server['use_nat'] === 1 )
					$ip_kind = "<b>Private (behind NAT)</b>";
				else
					$ip_kind = "<b class='failure'>Private: games will not start on it, because Use NAT is off. Remove it, or turn on Use NAT if the agent is behind a router.</b>";
				$disabled = $servers_running ? "disabled" : "";
				$warning = $servers_running ? "<b class='failure' >". get_lang("there_are_servers_running_on_this_ip") ."<b>" : "";
				echo "<table style='width:auto;'>
					 <tr>
					 <td align='left' >
					 <form method='post' action=''>
					 <input name='ip_id' type='hidden' value='".$ip_row['ip_id']."'/>
					 <input name='ip' type='text' value='".$ip_row['ip']."'/>
					 <input type='submit' name='edit_ip' value='". get_lang("edit_ip") ."' $disabled/>
					 <input type='submit' name='remove_ip' value='". get_lang("remove_ip") ."' $disabled/> $ip_kind $warning
					 </form>
					 </td>
					 <td style='text-align:left;'>
					 <!-- Arrange ports (legacy) removed: use Port Blocks -->
					 <form method='post' action='?m=server&p=port_blocks'>
					 <input type='submit' name='port_blocks' value='". get_lang("port_blocks") ."'/>
					 </form>
					 </td>
					 </tr>
					 </table>";
            }
        }

		echo "<h1>". get_lang("add_ip") ."s</h1>";

        $ft = new FormTable();
        $ft->start_form('');
        $ft->add_field_hidden('rhost_id',$remote_server['remote_server_id']);
        $ft->start_table();
        $ft->add_field('string','remote_ip','');
        $ft->end_table();
        $ft->add_button('submit','add_ip', get_lang("add_ip") );
        $ft->end_form();
    }
    else
    {
        print_failure("Invalid url.");
        $view->refresh("?m=server");
    }
}
?>
