-- GSP MySQL module migration: support configurable admin user and longer admin password.

ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_servers`
  ADD COLUMN `mysql_admin_user` varchar(64) NOT NULL DEFAULT 'root' AFTER `mysql_port`;

ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_servers`
  MODIFY COLUMN `mysql_root_passwd` varchar(255) NULL;

-- Optional: set current rows to your preferred admin user (example: remoteuser)
-- UPDATE `TABLE_PREFIX_PLACEHOLDERmysql_servers` SET `mysql_admin_user` = 'remoteuser' WHERE `mysql_admin_user` = 'root' OR `mysql_admin_user` = '';
