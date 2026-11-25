<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 * Status history: stores agent resource samples (CPU, RAM, disk, network, uptime and the top
 * processes) and their hourly and daily rollups. Default module; admins can turn reporting off.
 *
 */

// Module general information
$module_title = "Status history";
$module_version = "1.0";
$db_version = 0;
$module_required = FALSE;
$module_menus = array(
    array( 'subpage' => 'detail', 'name' => 'Server status', 'group' => 'user' ),
    array( 'subpage' => 'settings', 'name' => 'Status history settings', 'group' => 'admin' )
);
