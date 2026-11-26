<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2017 The GSP Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 */

// One module for everything notification related: admin broadcasts, the user inbox with the
// dashboard popup, and each customer's push (ntfy) subscription page.
$module_title = "Notifications";
$module_version = "2.0";
$db_version = 1;
$module_required = FALSE;
$module_menus = array(
	array('subpage' => '', 'name' => 'Notifications', 'group' => 'admin'),
	array('subpage' => 'my_notifications', 'name' => 'Notifications', 'group' => 'user'),
);

$install_queries[1] = array(
	"CREATE TABLE IF NOT EXISTS `".DB_PREFIX."notification` (
		`notification_id` int NOT NULL AUTO_INCREMENT,
		`subject` TEXT NOT NULL,
		`message` TEXT NOT NULL,
		`timestamp` TIMESTAMP,
		PRIMARY KEY (`notification_id`)
	);",
	"CREATE TABLE IF NOT EXISTS `".DB_PREFIX."notification_recipients` (
		`user_id` int NOT NULL,
		`notification_id` int NOT NULL,
		`status` tinyint NOT NULL,
		PRIMARY KEY (`user_id`, `notification_id`)
	);"
);
?>
