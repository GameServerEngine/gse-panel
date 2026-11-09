<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 */

// Customer-facing page: shows each owned server's ntfy subscribe link and the
// ntfy app download links. Read-only; does not send anything itself.

require_once('modules/notifications/ntfy_helpers.php');

function exec_module()
{
	global $db, $settings;

	$homes = $db->getIpPortsForUser($_SESSION['user_id']);
	if (!$homes) {
		print_failure(get_lang('cron_no_servers_tied_to_account'));
		return 0;
	}

	$baseUrl = isset($settings['ntfy_base_url']) ? $settings['ntfy_base_url'] : '';
	if (empty($baseUrl)) {
		echo '<p>' . get_lang('notifications_not_configured') . '</p>';
		return 0;
	}
	$baseUrl = rtrim($baseUrl, '/');

	echo '<h2>' . get_lang('notifications_title') . '</h2>';
	echo '<p>' . get_lang('notifications_intro') . '</p>';

	echo '<table class="center">';
	echo '<tr><th>Server</th><th>Subscribe link</th></tr>';
	foreach ((array)$homes as $home) {
		$topic = ntfy_topic_for_home($home['home_id']);
		$subscribeUrl = $baseUrl . '/' . rawurlencode($topic);
		echo '<tr>';
		echo '<td>' . htmlspecialchars($home['ip'] . ':' . $home['port']) . '</td>';
		echo '<td><a href="' . htmlspecialchars($subscribeUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($subscribeUrl) . '</a></td>';
		echo '</tr>';
	}
	echo '</table>';

	echo '<h3>' . get_lang('notifications_get_the_app') . '</h3>';
	echo '<ul>';
	echo '<li><a href="https://play.google.com/store/apps/details?id=io.heckel.ntfy" target="_blank" rel="noopener">Android (Google Play)</a></li>';
	echo '<li><a href="https://f-droid.org/packages/io.heckel.ntfy/" target="_blank" rel="noopener">Android (F-Droid)</a></li>';
	echo '<li><a href="https://apps.apple.com/us/app/ntfy/id1625396347" target="_blank" rel="noopener">iOS (App Store)</a></li>';
	echo '<li><a href="https://docs.ntfy.sh/subscribe/web/" target="_blank" rel="noopener">Browser / web push</a></li>';
	echo '</ul>';

	return 1;
}
?>
