<?php
/*
 * JSON endpoint for the Utilities page. home.php?m=util&p=diag&type=cleared
 *   GET  action=config   tools this user may use, own servers, the visitor's IP, the CSRF token
 *   POST action=run      runs one tool (needs the CSRF token)
 * All rules live in includes/diagnostics/ (see runner.php); this file only connects them to the session.
 */
require_once 'modules/util/diag_env.php';

function util_diag_json($data, $http_code = 200)
{
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=UTF-8');
		http_response_code($http_code);
	}
	echo json_encode($data);
}

// What the page needs to build its forms. No agent keys, no endpoint URLs.
function util_diag_config($db, $env)
{
	$servers = array();
	foreach ($env['rows'] as $row) {
		$servers[] = array('label' => $row['home_name'] . ' (' . $row['ip'] . ':' . $row['port'] . ')', 'ip' => $row['ip'], 'port' => (int)$row['port'],
			'agent' => (int)$row['remote_server_id']);
	}
	$tools = array();
	foreach (diag_default_tool_access() as $tool => $roles) {
		if (in_array($env['role'], $roles, true)) {
			$tools[] = $tool;
		}
	}
	return array('csrf' => diag_csrf_token(), 'tools' => $tools, 'servers' => $servers, 'my_ip' => $env['client_ip'], 'is_admin' => ($env['role'] === 'admin'));
}

function util_diag_run($db, $env)
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !diag_csrf_valid(diag_csrf_from_request())) {
		util_diag_json(array('ok' => false, 'code' => 'csrf', 'message' => 'The page expired. Reload it and try again.'), 403);
		return;
	}
	$tool = isset($_POST['tool']) ? (string)$_POST['tool'] : '';
	set_time_limit(90);
	$result = diag_execute($tool, $_POST, $env);
	$db->logger('Diagnostics ' . $tool . ' agent ' . (int)(isset($_POST['agent']) ? $_POST['agent'] : 0) . ' target ' . substr((string)(isset($_POST['host']) ? $_POST['host'] : ''), 0, 100)
		. ' -> ' . ($result['ok'] ? $result['outcome'] : $result['code']));
	util_diag_json($result);
}

function exec_module()
{
	global $db;
	// An offline agent makes the XML-RPC decoder print a warning. That text must not end up inside the JSON.
	ini_set('display_errors', '0');
	$env = util_diag_env($db);
	$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : 'config';
	if ($action === 'run') {
		util_diag_run($db, $env);
	} else {
		util_diag_json(util_diag_config($db, $env));
	}
}
