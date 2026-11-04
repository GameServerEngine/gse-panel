<?php

//- version
//- name
//- menu entries
//- sql installation queries

//* navigation.xml (OPTIONAL):

$module_title = "Backup/Restore";
$module_version = "1.2";
$db_version = 1;
$module_required = false;
$module_menus = array(
    array( 'subpage' => 'admin', 'name' => 'Backup/Restore Admin', 'group' => 'admin' )
);

// Customer restic data. Never DROP these tables: they hold the snapshot
// records that the retention rules depend on. Only CREATE IF NOT EXISTS.
$install_queries = array();
$install_queries[1] = array(
"CREATE TABLE IF NOT EXISTS `".GSP_DB_PREFIX."restic_homes`
(
  home_id INT UNSIGNED NOT NULL PRIMARY KEY,
  rest_user VARCHAR(64) NOT NULL,
  repo_state VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at INT UNSIGNED NOT NULL DEFAULT 0,
  delete_after INT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;",
"CREATE TABLE IF NOT EXISTS `".GSP_DB_PREFIX."restic_snapshots`
(
  snapshot_row_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  home_id INT UNSIGNED NOT NULL,
  snapshot_id VARCHAR(64) NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'customer',
  comment VARCHAR(200) NOT NULL DEFAULT '',
  created_by VARCHAR(120) NOT NULL DEFAULT '',
  created_at INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_restic_snap` (home_id, snapshot_id),
  KEY `idx_restic_snap_kind` (home_id, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;"
);
?>
