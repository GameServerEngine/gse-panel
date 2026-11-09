-- Remove the gsp_ prefix from the remote_servers user column and the
-- panel update/version settings keys.
ALTER TABLE `remote_servers` CHANGE `gsp_user` `user` varchar(100) NOT NULL;
UPDATE `settings` SET `setting` = 'panel_version' WHERE `setting` = 'gsp_version';
UPDATE `settings` SET `setting` = 'update_action' WHERE `setting` = 'gsp_update_action';
UPDATE `settings` SET `setting` = 'update_backup_before' WHERE `setting` = 'gsp_update_backup_before';
UPDATE `settings` SET `setting` = 'update_backup_path' WHERE `setting` = 'gsp_update_backup_path';
UPDATE `settings` SET `setting` = 'update_branch' WHERE `setting` = 'gsp_update_branch';
UPDATE `settings` SET `setting` = 'update_channel' WHERE `setting` = 'gsp_update_channel';
UPDATE `settings` SET `setting` = 'update_csrf' WHERE `setting` = 'gsp_update_csrf';
UPDATE `settings` SET `setting` = 'update_custom_release_zip_url' WHERE `setting` = 'gsp_update_custom_release_zip_url';
UPDATE `settings` SET `setting` = 'update_log' WHERE `setting` = 'gsp_update_log';
UPDATE `settings` SET `setting` = 'update_panel_' WHERE `setting` = 'gsp_update_panel_';
UPDATE `settings` SET `setting` = 'update_panel_path' WHERE `setting` = 'gsp_update_panel_path';
UPDATE `settings` SET `setting` = 'update_panel_source_path' WHERE `setting` = 'gsp_update_panel_source_path';
UPDATE `settings` SET `setting` = 'update_release_zip_url' WHERE `setting` = 'gsp_update_release_zip_url';
UPDATE `settings` SET `setting` = 'update_repo_root' WHERE `setting` = 'gsp_update_repo_root';
UPDATE `settings` SET `setting` = 'update_repo_url' WHERE `setting` = 'gsp_update_repo_url';
UPDATE `settings` SET `setting` = 'update_restart_form' WHERE `setting` = 'gsp_update_restart_form';
UPDATE `settings` SET `setting` = 'update_settings' WHERE `setting` = 'gsp_update_settings';
UPDATE `settings` SET `setting` = 'update_smoke_' WHERE `setting` = 'gsp_update_smoke_';
UPDATE `settings` SET `setting` = 'update_smoke_assert' WHERE `setting` = 'gsp_update_smoke_assert';
