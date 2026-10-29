-- Adds the module enable/disable flag used by Module Manager.
-- Default prefix is gsp_. If an installation uses a custom prefix, replace gsp_ before applying.
-- Safe to re-run: guarded with information_schema check since MySQL/MariaDB do not
-- all support "ADD COLUMN IF NOT EXISTS" uniformly across supported versions.

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gsp_modules'
    AND COLUMN_NAME = 'enabled'
);

SET @ddl = IF(@column_exists = 0,
  'ALTER TABLE `GSP_DB_PREFIXmodules` ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `db_version`',
  'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `GSP_DB_PREFIXmodules` SET `enabled` = 1 WHERE `enabled` IS NULL;
