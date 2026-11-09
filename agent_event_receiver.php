<?php

require_once("includes/functions.php");
require_once("includes/helpers.php");
define("CONFIG_FILE", "includes/config.inc.php");
require_once CONFIG_FILE;
require_once("includes/agent_event_log.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	agent_event_json_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
}

$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : NULL);
if (!$db instanceof Database) {
	agent_event_fail(503, 'database_unavailable', 'Could not connect to database.');
}

$raw_body = file_get_contents('php://input');
if ($raw_body === false || strlen($raw_body) > 65536) {
	agent_event_fail(400, 'invalid_body', 'Invalid or oversized request body.');
}

$remote = agent_event_authenticate($db, $raw_body);
$event = json_decode($raw_body, true);
if (!is_array($event)) {
	agent_event_fail(400, 'invalid_json', 'Request body is not valid JSON.');
}

$header_remote_id = (int)agent_event_header('X-GSP-Agent-Id');
$event_remote_id = isset($event['remote_server_id']) && is_numeric($event['remote_server_id']) ? (int)$event['remote_server_id'] : $header_remote_id;
if ($event_remote_id !== $header_remote_id) {
	agent_event_fail(403, 'remote_id_mismatch', 'Payload remote server does not match authenticated agent.');
}
$event['remote_server_id'] = $header_remote_id;
$event['source'] = 'agent';

$errors = agent_event_validate($event);
if ($errors) {
	agent_event_fail(400, 'validation_failed', implode('; ', $errors));
}

$result = agent_event_insert($db, $event, $remote);
agent_event_json_response(200, $result);
