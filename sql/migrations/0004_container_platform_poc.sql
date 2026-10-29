-- Container platform proof-of-concept schema.
-- These tables are intentionally isolated from legacy game-server homes so the
-- customer-to-agent container workflow can be demonstrated without rewriting
-- existing provisioning logic.

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `region_code` varchar(64) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uq_container_locations_region` (`region_code`),
  KEY `idx_container_locations_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_agents` (
  `agent_id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `base_url` varchar(512) NOT NULL,
  `api_token` varchar(512) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `platform` varchar(64) NOT NULL DEFAULT 'linux',
  `version` varchar(64) NOT NULL DEFAULT '',
  `available_cpu` decimal(8,2) NOT NULL DEFAULT 0,
  `available_memory_mb` int(11) NOT NULL DEFAULT 0,
  `available_storage_gb` int(11) NOT NULL DEFAULT 0,
  `available_ip_addresses_json` mediumtext,
  `capabilities_json` mediumtext,
  `current_container_count` int(11) NOT NULL DEFAULT 0,
  `current_application_count` int(11) NOT NULL DEFAULT 0,
  `max_container_count` int(11) NOT NULL DEFAULT 100,
  `last_heartbeat_at` datetime DEFAULT NULL,
  `last_error` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`agent_id`),
  KEY `idx_container_agents_enabled_location` (`enabled`,`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_applications` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL,
  `name` varchar(160) NOT NULL,
  `icon` varchar(512) NOT NULL DEFAULT '',
  `short_description` varchar(512) NOT NULL DEFAULT '',
  `version` varchar(80) NOT NULL DEFAULT '',
  `supported_platform` varchar(64) NOT NULL DEFAULT 'linux',
  `recommended_cpu` decimal(8,2) NOT NULL DEFAULT 1,
  `recommended_memory_mb` int(11) NOT NULL DEFAULT 1024,
  `recommended_storage_gb` int(11) NOT NULL DEFAULT 10,
  `minimum_cpu` decimal(8,2) NOT NULL DEFAULT 1,
  `minimum_memory_mb` int(11) NOT NULL DEFAULT 1024,
  `minimum_storage_gb` int(11) NOT NULL DEFAULT 10,
  `required_ports_json` mediumtext,
  `preferred_ports_json` mediumtext,
  `default_port_block_size` int(11) NOT NULL DEFAULT 0,
  `compatibility_notes` text,
  `container_image` varchar(512) NOT NULL DEFAULT 'alpine:latest',
  `install_interpreter` enum('none','sh','bash','powershell','cmd') NOT NULL DEFAULT 'none',
  `install_script` mediumtext,
  `status` enum('active','disabled','draft') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`application_id`),
  UNIQUE KEY `uq_container_applications_slug` (`slug`),
  KEY `idx_container_applications_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_plans` (
  `plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `cpu` decimal(8,2) NOT NULL,
  `memory_mb` int(11) NOT NULL,
  `storage_gb` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`plan_id`),
  UNIQUE KEY `uq_container_plans_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_port_pools` (
  `port_pool_id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `host_ip` varchar(64) NOT NULL DEFAULT '0.0.0.0',
  `range_start` int(11) NOT NULL,
  `range_end` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`port_pool_id`),
  UNIQUE KEY `uq_container_port_pools_name` (`name`),
  KEY `idx_container_port_pools_range` (`enabled`,`range_start`,`range_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_reserved_ports` (
  `reserved_port_id` int(11) NOT NULL AUTO_INCREMENT,
  `port_pool_id` int(11) DEFAULT NULL,
  `container_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `host_ip` varchar(64) NOT NULL DEFAULT '0.0.0.0',
  `host_port` int(11) NOT NULL,
  `container_port` int(11) NOT NULL,
  `protocol` enum('tcp','udp') NOT NULL DEFAULT 'tcp',
  `status` enum('reserved','active','released') NOT NULL DEFAULT 'reserved',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reserved_port_id`),
  UNIQUE KEY `uq_container_reserved_port` (`host_ip`,`host_port`,`protocol`,`status`),
  KEY `idx_container_reserved_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXcontainer_provisioning_jobs` (
  `job_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `application_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `container_name` varchar(128) NOT NULL,
  `platform` varchar(64) NOT NULL DEFAULT 'linux',
  `cpu` decimal(8,2) NOT NULL,
  `memory_mb` int(11) NOT NULL,
  `storage_gb` int(11) NOT NULL,
  `port_mode` enum('none','automatic','block') NOT NULL DEFAULT 'none',
  `requested_port_block_size` int(11) NOT NULL DEFAULT 0,
  `status` enum('queued','provisioning','installing','running','failed','cancelled','deleted') NOT NULL DEFAULT 'queued',
  `agent_container_id` varchar(160) DEFAULT NULL,
  `last_result_json` mediumtext,
  `last_error` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`job_id`),
  KEY `idx_container_jobs_user_status` (`user_id`,`status`),
  KEY `idx_container_jobs_agent` (`agent_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXmanaged_containers` (
  `container_id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `application_id` int(11) DEFAULT NULL,
  `agent_id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `agent_container_id` varchar(160) NOT NULL,
  `status` enum('created','running','stopped','failed','deleted') NOT NULL DEFAULT 'created',
  `platform` varchar(64) NOT NULL DEFAULT 'linux',
  `cpu` decimal(8,2) NOT NULL,
  `memory_mb` int(11) NOT NULL,
  `storage_gb` int(11) NOT NULL,
  `ports_json` mediumtext,
  `access_profile_json` mediumtext,
  `access_last_set_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`container_id`),
  UNIQUE KEY `uq_managed_containers_agent_runtime` (`agent_id`,`agent_container_id`),
  KEY `idx_managed_containers_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `GSP_DB_PREFIXcontainer_locations` (`name`,`region_code`,`enabled`)
VALUES ('Kansas City','us-central',1);

INSERT IGNORE INTO `GSP_DB_PREFIXcontainer_plans` (`name`,`cpu`,`memory_mb`,`storage_gb`,`enabled`)
VALUES ('POC Small',1,1024,10,1),('POC Medium',2,4096,30,1);

INSERT IGNORE INTO `GSP_DB_PREFIXcontainer_port_pools` (`name`,`host_ip`,`range_start`,`range_end`,`enabled`)
VALUES ('POC Default Ports','0.0.0.0',30000,30100,1);

INSERT IGNORE INTO `GSP_DB_PREFIXcontainer_applications`
(`slug`,`name`,`icon`,`short_description`,`version`,`supported_platform`,`recommended_cpu`,`recommended_memory_mb`,`recommended_storage_gb`,`minimum_cpu`,`minimum_memory_mb`,`minimum_storage_gb`,`required_ports_json`,`preferred_ports_json`,`default_port_block_size`,`compatibility_notes`,`container_image`,`install_interpreter`,`install_script`,`status`,`sort_order`)
VALUES
('empty-container','None / Empty Container','','Blank managed container. Install applications later.','0.1','linux',1,1024,10,1,512,5,'[]','[]',0,'No server content is installed. Ports are optional.','alpine:latest','none',NULL,'active',0),
('minecraft-poc','Minecraft POC','','Java server content proof of concept.','demo','linux',2,4096,20,1,2048,10,'[{\"label\":\"Game\",\"protocol\":\"tcp\",\"container_port\":25565,\"preferred_host_port\":25565}]','[{\"label\":\"Game\",\"protocol\":\"tcp\",\"container_port\":25565,\"preferred_host_port\":25565}]',1,'Requires TCP 25565 or an allocated replacement.','eclipse-temurin:21-jre','sh','mkdir -p /srv/server-content\nprintf ''Minecraft POC installed\n'' > /srv/server-content/install.txt\n','active',10);
