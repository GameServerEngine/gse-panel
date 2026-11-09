-- GSP migration: persist MySQL connection profile snapshot in TABLE_PREFIX_PLACEHOLDERmysql_databases
-- Run this against the panel database (adjust prefix if needed).

ALTER TABLE `TABLE_PREFIX_PLACEHOLDERmysql_databases`
  ADD COLUMN `db_mysql_ip` varchar(255) NULL AFTER `enabled`,
  ADD COLUMN `db_mysql_port` int(11) NULL AFTER `db_mysql_ip`,
  ADD COLUMN `db_admin_user` varchar(64) NULL AFTER `db_mysql_port`,
  ADD COLUMN `db_admin_passwd` varchar(255) NULL AFTER `db_admin_user`;

-- Backfill from TABLE_PREFIX_PLACEHOLDERmysql_servers for existing rows.
UPDATE `TABLE_PREFIX_PLACEHOLDERmysql_databases` d
JOIN `TABLE_PREFIX_PLACEHOLDERmysql_servers` s ON s.mysql_server_id = d.mysql_server_id
SET d.db_mysql_ip = s.mysql_ip,
    d.db_mysql_port = s.mysql_port,
    d.db_admin_user = COALESCE(NULLIF(s.mysql_admin_user, ''), 'root'),
    d.db_admin_passwd = s.mysql_root_passwd
WHERE d.db_mysql_ip IS NULL
   OR d.db_mysql_port IS NULL
   OR d.db_admin_user IS NULL
   OR d.db_admin_passwd IS NULL;
