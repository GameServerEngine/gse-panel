-- Values learned for "combo" start parameters (a text box with a dropdown, such as a map or a gamemode).
-- When a game server is RUNNING with a value its game XML does not list, the status poller adds the
-- value here, and every server of that game then offers it in the dropdown next to the XML options.
-- Additive only. Safe to re-run.

CREATE TABLE IF NOT EXISTS `DB_PREFIXgame_param_options` (
  `home_cfg_id` INT NOT NULL,
  `param_key` VARCHAR(64) NOT NULL,
  `value` VARCHAR(128) NOT NULL,
  `learned_at` INT UNSIGNED NOT NULL,
  `home_id` INT NOT NULL DEFAULT 0 COMMENT 'the game server that first ran with the value',
  PRIMARY KEY (`home_cfg_id`, `param_key`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
