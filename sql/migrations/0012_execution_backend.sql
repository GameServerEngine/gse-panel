-- Execution backend per game home: native (screen, the default) or docker.
-- A home without a row here is native. The game home stays authoritative for owner,
-- paths, ports and billing; this table only holds what the container needs on top.
CREATE TABLE IF NOT EXISTS `DB_PREFIXruntime_images` (
  `image_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `reference` varchar(255) NOT NULL COMMENT 'e.g. eclipse-temurin:25-jre',
  `digest` varchar(80) NOT NULL DEFAULT '' COMMENT 'sha256:... pinned digest',
  `platform` enum('linux','windows') NOT NULL DEFAULT 'linux',
  `notes` varchar(255) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`image_id`),
  UNIQUE KEY `uniq_runtime_images_ref` (`reference`,`digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Approved container runtime images';

CREATE TABLE IF NOT EXISTS `DB_PREFIXhome_execution_runtime` (
  `home_id` int(11) NOT NULL,
  `execution_backend` enum('native','docker') NOT NULL DEFAULT 'native',
  `runtime_image_id` int(11) NULL DEFAULT NULL,
  `cpu_cores` decimal(6,2) NOT NULL DEFAULT '2.00',
  `memory_mb` int(10) unsigned NOT NULL DEFAULT '2048',
  `memory_swap_mb` int(10) unsigned NULL DEFAULT NULL COMMENT 'RAM plus swap; NULL = no swap',
  `pids_limit` int(10) unsigned NOT NULL DEFAULT '512',
  `stop_command` varchar(255) NOT NULL DEFAULT '' COMMENT 'console line for a clean stop, e.g. stop',
  `stop_timeout_seconds` smallint(5) unsigned NOT NULL DEFAULT '60',
  `container_name` varchar(64) NOT NULL DEFAULT '',
  `config_hash` varchar(64) NOT NULL DEFAULT '',
  `runtime_state` varchar(32) NOT NULL DEFAULT '',
  `runtime_state_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`home_id`),
  KEY `idx_home_execution_backend` (`execution_backend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-home execution backend (native default)';

INSERT IGNORE INTO `DB_PREFIXruntime_images` (`name`, `reference`, `digest`, `platform`, `notes`)
VALUES ('Java 25 (Minecraft 26.x)', 'eclipse-temurin:25-jre', 'sha256:4ed132f6832e9804b123e89a849a7b4502756ae64a4ee08ebab939d9d5d0a9fa', 'linux', 'Minecraft 26.x needs Java 25'),
       ('Java 21 (Minecraft 1.20.5-1.21.x)', 'eclipse-temurin:21-jre', 'sha256:d77e090ae1f6970c047b443c6f0a6c1358b0426d44432bb5a7753408cd43de0d', 'linux', 'Minecraft 1.20.5 to 1.21.x');
