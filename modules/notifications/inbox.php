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
include 'modules/notifications/functions.php';
function exec_module()
{
	$notifications = get_notifications();
	if(isset($_GET['list']))
	{
		echo '<h2>'.get_lang('your_notifications').'</h2>';
		rsort($notifications);
		echo "<table id='notification_list'>\n".
			 "<thead><tr><th>".get_lang('status')."</th><th>".get_lang('subject')."</th><th>".get_lang('date')."</th></tr></thead><tbody>\n";
		foreach ((array)$notifications as $key => $notification)
		{
			echo '<tr><td><i class="status_'.$notification['status'].'"></i></td><td><a href="?m=notifications&p=inbox&read_notification='.$notification['notification_id'].'">'.$notification['subject']."</a></td><td>".$notification['timestamp']."</td></tr>\n";
		}
		echo "</tbody></table>\n";
	}
	elseif(isset($_GET['read_notification']) and is_numeric($_GET['read_notification']))
	{
		foreach ((array)$notifications as $notification)
			if($notification['notification_id'] == $_GET['read_notification'])
				break;
		echo '<div id="notification_message">'.$notification['message']."</div>\n".
			 '<p><a href="?m=notifications&p=inbox&list">&lt;&lt; '.get_lang('back')."</a></p>\n";
		if($notification['status'] == "0")
			set_notification_readed($notification['notification_id']);
	}
	else
	{
		foreach ((array)$notifications as $key => $notification)
			if($notification['status'] == "1")
				unset($notifications[$key]);
		sort($notifications);
		header('Content-Type: application/json');
		echo json_encode($notifications);
	}
}
?>