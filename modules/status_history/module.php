<?php
/*
 * GSE panel: status history module.
 * Keeps the agent resource samples (CPU, RAM, disk, network and the top processes) for the
 * status table and the per-server pages. The poller (poller.php) fills the tables; this
 * module's settings choose the poll interval and how long each table keeps its rows.
 */

// Module general information
$module_title = "Status history";
$module_version = "1.0";
$db_version = 0;
$module_required = FALSE;
?>
