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

// Which roles may use which network tool is set in includes/diagnostics/runner.php (diag_default_tool_access()).

// Games which Sourcemod supports.
// Needs to be exactly what GSP reports as the game_name.
$supportedGames = array(
	'Counter Strike Global Offensive',
	'Counter Strike Source',
	'Day of Defeat Source',
	'Dystopia',
	'Garrys Mod',
	'Half-Life 2: Deathmatch',
	'Hidden: Source',
	'Pirates, Vikings and Knights II',
	'Team Fortress 2',
	'Team Fortress 2 Beta',
	'Left 4 Dead',
	'Left 4 Dead 2'
);

// Games which amx mod supports.
// Needs to be exactly what GSP reports as the game_name.
$amx_supportedGames = array(
	'Counter-Strike',
	'Counter-Strike Condition Zero',
	'Day of Defeat',
	'Death Match Classic',
	'Team Fortress Classic'
);

// Simple array of where admins are stored depending on the admin mod.
// mod.name => file.location
$adminFiles = array(
	'sourcemod'	=>	'addons/sourcemod/configs/admins_simple.ini',
	'amx_mod'	=>	'addons/amxmodx/configs/users.ini',
);

// Flags that sub-users need to add admins to owned parent game-servers.
// This should just be the same as allow_file_management and allow_ftp - as they'll be able to add admins anyway with those flags.
$subuserAdminManagement = 'ft';