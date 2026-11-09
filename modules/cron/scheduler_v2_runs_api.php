<?php
/*
 * Scheduler v2 phase P1: read-only run-history JSON API.
 *
 * Deliberately kept separate from api.php: that dispatcher is the
 * token-authenticated surface remote agents call, and is shared by every
 * existing module. This file instead reuses the Panel's own logged-in web
 * session (same auth as any other home.php?m=... page), so it only needs to
 * be reachable through the normal module routing, not through the agent API.
 *
 * Returns JSON: {"runs": [...]}. No mutation, ever.
 */

require_once('modules/cron/scheduler_v2_catalog.php');

function exec_module()
{
	global $db;

	header('Content-Type: application/json');

	if (!isset($_SESSION['user_id'])) {
		http_response_code(401);
		echo json_encode(array('error' => 'not logged in'));
		return 0;
	}

	$isAdmin = $db->isAdmin($_SESSION['user_id']);
	$prefix = $db->getTablePrefix();

	$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
	$limit = 50;

	if ($isAdmin) {
		$homeFilter = '';
	} else {
		$homes = $db->getIpPortsForUser($_SESSION['user_id']);
		$homeIds = array();
		foreach ((array)$homes as $home) {
			$homeIds[] = (int)$home['home_id'];
		}
		if (empty($homeIds)) {
			echo json_encode(array('runs' => array()));
			return 1;
		}
		$homeFilter = 'AND t.home_id IN (' . implode(',', $homeIds) . ')';
	}

	$taskFilter = $taskId > 0 ? "AND r.task_id = {$taskId}" : '';

	$query = "SELECT r.run_id, r.task_id, r.trigger, r.status, r.started_at, r.finished_at,
			r.error_code, t.home_id, t.action_key
		FROM `{$prefix}scheduled_task_run` r
		INNER JOIN `{$prefix}scheduled_task` t ON t.task_id = r.task_id
		WHERE 1=1 {$homeFilter} {$taskFilter}
		ORDER BY r.started_at DESC, r.run_id DESC
		LIMIT {$limit}";

	$rows = (array)$db->resultQuery($query);

	echo json_encode(array('runs' => $rows));
	return 1;
}
?>
