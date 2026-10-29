-- Game catalog and locked order pricing.
-- Default prefix is gsp_. If an installation uses a custom prefix, replace gsp_ before applying.
-- Additive only: billing_services, billing_orders, and billing_invoices keep working during the
-- move to the panel-owned catalog. See docs/plans/game-catalog-pricing/PLAN.md.
-- Safe to re-run: every CREATE uses IF NOT EXISTS.
-- MySQL 5.7 rule: no DEFAULT on TEXT columns (pricing_detail below).

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcatalog_offer` (
  `offer_id` int(11) NOT NULL AUTO_INCREMENT,
  `home_cfg_id` int(11) NOT NULL COMMENT 'GSP_DB_PREFIXconfig_homes.home_cfg_id',
  `remote_server_id` int(11) NOT NULL COMMENT 'GSP_DB_PREFIXremote_servers.remote_server_id (location)',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pricing_mode` enum('per_slot','tiers','base_plus_extra') NOT NULL DEFAULT 'per_slot',
  `period_default` enum('daily','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `currency` varchar(8) NOT NULL DEFAULT 'USD',
  `slot_min_qty` int(11) NOT NULL DEFAULT 1,
  `slot_max_qty` int(11) NOT NULL DEFAULT 100,
  `per_slot_price_daily` float(15,4) NOT NULL DEFAULT 0,
  `per_slot_price_monthly` float(15,4) NOT NULL DEFAULT 0,
  `per_slot_price_year` float(15,4) NOT NULL DEFAULT 0,
  `base_slots` int(11) NOT NULL DEFAULT 0,
  `base_price_daily` float(15,4) NOT NULL DEFAULT 0,
  `base_price_monthly` float(15,4) NOT NULL DEFAULT 0,
  `base_price_year` float(15,4) NOT NULL DEFAULT 0,
  `extra_price_per_slot_daily` float(15,4) NOT NULL DEFAULT 0,
  `extra_price_per_slot_monthly` float(15,4) NOT NULL DEFAULT 0,
  `extra_price_per_slot_year` float(15,4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`offer_id`),
  UNIQUE KEY `uniq_catalog_offer_home_server` (`home_cfg_id`, `remote_server_id`),
  KEY `idx_catalog_offer_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcatalog_tier` (
  `tier_id` int(11) NOT NULL AUTO_INCREMENT,
  `offer_id` int(11) NOT NULL,
  `tier_key` enum('small','medium','large','full') NOT NULL,
  `slot_count` int(11) NOT NULL,
  `price_daily` float(15,4) NOT NULL DEFAULT 0,
  `price_monthly` float(15,4) NOT NULL DEFAULT 0,
  `price_year` float(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tier_id`),
  UNIQUE KEY `uniq_catalog_tier_offer_key` (`offer_id`, `tier_key`),
  CONSTRAINT `fk_catalog_tier_offer` FOREIGN KEY (`offer_id`)
    REFERENCES `GSP_DB_PREFIXcatalog_offer` (`offer_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcatalog_order_price` (
  `lock_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL COMMENT 'GSP_DB_PREFIXbilling_orders.order_id',
  `offer_id` int(11) NOT NULL,
  `pricing_mode` varchar(32) NOT NULL,
  `period` varchar(16) NOT NULL,
  `slots` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` float(15,4) NOT NULL,
  `total_price` float(15,4) NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'USD',
  `pricing_detail` text NULL COMMENT 'JSON snapshot of the catalog pricing params at order time',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`lock_id`),
  UNIQUE KEY `uniq_catalog_order_price_order` (`order_id`),
  KEY `idx_catalog_order_price_offer` (`offer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carries the catalog offer/pricing choice from invoice (cart) time through to
-- payment capture, where BillingLifecycleService::processPaidInvoice() reads it
-- back to populate catalog_order_price with the real order_id. Guarded the same
-- way migration 0005 guards its ADD COLUMN, since not every MySQL/MariaDB version
-- in use here supports "ADD COLUMN IF NOT EXISTS".
SET @catalog_snapshot_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'GSP_DB_PREFIXbilling_invoices'
    AND COLUMN_NAME = 'catalog_snapshot'
);

SET @catalog_snapshot_ddl = IF(@catalog_snapshot_exists = 0,
  'ALTER TABLE `GSP_DB_PREFIXbilling_invoices` ADD COLUMN `catalog_snapshot` TEXT NULL COMMENT ''JSON: offer_id, pricing_mode, slots or tier_key, period'' AFTER `qty`',
  'SELECT 1'
);

PREPARE catalog_snapshot_stmt FROM @catalog_snapshot_ddl;
EXECUTE catalog_snapshot_stmt;
DEALLOCATE PREPARE catalog_snapshot_stmt;
