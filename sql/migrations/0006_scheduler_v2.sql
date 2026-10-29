-- Scheduler v2 phase P1: typed-action tasks and run history.
-- Default prefix is gsp_. If an installation uses a custom prefix, replace gsp_ before applying.
-- Additive only: the legacy cron system (agent-side cron, modules/cron) is untouched and
-- keeps working. See docs/plans/scheduler-v2/PLAN.md.
-- Safe to re-run: every CREATE uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXscheduled_task` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL COMMENT 'gsp_users.user_id of the owning customer',
  `home_id` int(11) NOT NULL COMMENT 'gsp_server_homes.home_id the task acts on',
  `action_key` varchar(64) NOT NULL COMMENT 'key into the typed action catalog, e.g. server.restart',
  `params_json` text NOT NULL COMMENT 'validated params for action_key, JSON',
  `cron_minute` varchar(32) NOT NULL DEFAULT '*',
  `cron_hour` varchar(32) NOT NULL DEFAULT '*',
  `cron_day_of_month` varchar(32) NOT NULL DEFAULT '*',
  `cron_month` varchar(32) NOT NULL DEFAULT '*',
  `cron_day_of_week` varchar(32) NOT NULL DEFAULT '*',
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `conflict_policy` enum('skip','queue','replace') NOT NULL DEFAULT 'skip',
  `consecutive_failures` int(10) unsigned NOT NULL DEFAULT 0,
  `next_run_at` datetime NULL DEFAULT NULL,
  `last_run_at` datetime NULL DEFAULT NULL,
  `last_status` enum('queued','running','success','failed','skipped','cancelled') NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL COMMENT 'gsp_users.user_id who saved this task',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`task_id`),
  KEY `idx_scheduled_task_owner` (`owner_id`),
  KEY `idx_scheduled_task_home` (`home_id`),
  KEY `idx_scheduled_task_next_run` (`enabled`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `GSP_DB_PREFIXscheduled_task_run` (
  `run_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `trigger` enum('schedule','manual','retry') NOT NULL DEFAULT 'schedule',
  `status` enum('queued','running','success','failed','skipped','cancelled') NOT NULL DEFAULT 'queued',
  `started_at` datetime NULL DEFAULT NULL,
  `finished_at` datetime NULL DEFAULT NULL,
  `result_json` text NULL COMMENT 'redacted result payload, JSON',
  `error_code` varchar(64) NULL DEFAULT NULL,
  `log_ref` varchar(255) NULL DEFAULT NULL COMMENT 'pointer to the detailed log, not the log itself',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`run_id`),
  KEY `idx_scheduled_task_run_task` (`task_id`, `started_at`),
  KEY `idx_scheduled_task_run_status` (`status`),
  CONSTRAINT `fk_scheduled_task_run_task` FOREIGN KEY (`task_id`)
    REFERENCES `GSP_DB_PREFIXscheduled_task` (`task_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
