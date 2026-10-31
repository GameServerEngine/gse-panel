<script type="text/javascript" src="js/modules/server.js"></script>
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

require_once('modules/server/agent_helpers.php');
function exec_gsp_module() {

	global $view;
	global $db;

	echo "<h2>". get_lang("add_new_remote_host") ."</h2>";
	echo "<p>". get_lang("note_remote_host") ."</p>";
	echo "<p><a href='?m=server&amp;p=port_blocks'>[". get_lang("port_blocks") ."]</a></p>";

	require_once("includes/form_table_class.php");

	$ft = new FormTable();
	$ft->start_form("?m=server&amp;p=add");
	$ft->start_table();
	$ft->add_field('string','remote_host',"");
	$ft->add_field('string','remote_host_port',"12679");
	$ft->add_field('string','remote_host_name',"");
	$ft->add_field('string','remote_host_ftp_ip',"");
	$ft->add_field('string','remote_host_ftp_port',"21");
	$ft->add_field('string','remote_encryption_key',"");
	$ft->add_field('string','timeout',"5");
	$ft->add_field('on_off','use_nat',"0");
	$ft->add_field('string','display_public_ip',"");
	$ft->end_table();
	$ft->add_button('submit','add_remote_host', get_lang("add_remote_host") );
	$ft->end_form();

	$servers = $db->getRemoteServers();

	if ( $servers === FALSE )
		return;

	$tr = 0;

	gsp_agent_ensure_snapshot_table($db);
	gsp_agent_refresh_one_stale_snapshot($db, $servers, 300);

	?>
	<form method="post" action="?m=server&amp;p=batch_update">
	<table id="servermonitor" class="tablesorter remote">
		<thead>
		<tr>
			<th colspan="9" class="header sorter-false"><?php print_lang('configured_remote_hosts'); ?></th>
		</tr>
		<tr>
			<th><input type="checkbox" id="agent_select_all" onclick="var boxes=document.getElementsByName('agent_ids[]');for(var i=0;i<boxes.length;i++){boxes[i].checked=this.checked;}" /></th>
			<th>ID</th>
			<th>Agent</th>
			<th>Address</th>
			<th>Status</th>
			<th>OS / Version</th>
			<th>Game Servers</th>
			<th>Resources</th>
			<th>Actions</th>
		</tr>
		</thead>
		<tbody> <?php
	foreach ((array)$servers as $server_row)
	{
		$remote = gsp_agent_build_remote($server_row);
		$host_stat = $remote->status_chk();
		$snapshot = gsp_agent_get_snapshot($db, $server_row['remote_server_id']);
		$home_count = gsp_agent_count_homes($db, $server_row['remote_server_id']);
		$os = gsp_agent_snapshot_field($snapshot, 'os', '');
		if ($os === '' || strtolower((string)$os) === 'unknown') {
			$os = (string)($server_row['server_os'] ?? '');
		}
		$version = gsp_agent_snapshot_field($snapshot, 'agent_version', '');
		$cpu_percent = gsp_agent_snapshot_field($snapshot, 'cpu_percent', '');
		$ram_percent = gsp_agent_snapshot_field($snapshot, 'ram_percent', '');
		$disk_percent = gsp_agent_snapshot_field($snapshot, 'disk_percent', '');
		$load_average = gsp_agent_snapshot_field($snapshot, 'load_average', '');
		$buttons = "<a href='?m=server&amp;p=agent_interface&amp;rhost_id=".$server_row['remote_server_id']."'>[Manage Agent]</a>\n".
					"<a href='?m=server&amp;p=edit&amp;rhost_id=".$server_row['remote_server_id']."&amp;edit'>[". get_lang("edit") ."]</a>\n".
					"<a href='?m=server&amp;p=edit&amp;rhost_id=".$server_row['remote_server_id']."&amp;delete'>[". get_lang("delete") ."]</a>\n".
					"<a href='?m=server&amp;p=log&amp;rhost_id=".$server_row['remote_server_id']."'>[". get_lang("view_log") ."]</a>\n".
					"<a href='?m=server&amp;p=restart&amp;rhost_id=".$server_row['remote_server_id']."'>[". get_lang("restart") ."]</a>\n".
					"<a href='?m=server&amp;p=agent_interface&amp;rhost_id=".$server_row['remote_server_id']."&amp;update_agent=1'>[Update Agent]</a>\n".
					"<a href='?m=server&amp;p=reboot&amp;rhost_id=".$server_row['remote_server_id']."'>[". get_lang("reboot") ."]</a>\n";

		$resource_text = "CPU: " . (is_numeric($cpu_percent) ? round($cpu_percent, 1) . '%' : 'n/a') . "<br />" .
			"RAM: " . (is_numeric($ram_percent) ? round($ram_percent, 1) . '%' : 'n/a') . "<br />" .
			"Disk: " . (is_numeric($disk_percent) ? round($disk_percent, 1) . '%' : 'n/a') . "<br />" .
			"Load: " . ($load_average !== '' ? gsp_agent_html($load_average) : 'n/a') . "<br />" .
			"Snapshot: " . gsp_agent_format_age($snapshot) . "<br />" .
			gsp_agent_resource_badges($snapshot);

		echo "<tr class='maintr'>";
		echo "<td><input type='checkbox' name='agent_ids[]' value='".(int)$server_row['remote_server_id']."' /></td>";
		echo "<td>".(int)$server_row['remote_server_id']."</td>";
		echo "<td>".gsp_agent_html($server_row['remote_server_name'])."</td>";
		echo "<td>".gsp_agent_html($server_row['agent_ip']).":".(int)$server_row['agent_port']."</td>";
		echo "<td>".gsp_agent_status_label($host_stat)."</td>";
		echo "<td>".gsp_agent_html($os ?: 'unknown')."<br />".gsp_agent_html($version ?: 'version unknown')."</td>";
		echo "<td>".(int)$home_count."</td>";
		echo "<td>".$resource_text."</td>";
		echo "<td>".$buttons."</td>";
		echo "</tr>";

		$ftp_ip = empty( $server_row['ftp_ip'] ) ? $server_row['agent_ip'] : $server_row['ftp_ip'];
		$agent_user = isset($server_row['gsp_user']) && $server_row['gsp_user'] !== '' ? $server_row['gsp_user'] : (isset($server_row['user']) ? $server_row['user'] : '');
				$gsp_user = $agent_user !== '' ? gsp_agent_html($agent_user) : 'n/a';
		$data = "<tr class='expand-child' >
				   <td></td><td></td><td colspan='7'>
					<b>". get_lang("gsp_user") .":</b> ".$gsp_user."<br />
					<b>". get_lang("agent_ip_port") .":</b> ".$server_row['agent_ip'].":".$server_row['agent_port']."<br />
					<b>". get_lang("remote_host_ftp_ip") .":</b> ".$ftp_ip."<br />
					<b>". get_lang("remote_host_ftp_port") .":</b> ".$server_row['ftp_port']."<br />
					<b>". get_lang("timeout") .":</b> ".$server_row['timeout']."&nbsp;". get_lang("seconds") ."<br />
					<b>". get_lang("encryption_key") .":</b> ".gsp_agent_mask_secret($server_row['encryption_key'])."<br />
					<b>". get_lang("display_public_ip") .":</b> ".checkDisplayPublicIP($server_row['display_public_ip'], $server_row['agent_ip'])."<br />
					<b>".  get_lang("ips") .": </b><br>";

		$remote_server_ips = $db->getRemoteServerIPs($server_row['remote_server_id']);
		if ( empty($remote_server_ips) )
		{
			$data .= "<span class='failure'>". get_lang("no_ip_for_remote_host") ."</span>";
		}
		else
		{
			foreach ((array)$remote_server_ips as $ip_row)
			{
				$data .= gsp_agent_html($ip_row['ip'])."<br>";
			}
		}

		$data .= "<br /><a href='?m=server&amp;p=firewall&amp;rhost_id=".$server_row['remote_server_id']."'>[". get_lang("firewall_settings") ."]</a>\n<br />
				   </td>
				   </tr>";
		echo $data;
	}
	echo "</tbody>";
	echo "</table>\n";
	echo "<p><button type='submit'>Update Selected Agents</button></p>\n";
	echo "</form>\n";
}
