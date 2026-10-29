<?php
/*
 * ntfy notification helpers.
 *
 * One ntfy topic per game server ID (gse-<home_id>), plus a single admin topic
 * that receives every event for every server. See
 * docs/plans/scheduler-v2/PLAN.md "Notifications: ntfy (decided)".
 *
 * This file only knows how to publish. It does not decide when to notify;
 * callers (scheduler v2 run events, and later agent events) pick the event
 * type and priority. Kept dependency-free (curl only) so it can be unit
 * tested with a fake transport.
 */

if (!defined('NTFY_HELPERS_LOADED')) {
	define('NTFY_HELPERS_LOADED', true);

	define('NTFY_ADMIN_TOPIC', 'gse-admin');

	/** Per-server topic name. Stable and predictable so customers can subscribe once. */
	function ntfy_topic_for_home($home_id)
	{
		return 'gse-' . (int)$home_id;
	}

	/**
	 * Priority per the plan: expected events are low priority; crashes,
	 * give-ups, and failed tasks are high priority. Unknown event types
	 * default to low so an unrecognized event never pages someone by mistake.
	 */
	function ntfy_priority_for_event($event_type)
	{
		$high = array('crash', 'give_up', 'task_failed', 'agent_unreachable');
		return in_array($event_type, $high, true) ? 'high' : 'low';
	}

	/**
	 * Builds the ntfy publish request (method, url, headers, body) without
	 * sending it, so the HTTP transport can be swapped/mocked in tests.
	 * $baseUrl is the self-hosted ntfy server's base URL (no trailing slash).
	 */
	function ntfy_build_request($baseUrl, $topic, $title, $message, $priority = 'low', $tags = array())
	{
		return array(
			'method' => 'POST',
			'url' => rtrim($baseUrl, '/') . '/' . rawurlencode($topic),
			'headers' => array(
				'Title: ' . $title,
				'Priority: ' . $priority,
				'Tags: ' . implode(',', $tags),
			),
			'body' => $message,
		);
	}

	/**
	 * Sends a request built by ntfy_build_request() using curl. Returns true on
	 * HTTP 2xx, false otherwise (including when curl is unavailable). Never
	 * throws: a notification failure must not break the caller's own flow.
	 */
	function ntfy_send_request($request)
	{
		if (!function_exists('curl_init')) {
			error_log('ntfy_send_request: curl extension not available');
			return false;
		}
		$ch = curl_init($request['url']);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$errored = curl_errno($ch) !== 0;
		curl_close($ch);
		if ($errored) {
			return false;
		}
		return $status >= 200 && $status < 300;
	}

	/**
	 * Publishes one event to a server's own topic, and the same event to the
	 * admin topic. $baseUrl comes from $settings['ntfy_base_url'] (set by an
	 * admin after installing the ntfy container; see scripts/install-ntfy.sh).
	 * Returns array('server' => bool, 'admin' => bool) so callers can log
	 * partial failures without crashing.
	 */
	function ntfy_publish_event($baseUrl, $home_id, $event_type, $title, $message, $tags = array())
	{
		if (empty($baseUrl)) {
			return array('server' => false, 'admin' => false);
		}
		$priority = ntfy_priority_for_event($event_type);

		$serverRequest = ntfy_build_request($baseUrl, ntfy_topic_for_home($home_id), $title, $message, $priority, $tags);
		$adminRequest = ntfy_build_request($baseUrl, NTFY_ADMIN_TOPIC, $title, "[server {$home_id}] " . $message, $priority, $tags);

		return array(
			'server' => ntfy_send_request($serverRequest),
			'admin' => ntfy_send_request($adminRequest),
		);
	}

	/**
	 * Scheduler v2 run-event hook point. Call this when a scheduled task run
	 * finishes. NOT WIRED to any execution path yet: scheduler v2 phase P1 has
	 * no execution path (see docs/plans/scheduler-v2/PLAN.md "Phases"). P2 should
	 * call this from wherever a gsp_scheduled_task_run row transitions to
	 * success/failed/skipped. $status is one of success|failed|skipped.
	 */
	function ntfy_notify_scheduled_task_run($baseUrl, $home_id, $action_key, $status)
	{
		$eventType = $status === 'failed' ? 'task_failed' : 'task_' . $status;
		$title = "Scheduled task: {$action_key}";
		$message = "Server {$home_id}: {$action_key} {$status}.";
		return ntfy_publish_event($baseUrl, $home_id, $eventType, $title, $message, array('scheduler'));
	}
}
