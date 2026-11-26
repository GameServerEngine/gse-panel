<?php
/*
 * GSE - Execution backend dispatch
 *
 * A game home runs either natively (screen, the default) or in a Docker container on its
 * agent. The choice lives in home_execution_runtime; a home without a row is native.
 * GSERemoteLibrary asks gse_execution_backend() before every lifecycle call, so the customer
 * UI, admin pages, API, scheduler and watchdog all use the same backend. A Docker home never
 * falls back to a native start.
 */

if (!defined('GSE_BACKEND_NATIVE')) {
	define('GSE_BACKEND_NATIVE', 'native');
	define('GSE_BACKEND_DOCKER', 'docker');
	define('GSE_BACKEND_ERROR', 'error');
	define('GSE_CONTAINER_CONTRACT', 'gse.container.v1');
}

/** The panel database handle, when this request has one. */
function gse_execution_db()
{
	return (isset($GLOBALS['db']) && is_object($GLOBALS['db'])) ? $GLOBALS['db'] : null;
}

/**
 * Returns the runtime row for a home with 'backend' set to native, docker or error.
 * error means the row could not be read: callers must refuse lifecycle actions rather
 * than guess, because guessing native could start a second copy of a Docker server.
 */
function gse_execution_runtime($home_id, $refresh = false)
{
	static $cache = array();
	$home_id = (int)$home_id;
	if (!$refresh && array_key_exists($home_id, $cache)) {
		return $cache[$home_id];
	}
	$db = gse_execution_db();
	if ($db === null || $home_id <= 0 || !method_exists($db, 'queryRows')) {
		return $cache[$home_id] = array('backend' => GSE_BACKEND_NATIVE);
	}
	$rows = $db->queryRows(
		"SELECT r.*, i.name AS image_name, i.reference AS image_reference, i.digest AS image_digest,"
		. " i.platform AS image_platform, i.enabled AS image_enabled"
		. " FROM `DB_PREFIXhome_execution_runtime` r"
		. " LEFT JOIN `DB_PREFIXruntime_images` i ON i.image_id = r.runtime_image_id"
		. " WHERE r.home_id = " . $home_id
	);
	if ($rows === false) {
		$error = method_exists($db, 'getError') ? (string)$db->getError() : '';
		// Before migration 0012 the table does not exist, so no home can be a Docker home.
		if ($error === '' || stripos($error, "doesn't exist") !== false) {
			return $cache[$home_id] = array('backend' => GSE_BACKEND_NATIVE);
		}
		return $cache[$home_id] = array('backend' => GSE_BACKEND_ERROR, 'error' => $error);
	}
	if (empty($rows)) {
		return $cache[$home_id] = array('backend' => GSE_BACKEND_NATIVE);
	}
	$row = $rows[0];
	$row['backend'] = ($row['execution_backend'] === GSE_BACKEND_DOCKER) ? GSE_BACKEND_DOCKER : GSE_BACKEND_NATIVE;
	return $cache[$home_id] = $row;
}

function gse_execution_backend($home_id)
{
	$runtime = gse_execution_runtime($home_id);
	return $runtime['backend'];
}

/** Result array in the lifecycle shape for a refused action. */
function gse_execution_refusal($status, $message, $extra = array())
{
	return array_merge(array(
		'success' => 0,
		'status' => $status,
		'message' => $message,
		'execution_backend' => GSE_BACKEND_DOCKER,
	), $extra);
}

/**
 * Builds the gse.container.v1 request for a home. Ports come from the home's active port
 * blocks (TCP and UDP, same numbers inside the container); a legacy home without blocks
 * publishes its game port. Returns array($request, $error).
 */
function gse_container_request($home_id, $server_ip, $server_port)
{
	$runtime = gse_execution_runtime($home_id);
	if ($runtime['backend'] !== GSE_BACKEND_DOCKER) {
		return array(null, 'This server is not set to run in a container.');
	}
	if (empty($runtime['image_reference']) || empty($runtime['image_enabled'])) {
		return array(null, 'No approved container image is set for this server.');
	}
	$db = gse_execution_db();
	$home_id = (int)$home_id;
	$home_rows = $db ? $db->queryRows("SELECT home_id, remote_server_id, user_id_main FROM `DB_PREFIXserver_homes` WHERE home_id = " . $home_id) : false;
	if (empty($home_rows)) {
		return array(null, 'Game home was not found.');
	}
	$home = $home_rows[0];

	$assigned = array();
	$blocks = $db->queryRows("SELECT block_start, block_end FROM `DB_PREFIXhome_port_blocks` WHERE home_id = " . $home_id . " AND status = 'active' ORDER BY block_start");
	if (is_array($blocks) && !empty($blocks)) {
		foreach ($blocks as $block) {
			foreach (array('tcp', 'udp') as $protocol) {
				$assigned[] = array(
					'protocol' => $protocol,
					'host_start' => (int)$block['block_start'],
					'host_end' => (int)$block['block_end'],
					'container_start' => (int)$block['block_start'],
				);
			}
		}
	} else {
		$port = (int)$server_port;
		if ($port <= 0) {
			return array(null, 'No game port is configured for this server.');
		}
		foreach (array('tcp', 'udp') as $protocol) {
			$assigned[] = array('protocol' => $protocol, 'host_start' => $port, 'host_end' => $port, 'container_start' => $port);
		}
	}

	$resources = array(
		'cpu_cores' => (float)$runtime['cpu_cores'],
		'memory_mb' => (int)$runtime['memory_mb'],
		'pids_limit' => (int)$runtime['pids_limit'],
	);
	if ($runtime['memory_swap_mb'] !== null && (int)$runtime['memory_swap_mb'] > 0) {
		$resources['memory_burst_mb'] = (int)$runtime['memory_swap_mb'];
	}
	$request = array(
		'contract' => GSE_CONTAINER_CONTRACT,
		'target' => array(
			'server_id' => (int)$home['remote_server_id'],
			'home_id' => $home_id,
			// One container per home: a stable name (gse-h<home>-a1) that survives port changes.
			'allocation_id' => 1,
			'owner_user_id' => (int)$home['user_id_main'],
		),
		'payload' => array(
			'image' => array(
				'reference' => (string)$runtime['image_reference'],
				'digest' => (string)$runtime['image_digest'],
				'pull_policy' => 'if_missing',
			),
			'resources' => $resources,
			'ports' => array(
				'ip' => (string)$server_ip,
				'assigned_blocks' => $assigned,
				'publish' => $assigned,
			),
			'stop_timeout_seconds' => (int)$runtime['stop_timeout_seconds'],
			// Docker restarts crashes only (non-zero exit, up to 3 times). A clean stop or a
			// panel stop stays stopped; the agent restarts RUNNING servers after a reboot.
			'restart_policy' => 'on-failure:3',
		),
		'stop_command' => (string)$runtime['stop_command'],
		'timeout_seconds' => (int)$runtime['stop_timeout_seconds'],
		'readiness_port' => (int)$server_port,
	);
	return array($request, '');
}

/** Records the last container state seen for a home (best effort). */
function gse_execution_record_state($home_id, $result)
{
	$db = gse_execution_db();
	if ($db === null || !is_array($result)) {
		return;
	}
	$state = '';
	if (isset($result['result']['game_state'])) {
		$state = (string)$result['result']['game_state'];
	} elseif (isset($result['state'])) {
		$state = strtoupper((string)$result['state']);
	}
	$name = isset($result['target']['container_name']) ? (string)$result['target']['container_name'] : '';
	$hash = isset($result['result']['config_hash']) ? (string)$result['result']['config_hash'] : '';
	$sets = array("runtime_state = '" . $db->realEscapeSingle(substr($state, 0, 32)) . "'", "runtime_state_at = NOW()");
	if ($name !== '') {
		$sets[] = "container_name = '" . $db->realEscapeSingle(substr($name, 0, 64)) . "'";
	}
	if ($hash !== '') {
		$sets[] = "config_hash = '" . $db->realEscapeSingle(substr($hash, 0, 64)) . "'";
	}
	$db->query("UPDATE `DB_PREFIXhome_execution_runtime` SET " . implode(', ', $sets) . " WHERE home_id = " . (int)$home_id);
}

/** Maps a container_game_status reply to the agent server_status shape the panel uses. */
function gse_container_status_to_agent_status($result, $server_ip, $server_port, $query_port = '', $rcon_port = '')
{
	$game_state = isset($result['result']['game_state']) ? (string)$result['result']['game_state'] : 'UNKNOWN';
	$map = array(
		'ONLINE' => 'ONLINE',
		'STARTING' => 'STARTING',
		'STOPPED' => 'STOPPED',
		'CREATED' => 'STOPPED',
		'FAILED' => 'CRASHED',
		'OOM' => 'CRASHED',
		'RUNTIME_UNAVAILABLE' => 'UNKNOWN',
		'UNKNOWN' => 'UNKNOWN',
	);
	$status = isset($map[$game_state]) ? $map[$game_state] : 'UNKNOWN';
	$running = in_array($game_state, array('ONLINE', 'STARTING'), true) ? 1 : 0;
	$last_error = '';
	if ($game_state === 'OOM') {
		$last_error = 'The server ran out of memory (container memory limit).';
	} elseif ($game_state === 'FAILED') {
		$last_error = 'The server process exited with an error.';
	} elseif ($game_state === 'RUNTIME_UNAVAILABLE') {
		$last_error = 'Docker is not available on the agent.';
	} elseif ($game_state === 'UNKNOWN' && !empty($result['message'])) {
		$last_error = (string)$result['message'];
	}
	$stats = isset($result['result']['stats']) && is_array($result['result']['stats']) ? $result['result']['stats'] : array();
	return array(
		'status' => $status,
		'ready' => $game_state === 'ONLINE' ? 1 : 0,
		'process_running' => $running,
		'session_running' => $running,
		'game_port_listening' => $game_state === 'ONLINE' ? 1 : 0,
		'query_port_listening' => 0,
		'rcon_port_listening' => 0,
		'pid' => '',
		'session_name' => isset($result['target']['container_name']) ? (string)$result['target']['container_name'] : '',
		'ip' => $server_ip,
		'port' => $server_port,
		'query_port' => $query_port,
		'rcon_port' => $rcon_port,
		'last_error' => $last_error,
		'query_info' => '',
		'execution_backend' => GSE_BACKEND_DOCKER,
		'container_state' => $game_state,
		'cpu_percent' => isset($stats['cpu_percent']) ? $stats['cpu_percent'] : '',
		'memory_usage' => isset($stats['memory_usage']) ? $stats['memory_usage'] : '',
		'status_source' => 'container',
	);
}

/** Approved, enabled images for a platform. */
function gse_runtime_images($platform = 'linux')
{
	$db = gse_execution_db();
	if ($db === null) {
		return array();
	}
	$rows = $db->queryRows("SELECT * FROM `DB_PREFIXruntime_images` WHERE enabled = 1 AND platform = '" . $db->realEscapeSingle($platform) . "' ORDER BY name");
	return is_array($rows) ? $rows : array();
}

/**
 * Admin form handler on the home edit page. Changing the backend or the container settings
 * is only allowed while the server is stopped, so a running copy is never orphaned.
 * Returns a message to show, or ''.
 */
function gse_execution_backend_handle_post($db, $remote, array $home_info)
{
	if (!isset($_POST['save_execution_backend'])) {
		return '';
	}
	$home_id = (int)$home_info['home_id'];
	$current = gse_execution_runtime($home_id, true);
	if ($current['backend'] === GSE_BACKEND_ERROR) {
		return "<p class='failure'>The execution backend could not be read; nothing was changed.</p>";
	}
	$running = ($remote->is_screen_running(SCREEN_TYPE_HOME, $home_id) === 1);
	if ($running) {
		return "<p class='failure'>Stop the server before changing how it runs.</p>";
	}
	$backend = (isset($_POST['execution_backend']) && $_POST['execution_backend'] === GSE_BACKEND_DOCKER) ? GSE_BACKEND_DOCKER : GSE_BACKEND_NATIVE;
	$image_id = isset($_POST['runtime_image_id']) ? (int)$_POST['runtime_image_id'] : 0;
	$cpu = isset($_POST['cpu_cores']) ? (float)$_POST['cpu_cores'] : 2;
	$memory = isset($_POST['memory_mb']) ? (int)$_POST['memory_mb'] : 2048;
	$swap = isset($_POST['memory_swap_mb']) && $_POST['memory_swap_mb'] !== '' ? (int)$_POST['memory_swap_mb'] : null;
	$pids = isset($_POST['pids_limit']) ? (int)$_POST['pids_limit'] : 512;
	$stop_command = isset($_POST['stop_command']) ? trim((string)$_POST['stop_command']) : '';
	$stop_timeout = isset($_POST['stop_timeout_seconds']) ? (int)$_POST['stop_timeout_seconds'] : 60;

	if ($backend === GSE_BACKEND_DOCKER) {
		$images = $db->queryRows("SELECT image_id FROM `DB_PREFIXruntime_images` WHERE enabled = 1 AND image_id = " . $image_id);
		if (empty($images)) {
			return "<p class='failure'>Choose an approved image.</p>";
		}
		if ($cpu < 0.1 || $cpu > 128 || $memory < 128 || $memory > 1048576 || $pids < 32 || $pids > 8192
			|| ($swap !== null && $swap < $memory) || $stop_timeout < 5 || $stop_timeout > 300
			|| strlen($stop_command) > 255 || preg_match('/[\x00-\x1f\x7f]/', $stop_command)) {
			return "<p class='failure'>Container limits are out of range (swap must be at least the RAM limit).</p>";
		}
	}

	// Leaving Docker: remove the stopped container so it cannot be started by mistake. Files stay.
	if ($current['backend'] === GSE_BACKEND_DOCKER && $backend === GSE_BACKEND_NATIVE) {
		list($request, $error) = gse_container_request($home_id, isset($home_info['ip']) ? $home_info['ip'] : '0.0.0.0', isset($home_info['port']) ? $home_info['port'] : 1);
		if ($request !== null && method_exists($remote, 'container_game_remove_result')) {
			$removed = $remote->container_game_remove_result($home_id, $request);
			if (empty($removed['success'])) {
				return "<p class='failure'>The container could not be removed: " . htmlentities(isset($removed['message']) ? $removed['message'] : 'agent error') . "</p>";
			}
		}
	}

	$ok = $db->query(sprintf(
		"INSERT INTO `DB_PREFIXhome_execution_runtime`"
		. " (home_id, execution_backend, runtime_image_id, cpu_cores, memory_mb, memory_swap_mb, pids_limit, stop_command, stop_timeout_seconds, updated_at)"
		. " VALUES (%d, '%s', %s, %.2f, %d, %s, %d, '%s', %d, NOW())"
		. " ON DUPLICATE KEY UPDATE execution_backend = VALUES(execution_backend), runtime_image_id = VALUES(runtime_image_id),"
		. " cpu_cores = VALUES(cpu_cores), memory_mb = VALUES(memory_mb), memory_swap_mb = VALUES(memory_swap_mb),"
		. " pids_limit = VALUES(pids_limit), stop_command = VALUES(stop_command), stop_timeout_seconds = VALUES(stop_timeout_seconds), updated_at = NOW()",
		$home_id, $backend, $image_id > 0 ? (string)$image_id : 'NULL', $cpu, $memory,
		$swap === null ? 'NULL' : (string)(int)$swap, $pids, $db->realEscapeSingle($stop_command), $stop_timeout
	));
	gse_execution_runtime($home_id, true);
	if (!$ok) {
		return "<p class='failure'>The execution backend could not be saved.</p>";
	}
	$db->logger("Execution backend for HOME ID:$home_id set to $backend.");
	return "<p class='success'>Execution backend saved: " . htmlentities($backend) . ".</p>";
}

/** Admin-only section on the home edit page. Customers never see or change Docker settings. */
function gse_execution_backend_render_admin($db, array $home_info)
{
	$home_id = (int)$home_info['home_id'];
	$runtime = gse_execution_runtime($home_id);
	if ($runtime['backend'] === GSE_BACKEND_ERROR) {
		echo "<h3>Execution backend</h3><p class='failure'>Could not read the execution backend for this server.</p>";
		return;
	}
	$is_docker = $runtime['backend'] === GSE_BACKEND_DOCKER;
	$images = gse_runtime_images('linux');
	$value = function ($key, $default) use ($runtime) {
		return htmlentities(isset($runtime[$key]) && $runtime[$key] !== null ? (string)$runtime[$key] : (string)$default);
	};
	$action = "?m=user_games&amp;p=edit&amp;home_id=" . $home_id;
	echo "<h3>Execution backend</h3>";
	echo "<form method='post' action='" . $action . "'><table class='center' id='execution_backend_settings'>";
	echo "<tr><td class='right'>Runs as:</td><td class='left'><select name='execution_backend'>"
		. "<option value='native'" . ($is_docker ? '' : " selected='selected'") . ">Native (default)</option>"
		. "<option value='docker'" . ($is_docker ? " selected='selected'" : '') . ">Docker container</option>"
		. "</select></td></tr>";
	echo "<tr><td class='right'>Image:</td><td class='left'><select name='runtime_image_id'>";
	foreach ($images as $image) {
		$selected = (!empty($runtime['runtime_image_id']) && (int)$runtime['runtime_image_id'] === (int)$image['image_id']) ? " selected='selected'" : '';
		echo "<option value='" . (int)$image['image_id'] . "'" . $selected . ">" . htmlentities($image['name'] . ' - ' . $image['reference']) . "</option>";
	}
	echo "</select></td></tr>";
	echo "<tr><td class='right'>CPU cores:</td><td class='left'><input type='number' step='0.1' min='0.1' name='cpu_cores' value='" . $value('cpu_cores', '2') . "' /></td></tr>";
	echo "<tr><td class='right'>RAM limit (MB):</td><td class='left'><input type='number' min='128' name='memory_mb' value='" . $value('memory_mb', '2048') . "' /> <span class='info'>keep the Java heap (-Xmx) about 700 MB below this</span></td></tr>";
	echo "<tr><td class='right'>RAM + swap (MB):</td><td class='left'><input type='number' min='128' name='memory_swap_mb' value='" . $value('memory_swap_mb', '') . "' placeholder='empty = no swap' /></td></tr>";
	echo "<tr><td class='right'>Process limit:</td><td class='left'><input type='number' min='32' max='8192' name='pids_limit' value='" . $value('pids_limit', '512') . "' /></td></tr>";
	echo "<tr><td class='right'>Stop command:</td><td class='left'><input type='text' maxlength='255' name='stop_command' value='" . $value('stop_command', 'stop') . "' /> <span class='info'>console line that saves and stops the game</span></td></tr>";
	echo "<tr><td class='right'>Stop timeout (s):</td><td class='left'><input type='number' min='5' max='300' name='stop_timeout_seconds' value='" . $value('stop_timeout_seconds', '60') . "' /></td></tr>";
	if ($is_docker) {
		echo "<tr><td class='right'>Container:</td><td class='left'>" . $value('container_name', '-') . " &middot; last state " . $value('runtime_state', '-') . " " . $value('runtime_state_at', '') . "</td></tr>";
	}
	echo "<tr><td colspan='2' class='info'>Files, FTP, file manager, ports and the startup line stay the same. Disk quotas are not enforced in containers. Change this only while the server is stopped.</td></tr>";
	echo "<tr><td colspan='2'><input type='submit' name='save_execution_backend' value='Save execution backend' /></td></tr>";
	echo "</table></form>";
}
