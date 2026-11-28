-- Hardware-neutral game usage (docs/hardware-neutral-game-usage.md).
-- agent_cpu_score: each agent machine's CPU speed, measured inside the machine the agent runs on.
--   cpu_s: 7-Zip "b 1 -mmt1 -md22" total rating MIPS / 10 (one core); cpu_t: the same on all cores.
--   A game's CPU in GRU = cores it uses x cpu_s of the machine it was measured on.
CREATE TABLE IF NOT EXISTS `DB_PREFIXagent_cpu_score` (
  `remote_server_id` INT NOT NULL,
  `cpu_s` DECIMAL(10,2) NOT NULL,
  `cpu_t` DECIMAL(10,2) NOT NULL,
  `cores` SMALLINT NOT NULL,
  `cpu_model` VARCHAR(128) NOT NULL DEFAULT '',
  `method` VARCHAR(128) NOT NULL DEFAULT '',
  `measured_at` DATETIME NOT NULL,
  `approved` TINYINT NOT NULL DEFAULT 0 COMMENT '0 = preliminary, not for automatic placement',
  `notes` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`remote_server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Measured 2026-11-26 (GSE-HARDWARE-RESOURCE-REFERENCE.txt). Single runs; Windows and Linux used different 7-Zip versions.
INSERT IGNORE INTO `DB_PREFIXagent_cpu_score` (`remote_server_id`, `cpu_s`, `cpu_t`, `cores`, `cpu_model`, `method`, `measured_at`, `approved`, `notes`)
SELECT remote_server_id, 564.90, 3901.80, 8, 'AMD Ryzen 7 3800X', '7-Zip 24.09 x64 b 1 -md22, -mmt1 and -mmt8', '2026-11-26 15:18:00', 0, 'game3, VM on node1; after reboot'
  FROM `DB_PREFIXremote_servers` WHERE agent_ip = 'game3.isaserver.net';
INSERT IGNORE INTO `DB_PREFIXagent_cpu_score` (`remote_server_id`, `cpu_s`, `cpu_t`, `cores`, `cpu_model`, `method`, `measured_at`, `approved`, `notes`)
SELECT remote_server_id, 240.00, 1153.00, 6, 'Intel Xeon E5-2699 v4', '7-Zip 23.01 b 1 -md22, -mmt1 and -mmt6', '2026-11-26 15:00:00', 0, 'game5 KVM VPS'
  FROM `DB_PREFIXremote_servers` WHERE agent_ip = 'game5.isaserver.net';
INSERT IGNORE INTO `DB_PREFIXagent_cpu_score` (`remote_server_id`, `cpu_s`, `cpu_t`, `cores`, `cpu_model`, `method`, `measured_at`, `approved`, `notes`)
SELECT remote_server_id, 129.40, 532.50, 6, 'Intel Xeon Gold 6152', '7-Zip 23.01 b 1 -md22, -mmt1 and -mmt6', '2026-11-26 15:00:00', 0, 'game6 KVM VPS'
  FROM `DB_PREFIXremote_servers` WHERE agent_ip = 'game6.isaserver.net';

-- Busiest thread and GRU next to every sample and rollup.
ALTER TABLE `DB_PREFIXgame_sample`
  ADD COLUMN `main_thread_cores` DECIMAL(6,3) NULL,
  ADD COLUMN `cpu_gru` DECIMAL(10,2) NULL,
  ADD COLUMN `main_thread_gru` DECIMAL(10,2) NULL;
ALTER TABLE `DB_PREFIXgame_hour`
  ADD COLUMN `main_thread_cores_max` DECIMAL(6,3) NULL,
  ADD COLUMN `cpu_gru_avg` DECIMAL(10,2) NULL,
  ADD COLUMN `cpu_gru_max` DECIMAL(10,2) NULL,
  ADD COLUMN `main_thread_gru_max` DECIMAL(10,2) NULL;
ALTER TABLE `DB_PREFIXgame_day`
  ADD COLUMN `main_thread_cores_max` DECIMAL(6,3) NULL,
  ADD COLUMN `cpu_gru_avg` DECIMAL(10,2) NULL,
  ADD COLUMN `cpu_gru_max` DECIMAL(10,2) NULL,
  ADD COLUMN `main_thread_gru_max` DECIMAL(10,2) NULL;
