-- Remove the old gsp_ / ogp_ names from the panel database.
-- Precondition: ogp_version and gsp_version both hold 'Panel-unstable', so one row is deleted.
-- ogp_ban_list is an unused leftover; ban_list is the table in use.
DELETE FROM `settings` WHERE `setting` = 'ogp_version';
UPDATE `settings` SET `setting` = 'panel_version' WHERE `setting` = 'gsp_version';
ALTER TABLE `remote_servers` CHANGE `ogp_user` `user` varchar(100) NOT NULL;
UPDATE `settings` SET `setting` = 'update_backup_before' WHERE `setting` = 'gsp_update_backup_before';
UPDATE `settings` SET `setting` = 'update_backup_path' WHERE `setting` = 'gsp_update_backup_path';
UPDATE `settings` SET `setting` = 'update_backup_retention' WHERE `setting` = 'gsp_update_backup_retention';
UPDATE `settings` SET `setting` = 'update_branch' WHERE `setting` = 'gsp_update_branch';
UPDATE `settings` SET `setting` = 'update_git_path' WHERE `setting` = 'gsp_update_git_path';
UPDATE `settings` SET `setting` = 'update_linux_agent_post_update_command' WHERE `setting` = 'gsp_update_linux_agent_post_update_command';
UPDATE `settings` SET `setting` = 'update_linux_agent_source_path' WHERE `setting` = 'gsp_update_linux_agent_source_path';
UPDATE `settings` SET `setting` = 'update_panel_path' WHERE `setting` = 'gsp_update_panel_path';
UPDATE `settings` SET `setting` = 'update_panel_post_update_command' WHERE `setting` = 'gsp_update_panel_post_update_command';
UPDATE `settings` SET `setting` = 'update_panel_source_path' WHERE `setting` = 'gsp_update_panel_source_path';
UPDATE `settings` SET `setting` = 'update_repo_root' WHERE `setting` = 'gsp_update_repo_root';
UPDATE `settings` SET `setting` = 'update_repo_url' WHERE `setting` = 'gsp_update_repo_url';
UPDATE `settings` SET `setting` = 'update_website_path' WHERE `setting` = 'gsp_update_website_path';
UPDATE `settings` SET `setting` = 'update_website_post_update_command' WHERE `setting` = 'gsp_update_website_post_update_command';
UPDATE `settings` SET `setting` = 'update_website_source_path' WHERE `setting` = 'gsp_update_website_source_path';
UPDATE `settings` SET `setting` = 'update_windows_agent_post_update_command' WHERE `setting` = 'gsp_update_windows_agent_post_update_command';
UPDATE `settings` SET `setting` = 'update_windows_agent_source_path' WHERE `setting` = 'gsp_update_windows_agent_source_path';
DROP TABLE IF EXISTS `ogp_ban_list`;
