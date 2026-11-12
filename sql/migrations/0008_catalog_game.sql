-- Game details for the sellable catalog: the name, description and picture shown on the storefront.
-- One row per game (DB_PREFIXconfig_homes.home_cfg_id). A game with no row here, or with a blank
-- display_name, is shown under its game config name, so this migration changes nothing by itself.
-- Default prefix is gsp_. If an installation uses a custom prefix, replace gsp_ before applying.
-- Additive only. Safe to re-run: the CREATE uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `DB_PREFIXcatalog_game` (
  `home_cfg_id` int(11) NOT NULL COMMENT 'DB_PREFIXconfig_homes.home_cfg_id',
  `display_name` varchar(255) NOT NULL DEFAULT '',
  `description` varchar(1000) NOT NULL DEFAULT '',
  `img_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'File name in the billing images/games folder',
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`home_cfg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
