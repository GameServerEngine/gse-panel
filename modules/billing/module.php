<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2017 The GSP Development Team
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
$module_title = "billing";
$module_version = "3.9";
$db_version = 15;
$module_required = TRUE;
// Module description
$module_description = "Billing storefront / provisioning integration.";

$module_menus = array(
    array('subpage' => 'billing', 'name' => 'Billing', 'group' => 'admin'),
);

$install_queries = array();

// -----------------------------------------------------------------------
// db_version 1 — Baseline schema for fresh installs.
// All CREATE TABLE statements use IF NOT EXISTS so they are safe to re-run.
// NOTE: The panel updater runs $install_queries[$i+1] when upgrading a
//       module from db_version $i.  A module installed fresh at db_version 0
//       therefore runs $install_queries[1], not $install_queries[0].
// -----------------------------------------------------------------------
$install_queries[1] = array(
    // Billing Services — available game server packages
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_services` (
        `service_id`       INT(11)          NOT NULL AUTO_INCREMENT,
        `home_cfg_id`      INT(11)          NOT NULL DEFAULT 0,
        `mod_cfg_id`       INT(11)          NOT NULL DEFAULT 0,
        `service_name`     VARCHAR(255)     NOT NULL,
        `description`      VARCHAR(1000)    NOT NULL DEFAULT '',
        `img_url`          VARCHAR(255)     NOT NULL DEFAULT '',
        `remote_server_id` VARCHAR(255)     NOT NULL DEFAULT '',
        `out_of_stock`     VARCHAR(255)     NOT NULL DEFAULT '',
        `slot_max_qty`     INT(11)          NOT NULL DEFAULT 0,
        `slot_min_qty`     INT(11)          NOT NULL DEFAULT 0,
        `price_daily`      FLOAT(15,4)      NOT NULL DEFAULT 0,
        `price_monthly`    FLOAT(15,4)      NOT NULL DEFAULT 0,
        `price_year`       FLOAT(15,4)      NOT NULL DEFAULT 0,
        `ftp`              VARCHAR(255)     NOT NULL DEFAULT '',
        `install_method`   VARCHAR(255)     NOT NULL DEFAULT 'steamcmd',
        `manual_url`       VARCHAR(255)     NOT NULL DEFAULT '',
        `access_rights`    VARCHAR(255)     NOT NULL DEFAULT '',
        `enabled`          INT(11)          NOT NULL DEFAULT 0,
        PRIMARY KEY (`service_id`),
        KEY `enabled`     (`enabled`),
        KEY `mod_cfg_id`  (`mod_cfg_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;",

    // Billing Orders — active game server instances
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_orders` (
        `order_id`               INT(11)      NOT NULL AUTO_INCREMENT,
        `user_id`                INT(11)      NOT NULL,
        `service_id`             INT(11)      NOT NULL,
        `home_name`              VARCHAR(255) NOT NULL,
        `ip`                     VARCHAR(255) NOT NULL DEFAULT '',
        `qty`                    INT(11)      NOT NULL DEFAULT 1,
        `invoice_duration`       VARCHAR(16)  NOT NULL DEFAULT 'month',
        `max_players`            INT(11)      NOT NULL DEFAULT 0,
        `price`                  FLOAT(15,2)  NOT NULL DEFAULT 0,
        `discount_amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `remote_control_password` VARCHAR(255) NULL,
        `ftp_password`           VARCHAR(255) NULL,
        `home_id`                VARCHAR(255) NOT NULL DEFAULT '0',
        `status`                 VARCHAR(16)  NOT NULL DEFAULT 'in-cart',
        `order_date`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `end_date`               DATETIME     NULL,
        `payment_txid`           VARCHAR(255) NULL,
        `paid_ts`                DATETIME     NULL,
        `coupon_id`              INT(11)      NOT NULL DEFAULT 0,
        `paypal_data`            TEXT         NULL,
        PRIMARY KEY (`order_id`),
        KEY `user_id` (`user_id`),
        KEY `status`  (`status`),
        KEY `home_id` (`home_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;",

    // Billing Invoices — created on cart add, paid after payment capture.
    // home_id is 0 until the service is provisioned after payment.
    // billing_status tracks the renewal lifecycle (Active / Invoiced / Expired)
    // independently of payment_status which tracks the payment state.
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_invoices` (
        `invoice_id`             INT(11)          NOT NULL AUTO_INCREMENT,
        `order_id`               INT(11)          NOT NULL DEFAULT 0,
        `user_id`                INT(11)          NOT NULL,
        `service_id`             INT(11)          NOT NULL DEFAULT 0, -- 0 = ad-hoc or admin-created invoice not linked to a catalogue service
        `home_id`                INT(11)          NOT NULL DEFAULT 0,
        `home_name`              VARCHAR(255)     NOT NULL DEFAULT '',
        `ip`                     INT(11)          NOT NULL DEFAULT 0,
        `max_players`            INT(11)          NOT NULL DEFAULT 0,
        `remote_control_password` VARCHAR(255)    NULL,
        `ftp_password`           VARCHAR(255)     NULL,
        `customer_name`          VARCHAR(255)     NOT NULL DEFAULT '',
        `customer_email`         VARCHAR(255)     NOT NULL DEFAULT '',
        `amount`                 FLOAT(15,2)      NOT NULL DEFAULT 0,
        `discount_amount`        DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
        `currency`               VARCHAR(3)       NOT NULL DEFAULT 'USD',
        `status`                 VARCHAR(16)      NOT NULL DEFAULT 'due',
        `billing_status`         VARCHAR(16)      NOT NULL DEFAULT 'due',
        `invoice_date`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `due_date`               DATETIME         NULL,
        `paid_date`              DATETIME         NULL,
        `payment_txid`           VARCHAR(255)     NULL,
        `payment_method`         VARCHAR(50)      NULL,
        `description`            VARCHAR(500)     NOT NULL DEFAULT '',
        `invoice_duration`       VARCHAR(16)      NOT NULL DEFAULT 'month',
        `rate_type`              ENUM('daily','monthly','yearly') NOT NULL DEFAULT 'monthly',
        `rate_per_player`        DECIMAL(15,4)    NOT NULL DEFAULT 0,
        `players`                INT(11)          NOT NULL DEFAULT 0,
        `period_start`           DATETIME         NULL,
        `period_end`             DATETIME         NULL,
        `subtotal`               DECIMAL(15,2)    NOT NULL DEFAULT 0,
        `total_due`              DECIMAL(15,2)    NOT NULL DEFAULT 0,
        `payment_status`         ENUM('unpaid','paid','cancelled','refunded') NOT NULL DEFAULT 'unpaid',
        `qty`                    INT(11)          NOT NULL DEFAULT 1,
        `coupon_id`              INT(11)          NOT NULL DEFAULT 0,
        PRIMARY KEY (`invoice_id`),
        KEY `order_id`      (`order_id`),
        KEY `user_id`       (`user_id`),
        KEY `home_id`       (`home_id`),
        KEY `status`        (`status`),
        KEY `due_date`      (`due_date`),
        KEY `service_id`    (`service_id`),
        KEY `coupon_id`     (`coupon_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;",

    // Billing Transactions — immutable payment audit trail
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_transactions` (
        `transaction_id`          INT(11)      NOT NULL AUTO_INCREMENT,
        `invoice_id`              INT(11)      NOT NULL DEFAULT 0,
        `user_id`                 INT(11)      NOT NULL DEFAULT 0,
        `home_id`                 INT(11)      NOT NULL DEFAULT 0,
        `payment_method`          VARCHAR(50)  NOT NULL DEFAULT 'paypal',
        `transaction_external_id` VARCHAR(255) NOT NULL DEFAULT '',
        `amount`                  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `currency`                VARCHAR(3)   NOT NULL DEFAULT 'USD',
        `status`                  ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
        `raw_response`            MEDIUMTEXT   NULL,
        `created_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`transaction_id`),
        KEY `invoice_id`     (`invoice_id`),
        KEY `user_id`        (`user_id`),
        KEY `home_id`        (`home_id`),
        KEY `status`         (`status`),
        KEY `payment_method` (`payment_method`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // Billing Coupons — discount codes
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_coupons` (
        `coupon_id`         INT(11)                              NOT NULL AUTO_INCREMENT,
        `code`              VARCHAR(50)                          NOT NULL,
        `name`              VARCHAR(255)                         NOT NULL DEFAULT '',
        `description`       TEXT                                 NULL,
        `discount_percent`  DECIMAL(5,2)                         NOT NULL DEFAULT 0.00,
        `usage_type`        ENUM('one_time','permanent')         NOT NULL DEFAULT 'one_time',
        `game_filter_type`  ENUM('all_games','specific_games')   NOT NULL DEFAULT 'all_games',
        `game_filter_list`  TEXT                                 NULL,
        `max_uses`          INT(11)                              NULL,
        `current_uses`      INT(11)                              NOT NULL DEFAULT 0,
        `expires`           DATETIME                             NULL,
        `created_date`      DATETIME                             NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_by`        INT(11)                              NULL,
        `is_active`         TINYINT(1)                           NOT NULL DEFAULT 1,
        PRIMARY KEY (`coupon_id`),
        UNIQUE KEY `idx_code`            (`code`),
        KEY `idx_active_expires`         (`is_active`,`expires`),
        KEY `idx_created_by`             (`created_by`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;",

    // Billing Config — global and per-game-key billing settings used by cron
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_config` (
        `config_id`                 INT(11)                          NOT NULL AUTO_INCREMENT,
        `game_key`                  VARCHAR(100)                     NULL DEFAULT NULL,
        `enabled`                   TINYINT(1)                       NOT NULL DEFAULT 1,
        `grace_days`                INT(11)                          NOT NULL DEFAULT 0,
        `delete_after_expired_days` INT(11)                          NOT NULL DEFAULT 7,
        `rate_type`                 ENUM('daily','monthly','yearly') NOT NULL DEFAULT 'monthly',
        `price_per_player`          DECIMAL(10,4)                    NOT NULL DEFAULT 0.0000,
        PRIMARY KEY (`config_id`),
        KEY `game_key` (`game_key`),
        KEY `enabled`  (`enabled`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;",

    // Legacy mapping table is handled by a later idempotent migration.
    "SELECT 1"
);

// db_version 11 — explicit billing suspension and reminder idempotency.
$install_queries[11] = array(
    function($db) { $r=$db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='DB_PREFIXserver_homes' AND COLUMN_NAME='billing_suspended'"); return ($r && (int)$r[0]['cnt']>0) || (bool)$db->query("ALTER TABLE `DB_PREFIXserver_homes` ADD `billing_suspended` TINYINT(1) NOT NULL DEFAULT 0"); },
    function($db) { $r=$db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='DB_PREFIXserver_homes' AND COLUMN_NAME='billing_reminder_sent_for'"); return ($r && (int)$r[0]['cnt']>0) || (bool)$db->query("ALTER TABLE `DB_PREFIXserver_homes` ADD `billing_reminder_sent_for` DATE NULL"); }
);

// db_version 12 — restore billing admin menu entries for existing installs.
$install_queries[12] = array(
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_config', 'admin', 'Billing Configuration', 0
     FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing'
       AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_config' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_payments', 'admin', 'Billing Payments', 1 FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing' AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_payments' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_orders', 'admin', 'Billing Orders', 2 FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing' AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_orders' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_invoices', 'admin', 'Billing Invoices', 3 FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing' AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_invoices' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_cancellations', 'admin', 'Billing Cancellations', 4 FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing' AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_cancellations' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_coupons', 'admin', 'Billing Coupons', 5 FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing' AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_coupons' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'admin_locations', 'admin', 'Billing Locations', 6 FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing' AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'admin_locations' AND mm.group = 'admin')"
);

// db_version 13 — consolidate the billing administration menu.
$install_queries[13] = array(
    "DELETE mm FROM `".DB_PREFIX."module_menus` mm
     INNER JOIN `".DB_PREFIX."modules` m ON m.id = mm.module_id
     WHERE m.folder = 'billing' AND mm.group = 'admin'",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'billing_setup', 'admin', 'Billing Setup', 0
     FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing'
       AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'billing_setup' AND mm.group = 'admin')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'billing_operations', 'admin', 'Billing Operations', 1
     FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing'
       AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'billing_operations' AND mm.group = 'admin')"
);

// -----------------------------------------------------------------------
// db_version 2 — Safe idempotent column migrations for existing installs.
// Each callable queries INFORMATION_SCHEMA to confirm the column is absent
// before issuing ALTER TABLE, so this migration is safe to re-run.
//
// NOTE: The 'DB_PREFIXtable_name' placeholder inside SQL string
// literals is intentional.  Both $db->query() and $db->resultQuery()
// call str_replace("DB_PREFIX", $this->table_prefix, $query) at
// the PHP level before passing the SQL to MySQL, so the placeholder is
// resolved to the real prefix (e.g. 'DB_PREFIXbilling_orders') even when it
// appears inside a single-quoted string in the SQL text.
// -----------------------------------------------------------------------
$install_queries[2] = array(
    // billing_orders: add discount_amount if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_orders' AND COLUMN_NAME = 'discount_amount'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_orders` ADD `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`");
    },
    // billing_invoices: add home_id if missing (needed by cron-shop.php join)
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'home_id'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `home_id` INT(11) NOT NULL DEFAULT 0 AFTER `service_id`");
    },
    // billing_invoices: add discount_amount if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'discount_amount'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `amount`");
    },
    // billing_invoices: add billing_status (lifecycle: Active/Invoiced/Expired) if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'billing_status'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `billing_status` VARCHAR(16) NOT NULL DEFAULT 'due' AFTER `status`");
    },
    // billing_invoices: add rate_type enum if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'rate_type'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `rate_type` ENUM('daily','monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `invoice_duration`");
    },
    // billing_invoices: add rate_per_player if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'rate_per_player'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `rate_per_player` DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER `rate_type`");
    },
    // billing_invoices: add players if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'players'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `players` INT(11) NOT NULL DEFAULT 0 AFTER `rate_per_player`");
    },
    // billing_invoices: add subtotal if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'subtotal'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `players`");
    },
    // billing_invoices: add total_due if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'total_due'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `total_due` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `subtotal`");
    },
    // billing_invoices: add payment_status enum if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'payment_status'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `payment_status` ENUM('unpaid','paid','cancelled','refunded') NOT NULL DEFAULT 'unpaid' AFTER `currency`");
    },
    // billing_invoices: add coupon_id if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'coupon_id'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `coupon_id` INT(11) NOT NULL DEFAULT 0 AFTER `qty`");
    },
    // Create billing_config table for cron-shop settings if missing
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_config` (
        `config_id`                 INT(11)                          NOT NULL AUTO_INCREMENT,
        `game_key`                  VARCHAR(100)                     NULL DEFAULT NULL,
        `enabled`                   TINYINT(1)                       NOT NULL DEFAULT 1,
        `grace_days`                INT(11)                          NOT NULL DEFAULT 0,
        `delete_after_expired_days` INT(11)                          NOT NULL DEFAULT 7,
        `rate_type`                 ENUM('daily','monthly','yearly') NOT NULL DEFAULT 'monthly',
        `price_per_player`          DECIMAL(10,4)                    NOT NULL DEFAULT 0.0000,
        PRIMARY KEY (`config_id`),
        KEY `game_key` (`game_key`),
        KEY `enabled`  (`enabled`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;",
    // Create billing_coupons table if missing (older installs using panel.sql may not have it)
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_coupons` (
        `coupon_id`         INT(11)                              NOT NULL AUTO_INCREMENT,
        `code`              VARCHAR(50)                          NOT NULL,
        `name`              VARCHAR(255)                         NOT NULL DEFAULT '',
        `description`       TEXT                                 NULL,
        `discount_percent`  DECIMAL(5,2)                         NOT NULL DEFAULT 0.00,
        `usage_type`        ENUM('one_time','permanent')         NOT NULL DEFAULT 'one_time',
        `game_filter_type`  ENUM('all_games','specific_games')   NOT NULL DEFAULT 'all_games',
        `game_filter_list`  TEXT                                 NULL,
        `max_uses`          INT(11)                              NULL,
        `current_uses`      INT(11)                              NOT NULL DEFAULT 0,
        `expires`           DATETIME                             NULL,
        `created_date`      DATETIME                             NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_by`        INT(11)                              NULL,
        `is_active`         TINYINT(1)                           NOT NULL DEFAULT 1,
        PRIMARY KEY (`coupon_id`),
        UNIQUE KEY `idx_code`       (`code`),
        KEY `idx_active_expires`    (`is_active`,`expires`),
        KEY `idx_created_by`        (`created_by`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;"
);

// -----------------------------------------------------------------------
// db_version 3 — Add billing_paypal_webhook_events table for idempotent
// webhook event processing.
// -----------------------------------------------------------------------
$install_queries[3] = array(
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_paypal_webhook_events` (
        `id`                INT(11)       NOT NULL AUTO_INCREMENT,
        `paypal_event_id`   VARCHAR(100)  NOT NULL DEFAULT '',
        `event_type`        VARCHAR(100)  NOT NULL DEFAULT '',
        `resource_id`       VARCHAR(100)  NOT NULL DEFAULT '',
        `order_id`          VARCHAR(100)  NOT NULL DEFAULT '',
        `capture_id`        VARCHAR(100)  NOT NULL DEFAULT '',
        `billing_order_id`  INT(11)       NOT NULL DEFAULT 0,
        `processing_status` VARCHAR(50)   NOT NULL DEFAULT 'received',
        `raw_json`          MEDIUMTEXT    NULL,
        `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `processed_at`      DATETIME      NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uidx_paypal_event_id` (`paypal_event_id`),
        KEY `idx_event_type`          (`event_type`),
        KEY `idx_billing_order_id`    (`billing_order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
);

// -----------------------------------------------------------------------
// db_version 4 — Add billing_paypal_errors table for checkout error logging.
// -----------------------------------------------------------------------
$install_queries[4] = array(
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_paypal_errors` (
        `id`                INT           NOT NULL AUTO_INCREMENT,
        `context`           VARCHAR(64)   NOT NULL DEFAULT '',
        `error_code`        VARCHAR(128)  NOT NULL DEFAULT '',
        `message`           TEXT          NULL,
        `paypal_debug_id`   VARCHAR(128)  NULL,
        `order_id`          VARCHAR(128)  NULL,
        `capture_id`        VARCHAR(128)  NULL,
        `billing_order_id`  INT           NULL,
        `user_id`           INT           NULL,
        `raw_json`          LONGTEXT      NULL,
        `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_context`    (`context`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
);

// -----------------------------------------------------------------------
// db_version 5 — Preserve the unused legacy service/node mapping table by
// renaming it to a *_deprecated_backup table instead of dropping it.
// -----------------------------------------------------------------------
$install_queries[5] = array(
    function($db) {
        $legacy = 'DB_PREFIXbilling_service_remote_servers';
        $backup = 'DB_PREFIXbilling_service_remote_servers_deprecated_backup';
        $legacyCheck = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$legacy}'");
        if (!$legacyCheck || empty($legacyCheck[0]['cnt']) || (int)$legacyCheck[0]['cnt'] === 0) return true;

        $backupCheck = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$backup}'");
        if ($backupCheck && !empty($backupCheck[0]['cnt']) && (int)$backupCheck[0]['cnt'] > 0) return true;

        return (bool)$db->query("RENAME TABLE `{$legacy}` TO `{$backup}`");
    }
);

// -----------------------------------------------------------------------
// db_version 6 — Add server_os column to remote_servers for OS-aware
// game/service selection in the billing storefront.
// Default 'linux' preserves existing behaviour for all current installs.
// -----------------------------------------------------------------------
$install_queries[6] = array(
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXremote_servers' AND COLUMN_NAME = 'server_os'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXremote_servers` ADD `server_os` ENUM('linux','windows','any') NOT NULL DEFAULT 'linux' AFTER `display_public_ip`");
    }
);

// -----------------------------------------------------------------------
// db_version 7 — Ensure period_start and period_end columns exist in
// billing_invoices.  These columns were defined in the baseline CREATE TABLE
// (db_version 1) but no migration was provided for existing installations
// that created the table before those columns were added, causing a fatal
// "Unknown column 'period_start'" error in add_to_cart.php.
// Each callable uses INFORMATION_SCHEMA so it is safe to re-run.
// -----------------------------------------------------------------------
$install_queries[7] = array(
    // billing_invoices: add period_start if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'period_start'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `period_start` DATETIME NULL AFTER `players`");
    },
    // billing_invoices: add period_end if missing
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'period_end'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `period_end` DATETIME NULL AFTER `period_start`");
    },
);

// -----------------------------------------------------------------------
// db_version 8 — Phase 2 lifecycle guards.
// Adds only verified drift needed by centralized billing lifecycle paths.
// -----------------------------------------------------------------------
$install_queries[8] = array(
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_orders' AND COLUMN_NAME = 'paypal_data'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_orders` ADD `paypal_data` TEXT NULL AFTER `coupon_id`");
    },
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = 'home_id'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD INDEX `idx_billing_invoices_home_id` (`home_id`)");
    },
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXserver_homes' AND INDEX_NAME = 'idx_server_homes_billing_cron'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXserver_homes` ADD INDEX `idx_server_homes_billing_cron` (`billing_enabled`, `billing_status`, `next_invoice_date`)");
    },
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXserver_homes' AND INDEX_NAME = 'idx_server_homes_last_invoice_id'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `DB_PREFIXserver_homes` ADD INDEX `idx_server_homes_last_invoice_id` (`last_invoice_id`)");
    },
);

// db_version 9 — cancellation requests, refund policy, and expiry cancellation.
$install_queries[9] = array(
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_cancellation_requests` (
        `request_id` INT(11) NOT NULL AUTO_INCREMENT,
        `home_id` INT(11) NOT NULL, `order_id` INT(11) NOT NULL, `user_id` INT(11) NOT NULL,
        `mode` ENUM('immediate','end_of_term') NOT NULL,
        `status` ENUM('pending','approved','rejected','completed','failed') NOT NULL DEFAULT 'pending',
        `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `decided_at` DATETIME NULL,
        `decided_by` INT(11) NULL, `refund_amount` DECIMAL(10,2) NULL,
        `refund_currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
        `refund_status` ENUM('none','pending','refunded','manual_required','failed') NOT NULL DEFAULT 'none',
        `refund_txid` VARCHAR(255) NULL, `note` TEXT NULL,
        PRIMARY KEY (`request_id`), KEY `idx_cancel_home_status` (`home_id`,`status`), KEY `idx_cancel_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    function($db) { $r=$db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='DB_PREFIXbilling_config' AND COLUMN_NAME='refund_full_days'"); return ($r && (int)$r[0]['cnt']>0) || (bool)$db->query("ALTER TABLE `DB_PREFIXbilling_config` ADD `refund_full_days` INT(11) NOT NULL DEFAULT 30"); },
    function($db) { $r=$db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='DB_PREFIXserver_homes' AND COLUMN_NAME='cancel_at_expiry'"); return ($r && (int)$r[0]['cnt']>0) || (bool)$db->query("ALTER TABLE `DB_PREFIXserver_homes` ADD `cancel_at_expiry` TINYINT(1) NOT NULL DEFAULT 0"); }
);


// -----------------------------------------------------------------------
// db_version 10 - locations (regions and cities), machine location and install
// toggle, per-game minimum resources, placement headroom, and PayPal credential
// columns (previously added by sql/010_paypal_credentials.sql).
if (!function_exists('billing_add_column_if_missing')) {
    function billing_add_column_if_missing($db, $table, $column, $definition)
    {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND COLUMN_NAME = '" . $column . "'");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        return (bool)$db->query("ALTER TABLE `" . $table . "` ADD `" . $column . "` " . $definition);
    }
}
$install_queries[10] = array(
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_locations` (
        `location_id` INT(11) NOT NULL AUTO_INCREMENT,
        `parent_id` INT(11) NULL,
        `kind` ENUM('region','city') NOT NULL,
        `name` VARCHAR(120) NOT NULL,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`location_id`), KEY `idx_loc_parent` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXremote_servers', 'location_id', 'INT(11) NULL'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXremote_servers', 'install_enabled', 'TINYINT(1) NOT NULL DEFAULT 1'); },
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_service_requirements` (
        `home_cfg_id` INT(11) NOT NULL,
        `min_cpu_cores` DECIMAL(6,2) NOT NULL DEFAULT 0,
        `min_ram_mb` INT(11) NOT NULL DEFAULT 0,
        `min_disk_gb` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`home_cfg_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'headroom_cpu_pct', 'TINYINT NOT NULL DEFAULT 70'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'headroom_ram_pct', 'TINYINT NOT NULL DEFAULT 70'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'headroom_disk_pct', 'TINYINT NOT NULL DEFAULT 85'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'paypal_mode', "ENUM('sandbox','live') NOT NULL DEFAULT 'sandbox'"); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'paypal_client_id', 'VARCHAR(255) NULL'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'paypal_client_secret_enc', 'TEXT NULL'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_config', 'paypal_webhook_id', 'VARCHAR(255) NULL'); }
);

// db_version 11 - game catalog on billing_services: one row per game, pricing modes on the game,
// agents allowed per game, and a location tree of any depth (billing_locations.kind is a free label).
$install_queries[11] = array(
    "ALTER TABLE `DB_PREFIXbilling_locations` MODIFY `kind` VARCHAR(32) NOT NULL DEFAULT 'region'",
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_service_agents` (
        `service_id` INT(11) NOT NULL,
        `remote_server_id` INT(11) NOT NULL,
        PRIMARY KEY (`service_id`, `remote_server_id`),
        KEY `idx_bsa_remote` (`remote_server_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    function($db) {
        $rows = $db->resultQuery("SELECT service_id, remote_server_id FROM `DB_PREFIXbilling_services` WHERE remote_server_id <> ''");
        foreach ((array)$rows as $row) {
            foreach (preg_split('/[\s,]+/', trim((string)$row['remote_server_id'])) as $rsid) {
                $rsid = (int)$rsid;
                if ($rsid > 0) {
                    $db->query("INSERT IGNORE INTO `DB_PREFIXbilling_service_agents` (service_id, remote_server_id) VALUES (" . (int)$row['service_id'] . ", " . $rsid . ")");
                }
            }
        }
        return true;
    },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_services', 'pricing_mode', "VARCHAR(16) NOT NULL DEFAULT 'per_slot'"); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_services', 'base_slots', 'INT(11) NOT NULL DEFAULT 0'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_services', 'per_slot_price_monthly', 'FLOAT(15,4) NOT NULL DEFAULT 0'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_services', 'base_price_monthly', 'FLOAT(15,4) NOT NULL DEFAULT 0'); },
    function($db) { return billing_add_column_if_missing($db, 'DB_PREFIXbilling_services', 'extra_price_per_slot_monthly', 'FLOAT(15,4) NOT NULL DEFAULT 0'); },
    "CREATE TABLE IF NOT EXISTS `DB_PREFIXbilling_service_tiers` (
        `service_id` INT(11) NOT NULL,
        `tier_key` VARCHAR(16) NOT NULL,
        `slot_count` INT(11) NOT NULL DEFAULT 0,
        `price_monthly` FLOAT(15,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (`service_id`, `tier_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// db_version 14 - one Billing entry in the Administration menu. All billing admin screens are
// tabs of that page (panel_admin.php). Idempotent: removes the old Billing Setup / Billing Operations
// rows and adds the Billing row only when it is missing.
$install_queries[14] = array(
    "DELETE mm FROM `".DB_PREFIX."module_menus` mm
     INNER JOIN `".DB_PREFIX."modules` m ON m.id = mm.module_id
     WHERE m.folder = 'billing' AND mm.group = 'admin' AND mm.subpage IN ('billing_setup', 'billing_operations')",
    "INSERT INTO `".DB_PREFIX."module_menus` (`module_id`, `subpage`, `group`, `menu_name`, `pos`)
     SELECT m.id, 'billing', 'admin', 'Billing', 0
     FROM `".DB_PREFIX."modules` m
     WHERE m.folder = 'billing'
       AND NOT EXISTS (SELECT 1 FROM `".DB_PREFIX."module_menus` mm WHERE mm.module_id = m.id AND mm.subpage = 'billing' AND mm.group = 'admin')"
);

// db_version 15 - services besides game servers (server setup, custom development, ...).
// Service types are edited by the admin; each order is a service request tied to a support ticket and to
// one invoice per payment stage. Idempotent: tables are created only when missing, the invoice columns
// only when absent, and the starter service types only when the table is empty.
$install_queries[15] = array(
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_service_types` (
        `type_id` INT(11) NOT NULL AUTO_INCREMENT,
        `slug` VARCHAR(40) NOT NULL,
        `name` VARCHAR(80) NOT NULL,
        `kind` VARCHAR(10) NOT NULL DEFAULT 'paid',
        `summary` VARCHAR(255) NOT NULL DEFAULT '',
        `description` TEXT NULL,
        `price_mode` VARCHAR(10) NOT NULL DEFAULT 'quote',
        `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `stages` TEXT NULL,
        `fields` TEXT NULL,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`type_id`),
        UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS `".DB_PREFIX."billing_service_requests` (
        `request_id` INT(11) NOT NULL AUTO_INCREMENT,
        `type_id` INT(11) NOT NULL,
        `user_id` INT(11) NOT NULL,
        `home_id` INT(11) NOT NULL DEFAULT 0,
        `tid` INT(11) NOT NULL DEFAULT 0,
        `details` TEXT NULL,
        `total_price` DECIMAL(10,2) NULL DEFAULT NULL,
        `next_stage` INT(11) NOT NULL DEFAULT 1,
        `status` VARCHAR(20) NOT NULL DEFAULT 'awaiting_quote',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`request_id`),
        KEY `user_id` (`user_id`),
        KEY `tid` (`tid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    function($db) {
        $cols = array(
            'invoice_kind' => "VARCHAR(10) NOT NULL DEFAULT 'game'",
            'request_id'   => "INT(11) NOT NULL DEFAULT 0",
            'stage_no'     => "INT(11) NOT NULL DEFAULT 0",
            'stage_label'  => "VARCHAR(60) NOT NULL DEFAULT ''",
        );
        foreach ($cols as $name => $def) {
            $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'DB_PREFIXbilling_invoices' AND COLUMN_NAME = '".$name."'");
            if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) continue;
            if (!$db->query("ALTER TABLE `DB_PREFIXbilling_invoices` ADD `".$name."` ".$def)) return false;
        }
        return true;
    },
    function($db) {
        $r = $db->resultQuery("SELECT COUNT(*) AS cnt FROM `DB_PREFIXbilling_service_types`");
        if ($r && isset($r[0]['cnt']) && (int)$r[0]['cnt'] > 0) return true;
        $two = json_encode(array(array('label' => 'Deposit to start', 'percent' => 50), array('label' => 'Payment on delivery', 'percent' => 50)));
        $one = json_encode(array(array('label' => 'Payment', 'percent' => 100)));
        $setup = json_encode(array(
            array('label' => 'Game', 'type' => 'text', 'required' => true),
            array('label' => 'Mods or plugins to install', 'type' => 'textarea', 'required' => false),
            array('label' => 'Settings you want (slots, difficulty, rules)', 'type' => 'textarea', 'required' => false),
        ));
        $dev = json_encode(array(
            array('label' => 'Game', 'type' => 'text', 'required' => true),
            array('label' => 'What should it do?', 'type' => 'textarea', 'required' => true),
            array('label' => 'Deadline, if any', 'type' => 'text', 'required' => false),
        ));
        $rows = array(
            array('support', 'Support Request', 'support', 'Help with your server, billing or an account question.', 'fixed', 0, $one, '[]', 1),
            array('server-setup', 'Server Setup', 'paid', 'We install and configure your game, mods and settings for you. Placeholder price: set yours in Administration.', 'fixed', 25, $one, $setup, 2),
            array('custom-development', 'Custom Development', 'paid', 'We build a mod, script or feature for your server. We quote after we read your idea.', 'quote', 0, $two, $dev, 3),
        );
        foreach ($rows as $x) {
            $ok = $db->query("INSERT INTO `DB_PREFIXbilling_service_types` (slug, name, kind, summary, price_mode, price, stages, fields, enabled, sort_order) VALUES ('"
                . $db->real_escape_string($x[0]) . "','" . $db->real_escape_string($x[1]) . "','" . $x[2] . "','" . $db->real_escape_string($x[3]) . "','"
                . $x[4] . "'," . (float)$x[5] . ",'" . $db->real_escape_string($x[6]) . "','" . $db->real_escape_string($x[7]) . "',1," . (int)$x[8] . ")");
            if (!$ok) return false;
        }
        return true;
    }
);

?>
