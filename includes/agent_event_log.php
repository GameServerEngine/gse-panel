<?php

function gsp_agent_event_allowed_types() {
	return array(
		'server_process_stopped',
		'server_process_started',
		'server_crash_detected',
		'server_unresponsive',
		'automatic_restart_started',
		'automatic_restart_succeeded',
		'automatic_restart_failed',
		'scheduled_restart_started',
		'scheduled_restart_succeeded',
		'scheduled_restart_failed',
		'server_stop_confirmed',
		'server_start_confirmed',
		'server_port_missing',
		'server_port_restored',
		'server_process_found_without_port',
		'server_port_found_without_managed_process',
		'restart_attempt_limit_reached',
		'agent_event_delivery_failed',
		'agent_event_queue_replayed'
	);
}

function gsp_agent_event_allowed_severities() {
	return array('info', 'notice', 'warning', 'error', 'critical', 'success');
}

function gsp_agent_event_header($name) {
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return isset($_SERVER[$key]) ? $_SERVER[$key] : '';
}

function gsp_agent_event_json_response($status, $payload) {
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=UTF-8', true, $status);
	}
	echo json_encode($payload);
	exit;
}

function gsp_agent_event_fail($status, $code, $message) {
	error_log("GSP agent event rejected: $code $message");
	gsp_agent_event_json_response($status, array('ok' => false, 'error' => $code));
}

function gsp_agent_event_text($value, $max = 255) {
	$value = is_scalar($value) ? (string)$value : '';
	$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
	return substr($value, 0, $max);
}

function gsp_agent_event_validate($event) {
	$errors = array();
	if (!is_array($event)) {
		return array('payload must be an object');
	}
	if (empty($event['event_uuid']) || !preg_match('/^[A-Za-z0-9._:-]{16,64}$/', (string)$event['event_uuid'])) {
		$errors[] = 'invalid event_uuid';
	}
	if (empty($event['timestamp_utc'])) {
		$errors[] = 'missing timestamp_utc';
	}
	if (empty($event['event_type']) || !in_array($event['event_type'], gsp_agent_event_allowed_types(), true)) {
		$errors[] = 'invalid event_type';
	}
	if (empty($event['severity']) || !in_array($event['severity'], gsp_agent_event_allowed_severities(), true)) {
		$errors[] = 'invalid severity';
	}
	if (isset($event['remote_server_id']) && $event['remote_server_id'] !== '' && !is_numeric($event['remote_server_id'])) {
		$errors[] = 'invalid remote_server_id';
	}
	if (isset($event['home_id']) && $event['home_id'] !== '' && !is_numeric($event['home_id'])) {
		$errors[] = 'invalid home_id';
	}
	return $errors;
}

function gsp_agent_event_authenticate($db, $raw_body) {
	$remote_server_id = gsp_agent_event_header('X-GSP-Agent-Id');
	$timestamp = gsp_agent_event_header('X-GSP-Agent-Timestamp');
	$signature = gsp_agent_event_header('X-GSP-Agent-Signature');
	if (!is_numeric($remote_server_id) || !preg_match('/^\d{10}$/', $timestamp)) {
		gsp_agent_event_fail(401, 'auth_headers_missing', 'Missing or invalid agent authentication headers.');
	}
	if (abs(time() - (int)$timestamp) > 300) {
		gsp_agent_event_fail(401, 'stale_timestamp', 'Agent event timestamp outside allowed request window.');
	}
	$remote = $db->getRemoteServer((int)$remote_server_id);
	if (!$remote || empty($remote['encryption_key'])) {
		gsp_agent_event_fail(401, 'unknown_agent', 'Unknown remote server.');
	}
	$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw_body, $remote['encryption_key']);
	if (!hash_equals($expected, $signature)) {
		gsp_agent_event_fail(401, 'bad_signature', 'Agent signature verification failed.');
	}
	return $remote;
}

function gsp_agent_event_home_belongs_to_remote($db, $home_id, $remote_server_id) {
	if (!is_numeric($home_id) || (int)$home_id <= 0) {
		return true;
	}
	$home = $db->getGameHome((int)$home_id);
	if (!$home) {
		return false;
	}
	return (int)$home['remote_server_id'] === (int)$remote_server_id;
}

function gsp_agent_event_format_message($event) {
	$name = gsp_agent_event_text($event['game_server_name'] ?? $event['server_name'] ?? $event['game_name'] ?? 'server', 120);
	$home_id = isset($event['home_id']) && $event['home_id'] !== '' ? (int)$event['home_id'] : 0;
	$port = gsp_agent_event_text($event['game_port'] ?? $event['port'] ?? '', 16);
	$pid = gsp_agent_event_text($event['detected_process_pid'] ?? $event['tracked_pid'] ?? $event['pid'] ?? '', 32);
	$attempt = gsp_agent_event_text($event['restart_attempt_number'] ?? '', 16);
	$base = "Agent event for server '$name'";
	if ($home_id > 0) {
		$base .= " (home ID $home_id)";
	}
	switch ($event['event_type']) {
		case 'server_crash_detected':
			return "$base: unexpected stop detected. PID/process/session or required port disappeared.";
		case 'server_process_stopped':
			return "$base: server process stopped.";
		case 'server_process_started':
		case 'server_start_confirmed':
			return "$base: server start confirmed" . ($pid !== '' ? " with PID $pid" : "") . ($port !== '' ? " and port $port listening" : "") . ".";
		case 'server_stop_confirmed':
			return "$base: server stop confirmed. Process/session and required port are no longer active.";
		case 'server_unresponsive':
			return "$base: process/session exists but required game port did not become ready.";
		case 'automatic_restart_started':
			return "$base: automatic restart attempt" . ($attempt !== '' ? " $attempt" : "") . " started.";
		case 'automatic_restart_succeeded':
			return "$base: automatic restart succeeded" . ($pid !== '' ? " with PID $pid" : "") . ($port !== '' ? " and port $port listening" : "") . ".";
		case 'automatic_restart_failed':
			return "$base: automatic restart failed.";
		case 'scheduled_restart_started':
			return "$base: scheduled restart started.";
		case 'scheduled_restart_succeeded':
			return "$base: scheduled restart completed successfully.";
		case 'scheduled_restart_failed':
			return "$base: scheduled restart failed.";
		case 'server_port_missing':
		case 'server_process_found_without_port':
			return "$base: process/session is active but expected port $port is not listening.";
		case 'server_port_restored':
			return "$base: expected port $port is listening again.";
		case 'server_port_found_without_managed_process':
			return "$base: expected port $port is listening but no managed process/session was found.";
		case 'restart_attempt_limit_reached':
			return "$base: restart attempt limit reached.";
		case 'agent_event_delivery_failed':
			return "Agent event delivery failed on " . gsp_agent_event_text($event['agent_hostname'] ?? 'agent', 120) . ".";
		case 'agent_event_queue_replayed':
			return "Agent event queue replayed by " . gsp_agent_event_text($event['agent_hostname'] ?? 'agent', 120) . ".";
	}
	return $base . ': ' . gsp_agent_event_text($event['message'] ?? $event['event_type'], 250);
}

function gsp_agent_event_insert($db, $event, $remote) {
	$remote_server_id = (int)$remote['remote_server_id'];
	$home_id = isset($event['home_id']) && is_numeric($event['home_id']) ? (int)$event['home_id'] : null;
	if (!gsp_agent_event_home_belongs_to_remote($db, $home_id, $remote_server_id)) {
		gsp_agent_event_fail(403, 'home_remote_mismatch', 'Home does not belong to reporting remote server.');
	}
	if ($db->loggerEventExists($event['event_uuid'])) {
		return array('ok' => true, 'duplicate' => true);
	}
	$agent_label = gsp_agent_event_text($event['agent_hostname'] ?? $remote['remote_server_name'] ?? $remote['agent_ip'] ?? 'agent', 120);
	$actor = trim(gsp_agent_event_text(($event['agent_os'] ?? 'Agent') . ': ' . $agent_label, 120));
	$metadata = $event;
	unset($metadata['password'], $metadata['steam_password'], $metadata['encryption_key'], $metadata['database_password']);
	$db->loggerEx(gsp_agent_event_format_message($event), array(
		'user_id' => 0,
		'ip' => $agent_label,
		'source_type' => 'agent',
		'category' => 'server_lifecycle',
		'event_type' => gsp_agent_event_text($event['event_type'], 80),
		'severity' => gsp_agent_event_text($event['severity'], 20),
		'remote_server_id' => $remote_server_id,
		'home_id' => $home_id,
		'event_uuid' => gsp_agent_event_text($event['event_uuid'], 64),
		'correlation_id' => gsp_agent_event_text($event['correlation_id'] ?? '', 64),
		'actor' => $actor,
		'metadata_json' => json_encode($metadata)
	));
	return array('ok' => true, 'duplicate' => false);
}
