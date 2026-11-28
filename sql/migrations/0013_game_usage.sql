-- Game usage: CPU, RAM and disk of every game server (and of containers the panel does not manage)
-- from the agents' status_sample reply, with hourly and daily rollups.
-- sample = one poll of one game server or container (kept for the status history raw window)
-- hour   = one clock hour per game server or container (kept for the hourly window)
-- day    = one local day per game server or container (kept for the daily window)
-- A series is one game server (home_id > 0, label '') or one container (home_id 0, label = its name).
-- CPU is stored as cores in use, so machines with different core counts can be compared.
-- Additive only. Safe to re-run: every CREATE uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `DB_PREFIXgame_sample` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `remote_server_id` INT NOT NULL,
  `sampled_at` INT UNSIGNED NOT NULL,
  `home_id` INT NOT NULL DEFAULT 0,
  `label` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'container name; empty for a game server',
  `game_key` VARCHAR(64) NOT NULL DEFAULT '',
  `process` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'main process of a container',
  `state` VARCHAR(16) NOT NULL DEFAULT '',
  `cpu_pct` DECIMAL(7,2) NULL COMMENT 'percent of the whole machine',
  `cpu_cores` DECIMAL(8,3) NULL,
  `ram_b` BIGINT UNSIGNED NULL,
  `disk_b` BIGINT UNSIGNED NULL,
  `disk_at` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_game_sample_time` (`sampled_at`),
  KEY `idx_game_sample_series` (`remote_server_id`, `home_id`, `label`, `sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `DB_PREFIXgame_hour` (
  `remote_server_id` INT NOT NULL,
  `home_id` INT NOT NULL DEFAULT 0,
  `label` VARCHAR(64) NOT NULL DEFAULT '',
  `bucket_start` INT UNSIGNED NOT NULL,
  `game_key` VARCHAR(64) NOT NULL DEFAULT '',
  `process` VARCHAR(64) NOT NULL DEFAULT '',
  `samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `running_samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `cpu_cores_avg` DECIMAL(8,3) NULL,
  `cpu_cores_max` DECIMAL(8,3) NULL,
  `cpu_max_at` INT UNSIGNED NULL,
  `ram_avg_b` BIGINT UNSIGNED NULL,
  `ram_max_b` BIGINT UNSIGNED NULL,
  `ram_max_at` INT UNSIGNED NULL,
  `disk_max_b` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`remote_server_id`, `home_id`, `label`, `bucket_start`),
  KEY `idx_game_hour_time` (`bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `DB_PREFIXgame_day` (
  `remote_server_id` INT NOT NULL,
  `home_id` INT NOT NULL DEFAULT 0,
  `label` VARCHAR(64) NOT NULL DEFAULT '',
  `bucket_start` INT UNSIGNED NOT NULL,
  `game_key` VARCHAR(64) NOT NULL DEFAULT '',
  `process` VARCHAR(64) NOT NULL DEFAULT '',
  `samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `running_samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `cpu_cores_avg` DECIMAL(8,3) NULL,
  `cpu_cores_max` DECIMAL(8,3) NULL,
  `cpu_max_at` INT UNSIGNED NULL,
  `ram_avg_b` BIGINT UNSIGNED NULL,
  `ram_max_b` BIGINT UNSIGNED NULL,
  `ram_max_at` INT UNSIGNED NULL,
  `disk_max_b` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`remote_server_id`, `home_id`, `label`, `bucket_start`),
  KEY `idx_game_day_time` (`bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
