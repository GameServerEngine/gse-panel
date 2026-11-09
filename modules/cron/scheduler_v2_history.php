<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 */

// Scheduler v2 phase P1: read-only run history. No task creation, no execution.
// Admins see every server's runs; customers see only runs for servers they own.
// See docs/plans/scheduler-v2/PLAN.md.

require_once('modules/cron/scheduler_v2_catalog.php');

function exec_module()
{
	global $db;

	$isAdmin = $db->isAdmin($_SESSION['user_id']);
	$prefix = $db->getTablePrefix();

	if ($isAdmin) {
		$homeFilter = '';
	} else {
		$homes = $db->getIpPortsForUser($_SESSION['user_id']);
		$homeIds = array();
		foreach ((array)$homes as $home) {
			$homeIds[] = (int)$home['home_id'];
		}
		if (empty($homeIds)) {
			print_failure(get_lang('cron_no_servers_tied_to_account'));
			return 0;
		}
		$homeFilter = 'AND t.home_id IN (' . implode(',', $homeIds) . ')';
	}

	$limit = 100;
	$query = "SELECT r.run_id, r.task_id, r.trigger, r.status, r.started_at, r.finished_at,
			r.error_code, t.home_id, t.action_key
		FROM `{$prefix}scheduled_task_run` r
		INNER JOIN `{$prefix}scheduled_task` t ON t.task_id = r.task_id
		WHERE 1=1 {$homeFilter}
		ORDER BY r.started_at DESC, r.run_id DESC
		LIMIT {$limit}";

	$rows = $db->resultQuery($query);

	echo '<h2>' . get_lang('scheduler_v2_run_history') . '</h2>';

	if (empty($rows)) {
		echo '<p>' . get_lang('scheduler_v2_no_runs_yet') . '</p>';
		return 0;
	}

	echo '<table class="center">';
	echo '<tr><th>Run</th><th>Server</th><th>Action</th><th>Trigger</th><th>Status</th><th>Started</th><th>Finished</th><th>Error</th></tr>';
	foreach ((array)$rows as $row) {
		$catalogEntry = scheduler_v2_catalog_entry($row['action_key']);
		$actionLabel = $catalogEntry !== null ? htmlspecialchars($row['action_key']) : htmlspecialchars($row['action_key']) . ' (removed from catalog)';
		echo '<tr>';
		echo '<td>' . (int)$row['run_id'] . '</td>';
		echo '<td>' . (int)$row['home_id'] . '</td>';
		echo '<td>' . $actionLabel . '</td>';
		echo '<td>' . htmlspecialchars($row['trigger']) . '</td>';
		echo '<td>' . htmlspecialchars($row['status']) . '</td>';
		echo '<td>' . htmlspecialchars((string)$row['started_at']) . '</td>';
		echo '<td>' . htmlspecialchars((string)$row['finished_at']) . '</td>';
		echo '<td>' . htmlspecialchars((string)$row['error_code']) . '</td>';
		echo '</tr>';
	}
	echo '</table>';

	return 1;
}
?>
