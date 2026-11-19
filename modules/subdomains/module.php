<?php
/*
 * GSP - GameServer Panel
 * Free per-server subdomains through the cPanel DNS API.
 * GNU General Public License v2 or later.
 */

// Module general information
$module_title = "Subdomains";
$module_version = "0.1";
$db_version = 0;
$module_required = FALSE;
$module_menus = array( array( 'subpage' => 'admin', 'name'=>'Subdomains', 'group'=>'admin' ) );

$install_queries[0] = array(
    "DROP TABLE IF EXISTS `".DB_PREFIX."subdomains`;",
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."subdomains` (
	`id` int(11) NOT NULL auto_increment,
	`home_id` int(11) NOT NULL default 0,
	`invoice_id` int(11) NOT NULL default 0,
	`user_id` int(11) NOT NULL default 0,
	`name` varchar(63) NOT NULL,
	`domain` varchar(100) NOT NULL,
	`status` varchar(16) NOT NULL default 'reserved',
	`ip` varchar(45) NOT NULL default '',
	`port` int(11) NOT NULL default 0,
	`created` int(11) NOT NULL default 0,
	`updated` int(11) NOT NULL default 0,
	`changed_at` int(11) NOT NULL default 0,
	PRIMARY KEY (`id`),
	UNIQUE KEY `name_domain` (`name`,`domain`),
	KEY `home_id` (`home_id`),
	KEY `invoice_id` (`invoice_id`)
) ENGINE=MyISAM;",
    "DROP TABLE IF EXISTS `".DB_PREFIX."subdomain_settings`;",
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."subdomain_settings` (
	`skey` varchar(50) NOT NULL,
	`svalue` text NOT NULL,
	PRIMARY KEY (`skey`)
) ENGINE=MyISAM;"
);
