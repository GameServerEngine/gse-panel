<?php
/*
 *
 * GSP - Server Content scheduler action hooks (addonsmanager)
 *
 */

require_once("includes/lib_remote.php");
require_once("includes/lifecycle_contract_helpers.php");
require_once("modules/config_games/server_config_parser.php");
require_once(dirname(__FILE__) . '/server_content_helpers.php');
require_once(dirname(__FILE__) . '/workshop_action.php');

function server_content_get_home_info($home_id)
{
	global $db;
	$home_id = (int)$home_id;
	if ($home_id <= 0) {
		return false;
	}
	return $db->getGameHome($home_id);
}

function server_content_collect_workshop_ids($db, $home_id, $pending_only = false)
{
	$where = "home_id=".(int)$home_id." AND install_state<>'removed'";
	if ($pending_only) {
		$where .= " AND pending_action='install_on_restart'";
	}
	$rows = $db->resultQuery(
		"SELECT workshop_item_id FROM `".TABLE_PREFIX_PLACEHOLDER."server_content_workshop`
		 WHERE {$where}"
	);
	$item_ids = array();
	if (is_array($rows)) {
		foreach ((array)$rows as $row) {
			$item_id = trim((string)$row['workshop_item_id']);
			if ($item_id !== '' && preg_match('/^[0-9]+$/', $item_id)) {
				$item_ids[$item_id] = $item_id;
			}
		}
	}
	return array_values($item_ids);
}

function server_content_collect_manifest_addon_rows($db, $home_id)
{
	$rows = $db->resultQuery(
		"SELECT m.addon_id, m.install_method, m.content_version, m.install_state, a.name, a.url
		   FROM `".TABLE_PREFIX_PLACEHOLDER."server_content_manifest` m
		   LEFT JOIN `".TABLE_PREFIX_PLACEHOLDER."addons` a ON a.addon_id = m.addon_id
		  WHERE m.home_id=".(int)$home_id."
		    AND m.install_state <> 'uninstalled'
		  ORDER BY m.updated_at DESC, m.installed_at DESC"
	);
	return is_array($rows) ? $rows : array();
}

function server_content_create_remote(array $home_info)
{
	return new RemoteLibrary(
		$home_info['agent_ip'],
		$home_info['agent_port'],
		$home_info['encryption_key'],
		$home_info['timeout']
	);
}

function server_content_result($status, $message, array $details = array())
{
	return array(
		'status' => (string)$status,
		'success' => ((string)$status === 'success' || (string)$status === 'no_updates' || (string)$status === 'restart_required'),
		'message' => (string)$message,
		'details' => $details,
	);
}

function server_content_record_installed_content_state(array $home_info, array $state)
{
	$home_path = rtrim(clean_path((string)$home_info['home_path']), '/');
	$file_path = clean_path($home_path . '/server_content/installed_content.json');
	if (!scm_path_is_under_home($home_path, $file_path)) {
		return false;
	}
	$json = json_encode($state);
	if ($json === false) {
		return false;
	}
	$remote = server_content_create_remote($home_info);
	$remote->exec("mkdir -p " . escapeshellarg(dirname($file_path)));
	return ((int)$remote->remote_writefile($file_path, $json) === 1);
}

function server_content_log_action($home_id, $action, $status, $message = '', $details = array())
{
	global $db;
	$payload = array(
		'home_id' => (int)$home_id,
		'action' => (string)$action,
		'status' => (string)$status,
		'message' => (string)$message,
		'details' => $details,
	);
	$db->logger("server_content_action " . json_encode($payload));
	return true;
}

function server_content_build_manifest($home_id, $content_type, $action, $items = array(), $options = array())
{
	$home_info = server_content_get_home_info($home_id);
	if ($home_info === false) {
		return false;
	}
	$home_path = rtrim(clean_path((string)$home_info['home_path']), '/');
	$manifest_dir = clean_path($home_path . '/server_content/manifests');
	$file_stub = preg_replace('/[^a-z0-9_\-]+/i', '_', (string)$content_type . '_' . (string)$action);
	if ($file_stub === '' || $file_stub === null) {
		$file_stub = 'manifest';
	}
	$manifest_path = clean_path($manifest_dir . '/' . $file_stub . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.json');
	if (!scm_path_is_under_home($home_path, $manifest_path)) {
		return false;
	}
	$manifest = array(
		'manifest_version' => 1,
		'home_id' => (int)$home_info['home_id'],
		'home_cfg_id' => (int)$home_info['home_cfg_id'],
		'remote_server_id' => (int)$home_info['remote_server_id'],
		'content_type' => (string)$content_type,
		'action' => (string)$action,
		'items' => is_array($items) ? array_values($items) : array(),
		'options' => is_array($options) ? $options : array(),
		'generated_at' => date('Y-m-d H:i:s'),
	);
	$manifest_json = json_encode($manifest);
	if ($manifest_json === false) {
		return false;
	}
	$remote = server_content_create_remote($home_info);
	$remote->exec("mkdir -p " . escapeshellarg($manifest_dir));
	if ((int)$remote->remote_writefile($manifest_path, $manifest_json) !== 1) {
		return false;
	}
	return $manifest_path;
}

function server_content_resolve_script_path(array $home_info, $script_key, array $options = array())
{
	$script_path = '';
	if (isset($options['script_path'])) {
		$script_path = trim((string)$options['script_path']);
	}
	$server_xml = read_server_config(SERVER_CONFIG_LOCATION . "/" . $home_info['home_cfg_file'], os_for_install_row($home_info));
	if ($server_xml === false) {
		return array(false, false);
	}
	if ($script_path === '' && $script_key !== '' && isset($server_xml->$script_key)) {
		$script_path = trim((string)$server_xml->$script_key);
	}
	return array($server_xml, $script_path);
}

function server_content_execute_manifest($home_id, $manifest_path, $script_key, $options = array())
{
	$home_info = server_content_get_home_info($home_id);
	if ($home_info === false) {
		return server_content_result('failed', 'Invalid server home.', array('home_id' => (int)$home_id));
	}
	list($server_xml, $script_path) = server_content_resolve_script_path($home_info, $script_key, $options);
	if ($server_xml === false) {
		return server_content_result('failed', 'Unable to load server XML configuration.', array('home_id' => (int)$home_id));
	}
	$remote = server_content_create_remote($home_info);
	if ($remote->status_chk() !== 1) {
		return server_content_result('failed', 'Agent is offline.', array('remote_server_id' => (int)$home_info['remote_server_id']));
	}
	if ($script_key === 'workshop') {
		$prepare_error = '';
		$prepared_path = scm_prepare_workshop_script_for_agent($remote, $home_info, $server_xml, $prepare_error);
		if ($prepared_path === false) {
			return server_content_result('failed', $prepare_error, array('script_key' => (string)$script_key));
		}
		$script_path = $prepared_path;
	}
	else {
		$script_path = trim((string)$script_path);
		if ($script_path === '' || !preg_match('/^[^\r\n\0]+$/', $script_path)) {
			return server_content_result('failed', 'Configured server content script path is invalid.', array('script_key' => (string)$script_key));
		}
		if ((int)$remote->rfile_exists($script_path) !== 1) {
			return server_content_result('failed', 'Server content script was not found on agent host.', array('script_path' => $script_path));
		}
	}
	$command = "bash " . escapeshellarg($script_path) . " " . escapeshellarg((string)$manifest_path) . " ; echo __SERVER_CONTENT_EXIT:$?";
	$output = $remote->exec($command);
	if (!is_string($output) || $output === '') {
		return server_content_result('failed', 'Server content script did not return output.', array('script_path' => $script_path));
	}
	if (!preg_match('/__SERVER_CONTENT_EXIT:(\d+)/', $output, $matches)) {
		return server_content_result('failed', 'Server content script exit marker was not found.', array('output' => trim($output)));
	}
	$exit_code = (int)$matches[1];
	if ($exit_code !== 0) {
		return server_content_result('failed', 'Server content script failed.', array(
			'exit_code' => $exit_code,
			'output' => trim($output),
			'script_path' => $script_path,
		));
	}
	return server_content_result('success', 'Server content script executed successfully.', array(
		'exit_code' => $exit_code,
		'output' => trim($output),
		'script_path' => $script_path,
		'manifest_path' => (string)$manifest_path,
	));
}

function server_content_check_updates($home_id, $options = array())
{
	$options['check_only'] = true;
	return server_content_install_updates($home_id, $options);
}

function server_content_update_workshop($home_id, $options = array())
{
	$options['workshop_only'] = true;
	return server_content_install_updates($home_id, $options);
}

function server_content_install_updates($home_id, $options = array())
{
	global $db;
	$home_info = server_content_get_home_info($home_id);
	if ($home_info === false) {
		return server_content_result('failed', 'Invalid server home.');
	}
	scm_ensure_phase2_schema($db);
	scm_ensure_workshop_schema($db);

	$workshop_action = isset($options['workshop_action']) ? (string)$options['workshop_action'] : '';
	if ($workshop_action === '') {
		$workshop_action = !empty($options['check_only']) ? 'check_updates' : 'update';
	}
	$workshop_ids = server_content_collect_workshop_ids($db, (int)$home_info['home_id'], !empty($options['pending_only']));
	$manifest_rows = server_content_collect_manifest_addon_rows($db, (int)$home_info['home_id']);
	if (empty($workshop_ids) && empty($manifest_rows)) {
		$result = server_content_result('no_updates', 'No installed server content records were found for this home.', array(
			'home_id' => (int)$home_info['home_id'],
		));
		server_content_record_installed_content_state($home_info, array(
			'home_id' => (int)$home_info['home_id'],
			'last_action' => 'no_updates',
			'last_updated_at' => date('Y-m-d H:i:s'),
		));
		return $result;
	}

	if (!empty($workshop_ids)) {
		$server_xml = read_server_config(SERVER_CONFIG_LOCATION . "/" . $home_info['home_cfg_file'], os_for_install_row($home_info));
		if ($server_xml === false) {
			return server_content_result('failed', 'Unable to load server XML configuration for Workshop update.');
		}
		$manifest_context = scm_workshop_build_manifest_context($db, $home_info, $server_xml, $workshop_ids, array());
		if (empty($manifest_context['workshop_app_id'])) {
			return server_content_result('failed', 'Workshop App ID is missing from the game XML workshop_support block.');
		}
		if (empty($options['check_only'])) {
			scm_workshop_update_rows_state($db, (int)$home_info['home_id'], $workshop_ids, 'installing', null, false, false);
		}
		$error = '';
		$details = array();
		$ok = scm_workshop_write_manifest_and_run($db, $home_info, $server_xml, $workshop_action, $workshop_ids, $error, $manifest_context, $details);
		if (!$ok) {
			if (empty($options['check_only'])) {
				scm_workshop_update_rows_state($db, (int)$home_info['home_id'], $workshop_ids, 'failed', $error, false, false);
			}
			return server_content_result('failed', $error, $details);
		}
		if (empty($options['check_only'])) {
			$final_state = ($workshop_action === 'download_only') ? 'downloaded' : 'installed';
			scm_workshop_update_rows_state($db, (int)$home_info['home_id'], $workshop_ids, $final_state, null, false, true);
			if (!empty($options['pending_only'])) {
				scm_workshop_set_update_policy($db, (int)$home_info['home_id'], $workshop_ids, 'manual');
			}
			if ($workshop_action === 'download_only') {
				scm_workshop_set_update_policy($db, (int)$home_info['home_id'], $workshop_ids, 'install_on_restart');
			}
			scm_workshop_record_catalog_items($db, (string)$manifest_context['workshop_app_id'], $workshop_ids, $home_info, isset($manifest_context['item_details']) ? $manifest_context['item_details'] : array(), true);
		}
		server_content_record_installed_content_state($home_info, array(
			'home_id' => (int)$home_info['home_id'],
			'last_action' => (string)$workshop_action,
			'last_result' => 'success',
			'last_manifest' => isset($details['manifest_path']) ? $details['manifest_path'] : '',
			'last_updated_at' => date('Y-m-d H:i:s'),
			'installed_workshop_ids' => $workshop_ids,
		));
		if (empty($manifest_rows) || !empty($options['workshop_only'])) {
			if (!empty($options['check_only'])) {
				return server_content_result('success', 'Workshop update check completed.', array(
					'workshop_items' => count($workshop_ids),
					'manifest_path' => isset($details['manifest_path']) ? $details['manifest_path'] : '',
					'log_path' => isset($details['log_path']) ? $details['log_path'] : '',
				));
			}
			return server_content_result('success', 'Workshop action completed.', array(
				'workshop_action' => $workshop_action,
				'workshop_items' => count($workshop_ids),
				'manifest_path' => isset($details['manifest_path']) ? $details['manifest_path'] : '',
				'log_path' => isset($details['log_path']) ? $details['log_path'] : '',
			));
		}
	}

	$manifest_items = array(
		'workshop_item_ids' => array(),
		'manifest_addons' => $manifest_rows,
	);
	$manifest_path = server_content_build_manifest($home_info['home_id'], 'server_content', $workshop_action, $manifest_items, $options);
	if ($manifest_path === false) {
		if (!empty($workshop_ids) && empty($options['check_only'])) {
			scm_workshop_update_rows_state($db, (int)$home_info['home_id'], $workshop_ids, 'failed', 'Failed to build server content manifest.', false, false);
		}
		return server_content_result('failed', 'Failed to build server content manifest.');
	}

	$execute = server_content_execute_manifest($home_info['home_id'], $manifest_path, 'workshop', $options);
	if (empty($execute['success'])) {
		if (!empty($workshop_ids) && empty($options['check_only'])) {
			$error_message = isset($execute['message']) ? $execute['message'] : 'Unknown failure.';
			scm_workshop_update_rows_state($db, (int)$home_info['home_id'], $workshop_ids, 'failed', $error_message, false, false);
		}
		return $execute;
	}

	server_content_record_installed_content_state($home_info, array(
		'home_id' => (int)$home_info['home_id'],
		'last_action' => (string)$workshop_action,
		'last_result' => 'success',
		'last_manifest' => $manifest_path,
		'last_updated_at' => date('Y-m-d H:i:s'),
		'installed_workshop_ids' => $workshop_ids,
	));

	if (!empty($options['check_only'])) {
		return server_content_result('success', 'Server content update check completed.', array(
			'manifest_path' => $manifest_path,
			'workshop_items' => count($workshop_ids),
		));
	}
	return server_content_result('success', 'Server content updates were installed.', array(
		'manifest_path' => $manifest_path,
		'workshop_items' => count($workshop_ids),
		'manifest_rows' => count($manifest_rows),
	));
}

function server_content_home_is_running(array $home_info)
{
	$remote = server_content_create_remote($home_info);
	return ($remote->is_screen_running(SCREEN_TYPE_HOME, $home_info['home_id']) == 1);
}

function server_content_install_updates_if_stopped($home_id, $options = array())
{
	$home_info = server_content_get_home_info($home_id);
	if ($home_info === false) {
		return server_content_result('failed', 'Invalid server home.');
	}
	if (server_content_home_is_running($home_info)) {
		return server_content_result('restart_required', 'Server is running; update skipped until server is stopped.', array(
			'home_id' => (int)$home_info['home_id'],
		));
	}
	return server_content_install_updates($home_id, $options);
}

function server_content_install_updates_next_restart($home_id, $options = array())
{
	$home_info = server_content_get_home_info($home_id);
	if ($home_info === false) {
		return server_content_result('failed', 'Invalid server home.');
	}
	$options['queued_for_restart'] = true;
	$manifest_path = server_content_build_manifest($home_info['home_id'], 'server_content', 'install_next_restart', array(), $options);
	if ($manifest_path === false) {
		return server_content_result('failed', 'Failed to queue update manifest for next restart.');
	}
	server_content_record_installed_content_state($home_info, array(
		'home_id' => (int)$home_info['home_id'],
		'last_action' => 'install_next_restart',
		'last_result' => 'queued',
		'queued_manifest' => $manifest_path,
		'last_updated_at' => date('Y-m-d H:i:s'),
	));
	return server_content_result('restart_required', 'Server content updates were queued for next restart.', array(
		'manifest_path' => $manifest_path,
	));
}

function server_content_restart_home($home_id, $options = array())
{
	global $db, $user_info;
	$home_info = server_content_get_home_info($home_id);
	if ($home_info === false) {
		return server_content_result('failed', 'Invalid server home.');
	}
	$server_xml = read_server_config(SERVER_CONFIG_LOCATION . "/" . $home_info['home_cfg_file'], os_for_install_row($home_info));
	if ($server_xml === false) {
		return server_content_result('failed', 'Could not load server XML for restart.');
	}
	$path_error = '';
	$home_info = with_game_root($home_info, $server_xml, $path_error);
	if ($home_info === false) {
		return server_content_result('failed', 'Invalid game directory for restart: ' . $path_error);
	}
	$remote = server_content_create_remote($home_info);
	$host_stat = $remote->status_chk();
	if ($host_stat !== 1) {
		return server_content_result('failed', 'Agent is offline; cannot restart server.');
	}

	$ip_ports = $db->getHomeIpPorts($home_info['home_id']);
	if (!is_array($ip_ports) || !isset($ip_ports[0])) {
		return server_content_result('failed', 'No IP/port mapping found for server restart.');
	}
	$ip = $ip_ports[0]['ip'];
	$port = (int)$ip_ports[0]['port'];

	$mod_id = key($home_info['mods']);
	$start_cmd = get_start_cmd($user_info, $remote, $server_xml, $home_info, $mod_id, $ip, $port, $db);
	if ($start_cmd === false) {
		return server_content_result('failed', 'Invalid game directory for restart.');
	}
	$preStart = isset($server_xml->pre_start) ? trim((string)$server_xml->pre_start) : "";
	$envVars = isset($server_xml->environment_variables) ? trim((string)$server_xml->environment_variables) : "";
	$replace_text_results = array();

	$delay = isset($options['restart_delay_seconds']) ? (int)$options['restart_delay_seconds'] : 0;
	if ($delay > 0) {
		if ($delay > 300) {
			$delay = 300;
		}
		sleep($delay);
	}
	$restart_reason = (isset($options['triggered_by']) && $options['triggered_by'] === 'scheduler') ? 'scheduled_restart' : 'panel_restart';

	$lifecycle_payload = lifecycle_start_payload($db, $remote, $home_info, $server_xml, $mod_id, $ip, $port, $start_cmd, $preStart, $envVars, $replace_text_results, $restart_reason);
	if (isset($lifecycle_payload['error'])) {
		return server_content_result('restart_required', 'Update completed but automatic restart failed: ' . $lifecycle_payload['error']);
	}

	$restart_result = $remote->remote_restart_server_result(
		$home_info['home_id'],
		$ip,
		$port,
		$server_xml->control_protocol,
		$home_info['control_password'],
		$server_xml->control_protocol_type,
		$home_info['home_path'],
		$server_xml->server_exec_name,
		clean_path((string)$server_xml->exe_location),
		$start_cmd,
		$home_info['mods'][$mod_id]['cpu_affinity'],
		$home_info['mods'][$mod_id]['nice'],
		$preStart,
		$envVars,
		$server_xml->game_key,
		(isset($server_xml->console_log) ? $server_xml->console_log : ""),
		$restart_reason,
		$lifecycle_payload
	);
	$remote_retval = isset($restart_result['raw_response']) ? $restart_result['raw_response'] : (!empty($restart_result['success']) ? 1 : 0);
	if (empty($restart_result['success'])) {
		return server_content_result('restart_required', 'Update completed but automatic restart failed.', array(
			'restart_status' => $remote_retval,
			'restart_result' => $restart_result,
		));
	}
	return server_content_result('success', 'Update completed and server restart was triggered.', array(
		'restart_status' => $remote_retval,
	));
}

function server_content_install_updates_and_restart($home_id, $options = array())
{
	$install_result = server_content_install_updates($home_id, $options);
	if (empty($install_result['success']) || $install_result['status'] === 'failed') {
		return $install_result;
	}
	$restart_result = server_content_restart_home($home_id, $options);
	$install_result['details']['restart'] = $restart_result;
	if ($restart_result['status'] === 'success') {
		$install_result['status'] = 'success';
		$install_result['message'] = 'Server content updates installed and server restart requested.';
		return $install_result;
	}
	$install_result['status'] = 'restart_required';
	$install_result['success'] = true;
	$install_result['message'] = 'Server content updates installed; restart is still required.';
	return $install_result;
}

function server_content_run_scheduled_action($home_id, $action, $options = array())
{
	$home_id = (int)$home_id;
	$action = trim((string)$action);
	if ($home_id <= 0) {
		return server_content_result('failed', 'Invalid server home id.');
	}
	$handlers = array(
		'server_content_check_updates' => 'server_content_check_updates',
		'server_content_check_workshop_updates' => 'server_content_update_workshop',
		'server_content_install_updates_if_stopped' => 'server_content_install_updates_if_stopped',
		'server_content_install_updates_next_restart' => 'server_content_install_updates_next_restart',
		'server_content_install_updates_now' => 'server_content_install_updates',
		'server_content_install_updates_and_restart' => 'server_content_install_updates_and_restart',
		'server_content_update_workshop' => 'server_content_update_workshop',
		'server_content_update_all' => 'server_content_install_updates',
		'server_content_notify_updates_only' => 'server_content_check_updates',
		'server_content_validate_files' => 'server_content_update_workshop',
		'server_content_backup_before_update' => 'server_content_install_updates',
		'workshop_update' => 'server_content_update_workshop',
		'workshop_update_and_restart' => 'server_content_install_updates_and_restart',
		'workshop_download_only' => 'server_content_update_workshop',
		'workshop_install_pending_on_restart' => 'server_content_install_updates',
	);
	if (!isset($handlers[$action]) || !function_exists($handlers[$action])) {
		$result = server_content_result('failed', 'Unsupported scheduled server content action.', array(
			'action' => $action,
		));
		server_content_log_action($home_id, $action, $result['status'], $result['message'], $result['details']);
		return $result;
	}

	if ($action === 'server_content_check_workshop_updates' || $action === 'server_content_validate_files') {
		$options['check_only'] = true;
		$options['workshop_action'] = ($action === 'server_content_validate_files') ? 'validate_files' : 'check_updates';
	}
	if ($action === 'server_content_backup_before_update') {
		$options['backup_before_update'] = true;
	}
	if ($action === 'server_content_install_updates_and_restart' || $action === 'workshop_update_and_restart') {
		$options['workshop_action'] = 'update';
	}
	if (in_array($action, array('workshop_update', 'workshop_update_and_restart', 'workshop_download_only', 'workshop_install_pending_on_restart'), true)) {
		$options['workshop_only'] = true;
	}
	if (($action === 'server_content_install_updates_and_restart' || $action === 'workshop_update_and_restart') && !isset($options['safe_restart'])) {
		$options['safe_restart'] = true;
	}
	if ($action === 'workshop_download_only') {
		$options['workshop_action'] = 'download_only';
	}
	if ($action === 'workshop_install_pending_on_restart') {
		$options['workshop_action'] = 'update';
		$options['pending_only'] = true;
	}
	if ($action === 'server_content_notify_updates_only') {
		$options['notify_only'] = true;
		$options['check_only'] = true;
	}

	server_content_log_action($home_id, $action, 'started', 'Scheduled action started.', $options);
	$handler = $handlers[$action];
	$result = $handler($home_id, $options);
	server_content_log_action($home_id, $action, $result['status'], $result['message'], $result['details']);
	return $result;
}
