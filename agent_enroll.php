<?php
/*
 * Agent installer enrollment.
 *
 * The agent installers (gse-agent-linux-install.sh, gse-agent-windows-install.ps1) call
 * this page with the agent key that was entered for the server on the panel's Servers
 * page. Each request is signed with that key: header X-GSE-Signature is
 * "sha256=" . HMAC-SHA256(key, "<X-GSE-Timestamp>.<body>"), so the key is never sent.
 * The panel finds the server whose key made the signature.
 *
 * POST {"step":"config","os":"linux|windows"}
 *   The server's settings, and the agent release that the panel's update settings
 *   (Administration > Update, release source and channel) pick for that OS.
 * POST {"step":"done","os":"linux|windows"}
 *   Sent after the agent service started. The panel connects to the agent, saves the
 *   agent's user name and IP addresses, and says whether it reached the agent.
 */

chdir(__DIR__);
require_once("includes/functions.php");
require_once("includes/helpers.php");
require_once("includes/config.inc.php");
require_once("includes/agent_event_log.php");
require_once("includes/lib_remote.php");
require_once("modules/update/release_source.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	agent_event_json_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
}

$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : NULL);
if (!$db instanceof Database) {
	agent_event_fail(503, 'database_unavailable', 'Could not connect to database.');
}
$settings = $db->getSettings();

$body = file_get_contents('php://input');
if ($body === false || strlen($body) > 4096) {
	agent_event_fail(400, 'invalid_body', 'Invalid or oversized request body.');
}
$request = json_decode($body, true);
$step = is_array($request) ? (string)($request['step'] ?? '') : '';
$os = is_array($request) ? (string)($request['os'] ?? '') : '';
if (!in_array($step, array('config', 'done'), true) || !in_array($os, array('linux', 'windows'), true)) {
	agent_event_fail(400, 'invalid_request', 'step must be config or done, os must be linux or windows.');
}

$server = enroll_find_server($db, $body);
$id = (int)$server['remote_server_id'];

if ($step === 'config') {
	$db->logger('Agent installer fetched settings for remote server ID:' . $id . ' (' . $os . ')');
	agent_event_json_response(200, array(
		'ok' => true,
		'remote_server_id' => $id,
		'name' => $server['remote_server_name'],
		'user' => $server['user'],
		'agent_ip' => $server['agent_ip'],
		'agent_port' => (int)$server['agent_port'],
		'ftp_ip' => $server['ftp_ip'],
		'ftp_port' => (int)$server['ftp_port'],
		'release' => enroll_release($os),
	));
}

// step done: reach the agent the same way the Servers page does.
$remote = new RemoteLibrary($server['agent_ip'], $server['agent_port'], $server['encryption_key'], $server['timeout']);
$status = $remote->status_chk();
if ($status !== 1) {
	agent_event_json_response(200, array('ok' => true, 'online' => false, 'status' => $status));
}
$user = trim((string)$remote->exec('echo %USERNAME%'));
if ($user === '' || $user === '%USERNAME%') {
	$user = trim((string)$remote->exec('whoami'));
}
$db->query(sprintf("UPDATE `%sremote_servers` SET `user`='%s', `server_os`='%s' WHERE `remote_server_id`=%d",
	$table_prefix, $db->realEscapeSingle($user !== '' ? $user : $server['user']), $os, $id));

$ips = array();
$known = $db->getRemoteServerIPs($id);
foreach (is_array($known) ? $known : array() as $row) {
	$ips[] = $row['ip'];
}
$found = empty($ips) ? $remote->discover_ips() : array();
if (is_array($found)) {
	foreach ($found as $ip) {
		$ip = trim((string)$ip);
		if ($ip !== '' && $db->addRemoteServerIP($id, $ip) !== FALSE) {
			$ips[] = $ip;
		}
	}
}
$db->logger('Agent installer finished for remote server ID:' . $id . '; agent online, user ' . $user);
agent_event_json_response(200, array('ok' => true, 'online' => true, 'user' => $user, 'ips' => $ips));


/* The server whose agent key signed this request. */
function enroll_find_server($db, $body)
{
	$timestamp = agent_event_header('X-GSE-Timestamp');
	$signature = (string)agent_event_header('X-GSE-Signature');
	if (!preg_match('/^\d{10}$/', $timestamp) || abs(time() - (int)$timestamp) > 300) {
		agent_event_fail(401, 'bad_timestamp', 'Missing timestamp, or the clocks differ by more than 5 minutes.');
	}
	$servers = $db->getRemoteServers();
	foreach (is_array($servers) ? $servers : array() as $row) {
		if ((string)$row['encryption_key'] === '') {
			continue;
		}
		$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $row['encryption_key']);
		if (hash_equals($expected, $signature)) {
			return $row;
		}
	}
	agent_event_fail(401, 'unknown_key', 'No server on the Servers page has this agent key.');
}

/*
 * The agent release for this OS from the panel's release settings: the newest stable
 * release that has a .sha256 file (when the channel is stable), else the newest
 * testing-* release that has one. Null when none is found; the installer then looks
 * on GitHub itself.
 */
function enroll_release($os)
{
	$cfg = release_source_settings($os === 'windows' ? 'agent_windows' : 'agent_linux');
	$rows = release_fetch_releases_live($cfg['repo_url'], read_release_token($cfg), release_provider_from_url($cfg['repo_url']));
	if (!is_array($rows)) {
		return null;
	}
	$want = ($cfg['channel'] === 'stable') ? array('stable', 'testing') : array('testing');
	foreach ($want as $kind) {
		foreach ($rows as $row) {
			$is_testing = (strpos((string)$row['tag'], 'testing-') === 0);
			if (($kind === 'stable' && !empty($row['prerelease'])) || ($kind === 'testing' && !$is_testing)) {
				continue;
			}
			foreach ((array)($row['assets'] ?? array()) as $name => $url) {
				if (preg_match('/\.zip$/i', $name) && isset($row['assets'][$name . '.sha256'])) {
					return array('tag' => $row['tag'], 'url' => $url, 'sha256_url' => $row['assets'][$name . '.sha256']);
				}
			}
		}
	}
	return null;
}
