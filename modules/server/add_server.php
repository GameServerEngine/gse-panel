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

function exec_module() {

	global $view;
	global $db;

	#This will add a remote host to the list
	if ( isset($_REQUEST['add_remote_host']) )
	{
		$rhost_ip = trim($_POST['remote_host']);
		$rhost_name = trim($_POST['remote_host_name']);
		$rhost_port = trim($_POST['remote_host_port']);
		$rhost_ftp_ip = trim($_POST['remote_host_ftp_ip']);
		$rhost_ftp_port = trim($_POST['remote_host_ftp_port']);
		$encryption_key = trim($_POST['remote_encryption_key']);
		if ( $encryption_key === '' || strlen($encryption_key) > 16 ){
			print_failure( 'The agent encryption key must be 1 to 16 characters.' );
			$view->refresh("?m=server");
			return;
		}
		// The agent installer finds its server by this key, so each server needs its own.
		foreach ( (array)$db->getRemoteServers() as $existing ) {
			if ( is_array($existing) && (string)$existing['encryption_key'] === $encryption_key ) {
				print_failure( 'Another server already uses this agent key. Use a different key for each server.' );
				$view->refresh("?m=server");
				return;
			}
		}
		$pending_user = trim($_POST['remote_host_user_name'] ?? '');
		if ( $pending_user === '' ) $pending_user = 'gameserver';
		$timeout = trim($_POST['timeout']);
		$use_nat = trim($_POST['use_nat']);
		$display_public_ip = trim($_POST['display_public_ip']);

		if ( empty($rhost_ip) ){
			print_failure( get_lang("enter_ip_host") );
			$view->refresh("?m=server");
			return;
		}

		if ( !isPortValid($rhost_port) ){
			print_failure( get_lang("enter_valid_ip") );
			$view->refresh("?m=server");
			return;
		}

		require_once('includes/lib_remote.php');

		$remote = new RemoteLibrary($rhost_ip,$rhost_port,$encryption_key,$timeout);
		$status = $remote->status_chk();
		if($status === 0)
		{
			// No agent answers yet: save the server so the agent installer can use this key.
			// The installer reports back when the agent runs; the panel then saves its IPs.
			$rhost_id = $db->addRemoteServer($rhost_ip,$rhost_name,$pending_user,$rhost_port,$rhost_ftp_ip,$rhost_ftp_port,$encryption_key,$timeout,$use_nat,$display_public_ip);
			if ( !$rhost_id )
			{
				print_failure( get_lang("could_not_add_server") ." ".$rhost_ip." ". get_lang("to_db") );
				$view->refresh("?m=server");
				return;
			}
			print_success( "Saved $rhost_ip. No agent is running there yet. Install it with the agent installer "
				. "(gse-agent-linux-install.sh or gse-agent-windows-install.ps1 from the agent's GitHub release) "
				. "and give it this panel's address and the agent key you entered." );
			$view->refresh("?m=server", 30);
			return;
		}
		elseif($status === -1)
		{
			print_failure( get_lang("encryption_key_mismatch") . "<br>" . get_lang("could_not_add_server") . " " . $rhost_ip );
			echo create_back_button($_GET['m']);
			return;
		}

		$rhost_user_name = trim($remote->exec('echo %USERNAME%'));
		if( $rhost_user_name == '%USERNAME%' ) $rhost_user_name = trim($remote->exec('whoami'));

		$rhost_id = $db->addRemoteServer($rhost_ip,$rhost_name,$rhost_user_name,$rhost_port,$rhost_ftp_ip,$rhost_ftp_port,$encryption_key,$timeout,$use_nat,$display_public_ip);
		if ( !$rhost_id )
		{
			print_failure( get_lang("could_not_add_server") ." ".$rhost_ip." ". get_lang("to_db") );
			$view->refresh("?m=server");
			return;
		}

		print_success( get_lang("added_server") ." $rhost_ip ". get_lang("with_port") ." $rhost_port ". get_lang("to_db_succesfully") );

		$iplist = $remote->discover_ips();

		if ( empty($iplist) )
			print_failure( get_lang("unable_discover") ." ".$rhost_ip.". ". get_lang("set_ip_manually") );
		else
		{
			print_success( get_lang("found_ips") ." (".implode(",",$iplist).") ". get_lang("for_remote_server") );
			foreach ((array)$iplist as $remote_ip)
			{
				$remote_ip = trim($remote_ip);
				if ( empty($remote_ip) )
					continue;

				if ( !$db->addRemoteServerIp($rhost_id,$remote_ip) )
					print_failure( get_lang("failed_add_ip") ." (".$remote_ip.") ". get_lang("for_remote_server") );
			}
		}
		$view->refresh("?m=server");
		return;
	}
}
?>

