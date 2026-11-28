-- Map pictures server owners upload for their own server, waiting for an admin to apply them to
-- every server of the game, keep them for that server only, or remove them (Administration > Map images).
CREATE TABLE IF NOT EXISTS `DB_PREFIXmap_image_upload` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `home_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `map` VARCHAR(128) NOT NULL,
  `file_name` VARCHAR(140) NOT NULL,
  `uploaded_at` INT UNSIGNED NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending, kept (this server only), applied (all servers)',
  `reviewed_by` INT NULL,
  `reviewed_at` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_map_image_home_map` (`home_id`, `map`),
  KEY `idx_map_image_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO DB_PREFIXmodule_menus (module_id, subpage, `group`, menu_name, pos)
  SELECT mo.id, 'map_images', 'admin', 'Map images', 0 FROM DB_PREFIXmodules mo
  WHERE mo.folder = 'gamemanager'
    AND NOT EXISTS (SELECT 1 FROM (SELECT module_id, subpage FROM DB_PREFIXmodule_menus) mm
                    WHERE mm.module_id = mo.id AND mm.subpage = 'map_images');
