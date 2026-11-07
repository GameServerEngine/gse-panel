<?php

if (!defined('GSP_AGENT_PROTOCOL_VERSION')) {
	define('GSP_AGENT_PROTOCOL_VERSION', 1);
}


require_once('includes/lib_remote.php');

function gsp_agent_html($value)
{
	return htmlentities((string)$value, ENT_QUOTES, 'UTF-8');
}

function gsp_agent_mask_secret($secret)
{
	$secret = (string)$secret;
	if ($secret === '') {
		return '';
	}
	if (strlen($secret) <= 8) {
		return str_repeat('*', strlen($secret));
	}
	return substr($secret, 0, 3) . str_repeat('*', max(4, strlen($secret) - 6)) . substr($secret, -3);
}

function gsp_agent_ensure_snapshot_table($db)
{
	static $done = false;
	if ($done) {
		return;
	}
	$db->query("CREATE TABLE IF NOT EXISTS `" . GSP_DB_PREFIX . "agent_resource_snapshots` (
		`remote_server_id` int(11) NOT NULL,
		`agent_ip` varchar(255) NOT NULL DEFAULT '',
		`agent_port` int(11) NOT NULL DEFAULT 0,
		`os` varchar(255) NOT NULL DEFAULT '',
		`agent_version` varchar(255) NOT NULL DEFAULT '',
		`cpu_percent` decimal(6,2) NULL,
		`ram_percent` decimal(6,2) NULL,
		`disk_percent` decimal(6,2) NULL,
		`load_average` varchar(64) NOT NULL DEFAULT '',
		`raw_payload` LONGTEXT NULL,
		`collected_at` int(11) NOT NULL DEFAULT 0,
		`status` varchar(64) NOT NULL DEFAULT 'unknown',
		`last_error` varchar(255) NOT NULL DEFAULT '',
		PRIMARY KEY (`remote_server_id`),
		KEY `collected_at` (`collected_at`)
	) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Latest cached per-agent resource snapshot';");
	$done = true;
}

function gsp_agent_build_remote($server_row)
{
	return new GSPRemoteLibrary($server_row['agent_ip'], $server_row['agent_port'], $server_row['encryption_key'], $server_row['timeout']);
}

function gsp_agent_snapshot_age($snapshot)
{
	if (!is_array($snapshot) || empty($snapshot['collected_at'])) {
		return null;
	}
	return max(0, time() - (int)$snapshot['collected_at']);
}

function gsp_agent_format_age($snapshot)
{
	$age = gsp_agent_snapshot_age($snapshot);
	if ($age === null) {
		return 'no cached data';
	}
	if ($age < 60) {
		return $age . 's ago';
	}
	if ($age < 3600) {
		return floor($age / 60) . 'm ago';
	}
	return floor($age / 3600) . 'h ago';
}

function gsp_agent_snapshot_payload($snapshot)
{
	if (!is_array($snapshot) || empty($snapshot['raw_payload'])) {
		return false;
	}
	$payload = json_decode((string)$snapshot['raw_payload'], true);
	return is_array($payload) ? $payload : false;
}

function gsp_agent_snapshot_field($snapshot, $key, $default = '')
{
	if (is_array($snapshot) && array_key_exists($key, $snapshot) && $snapshot[$key] !== '' && $snapshot[$key] !== null) {
		return $snapshot[$key];
	}
	$payload = gsp_agent_snapshot_payload($snapshot);
	if (is_array($payload) && array_key_exists($key, $payload) && $payload[$key] !== '' && $payload[$key] !== null) {
		return $payload[$key];
	}
	return $default;
}

function gsp_agent_snapshot_successful($snapshot)
{
	$success = gsp_agent_snapshot_field($snapshot, 'success', null);
	if ($success !== null) {
		return in_array(strtolower((string)$success), array('1', 'true', 'yes'), true);
	}
	return strtolower((string)gsp_agent_snapshot_field($snapshot, 'status', 'unknown')) === 'ok';
}

function gsp_agent_redact_diagnostic_data($value, $key = '')
{
	if ($key !== '' && preg_match('/(key|secret|password|token|credential)/i', $key)) {
		return '[redacted]';
	}
	if (is_array($value)) {
		$redacted = array();
		foreach ($value as $child_key => $child_value) {
			$redacted[$child_key] = gsp_agent_redact_diagnostic_data($child_value, (string)$child_key);
		}
		return $redacted;
	}
	return $value;
}

function gsp_agent_diagnostic_text($value, $limit = 500)
{
	if (is_array($value)) {
		$value = json_encode(gsp_agent_redact_diagnostic_data($value));
	}
	$value = trim((string)$value);
	if (strlen($value) > $limit) {
		$value = substr($value, 0, $limit) . '...';
	}
	return $value;
}

function gsp_agent_snapshot_diagnostic($snapshot)
{
	if (gsp_agent_snapshot_successful($snapshot)) {
		return '';
	}
	$parts = array();
	$status = gsp_agent_diagnostic_text(gsp_agent_snapshot_field($snapshot, 'status', 'unknown'), 120);
	if ($status !== '') {
		$parts[] = 'status: ' . $status;
	}
	$message = gsp_agent_diagnostic_text(gsp_agent_snapshot_field($snapshot, 'message', ''), 240);
	if ($message !== '') {
		$parts[] = 'message: ' . $message;
	}
	$last_error = gsp_agent_diagnostic_text(gsp_agent_snapshot_field($snapshot, 'last_error', ''), 240);
	if ($last_error !== '' && $last_error !== $message) {
		$parts[] = 'last error: ' . $last_error;
	}
	if (count($parts) <= 1) {
		$payload = gsp_agent_snapshot_payload($snapshot);
		if (is_array($payload)) {
			$parts[] = 'raw: ' . gsp_agent_diagnostic_text($payload, 500);
		}
	}
	if (empty($parts)) {
		$parts[] = 'no cached resource snapshot';
	}
	return "<span class='failure'>Snapshot diagnostic: " . gsp_agent_html(implode(' | ', $parts)) . "</span>";
}

function gsp_agent_level($metric, $value)
{
	if ($value === '' || $value === null || !is_numeric($value)) {
		return 'unknown';
	}
	$value = (float)$value;
	if ($metric === 'disk') {
		if ($value >= 95) return 'critical';
		if ($value >= 85) return 'warning';
		return 'ok';
	}
	if ($value >= 95) return 'critical';
	if ($value >= 80) return 'warning';
	return 'ok';
}

function gsp_agent_badge($label, $level)
{
	// Small inline status text, not the theme's large success/note boxes.
	$colors = array('ok' => '#5cb85c', 'warning' => '#f0ad4e', 'critical' => '#d9534f');
	$color = isset($colors[$level]) ? $colors[$level] : '#999999';
	return "<span class='agent-badge' style='display:inline-block;font-size:11px;line-height:1.4;padding:0 4px;margin:0 2px;color:" . $color . ";background:none;border:0;box-shadow:none;'>" . gsp_agent_html($label) . "</span>";
}

function gsp_agent_resource_badges($snapshot)
{
	$badges = array();
	if (!is_array($snapshot)) {
		return gsp_agent_badge('no resource data', 'warning');
	}
	$age = gsp_agent_snapshot_age($snapshot);
	if ($age === null || $age > 600) {
		$badges[] = gsp_agent_badge('stale resource data', 'warning');
	}
	foreach (array('cpu_percent' => 'CPU', 'ram_percent' => 'RAM', 'disk_percent' => 'disk') as $key => $label) {
		$metric_value = gsp_agent_snapshot_field($snapshot, $key, '');
		$level = gsp_agent_level($key === 'disk_percent' ? 'disk' : 'default', $metric_value);
		if ($level === 'warning' || $level === 'critical') {
			$value = is_numeric($metric_value) ? round((float)$metric_value, 1) . '%' : 'unknown';
			$badges[] = gsp_agent_badge($label . ' ' . $level . ' ' . $value, $level);
		}
	}
	return empty($badges) ? gsp_agent_badge('resources ok', 'ok') : implode(' ', $badges);
}

function gsp_agent_refresh_snapshot($db, $server_row)
{
	gsp_agent_ensure_snapshot_table($db);
	$remote = gsp_agent_build_remote($server_row);
	$snapshot = $remote->agent_resource_snapshot();
	if (empty($snapshot['success'])) {
		$snapshot['agent_ip'] = $server_row['agent_ip'];
		$snapshot['agent_port'] = $server_row['agent_port'];
		$snapshot['collected_at'] = time();
		$snapshot['raw_payload'] = json_encode($snapshot);
		$db->upsertAgentResourceSnapshot($server_row['remote_server_id'], $snapshot);
		return $snapshot;
	}
	$snapshot['agent_ip'] = $server_row['agent_ip'];
	$snapshot['agent_port'] = $server_row['agent_port'];
	$snapshot['collected_at'] = time();
	$snapshot['raw_payload'] = json_encode($snapshot);
	if (isset($snapshot['memory_percent']) && !isset($snapshot['ram_percent'])) {
		$snapshot['ram_percent'] = $snapshot['memory_percent'];
	}
	$db->upsertAgentResourceSnapshot($server_row['remote_server_id'], $snapshot);
	return $snapshot;
}

function gsp_agent_get_snapshot($db, $remote_server_id)
{
	gsp_agent_ensure_snapshot_table($db);
	if (!method_exists($db, 'getAgentResourceSnapshot')) {
		return false;
	}
	return $db->getAgentResourceSnapshot($remote_server_id);
}

function gsp_agent_refresh_one_stale_snapshot($db, $servers, $max_age = 300)
{
	foreach ((array)$servers as $server_row) {
		$snapshot = gsp_agent_get_snapshot($db, $server_row['remote_server_id']);
		$age = gsp_agent_snapshot_age($snapshot);
		if ($age === null || $age >= $max_age) {
			gsp_agent_refresh_snapshot($db, $server_row);
			return (int)$server_row['remote_server_id'];
		}
	}
	return 0;
}

function gsp_agent_count_homes($db, $remote_server_id)
{
	$remote_server_id = (int)$remote_server_id;
	$row = $db->resultQuery("SELECT COUNT(*) AS home_count FROM `" . GSP_DB_PREFIX . "server_homes` WHERE remote_server_id = " . $remote_server_id);
	if (is_array($row) && isset($row[0]['home_count'])) {
		return (int)$row[0]['home_count'];
	}
	return 0;
}

function gsp_agent_payload_line($key, $value)
{
	return $key . '=' . base64_encode((string)$value);
}

function gsp_agent_release_payload($component, $archive_url, $checksum, $version, $os)
{
	return implode("
", array(
		gsp_agent_payload_line('component', $component),
		gsp_agent_payload_line('archive_url', $archive_url),
		gsp_agent_payload_line('checksum', strtolower((string)$checksum)),
		gsp_agent_payload_line('version', $version),
		gsp_agent_payload_line('timeout', 60),
		gsp_agent_payload_line('requested_os', $os)
	));
}

function gsp_agent_component_from_os($os)
{
	return (preg_match('/CYGWIN|Windows/i', (string)$os)) ? 'windows_agent' : 'linux_agent';
}

/**
 * Sanitizes a raw agent_ids[] POST array into a deduplicated list of positive
 * integer remote_server_ids, in first-seen order. Pulled out as a pure
 * function so the Remote Servers batch-update selection logic is testable
 * without a database or live agents.
 */
function gsp_agent_sanitize_ids($raw)
{
	$ids = array();
	if (is_array($raw)) {
		foreach ($raw as $id) {
			$id = (int)$id;
			if ($id > 0) {
				$ids[] = $id;
			}
		}
	}
	return array_values(array_unique($ids));
}

/**
 * Queues a self-update and restart on a single agent, the same mechanism as
 * the per-agent "Queue Update And Restart" action, but returning a plain
 * result array instead of printing to the page. Used by both the single-
 * agent interface and the Remote Servers batch update.
 */
/**
 * Sends one agent a release update (archive_url, checksum, version). The agent
 * downloads, verifies, and installs the release itself and stops; it is not
 * restarted here. Refused if the agent reports a protocol version this panel
 * does not speak.
 */
function gsp_agent_perform_release_update($db, array $remote_server_row, $archive_url, $checksum, $version)
{
	$remote_server_id = (int)$remote_server_row['remote_server_id'];
	$name = $remote_server_row['remote_server_name'];
	$remote = gsp_agent_build_remote($remote_server_row);
	$fail = function ($message) use ($remote_server_id, $name) {
		return array('remote_server_id' => $remote_server_id, 'name' => $name, 'success' => false, 'message' => $message);
	};
	if ($remote->status_chk() !== 1) {
		return $fail('Agent is not reachable; update was not attempted.');
	}
	$info = $remote->agent_version();
	$os = (is_array($info) && !empty($info['os'])) ? (string)$info['os'] : (string)$remote->what_os();
	$protocol = (is_array($info) && isset($info['protocol_version'])) ? (int)$info['protocol_version'] : null;
	if ($protocol !== null && $protocol !== GSP_AGENT_PROTOCOL_VERSION) {
		return $fail('Agent protocol ' . $protocol . ' does not match panel protocol ' . GSP_AGENT_PROTOCOL_VERSION . '; update was not attempted.');
	}
	$component = gsp_agent_component_from_os($os);
	$payload = gsp_agent_release_payload($component, $archive_url, $checksum, $version, $os);
	$result = $remote->agent_self_update($payload);
	$db->logger('Admin requested release update ' . $version . ' for remote server ID:' . $remote_server_id . ' component ' . $component);
	$success = !empty($result['success']);
	return array(
		'remote_server_id' => $remote_server_id,
		'name' => $name,
		'success' => $success,
		'message' => isset($result['message']) ? (string)$result['message'] : ($success ? 'Update started.' : 'Update failed.'),
	);
}

function gsp_agent_status_label($status)
{
	if ($status === 1) return gsp_agent_badge('online', 'ok');
	if ($status === -1) return gsp_agent_badge('encryption mismatch', 'critical');
	if ($status === 0) return gsp_agent_badge('offline', 'critical');
	return gsp_agent_badge('unknown', 'warning');
}

?>
