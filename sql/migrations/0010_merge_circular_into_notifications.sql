-- The circular (broadcast) module is merged into notifications.
-- Tables and the id column take the notifications names; the old module row is removed.
RENAME TABLE `DB_PREFIXcircular` TO `DB_PREFIXnotification`;
RENAME TABLE `DB_PREFIXcircular_recipients` TO `DB_PREFIXnotification_recipients`;
ALTER TABLE `DB_PREFIXnotification` RENAME COLUMN `circular_id` TO `notification_id`;
ALTER TABLE `DB_PREFIXnotification_recipients` RENAME COLUMN `circular_id` TO `notification_id`;
DELETE FROM `DB_PREFIXmodules` WHERE `folder` = 'circular';
