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

// Module general information
$module_title = "Utilities";
$module_version = "2.0";
$db_version = 1;
$module_required = TRUE;
$module_menus = array( array( 'subpage' => '', 'name'=>'Utilities', 'group'=>'user' ) );
$module_prereqs = array(
	array( "name" => "PHP BCMath Extension", "type" => "f", "value" => "bcadd" ),
);

// db_version 1 - audit trail, rate limits and concurrency counts for agent diagnostics (includes/diagnostics/limits.php).
$install_queries[1] = array(
"CREATE TABLE IF NOT EXISTS `".DB_PREFIX."diag_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_at` int unsigned NOT NULL,
  `user_id` int NOT NULL DEFAULT 0,
  `remote_server_id` int NOT NULL DEFAULT 0,
  `tool` varchar(32) NOT NULL DEFAULT '',
  `target` varchar(255) NOT NULL DEFAULT '',
  `port` int NOT NULL DEFAULT 0,
  `protocol` varchar(16) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'running',
  `outcome` varchar(40) NOT NULL DEFAULT '',
  `duration_ms` int NOT NULL DEFAULT 0,
  `detail` varchar(255) NOT NULL DEFAULT '',
  `client_ip` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_diag_user_time` (`user_id`,`created_at`),
  KEY `idx_diag_agent_time` (`remote_server_id`,`created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;");
?>

