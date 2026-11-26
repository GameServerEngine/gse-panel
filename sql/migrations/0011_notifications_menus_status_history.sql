-- Re-register the merged Notifications module (version 2.0, db_version 1) and its menus,
-- which the earlier circular merge never wrote into module_menus.
UPDATE DB_PREFIXmodules SET version = '2.0', db_version = 1 WHERE folder = 'notifications';
DELETE FROM DB_PREFIXmodule_menus WHERE module_id IN (SELECT id FROM DB_PREFIXmodules WHERE folder = 'notifications');
INSERT INTO DB_PREFIXmodule_menus (module_id, subpage, `group`, menu_name, pos)
  SELECT id, '', 'admin', 'Notifications', 0 FROM DB_PREFIXmodules WHERE folder = 'notifications';
INSERT INTO DB_PREFIXmodule_menus (module_id, subpage, `group`, menu_name, pos)
  SELECT id, 'my_notifications', 'user', 'Notifications', 0 FROM DB_PREFIXmodules WHERE folder = 'notifications';
-- Status history admin page gets its own key so it no longer shows as Panel Settings.
UPDATE DB_PREFIXmodule_menus SET subpage = 'history_settings', menu_name = 'Status history'
  WHERE subpage = 'settings' AND `group` = 'admin'
  AND module_id IN (SELECT id FROM DB_PREFIXmodules WHERE folder = 'status_history');
