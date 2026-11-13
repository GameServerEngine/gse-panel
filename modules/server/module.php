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
$module_title = "Remote Agents";
$module_version = "1.1";
$db_version = 3;
$module_required = TRUE;
$module_menus = array(
array( 'subpage' => '', 'name'=>'Remote Agents', 'group'=>'admin' )
);

$install_queries = array();
$install_queries[0] = array(
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."remote_server_ips` (
        `ip_id` int(11) NOT NULL AUTO_INCREMENT,
        `remote_server_id` int(11) NOT NULL,
        `ip` varchar(255) NOT NULL,
        PRIMARY KEY (`ip_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1;",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."remote_servers` (
        `remote_server_id` int(11) NOT NULL auto_increment,
        `remote_server_name` varchar(100) NOT NULL,
        `user` varchar(100) NOT NULL,
        `agent_ip` varchar(255) NOT NULL,
        `agent_port` int(11) NOT NULL,
        `ftp_port` int(11) NOT NULL,
        `encryption_key` varchar(50) NOT NULL,
        `timeout` int(11) NOT NULL,
        `use_nat` int(11) NOT NULL DEFAULT 0,
        `ftp_ip` varchar(255) NOT NULL DEFAULT '',
        `firewall_settings` LONGTEXT NULL,
        `display_public_ip` varchar(255) NOT NULL DEFAULT '',
        PRIMARY KEY  (`remote_server_id`),
        UNIQUE KEY `agent_ip` (`agent_ip`,`agent_port`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Remote servers and IPs';",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."arrange_ports` (
        `range_id` int(11) NOT NULL auto_increment,
        `ip_id` int(11) NOT NULL,
        `home_cfg_id` int(11) NOT NULL,
        `start_port` smallint(11) unsigned NOT NULL,
        `end_port` smallint(11) unsigned NOT NULL,
        `port_increment` smallint(11) unsigned NOT NULL,
        PRIMARY KEY  (`range_id`),
        UNIQUE KEY `ip_id` (`ip_id`,`home_cfg_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Remote servers and IPs';"
);
$install_queries[1] = array(
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."agent_resource_snapshots` (
        `remote_server_id` int(11) NOT NULL,
        `agent_ip` varchar(255) NOT NULL DEFAULT '',
        `agent_port` int(11) NOT NULL DEFAULT 0,
        `os` varchar(255) NOT NULL DEFAULT '',
        `agent_version` varchar(255) NOT NULL DEFAULT '',
        `cpu_percent` decimal(6,2) NULL,
        `ram_percent` decimal(6,2) NULL,
        `disk_percent` decimal(6,2) NULL,
        `load_average` varchar(64) NOT NULL DEFAULT '',
        `raw_payload` LONGTEXT NULL,
        `collected_at` int(11) NOT NULL DEFAULT 0,
        `status` varchar(64) NOT NULL DEFAULT 'unknown',
        `last_error` varchar(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`remote_server_id`),
        KEY `collected_at` (`collected_at`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Latest cached per-agent resource snapshot';"
);
$install_queries[3] = array(
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."port_pools` (
        `pool_id` int(11) NOT NULL AUTO_INCREMENT,
        `pool_key` varchar(64) NOT NULL,
        `name` varchar(128) NOT NULL,
        `purpose` enum('game','overflow','service','manual','legacy') NOT NULL DEFAULT 'game',
        `base_port` int(10) unsigned NOT NULL,
        `range_start` int(10) unsigned NOT NULL,
        `range_end` int(10) unsigned NOT NULL,
        `block_size` int(10) unsigned NOT NULL DEFAULT 10,
        `priority` int(11) NOT NULL DEFAULT 100,
        `enabled` tinyint(1) NOT NULL DEFAULT 1,
        `allow_manual` tinyint(1) NOT NULL DEFAULT 0,
        `notes` text NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`pool_id`),
        UNIQUE KEY `uq_port_pools_key` (`pool_key`),
        KEY `idx_port_pools_enabled_priority` (`enabled`,`priority`),
        KEY `idx_port_pools_range` (`range_start`,`range_end`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Global reusable port pool catalog';",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."port_pool_games` (
        `pool_game_id` int(11) NOT NULL AUTO_INCREMENT,
        `pool_id` int(11) NOT NULL,
        `home_cfg_id` int(11) NOT NULL,
        `role` enum('preferred','compatible','fallback','excluded') NOT NULL DEFAULT 'preferred',
        `priority` int(11) NOT NULL DEFAULT 100,
        `block_size_override` int(10) unsigned NULL DEFAULT NULL,
        `allocation_policy` enum('standard','non_standard','manual_review','service_specific') NOT NULL DEFAULT 'standard',
        `enabled` tinyint(1) NOT NULL DEFAULT 1,
        `notes` text NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`pool_game_id`),
        UNIQUE KEY `uq_pool_game_role` (`home_cfg_id`,`pool_id`,`role`),
        KEY `idx_pool_games_home_role_priority` (`home_cfg_id`,`role`,`priority`),
        KEY `idx_pool_games_pool` (`pool_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Game config to global port pool mappings';",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."game_port_offsets` (
        `offset_id` int(11) NOT NULL AUTO_INCREMENT,
        `home_cfg_id` int(11) NOT NULL,
        `port_role` enum('primary','query','rcon','web','steam','peer','admin','filetransfer','query_ssh','custom') NOT NULL,
        `token_name` varchar(64) NULL DEFAULT NULL,
        `protocol` enum('tcp','udp','tcp_udp','unknown') NOT NULL DEFAULT 'unknown',
        `offset_type` enum('add','subtract','absolute') NOT NULL DEFAULT 'add',
        `offset_value` int(11) NOT NULL DEFAULT 0,
        `required` tinyint(1) NOT NULL DEFAULT 1,
        `fits_standard_block` tinyint(1) NOT NULL DEFAULT 1,
        `source` enum('xml_query_port','xml_reserve_ports','lgsl_conversion','manual','imported') NOT NULL DEFAULT 'manual',
        `notes` text NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`offset_id`),
        UNIQUE KEY `uq_game_port_offset` (`home_cfg_id`,`port_role`,`token_name`),
        KEY `idx_game_port_offsets_home` (`home_cfg_id`),
        KEY `idx_game_port_offsets_fit` (`fits_standard_block`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Normalized per-game port offsets imported from XML or review';",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."static_port_reservations` (
        `reservation_id` int(11) NOT NULL AUTO_INCREMENT,
        `scope` enum('ip','remote_server','global') NOT NULL DEFAULT 'ip',
        `ip_id` int(11) NULL DEFAULT NULL,
        `remote_server_id` int(11) NULL DEFAULT NULL,
        `service_name` varchar(128) NOT NULL,
        `reservation_type` enum('single_port','block','range') NOT NULL DEFAULT 'block',
        `port_start` int(10) unsigned NOT NULL,
        `port_end` int(10) unsigned NOT NULL,
        `protocol` enum('tcp','udp','tcp_udp','unknown') NOT NULL DEFAULT 'tcp_udp',
        `source` enum('admin','migration','detected','policy') NOT NULL DEFAULT 'admin',
        `status` enum('active','disabled','needs-review') NOT NULL DEFAULT 'active',
        `notes` text NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`reservation_id`),
        UNIQUE KEY `uq_static_res_exact` (`scope`,`ip_id`,`remote_server_id`,`service_name`,`port_start`,`port_end`,`protocol`),
        KEY `idx_static_res_ip_range` (`ip_id`,`port_start`,`port_end`,`status`),
        KEY `idx_static_res_remote` (`remote_server_id`,`status`),
        KEY `idx_static_res_service` (`service_name`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Static host and service port reservations';",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."home_port_blocks` (
        `block_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `home_id` int(11) NULL DEFAULT NULL,
        `ip_id` int(11) NOT NULL,
        `pool_id` int(11) NULL DEFAULT NULL,
        `home_cfg_id` int(11) NULL DEFAULT NULL,
        `block_start` int(10) unsigned NOT NULL,
        `block_end` int(10) unsigned NOT NULL,
        `primary_port` int(10) unsigned NOT NULL,
        `block_size` int(10) unsigned NOT NULL,
        `allocation_source` enum('preferred','compatible','fallback','overflow','manual','static_service','legacy_imported','admin_override') NOT NULL,
        `status` enum('active','pending','released','needs-review','conflict') NOT NULL DEFAULT 'active',
        `reservation_id` int(11) NULL DEFAULT NULL,
        `legacy_range_id` int(11) NULL DEFAULT NULL,
        `notes` text NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NULL DEFAULT NULL,
        `released_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`block_id`),
        KEY `idx_home_port_blocks_home` (`home_id`,`status`),
        KEY `idx_home_port_blocks_ip_range` (`ip_id`,`block_start`,`block_end`,`status`),
        KEY `idx_home_port_blocks_pool` (`pool_id`,`status`),
        KEY `idx_home_port_blocks_primary` (`ip_id`,`primary_port`,`status`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Whole port-block allocation ledger';",

    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."port_allocation_events` (
        `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `block_id` bigint(20) NULL DEFAULT NULL,
        `home_id` int(11) NULL DEFAULT NULL,
        `ip_id` int(11) NULL DEFAULT NULL,
        `pool_id` int(11) NULL DEFAULT NULL,
        `event_type` enum('allocated','released','imported','conflict_detected','reservation_added','reservation_removed','manual_override','failed_allocation') NOT NULL,
        `actor_user_id` int(11) NULL DEFAULT NULL,
        `source` varchar(64) NOT NULL DEFAULT 'system',
        `message` varchar(255) NOT NULL DEFAULT '',
        `metadata_json` mediumtext NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`event_id`),
        KEY `idx_port_events_home_time` (`home_id`,`created_at`),
        KEY `idx_port_events_ip_time` (`ip_id`,`created_at`),
        KEY `idx_port_events_type_time` (`event_type`,`created_at`)
    ) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Port allocation audit events';",

    "INSERT INTO `".DB_PREFIX."port_pools`
        (`pool_key`,`name`,`purpose`,`base_port`,`range_start`,`range_end`,`block_size`,`priority`,`enabled`,`allow_manual`,`notes`)
     VALUES
        ('arma-dayz-2302','Arma/DayZ 2302','game',2302,2302,2399,10,10,1,0,'Seeded from docs/plans/global-port-allocation/PORT_POOLS.md'),
        ('valheim-2456','Valheim 2456','game',2456,2456,2525,10,20,1,0,'Seeded from docs/plans/global-port-allocation/PORT_POOLS.md'),
        ('unreal-7777','Unreal-style 7777','game',7777,7777,7866,10,30,1,0,'Non-standard large query offsets still require per-game review.'),
        ('minecraft-25565','Minecraft 25565','game',25565,25565,25654,10,40,1,0,'Seeded from docs/plans/global-port-allocation/PORT_POOLS.md'),
        ('7dtd-26900','7 Days to Die 26900','game',26900,26900,27009,10,50,1,0,'Capped before the Source/SRCDS 27015 pool.'),
        ('source-27015','Source/SRCDS 27015','game',27015,27015,27104,10,60,1,0,'Seeded from docs/plans/global-port-allocation/PORT_POOLS.md'),
        ('rust-28015','Rust 28015','game',28015,28015,28104,10,70,1,0,'Seeded from docs/plans/global-port-allocation/PORT_POOLS.md'),
        ('quake-cod-28960','Quake/Call of Duty 28960','game',28960,28960,29049,10,80,1,0,'Seeded from docs/plans/global-port-allocation/PORT_POOLS.md'),
        ('gse-overflow-32000','GSE controlled overflow 32000','overflow',32000,32000,32999,10,1000,1,0,'Controlled overflow only: 100 ten-port blocks per IP.')
     ON DUPLICATE KEY UPDATE
        `name`=VALUES(`name`),
        `purpose`=VALUES(`purpose`),
        `base_port`=VALUES(`base_port`),
        `range_start`=VALUES(`range_start`),
        `range_end`=VALUES(`range_end`),
        `block_size`=VALUES(`block_size`),
        `priority`=VALUES(`priority`),
        `enabled`=VALUES(`enabled`),
        `allow_manual`=VALUES(`allow_manual`),
        `notes`=VALUES(`notes`),
        `updated_at`=NOW();",

    "INSERT INTO `".DB_PREFIX."settings` (`setting`,`value`)
     VALUES ('port_block_size','10')
     ON DUPLICATE KEY UPDATE `value`=`value`;"
);
?>
