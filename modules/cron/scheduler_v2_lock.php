<?php
/*
 * Scheduler v2 per-server lock and conflict policy (phase P1).
 * See docs/plans/scheduler-v2/PLAN.md "Conflict policy". Only one mutating action
 * may run per server at a time; the Panel holds the lock (not the agent).
 *
 * The decision logic (scheduler_v2_resolve_conflict) is a pure function so it can
 * be unit tested without a database. The DB-backed lock
 * (scheduler_v2_lock / scheduler_v2_unlock) is a thin wrapper around it using the
 * existing $db mysqli connection and scheduled_task_run as the lock record.
 */

if (!defined('SCHEDULER_V2_LOCK_LOADED')) {
	define('SCHEDULER_V2_LOCK_LOADED', true);

	/**
	 * Decides what to do when $newTrigger wants to run on a server that already
	 * has $runningRun in flight (or null if nothing is running).
	 *
	 * $runningRun, when set, is array('is_read_only' => bool, 'started_at' => int unix ts).
	 * $nowTs is the current unix timestamp (passed in so this stays pure).
	 * $queueMinutes bounds how long a 'queue' policy is willing to wait.
	 *
	 * Returns one of:
	 *   array('action' => 'run')                 - no conflict, proceed now
	 *   array('action' => 'skip')                 - record a skipped run, do not run
	 *   array('action' => 'wait')                  - caller should re-check later (still queued)
	 *   array('action' => 'replace')               - cancel the running action, then run
	 *
	 * Per the plan: restart and stop always wait for an in-progress install to
	 * finish or time out, regardless of conflict_policy.
	 */
	function scheduler_v2_resolve_conflict($conflictPolicy, $isMutatingAction, $runningRun, $nowTs, $queueMinutes = 10, $isRestartOrStop = false)
	{
		if ($runningRun === null) {
			return array('action' => 'run');
		}
		if (!$isMutatingAction) {
			// Read-only checks never conflict with anything.
			return array('action' => 'run');
		}

		$waitedSeconds = $nowTs - (int)$runningRun['started_at'];

		if ($isRestartOrStop) {
			// Always wait for an in-progress install to finish or time out; never skip or replace.
			if ($waitedSeconds >= $queueMinutes * 60) {
				return array('action' => 'skip');
			}
			return array('action' => 'wait');
		}

		switch ($conflictPolicy) {
			case 'skip':
				return array('action' => 'skip');
			case 'queue':
				if ($waitedSeconds >= $queueMinutes * 60) {
					return array('action' => 'skip');
				}
				return array('action' => 'wait');
			case 'replace':
				if (!empty($runningRun['is_read_only'])) {
					return array('action' => 'replace');
				}
				// Never cancel a mutating run in place; fall back to skip.
				return array('action' => 'skip');
			default:
				return array('action' => 'skip');
		}
	}

	/** Action keys that only read state; everything else is mutating. */
	function scheduler_v2_is_read_only_action($action_key)
	{
		return in_array($action_key, array('workshop.check_updates', 'content.validate_files'), true);
	}

	function scheduler_v2_is_restart_or_stop($action_key)
	{
		return in_array($action_key, array('server.stop', 'server.restart'), true);
	}

	/**
	 * Attempts to take the per-home lock by checking for an existing run in
	 * ('queued','running') status for the same home. $db is the GSP mysqli wrapper
	 * (table_prefix + realEscapeSingle + a SELECT helper); $homeId is an int.
	 * Returns the same shape as scheduler_v2_resolve_conflict(), after looking up
	 * the current running run (if any) for the home via its task.
	 */
	function scheduler_v2_check_lock($db, $homeId, $conflictPolicy, $actionKey, $nowTs, $queueMinutes = 10)
	{
		$prefix = $db->getTablePrefix();
		$homeId = (int)$homeId;
		$query = "SELECT r.started_at, r.task_id FROM `{$prefix}scheduled_task_run` r
			INNER JOIN `{$prefix}scheduled_task` t ON t.task_id = r.task_id
			WHERE t.home_id = {$homeId} AND r.status IN ('queued','running')
			ORDER BY r.started_at ASC LIMIT 1";
		$rows = $db->resultQuery($query);

		$runningRun = null;
		if (!empty($rows) && isset($rows[0]['started_at'])) {
			$runningRun = array(
				// We do not know here whether that other run was read-only; callers that
				// need that should join in action_key. P1 only needs the lock to exist.
				'is_read_only' => false,
				'started_at' => strtotime($rows[0]['started_at']),
			);
		}

		return scheduler_v2_resolve_conflict(
			$conflictPolicy,
			!scheduler_v2_is_read_only_action($actionKey),
			$runningRun,
			$nowTs,
			$queueMinutes,
			scheduler_v2_is_restart_or_stop($actionKey)
		);
	}
}
