-- phpMyAdmin SQL Dump
-- version 4.9.5deb2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 05, 2025 at 12:36 PM
-- Server version: 5.7.42-log
-- PHP Version: 7.4.3-4ubuntu2.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `panel`
--

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERban_list`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERban_list` (
  `client_ip` varchar(255) NOT NULL,
  `logging_attempts` int(11) NOT NULL DEFAULT '0',
  `banned_until` varchar(16) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmachines`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmachines` (
  `id` int(11) NOT NULL,
  `machine_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hostname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmachine_samples`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmachine_samples` (
  `id` bigint(20) NOT NULL,
  `machine_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ts` datetime NOT NULL,
  `load1` decimal(6,2) DEFAULT NULL,
  `load5` decimal(6,2) DEFAULT NULL,
  `load15` decimal(6,2) DEFAULT NULL,
  `cpu_pct` decimal(6,2) DEFAULT NULL,
  `mem_used_bytes` bigint(20) DEFAULT NULL,
  `mem_total_bytes` bigint(20) DEFAULT NULL,
  `mem_used_pct` decimal(6,2) DEFAULT NULL,
  `swap_used_bytes` bigint(20) DEFAULT NULL,
  `swap_total_bytes` bigint(20) DEFAULT NULL,
  `disk_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk_total_bytes` bigint(20) DEFAULT NULL,
  `disk_used_bytes` bigint(20) DEFAULT NULL,
  `disk_used_pct` decimal(6,2) DEFAULT NULL,
  `net_iface` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rx_bytes` bigint(20) DEFAULT NULL,
  `tx_bytes` bigint(20) DEFAULT NULL,
  `iface_speed_mbps` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERprocess_samples`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERprocess_samples` (
  `id` bigint(20) NOT NULL,
  `machine_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ts` datetime NOT NULL,
  `server_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `server_path` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pid` int(11) NOT NULL,
  `proc_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cmd` text COLLATE utf8mb4_unicode_ci,
  `cpu_pct` decimal(7,2) DEFAULT NULL,
  `rss_bytes` bigint(20) DEFAULT NULL,
  `vms_bytes` bigint(20) DEFAULT NULL,
  `mem_pct` decimal(6,2) DEFAULT NULL,
  `io_read_bytes` bigint(20) DEFAULT NULL,
  `io_write_bytes` bigint(20) DEFAULT NULL,
  `open_fds` int(11) DEFAULT NULL,
  `listening_ports` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `folder_size_bytes` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERaddons`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERaddons` (
  `addon_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `url` varchar(200) NOT NULL,
  `path` varchar(80) NOT NULL,
  `addon_type` varchar(7) NOT NULL,
  `home_cfg_id` varchar(7) NOT NULL,
  `post_script` longtext NOT NULL,
  `group_id` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERadminexternallinks`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERadminexternallinks` (
  `link_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `url` varchar(200) NOT NULL,
  `user_id` varchar(7) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERadminlte_serverstats`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERadminlte_serverstats` (
  `home_id` int(4) NOT NULL,
  `users_online` int(4) NOT NULL,
  `current_stamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERadminlte_settings`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERadminlte_settings` (
  `id` int(20) NOT NULL,
  `user` int(4) NOT NULL,
  `name` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERapi_tokens`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERapi_tokens` (
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERarrange_ports`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERarrange_ports` (
  `range_id` int(11) NOT NULL,
  `ip_id` int(11) NOT NULL,
  `home_cfg_id` int(11) NOT NULL,
  `start_port` smallint(11) UNSIGNED NOT NULL,
  `end_port` smallint(11) UNSIGNED NOT NULL,
  `port_increment` smallint(11) UNSIGNED NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Remote servers and IPs';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERport_pools`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERport_pools` (
  `pool_id` int(11) NOT NULL,
  `pool_key` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `purpose` enum('game','overflow','service','manual','legacy') NOT NULL DEFAULT 'game',
  `base_port` int(10) UNSIGNED NOT NULL,
  `range_start` int(10) UNSIGNED NOT NULL,
  `range_end` int(10) UNSIGNED NOT NULL,
  `block_size` int(10) UNSIGNED NOT NULL DEFAULT '10',
  `priority` int(11) NOT NULL DEFAULT '100',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `allow_manual` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Global reusable port pool catalog';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERport_pool_games`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERport_pool_games` (
  `pool_game_id` int(11) NOT NULL,
  `pool_id` int(11) NOT NULL,
  `home_cfg_id` int(11) NOT NULL,
  `role` enum('preferred','compatible','fallback','excluded') NOT NULL DEFAULT 'preferred',
  `priority` int(11) NOT NULL DEFAULT '100',
  `block_size_override` int(10) UNSIGNED DEFAULT NULL,
  `allocation_policy` enum('standard','non_standard','manual_review','service_specific') NOT NULL DEFAULT 'standard',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Game config to global port pool mappings';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERgame_port_offsets`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERgame_port_offsets` (
  `offset_id` int(11) NOT NULL,
  `home_cfg_id` int(11) NOT NULL,
  `port_role` enum('primary','query','rcon','web','steam','peer','admin','filetransfer','query_ssh','custom') NOT NULL,
  `token_name` varchar(64) DEFAULT NULL,
  `protocol` enum('tcp','udp','tcp_udp','unknown') NOT NULL DEFAULT 'unknown',
  `offset_type` enum('add','subtract','absolute') NOT NULL DEFAULT 'add',
  `offset_value` int(11) NOT NULL DEFAULT '0',
  `required` tinyint(1) NOT NULL DEFAULT '1',
  `fits_standard_block` tinyint(1) NOT NULL DEFAULT '1',
  `source` enum('xml_query_port','xml_reserve_ports','lgsl_conversion','manual','imported') NOT NULL DEFAULT 'manual',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Normalized per-game port offsets imported from XML or review';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERstatic_port_reservations`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERstatic_port_reservations` (
  `reservation_id` int(11) NOT NULL,
  `scope` enum('ip','remote_server','global') NOT NULL DEFAULT 'ip',
  `ip_id` int(11) DEFAULT NULL,
  `remote_server_id` int(11) DEFAULT NULL,
  `service_name` varchar(128) NOT NULL,
  `reservation_type` enum('single_port','block','range') NOT NULL DEFAULT 'block',
  `port_start` int(10) UNSIGNED NOT NULL,
  `port_end` int(10) UNSIGNED NOT NULL,
  `protocol` enum('tcp','udp','tcp_udp','unknown') NOT NULL DEFAULT 'tcp_udp',
  `source` enum('admin','migration','detected','policy') NOT NULL DEFAULT 'admin',
  `status` enum('active','disabled','needs-review') NOT NULL DEFAULT 'active',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Static host and service port reservations';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERhome_port_blocks`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERhome_port_blocks` (
  `block_id` bigint(20) NOT NULL,
  `home_id` int(11) DEFAULT NULL,
  `ip_id` int(11) NOT NULL,
  `pool_id` int(11) DEFAULT NULL,
  `home_cfg_id` int(11) DEFAULT NULL,
  `block_start` int(10) UNSIGNED NOT NULL,
  `block_end` int(10) UNSIGNED NOT NULL,
  `primary_port` int(10) UNSIGNED NOT NULL,
  `block_size` int(10) UNSIGNED NOT NULL,
  `allocation_source` enum('preferred','compatible','fallback','overflow','manual','static_service','legacy_imported','admin_override') NOT NULL,
  `status` enum('active','pending','released','needs-review','conflict') NOT NULL DEFAULT 'active',
  `reservation_id` int(11) DEFAULT NULL,
  `legacy_range_id` int(11) DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Whole port-block allocation ledger';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERport_allocation_events`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERport_allocation_events` (
  `event_id` bigint(20) NOT NULL,
  `block_id` bigint(20) DEFAULT NULL,
  `home_id` int(11) DEFAULT NULL,
  `ip_id` int(11) DEFAULT NULL,
  `pool_id` int(11) DEFAULT NULL,
  `event_type` enum('allocated','released','imported','conflict_detected','reservation_added','reservation_removed','manual_override','failed_allocation') NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `source` varchar(64) NOT NULL DEFAULT 'system',
  `message` varchar(255) NOT NULL DEFAULT '',
  `metadata_json` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Port allocation audit events';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERbackup_restore`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERbackup_restore` (
  `id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERbilling_coupons`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERbilling_coupons` (
  `coupon_id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `description` text,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `usage_type` enum('one_time','permanent') NOT NULL DEFAULT 'one_time',
  `game_filter_type` enum('all_games','specific_games') NOT NULL DEFAULT 'all_games',
  `game_filter_list` text COMMENT 'JSON array of game keys when game_filter_type=specific_games',
  `max_uses` int(11) DEFAULT NULL COMMENT 'NULL for unlimited uses',
  `current_uses` int(11) NOT NULL DEFAULT '0',
  `expires` datetime DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERbilling_invoices`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERbilling_invoices` (
  `invoice_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL DEFAULT '0',
  `user_id` int(11) NOT NULL,
  `coupon_id` int(11) DEFAULT NULL,
  `service_id` int(11) NOT NULL,
  `home_name` varchar(255) NOT NULL DEFAULT '',
  `ip` int(11) NOT NULL DEFAULT '0',
  `max_players` int(11) NOT NULL DEFAULT '0',
  `remote_control_password` varchar(255) DEFAULT NULL,
  `ftp_password` varchar(255) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL DEFAULT '',
  `customer_email` varchar(255) NOT NULL DEFAULT '',
  `amount` float(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `status` varchar(16) NOT NULL DEFAULT 'due',
  `invoice_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_date` datetime DEFAULT NULL,
  `paid_date` datetime DEFAULT NULL,
  `payment_txid` varchar(255) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `invoice_duration` varchar(16) NOT NULL DEFAULT 'month',
  `qty` int(11) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERbilling_orders`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERbilling_orders` (
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `home_name` varchar(255) NOT NULL,
  `ip` varchar(255) NOT NULL,
  `qty` int(11) NOT NULL,
  `invoice_duration` varchar(16) NOT NULL,
  `max_players` int(11) NOT NULL,
  `price` float(15,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `remote_control_password` varchar(255) DEFAULT NULL,
  `ftp_password` varchar(255) DEFAULT NULL,
  `home_id` varchar(255) NOT NULL DEFAULT '0',
  `status` varchar(16) NOT NULL DEFAULT 'in-cart',
  `order_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `end_date` datetime DEFAULT NULL,
  `payment_txid` varchar(255) DEFAULT NULL,
  `paid_ts` datetime DEFAULT NULL,
  `coupon_id` int(11) NOT NULL DEFAULT '0',
  `paypal_data` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERbilling_services`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERbilling_services` (
  `service_id` int(11) NOT NULL,
  `home_cfg_id` int(11) NOT NULL,
  `mod_cfg_id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `remote_server_id` varchar(255) NOT NULL,
  `out_of_stock` varchar(255) NOT NULL,
  `slot_max_qty` int(11) NOT NULL,
  `slot_min_qty` int(11) NOT NULL,
  `price_daily` float(15,4) NOT NULL,
  `price_monthly` float(15,4) NOT NULL,
  `price_year` float(15,4) NOT NULL,
  `description` varchar(1000) NOT NULL,
  `img_url` varchar(255) NOT NULL,
  `ftp` varchar(255) NOT NULL,
  `install_method` varchar(255) NOT NULL,
  `manual_url` varchar(255) NOT NULL,
  `access_rights` varchar(255) NOT NULL,
  `enabled` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERcircular`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERcircular` (
  `circular_id` int(11) NOT NULL,
  `subject` text NOT NULL,
  `message` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERcircular_recipients`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERcircular_recipients` (
  `user_id` int(11) NOT NULL,
  `circular_id` int(11) NOT NULL,
  `status` tinyint(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERconfig_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERconfig_homes` (
  `home_cfg_id` int(20) NOT NULL,
  `game_key` varchar(64) NOT NULL,
  `game_name` varchar(255) NOT NULL,
  `home_cfg_file` varchar(64) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERconfig_mods`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERconfig_mods` (
  `mod_cfg_id` int(50) NOT NULL,
  `home_cfg_id` varchar(50) NOT NULL,
  `mod_key` varchar(100) NOT NULL COMMENT 'mod short name - used by the game server for startup commands - ex cstrike',
  `mod_name` varchar(255) NOT NULL COMMENT 'Mod value is user defined string - like Counter-Strike',
  `def_precmd` text,
  `def_postcmd` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERgame_mods`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERgame_mods` (
  `mod_id` int(50) NOT NULL,
  `home_id` int(255) NOT NULL,
  `mod_cfg_id` int(11) NOT NULL,
  `max_players` smallint(3) DEFAULT NULL,
  `extra_params` varchar(255) DEFAULT NULL,
  `cpu_affinity` varchar(64) DEFAULT NULL,
  `nice` smallint(3) DEFAULT '0',
  `precmd` text,
  `postcmd` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='utf8mb4_general_ci';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERhome_ip_ports`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERhome_ip_ports` (
  `ip_id` int(11) NOT NULL,
  `port` int(11) NOT NULL,
  `home_id` int(11) NOT NULL,
  `force_mod_id` int(11) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERlgsl`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERlgsl` (
  `id` int(11) NOT NULL,
  `type` varchar(50) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `ip` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `c_port` varchar(5) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `q_port` varchar(5) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `s_port` varchar(5) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
  `zone` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `disabled` tinyint(1) NOT NULL DEFAULT '0',
  `comment` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `cache` text COLLATE utf8_unicode_ci NOT NULL,
  `cache_time` text COLLATE utf8_unicode_ci NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERlogger`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERlogger` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `date` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip` varchar(15) NOT NULL,
  `message` varchar(250) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmaster_server_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmaster_server_homes` (
  `home_id` int(11) NOT NULL,
  `home_cfg_id` int(11) NOT NULL,
  `remote_server_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmodules`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmodules` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL DEFAULT '',
  `folder` varchar(100) NOT NULL DEFAULT '',
  `version` varchar(10) NOT NULL DEFAULT '0',
  `db_version` int(10) NOT NULL DEFAULT '0',
  `enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmodule_access_rights`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmodule_access_rights` (
  `module_id` int(11) NOT NULL COMMENT 'This references to modules.id',
  `flag` char(1) NOT NULL,
  `description` varchar(64) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmodule_menus`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmodule_menus` (
  `module_id` int(11) NOT NULL COMMENT 'This references to modules.id',
  `subpage` varchar(64) NOT NULL DEFAULT '',
  `group` varchar(32) NOT NULL,
  `menu_name` varchar(128) NOT NULL,
  `pos` int(10) UNSIGNED NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmysql_databases`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmysql_databases` (
  `db_id` int(11) NOT NULL,
  `mysql_server_id` int(11) NOT NULL,
  `home_id` int(11) NOT NULL,
  `db_user` varchar(50) NOT NULL,
  `db_passwd` varchar(50) NOT NULL,
  `db_name` varchar(50) NOT NULL,
  `enabled` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERmysql_servers`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERmysql_servers` (
  `mysql_server_id` int(11) NOT NULL,
  `remote_server_id` int(11) NOT NULL,
  `mysql_name` varchar(100) NOT NULL,
  `mysql_ip` varchar(255) NOT NULL,
  `mysql_port` int(11) NOT NULL,
  `mysql_root_passwd` varchar(32) DEFAULT NULL,
  `privilegies_str` longtext
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERnotification`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERnotification` (
  `notification_id` int(11) NOT NULL,
  `subject` text NOT NULL,
  `message` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERnotification_recipients`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERnotification_recipients` (
  `user_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `status` tinyint(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERrcon_presets`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERrcon_presets` (
  `preset_id` int(50) NOT NULL,
  `name` varchar(20) NOT NULL,
  `command` varchar(100) NOT NULL,
  `home_cfg_id` int(50) NOT NULL,
  `mod_cfg_id` int(50) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERremote_servers`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERremote_servers` (
  `remote_server_id` int(11) NOT NULL,
  `remote_server_name` varchar(100) NOT NULL,
  `TABLE_PREFIX_PLACEHOLDERuser` varchar(100) NOT NULL,
  `agent_ip` varchar(255) NOT NULL,
  `agent_port` int(11) NOT NULL,
  `ftp_port` int(11) NOT NULL,
  `encryption_key` varchar(50) NOT NULL,
  `timeout` int(11) NOT NULL,
  `use_nat` int(11) NOT NULL,
  `ftp_ip` varchar(255) NOT NULL,
  `firewall_settings` longtext,
  `display_public_ip` varchar(255) NOT NULL,
  `enabled` int(11) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Remote servers and IPs';

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERremote_server_ips`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERremote_server_ips` (
  `ip_id` int(11) NOT NULL,
  `remote_server_id` int(11) NOT NULL,
  `ip` varchar(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERreseller_accounts`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERreseller_accounts` (
  `account_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `qty` int(11) DEFAULT NULL,
  `invoice_duration` varchar(7) NOT NULL,
  `discount` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `payment_date` varchar(20) NOT NULL DEFAULT '0',
  `cart_id` int(11) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT '0',
  `available_months` int(11) NOT NULL DEFAULT '0',
  `available_slots` int(11) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERreseller_carts`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERreseller_carts` (
  `cart_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `paid` int(11) DEFAULT NULL,
  `tax_amount` varchar(20) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERreseller_discount_codes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERreseller_discount_codes` (
  `discount_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `percentage` int(11) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '0',
  `code` varchar(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERreseller_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERreseller_homes` (
  `home_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `assigned_slots` int(11) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERreseller_services`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERreseller_services` (
  `service_id` int(11) NOT NULL,
  `service_name` varchar(60) NOT NULL,
  `slot_max_qty` int(11) NOT NULL,
  `price_per_month` float(15,4) NOT NULL,
  `price_per_year` float(15,4) NOT NULL,
  `description` varchar(1000) NOT NULL,
  `remote_server_id` int(11) NOT NULL,
  `start_port` int(11) NOT NULL,
  `end_port` int(11) NOT NULL,
  `max_access_rights` varchar(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERserver_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERserver_homes` (
  `home_id` int(50) NOT NULL,
  `remote_server_id` int(11) NOT NULL,
  `user_id_main` int(11) NOT NULL,
  `home_path` varchar(500) DEFAULT NULL,
  `home_cfg_id` int(50) NOT NULL,
  `home_name` varchar(500) DEFAULT NULL,
  `control_password` varchar(128) DEFAULT NULL,
  `ftp_password` varchar(128) DEFAULT NULL,
  `last_param` longtext,
  `ftp_login` varchar(32) DEFAULT NULL,
  `ftp_status` int(11) NOT NULL DEFAULT '0',
  `custom_fields` longtext,
  `server_expiration_date` varchar(21) NOT NULL DEFAULT 'X',
  `home_user_order` int(11) NOT NULL DEFAULT '99999'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERserver_status`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERserver_status` (
  `status_id` int(11) NOT NULL,
  `remote_server_id` int(11) NOT NULL,
  `server_name` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('online','offline','maintenance') DEFAULT 'offline',
  `cpu_usage` decimal(5,2) DEFAULT NULL,
  `memory_usage` decimal(5,2) DEFAULT NULL,
  `disk_usage` decimal(5,2) DEFAULT NULL,
  `uptime` varchar(50) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERsettings`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERsettings` (
  `setting` varchar(63) NOT NULL,
  `value` varchar(1024) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERstatus_cache`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERstatus_cache` (
  `date_timestamp` char(16) NOT NULL,
  `ip_id` char(3) NOT NULL,
  `port` char(6) NOT NULL,
  `server_status_cache` longtext NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERtickets`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERtickets` (
  `tid` int(11) NOT NULL,
  `uid` varchar(32) NOT NULL,
  `user_id` int(11) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `user_ip` varbinary(16) NOT NULL,
  `subject` varchar(64) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated` varchar(22) DEFAULT NULL,
  `status` tinyint(4) NOT NULL,
  `assigned_to` tinyint(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERticket_attachments`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERticket_attachments` (
  `attachment_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `reply_id` int(11) DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `unique_name` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERticket_messages`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERticket_messages` (
  `reply_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_ip` varbinary(16) NOT NULL,
  `message` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `rating` tinyint(4) DEFAULT '0',
  `is_admin` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERticket_settings`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERticket_settings` (
  `id` int(11) NOT NULL,
  `setting_name` varchar(32) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERts3_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERts3_homes` (
  `ts3_id` int(50) NOT NULL,
  `rserver_id` int(50) NOT NULL,
  `ip` varchar(20) NOT NULL,
  `pwd` varchar(40) NOT NULL,
  `vserver_id` int(50) NOT NULL,
  `user_id` int(50) NOT NULL,
  `port` int(11) DEFAULT '10011'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERtshock`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERtshock` (
  `token_id` int(11) NOT NULL,
  `ip` varchar(255) NOT NULL,
  `port` int(11) NOT NULL,
  `token` varchar(64) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERupdate_blacklist`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERupdate_blacklist` (
  `file_path` varchar(1000) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERusers`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERusers` (
  `user_id` int(11) NOT NULL,
  `users_login` varchar(255) NOT NULL,
  `users_passwd` varchar(255) NOT NULL,
  `users_lang` varchar(20) NOT NULL DEFAULT 'English',
  `users_role` varchar(30) NOT NULL DEFAULT 'user',
  `users_group` varchar(100) DEFAULT NULL,
  `users_fname` varchar(255) DEFAULT NULL,
  `users_lname` varchar(255) DEFAULT NULL,
  `users_email` varchar(255) DEFAULT NULL,
  `users_phone` varchar(12) DEFAULT NULL,
  `users_city` varchar(255) DEFAULT NULL,
  `users_province` varchar(255) DEFAULT NULL,
  `users_country` varchar(255) DEFAULT NULL,
  `users_comment` text,
  `users_theme` varchar(255) DEFAULT NULL,
  `user_expires` varchar(30) NOT NULL DEFAULT 'X',
  `users_parent` int(11) DEFAULT NULL,
  `users_page_limit` int(11) DEFAULT '25',
  `user_receives_emails` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERuser_groups`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERuser_groups` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `group_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERuser_group_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_homes` (
  `home_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `access_rights` varchar(63) DEFAULT NULL,
  `user_group_expiration_date` varchar(21) NOT NULL DEFAULT 'X'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERuser_group_info`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_info` (
  `group_id` int(11) NOT NULL,
  `group_name` varchar(255) DEFAULT NULL,
  `main_user_id` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERuser_group_remote_servers`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_remote_servers` (
  `remote_server_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `access_rights` varchar(63) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERuser_homes`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERuser_homes` (
  `home_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `access_rights` varchar(63) DEFAULT NULL,
  `user_expiration_date` varchar(21) NOT NULL DEFAULT 'X'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERuser_role_info`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERuser_role_info` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(100) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERwidgets`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERwidgets` (
  `id` int(11) NOT NULL,
  `column_id` int(11) NOT NULL,
  `sort_no` int(11) NOT NULL,
  `collapsed` tinyint(4) NOT NULL,
  `title` varchar(100) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `TABLE_PREFIX_PLACEHOLDERwidgets_users`
--

CREATE TABLE `TABLE_PREFIX_PLACEHOLDERwidgets_users` (
  `user_id` int(11) NOT NULL,
  `widget_id` int(11) NOT NULL,
  `column_id` int(11) NOT NULL,
  `sort_no` int(11) NOT NULL,
  `collapsed` tinyint(4) NOT NULL,
  `title` varchar(100) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERban_list`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERban_list`
  ADD PRIMARY KEY (`client_ip`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmachines`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmachines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_machine` (`machine_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmachine_samples`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmachine_samples`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_machine_ts` (`machine_id`,`ts`),
  ADD KEY `idx_ts` (`ts`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERprocess_samples`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERprocess_samples`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proc_server` (`machine_id`,`server_name`,`ts`),
  ADD KEY `idx_proc_pid` (`machine_id`,`pid`,`ts`),
  ADD KEY `idx_ts` (`ts`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERaddons`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERaddons`
  ADD PRIMARY KEY (`addon_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERadminexternallinks`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERadminexternallinks`
  ADD PRIMARY KEY (`link_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERadminlte_settings`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERadminlte_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UniqueSetting` (`user`,`name`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERapi_tokens`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERapi_tokens`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERarrange_ports`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERarrange_ports`
  ADD PRIMARY KEY (`range_id`),
  ADD UNIQUE KEY `ip_id` (`ip_id`,`home_cfg_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERport_pools`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERport_pools`
  ADD PRIMARY KEY (`pool_id`),
  ADD UNIQUE KEY `uq_port_pools_key` (`pool_key`),
  ADD KEY `idx_port_pools_enabled_priority` (`enabled`,`priority`),
  ADD KEY `idx_port_pools_range` (`range_start`,`range_end`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERport_pool_games`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERport_pool_games`
  ADD PRIMARY KEY (`pool_game_id`),
  ADD UNIQUE KEY `uq_pool_game_role` (`home_cfg_id`,`pool_id`,`role`),
  ADD KEY `idx_pool_games_home_role_priority` (`home_cfg_id`,`role`,`priority`),
  ADD KEY `idx_pool_games_pool` (`pool_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERgame_port_offsets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERgame_port_offsets`
  ADD PRIMARY KEY (`offset_id`),
  ADD UNIQUE KEY `uq_game_port_offset` (`home_cfg_id`,`port_role`,`token_name`),
  ADD KEY `idx_game_port_offsets_home` (`home_cfg_id`),
  ADD KEY `idx_game_port_offsets_fit` (`fits_standard_block`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERstatic_port_reservations`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERstatic_port_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD UNIQUE KEY `uq_static_res_exact` (`scope`,`ip_id`,`remote_server_id`,`service_name`,`port_start`,`port_end`,`protocol`),
  ADD KEY `idx_static_res_ip_range` (`ip_id`,`port_start`,`port_end`,`status`),
  ADD KEY `idx_static_res_remote` (`remote_server_id`,`status`),
  ADD KEY `idx_static_res_service` (`service_name`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERhome_port_blocks`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERhome_port_blocks`
  ADD PRIMARY KEY (`block_id`),
  ADD KEY `idx_home_port_blocks_home` (`home_id`,`status`),
  ADD KEY `idx_home_port_blocks_ip_range` (`ip_id`,`block_start`,`block_end`,`status`),
  ADD KEY `idx_home_port_blocks_pool` (`pool_id`,`status`),
  ADD KEY `idx_home_port_blocks_primary` (`ip_id`,`primary_port`,`status`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERport_allocation_events`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERport_allocation_events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `idx_port_events_home_time` (`home_id`,`created_at`),
  ADD KEY `idx_port_events_ip_time` (`ip_id`,`created_at`),
  ADD KEY `idx_port_events_type_time` (`event_type`,`created_at`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERbackup_restore`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbackup_restore`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERbilling_coupons`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_coupons`
  ADD PRIMARY KEY (`coupon_id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `idx_code` (`code`),
  ADD KEY `idx_active_expires` (`is_active`,`expires`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERbilling_invoices`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `idx_coupon` (`coupon_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERbilling_orders`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_home_id` (`home_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERbilling_services`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERcircular`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERcircular`
  ADD PRIMARY KEY (`circular_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERcircular_recipients`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERcircular_recipients`
  ADD PRIMARY KEY (`user_id`,`circular_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERconfig_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERconfig_homes`
  ADD PRIMARY KEY (`home_cfg_id`),
  ADD UNIQUE KEY `game_key` (`game_key`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERconfig_mods`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERconfig_mods`
  ADD PRIMARY KEY (`mod_cfg_id`),
  ADD UNIQUE KEY `home_cfg_id` (`home_cfg_id`,`mod_key`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERgame_mods`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERgame_mods`
  ADD PRIMARY KEY (`mod_id`),
  ADD UNIQUE KEY `home_id` (`home_id`,`mod_cfg_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERhome_ip_ports`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERhome_ip_ports`
  ADD PRIMARY KEY (`ip_id`,`port`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERlgsl`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERlgsl`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERlogger`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERlogger`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmaster_server_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmaster_server_homes`
  ADD PRIMARY KEY (`remote_server_id`,`home_cfg_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmodules`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmodules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `folder` (`folder`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmodule_access_rights`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmodule_access_rights`
  ADD UNIQUE KEY `flag` (`flag`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmodule_menus`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmodule_menus`
  ADD PRIMARY KEY (`module_id`,`subpage`,`group`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmysql_databases`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_databases`
  ADD PRIMARY KEY (`db_id`),
  ADD UNIQUE KEY `mysql_server_id` (`mysql_server_id`,`db_name`),
  ADD UNIQUE KEY `mysql_server_id_2` (`mysql_server_id`,`db_user`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERmysql_servers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_servers`
  ADD PRIMARY KEY (`mysql_server_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERnotification`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERnotification`
  ADD PRIMARY KEY (`notification_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERnotification_recipients`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERnotification_recipients`
  ADD PRIMARY KEY (`user_id`,`notification_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERrcon_presets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERrcon_presets`
  ADD PRIMARY KEY (`preset_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERremote_servers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERremote_servers`
  ADD PRIMARY KEY (`remote_server_id`),
  ADD UNIQUE KEY `agent_ip` (`agent_ip`,`agent_port`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERremote_server_ips`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERremote_server_ips`
  ADD PRIMARY KEY (`ip_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERreseller_accounts`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_accounts`
  ADD PRIMARY KEY (`account_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERreseller_carts`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_carts`
  ADD PRIMARY KEY (`cart_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERreseller_discount_codes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_discount_codes`
  ADD PRIMARY KEY (`discount_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERreseller_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_homes`
  ADD PRIMARY KEY (`home_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERreseller_services`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERserver_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERserver_homes`
  ADD PRIMARY KEY (`home_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERserver_status`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERserver_status`
  ADD PRIMARY KEY (`status_id`),
  ADD UNIQUE KEY `unique_server` (`remote_server_id`),
  ADD KEY `idx_remote_server` (`remote_server_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERsettings`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERsettings`
  ADD PRIMARY KEY (`setting`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERtickets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERtickets`
  ADD PRIMARY KEY (`tid`),
  ADD UNIQUE KEY `uid` (`uid`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERticket_attachments`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD UNIQUE KEY `unique_name` (`unique_name`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERticket_messages`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_messages`
  ADD PRIMARY KEY (`reply_id`),
  ADD KEY `TABLE_PREFIX_PLACEHOLDERticket_messages_fk0` (`ticket_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERticket_settings`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_name` (`setting_name`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERts3_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERts3_homes`
  ADD PRIMARY KEY (`ts3_id`),
  ADD UNIQUE KEY `rserver_id` (`rserver_id`,`vserver_id`,`user_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERtshock`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERtshock`
  ADD PRIMARY KEY (`token_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERupdate_blacklist`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERupdate_blacklist`
  ADD UNIQUE KEY `file_path` (`file_path`),
  ADD UNIQUE KEY `file_path_2` (`file_path`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERusers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERusers`
  ADD PRIMARY KEY (`users_login`),
  ADD UNIQUE KEY `id` (`user_id`),
  ADD UNIQUE KEY `email` (`users_email`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERuser_groups`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_groups`
  ADD PRIMARY KEY (`user_id`,`group_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERuser_group_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_homes`
  ADD PRIMARY KEY (`home_id`,`group_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERuser_group_info`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_info`
  ADD PRIMARY KEY (`group_id`),
  ADD UNIQUE KEY `group_name` (`group_name`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERuser_group_remote_servers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_remote_servers`
  ADD PRIMARY KEY (`remote_server_id`,`group_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERuser_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_homes`
  ADD PRIMARY KEY (`user_id`,`home_id`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERuser_role_info`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_role_info`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `TABLE_PREFIX_PLACEHOLDERwidgets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERwidgets`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERmachines`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmachines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERmachine_samples`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmachine_samples`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERprocess_samples`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERprocess_samples`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERaddons`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERaddons`
  MODIFY `addon_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERadminexternallinks`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERadminexternallinks`
  MODIFY `link_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERadminlte_settings`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERadminlte_settings`
  MODIFY `id` int(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERarrange_ports`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERarrange_ports`
  MODIFY `range_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERport_pools`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERport_pools`
  MODIFY `pool_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERport_pool_games`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERport_pool_games`
  MODIFY `pool_game_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERgame_port_offsets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERgame_port_offsets`
  MODIFY `offset_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERstatic_port_reservations`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERstatic_port_reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERhome_port_blocks`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERhome_port_blocks`
  MODIFY `block_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERport_allocation_events`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERport_allocation_events`
  MODIFY `event_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERbackup_restore`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbackup_restore`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERbilling_coupons`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_coupons`
  MODIFY `coupon_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERbilling_invoices`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERbilling_orders`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERbilling_services`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERbilling_services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERcircular`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERcircular`
  MODIFY `circular_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERconfig_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERconfig_homes`
  MODIFY `home_cfg_id` int(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERconfig_mods`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERconfig_mods`
  MODIFY `mod_cfg_id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERgame_mods`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERgame_mods`
  MODIFY `mod_id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERlgsl`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERlgsl`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERlogger`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERlogger`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERmodules`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmodules`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERmysql_databases`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_databases`
  MODIFY `db_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERmysql_servers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_servers`
  MODIFY `mysql_server_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERnotification`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERnotification`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERrcon_presets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERrcon_presets`
  MODIFY `preset_id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERremote_servers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERremote_servers`
  MODIFY `remote_server_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERremote_server_ips`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERremote_server_ips`
  MODIFY `ip_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERreseller_accounts`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_accounts`
  MODIFY `account_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERreseller_carts`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_carts`
  MODIFY `cart_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERreseller_discount_codes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_discount_codes`
  MODIFY `discount_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERreseller_services`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERreseller_services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERserver_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERserver_homes`
  MODIFY `home_id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERserver_status`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERserver_status`
  MODIFY `status_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERtickets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERtickets`
  MODIFY `tid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERticket_attachments`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERticket_messages`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_messages`
  MODIFY `reply_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERticket_settings`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERts3_homes`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERts3_homes`
  MODIFY `ts3_id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERtshock`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERtshock`
  MODIFY `token_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERusers`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERusers`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERuser_group_info`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_group_info`
  MODIFY `group_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERuser_role_info`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERuser_role_info`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TABLE_PREFIX_PLACEHOLDERwidgets`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERwidgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `TABLE_PREFIX_PLACEHOLDERticket_messages`
--
ALTER TABLE `TABLE_PREFIX_PLACEHOLDERticket_messages`
  ADD CONSTRAINT `TABLE_PREFIX_PLACEHOLDERticket_messages_fk0` FOREIGN KEY (`ticket_id`) REFERENCES `TABLE_PREFIX_PLACEHOLDERtickets` (`tid`);
-- Core tables created at runtime by the update runner and addons helpers,
-- plus the customer restic tables. Part of the base schema, not install_queries.
CREATE TABLE IF NOT EXISTS `TABLE_PREFIX_PLACEHOLDERschema_migrations` (
  `migration_id` VARCHAR(191) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `TABLE_PREFIX_PLACEHOLDERserver_content_workshop_catalog` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `workshop_id` VARCHAR(64) NOT NULL,
  `app_id` VARCHAR(32) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NULL,
  `install_count` INT NOT NULL DEFAULT 0,
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_installed` DATETIME NULL,
  `last_updated` DATETIME NULL,
  `published_date` DATETIME NULL,
  `tags` TEXT NULL,
  `game_key` VARCHAR(128) NULL,
  `local_cache_path` VARCHAR(512) NULL,
  `author` VARCHAR(255) NULL,
  `thumbnail_url` VARCHAR(512) NULL,
  UNIQUE KEY `uniq_workshop_app` (`workshop_id`, `app_id`),
  KEY `idx_app_id` (`app_id`),
  KEY `idx_install_count` (`install_count`),
  KEY `idx_last_installed` (`last_installed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TABLE_PREFIX_PLACEHOLDERrestic_homes` (
  `home_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `rest_user` VARCHAR(64) NOT NULL,
  `repo_state` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_after` INT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `TABLE_PREFIX_PLACEHOLDERrestic_snapshots` (
  `snapshot_row_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `home_id` INT UNSIGNED NOT NULL,
  `snapshot_id` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(12) NOT NULL DEFAULT 'customer',
  `comment` VARCHAR(200) NOT NULL DEFAULT '',
  `created_by` VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_restic_snap` (`home_id`, `snapshot_id`),
  KEY `idx_restic_snap_kind` (`home_id`, `kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
