<script type="text/javascript" src="js/jquery/plugins/jquery.json-2.3.min.js"></script>
<script type="text/javascript" src="js/modules/dashboard.js"></script>
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

require_once('includes/lib_remote.php');

function dash_card($title, $body, $href)
{
	$out = "<div class='bloc rounded' style='flex:1 1 280px;min-width:260px;'>"
		. "<h4>" . htmlspecialchars($title) . "</h4>"
		. "<div style='padding:10px;'>" . $body . "</div>";
	if ($href !== '')
		$out .= "<div style='padding:0 10px 10px;'><a href='" . htmlspecialchars($href) . "'>Open</a></div>";
	return $out . "</div>";
}

function dash_count($db, $sql)
{
	$rows = $db->resultQuery($sql);
	return (is_array($rows) && isset($rows[0]['c'])) ? (int)$rows[0]['c'] : 0;
}

function dash_notifications_card($db)
{
	$uid = (int)$_SESSION['user_id'];
	$body = '';

	if ($db->isModuleInstalled('status_history'))
	{
		require_once(__DIR__ . '/../status_history/includes/status_view.php');
		$cfg = sv_settings($db);
		if ($cfg['enabled'])
		{
			$servers = sv_servers($db);
			$cycle = count($servers) * $cfg['interval_seconds'];
			$latest = sv_latest_samples($db);
			$up = 0;
			foreach ($servers as $server)
			{
				$sid = (int)$server['remote_server_id'];
				list($status) = sv_effective_status(isset($latest[$sid]) ? $latest[$sid] : null, time(), $cycle);
				if ($status === 'UP')
					$up++;
			}
			$body .= "<p>Servers: <b>$up of " . count($servers) . "</b> up</p>";
		}
	}

	$tickets = dash_count($db, "SELECT COUNT(1) AS c FROM DB_PREFIXtickets WHERE user_id = $uid AND status <> 0");
	$invoices = dash_count($db, "SELECT COUNT(1) AS c FROM DB_PREFIXbilling_invoices WHERE user_id = $uid AND payment_status <> 'paid'");
	$body .= "<p>Open tickets: <b>$tickets</b></p>";
	$body .= "<p>Unpaid invoices: <b>$invoices</b></p>";

	// Only unread notifications are active; read ones leave the dashboard.
	$notifications = $db->resultQuery("SELECT c.notification_id, c.subject FROM DB_PREFIXnotification_recipients r NATURAL JOIN DB_PREFIXnotification c WHERE r.user_id = $uid AND r.status = 0 ORDER BY c.`timestamp` DESC LIMIT 5");
	if (is_array($notifications) && count($notifications) > 0)
	{
		$body .= "<ul style='margin:0;padding-left:18px;'>";
		foreach ($notifications as $notification)
			$body .= "<li><a href='home.php?m=notifications&amp;p=inbox&amp;read_notification=" . (int)$notification['notification_id'] . "'>" . htmlspecialchars($notification['subject']) . "</a></li>";
		$body .= "</ul>";
	}
	else
		$body .= "<p>No unread notifications.</p>";

	return dash_card('Notifications', $body, 'home.php?m=notifications&p=inbox&list=true');
}

function dash_news_card()
{
	$file = __DIR__ . '/../news/data/listings.xml';
	$xml = file_exists($file) ? simplexml_load_file($file) : false;
	$items = array();
	if ($xml)
	{
		$index = 0;
		foreach ($xml->listing as $item)
		{
			$items[] = array(
				'id' => $index,
				'time' => (int)$item->time,
				'title' => htmlspecialchars(strip_tags(html_entity_decode((string)$item->title))),
			);
			$index++;
		}
		usort($items, function ($a, $b) { return $b['time'] - $a['time']; });
	}
	if (empty($items))
		return dash_card('Latest News', '<p>No news yet.</p>', '');

	// Items from the last 7 days are marked NEW; the newest items are listed first.
	$recent_since = time() - 7 * 86400;
	$body = '';
	foreach (array_slice($items, 0, 4) as $item)
	{
		$when = new DateTime('@' . $item['time']);
		$when->setTimezone(new DateTimeZone('America/Chicago'));
		$badge = $item['time'] >= $recent_since
			? " <span style='background:#f90;color:#000;border-radius:3px;padding:0 5px;font-size:11px;'>NEW</span>"
			: '';
		$body .= "<p style='margin:0 0 8px;'><a href='home.php?m=news&amp;p=news&amp;page=details&amp;id=" . $item['id'] . "'>" . $item['title'] . "</a>" . $badge . "<br><small>" . $when->format('M j, Y') . "</small></p>";
	}
	return dash_card('Latest News', $body, 'home.php?m=news&p=news');
}

function dash_account_card($db)
{
	$uid = (int)$_SESSION['user_id'];
	if ($db->isAdmin($uid))
		$homes = $db->getHomesFor('admin', $uid);
	elseif ($db->isSubUser($uid))
		$homes = $db->getHomesFor('subuser', $uid);
	else
		$homes = $db->getHomesFor('user_and_group', $uid);

	$body = '';
	$seen = array();
	foreach ((array)$homes as $home)
	{
		$hid = (int)(isset($home['home_id']) ? $home['home_id'] : $home['hid']);
		if (isset($seen[$hid]))
			continue;
		$seen[$hid] = true;
		$name = isset($home['home_name']) ? $home['home_name'] : ('Server ' . $hid);
		$body .= "<p style='margin:0 0 6px;'><a href='home.php?m=gamemanager&amp;p=game_monitor&amp;home_id=$hid'>" . htmlspecialchars($name) . "</a></p>";
	}
	if ($body === '')
		$body = '<p>No game servers assigned to you yet.</p>';

	return dash_card('Account Overview', $body, 'home.php?m=gamemanager&p=game_monitor');
}

function exec_module()
{
	global $db, $settings, $loggedInUserInfo;

	$isAdmin = $db->isAdmin($_SESSION['user_id']);
	$user_id = $_SESSION['user_id'];

	$page_user = (isset($_GET['page']) && (int)$_GET['page'] > 0) ? (int)$_GET['page'] : 1; // thanks for Adjokip
	$limit_user = isset($_GET['limit']) ? $_GET['limit'] : 10;





	if(hasValue($loggedInUserInfo) && is_array($loggedInUserInfo) && $loggedInUserInfo["users_page_limit"] && !(isset($_GET['limit']) and !empty($_GET['limit']))){
		$limit_user = $loggedInUserInfo["users_page_limit"];
	}


	if( isset($settings['welcome_title']) && $settings['welcome_title'] == "1" )
	{
		if( isset($settings['welcome_title_message']) && !empty($settings['welcome_title_message'] ))
		{
			echo "<div>" . $settings['welcome_title_message'] . "</div>";
		}
	}


	require_once("includes/refreshed.php");
	$refresh = new refreshed();
	$OnlineServers = "<p>Recent updates and changes</p>";
	?>
	<div style="margin-top:20px;">
	<?php

	$dashCards = array();
	$dashCards[] = dash_account_card($db);
	$dashCards[] = dash_notifications_card($db);
	$dashCards[] = dash_news_card();
	echo "<div class='dash-cards' style='display:flex;flex-wrap:wrap;gap:16px;align-items:stretch;'>" . implode('', $dashCards) . "</div>";

	// Server status: stored history only, no agent is called here. Visible to all users.
	if ( $db->isModuleInstalled('status_history') )
	{
		require_once(__DIR__ . '/../status_history/includes/status_view.php');
		echo sv_dashboard_html($db, $isAdmin);
	}

	if( $isAdmin AND $db->isModuleInstalled('status') )
	{
		echo "<h0>".get_lang('server_status')."</h0><br>";
		$servers = $db->getRemoteServers();

		echo "<div id='column4' style='float:left;width:40%;' >
			   <div class='bloc rounded' >
			   <h4>".get_lang('select_remote_server')."</h4>
				<div>
				<br>
				<center>
				<form action='' method='GET'>
				<input type='hidden' name='m' value='".$_GET['m']."'/>
				<input type='hidden' name='p' value='".$_GET['p']."'/>
				<select name='remote_server_id' onchange=".'"this.form.submit()"'.">\n";

		$agents_ips = array();
		foreach ((array)$servers as $server_row)
		{
			$agents_ips[$server_row['remote_server_id']] = gethostbyname($server_row['agent_ip']);
			if( !empty( $server_row['remote_server_id'] ) and !isset( $_GET['remote_server_id'] ) OR !empty( $server_row['remote_server_id'] ) and empty( $_GET['remote_server_id'] ) )
			{
				$_GET['remote_server_id'] = $server_row['remote_server_id'];
			}

			if( isset($_GET['remote_server_id']) AND $_GET['remote_server_id'] == $server_row['remote_server_id'] )
			{
				$remote = new RemoteLibrary( $server_row['agent_ip'], $server_row['agent_port'],
												$server_row['encryption_key'], $server_row['timeout'] );
				$host_stat = $remote->status_chk();
				if( $host_stat === 1 )
				{
					$checked = "selected='selected'";
				}
				else
				{
					$checked = '';
					$_GET['remote_server_id'] = 'webhost';
				}
			}
			else
			{
				$checked = '';
			}
			echo "<option value='".$server_row['remote_server_id']."' $checked >".$server_row['remote_server_name']."</option>\n";
		}

		if ( function_exists('exec') )
		{
			$host_ip = isset($_SERVER['LOCAL_ADDR']) ? $_SERVER['LOCAL_ADDR'] : $_SERVER['SERVER_ADDR'];
			$remote_server_id = array_search($host_ip,$agents_ips);
			$show_webhost = true;
			if($remote_server_id)
			{
				$remote_server = $db->getRemoteServer($remote_server_id);
				$remote = new RemoteLibrary( $remote_server['agent_ip'], $remote_server['agent_port'],
												$remote_server['encryption_key'], $remote_server['timeout'] );
				$host_stat = $remote->status_chk();
				if( $host_stat === 1 )
					$show_webhost = false;
			}
			if($show_webhost)
			{
				$checked = ( isset($_GET['remote_server_id']) AND $_GET['remote_server_id'] == 'webhost' ) ? "selected='selected'" : "";
				echo "<option value='webhost' $checked >Webhost Status</option>";
			}
		}

		echo "	</select>
				</form>
				</center>
				<br><br>
				</div>
			   </div>
			  </div>\n";

		if( isset($_GET['remote_server_id']) AND ( $_GET['remote_server_id'] == "webhost" or $_GET['remote_server_id'] == "" ) )
			unset($_GET['remote_server_id']);

		if( isset($_GET['remote_server_id']) )
			$remote_server = "&remote_server_id=".$_GET['remote_server_id'];
		else
			$remote_server = "";

		if( isset($_GET['remote_server_id']) OR function_exists('exec') )
			echo $refresh->getdiv($refresh->add("home.php?m=status&type=cleared".$remote_server));
	}

?>
</div>
<script type="text/javascript">
$(document).ready(function(){
	<?php echo $refresh->build(isset($settings['query_cache_life']) ? $settings['query_cache_life'] * 2000 : 60000); ?>
});
</script>
<?php

}
?>
