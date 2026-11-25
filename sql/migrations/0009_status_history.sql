-- Status history: agent resource samples, hourly and daily rollups.
-- sample  = one poll of one server (kept for the rolling raw window, default 6 hours)
-- hour    = one clock hour per server (kept for the hourly window, default 7 days)
-- day     = one local day per server (kept for the daily window, default 365 days)
-- Percentages are a share of the whole machine. Absolute numbers stay in the sample rows only.
-- Additive only. Safe to re-run: every CREATE uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `DB_PREFIXstatus_sample` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `remote_server_id` INT NOT NULL,
  `sampled_at` INT UNSIGNED NOT NULL,
  `is_up` TINYINT NOT NULL DEFAULT 0,
  `status_code` VARCHAR(24) NOT NULL DEFAULT 'down',
  `cores` SMALLINT NULL,
  `cpu_pct` DECIMAL(6,2) NULL,
  `ram_pct` DECIMAL(6,2) NULL,
  `disk_pct` DECIMAL(6,2) NULL,
  `net_in_bytes_s` BIGINT NULL,
  `net_out_bytes_s` BIGINT NULL,
  `load1` DECIMAL(8,2) NULL,
  `uptime_s` BIGINT UNSIGNED NULL,
  `cpu_total_j` BIGINT UNSIGNED NULL,
  `cpu_idle_j` BIGINT UNSIGNED NULL,
  `net_rx_total` BIGINT UNSIGNED NULL,
  `net_tx_total` BIGINT UNSIGNED NULL,
  `mem_total_b` BIGINT UNSIGNED NULL,
  `mem_used_b` BIGINT UNSIGNED NULL,
  `disk_total_b` BIGINT UNSIGNED NULL,
  `disk_used_b` BIGINT UNSIGNED NULL,
  `top_procs` TEXT NULL COMMENT 'JSON: {"cpu":[top 5],"ram":[top 5]} with pid, name, value',
  PRIMARY KEY (`id`),
  KEY `idx_status_sample_server_time` (`remote_server_id`, `sampled_at`),
  KEY `idx_status_sample_time` (`sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `DB_PREFIXstatus_hour` (
  `remote_server_id` INT NOT NULL,
  `bucket_start` INT UNSIGNED NOT NULL,
  `samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `up_samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `cpu_avg` DECIMAL(6,2) NULL,
  `cpu_max` DECIMAL(6,2) NULL,
  `cpu_max_at` INT UNSIGNED NULL,
  `ram_avg` DECIMAL(6,2) NULL,
  `ram_max` DECIMAL(6,2) NULL,
  `ram_max_at` INT UNSIGNED NULL,
  `disk_avg` DECIMAL(6,2) NULL,
  `disk_max` DECIMAL(6,2) NULL,
  `disk_max_at` INT UNSIGNED NULL,
  `net_in_avg` BIGINT NULL,
  `net_in_max` BIGINT NULL,
  `net_in_max_at` INT UNSIGNED NULL,
  `net_out_avg` BIGINT NULL,
  `net_out_max` BIGINT NULL,
  `net_out_max_at` INT UNSIGNED NULL,
  `load_max` DECIMAL(8,2) NULL,
  `load_max_at` INT UNSIGNED NULL,
  `uptime_end` BIGINT UNSIGNED NULL,
  `peak_cpu_procs` TEXT NULL COMMENT 'top processes in the sample with the highest CPU',
  `peak_ram_procs` TEXT NULL COMMENT 'top processes in the sample with the highest RAM',
  PRIMARY KEY (`remote_server_id`, `bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `DB_PREFIXstatus_day` (
  `remote_server_id` INT NOT NULL,
  `bucket_start` INT UNSIGNED NOT NULL,
  `samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `up_samples` INT UNSIGNED NOT NULL DEFAULT 0,
  `cpu_avg` DECIMAL(6,2) NULL,
  `cpu_max` DECIMAL(6,2) NULL,
  `cpu_max_at` INT UNSIGNED NULL,
  `ram_avg` DECIMAL(6,2) NULL,
  `ram_max` DECIMAL(6,2) NULL,
  `ram_max_at` INT UNSIGNED NULL,
  `disk_avg` DECIMAL(6,2) NULL,
  `disk_max` DECIMAL(6,2) NULL,
  `disk_max_at` INT UNSIGNED NULL,
  `net_in_avg` BIGINT NULL,
  `net_in_max` BIGINT NULL,
  `net_in_max_at` INT UNSIGNED NULL,
  `net_out_avg` BIGINT NULL,
  `net_out_max` BIGINT NULL,
  `net_out_max_at` INT UNSIGNED NULL,
  `load_max` DECIMAL(8,2) NULL,
  `load_max_at` INT UNSIGNED NULL,
  `uptime_end` BIGINT UNSIGNED NULL,
  `peak_cpu_procs` TEXT NULL,
  `peak_ram_procs` TEXT NULL,
  PRIMARY KEY (`remote_server_id`, `bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
