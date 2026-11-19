<?php
/*
 * GSE - GameServer Engine
 *
 * Assistant: help from the Claude API inside the panel, for one server at a time.
 *  - Customers open it from a server in Game Monitor (the "AI Assistant" button). It answers about that
 *    server (status, errors in the log, who joined, its MySQL database and FTP login) and offers buttons:
 *    Start / Stop / Restart or a new database / FTP password. The customer presses the button.
 *  - Administrators have an "Assistant" page in the Admin menu with read-only diagnostics for the panel,
 *    MySQL, the game databases, the agents and every server.
 * The API key lives only on the panel server. Agents never see it.
 */

// Module general information
$module_title = "Assistant";
$module_version = "1.0";
$db_version = 1;
$module_required = FALSE;
// Customers do not get a menu entry: the chat is opened from a server in Game Monitor.
$module_menus = array( array( 'subpage' => 'admin', 'name'=>'Assistant', 'group'=>'admin' ) );

// Existing installs get these when the module upgrades from db_version 0.
// Fresh installs also get them from install/gse_panel.sql. Both use IF NOT EXISTS, so running them again is safe.
$install_queries[1] = array(
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."assistant_usage` (  `user_id` int NOT NULL,  `month` char(7) NOT NULL,  `input_tokens` bigint NOT NULL DEFAULT 0,  `output_tokens` bigint NOT NULL DEFAULT 0,  `requests` int NOT NULL DEFAULT 0,  PRIMARY KEY (`user_id`,`month`) ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."assistant_log` (  `id` bigint NOT NULL AUTO_INCREMENT,  `user_id` int NOT NULL,  `created` datetime NOT NULL,  `question` text,  `tools` varchar(255) DEFAULT NULL,  `answer` text,  `input_tokens` int NOT NULL DEFAULT 0,  `output_tokens` int NOT NULL DEFAULT 0,  PRIMARY KEY (`id`),  KEY `user_time` (`user_id`,`created`) ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
);
?>
