<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * GSP Panel Update System
 * Repository-layout aware updater with backup, rollback, patching, and apache path helpers.
 *
 */

defined('GSP_PANEL_DIR') || define('GSP_PANEL_DIR', realpath(dirname(__FILE__) . '/../../'));
defined('GSP_ROOT_DIR') || define('GSP_ROOT_DIR', GSP_PANEL_DIR);
defined('GSP_WEBSITE_DIR') || define('GSP_WEBSITE_DIR', GSP_PANEL_DIR . '/modules/billing');
defined('GSP_BACKUP_BASE') || define('GSP_BACKUP_BASE', GSP_ROOT_DIR . '/backups');
defined('GSP_UPDATE_LOG') || define('GSP_UPDATE_LOG', GSP_ROOT_DIR . '/logs/update_trace.log');
defined('GSP_VERSION_FILE') || define('GSP_VERSION_FILE', GSP_PANEL_DIR . '/includes/panel_version.php');
defined('GSP_VERSION_JSON') || define('GSP_VERSION_JSON', GSP_ROOT_DIR . '/version.json');
defined('GSP_MIGRATIONS_DIR') || define('GSP_MIGRATIONS_DIR', GSP_ROOT_DIR . '/sql/migrations');
defined('GSP_CANONICAL_TIMESTAMP_FILE') || define('GSP_CANONICAL_TIMESTAMP_FILE', GSP_PANEL_DIR . '/modules/billing/timestamp.txt');
defined('GSP_BILLING_TIMESTAMP_FILE') || define('GSP_BILLING_TIMESTAMP_FILE', GSP_PANEL_DIR . '/modules/billing/timestamp.txt');
defined('GSP_LAST_UPDATE_FILE') || define('GSP_LAST_UPDATE_FILE', GSP_ROOT_DIR . '/LAST_UPDATE.txt');
defined('GSP_DEFAULT_REPO_URL') || define('GSP_DEFAULT_REPO_URL', 'https://github.com/GameServerEngine/gse-panel.git');
defined('GSP_DEFAULT_BRANCH') || define('GSP_DEFAULT_BRANCH', 'unstable');
defined('GSP_DEFAULT_RELEASE_BASE_URL') || define('GSP_DEFAULT_RELEASE_BASE_URL', 'https://github.com/GameServerEngine/gse-panel/archive/refs/heads');
defined('GSP_DEFAULT_RELEASE_CHANNEL') || define('GSP_DEFAULT_RELEASE_CHANNEL', 'stable');
defined('GSP_DEFAULT_REPO_ROOT') || define('GSP_DEFAULT_REPO_ROOT', GSP_PANEL_DIR);
defined('GSP_DEFAULT_PANEL_PATH') || define('GSP_DEFAULT_PANEL_PATH', GSP_PANEL_DIR);
defined('GSP_DEFAULT_PANEL_SOURCE') || define('GSP_DEFAULT_PANEL_SOURCE', '.');

$gspMigrationRunner = GSP_PANEL_DIR . '/modules/update/migration_runner.php';
if (file_exists($gspMigrationRunner)) {
require_once($gspMigrationRunner);
}
$gspReleaseSource = GSP_PANEL_DIR . '/modules/update/release_source.php';
if (file_exists($gspReleaseSource)) {
require_once($gspReleaseSource);
}

function gsp_update_log($message)
{
$log_dir = dirname(GSP_UPDATE_LOG);
if (!is_dir($log_dir)) {
@mkdir($log_dir, 0755, true);
}
$line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
@file_put_contents(GSP_UPDATE_LOG, $line, FILE_APPEND | LOCK_EX);
}

function gsp_path_debug_summary($path)
{
$path = rtrim((string)$path, '/');
if ($path === '') {
return '[empty path]';
}
$parent = dirname($path);
$parts = array(
'path=' . $path,
'exists=' . (file_exists($path) ? 'yes' : 'no'),
'is_dir=' . (is_dir($path) ? 'yes' : 'no'),
'writable=' . (is_writable($path) ? 'yes' : 'no'),
'parent=' . $parent,
'parent_exists=' . (file_exists($parent) ? 'yes' : 'no'),
'parent_writable=' . (is_writable($parent) ? 'yes' : 'no'),
);
return implode(', ', $parts);
}

function gsp_ensure_directory($path, $label = 'directory')
{
$path = rtrim((string)$path, '/');
if ($path === '' || strpos($path, "\0") !== false) {
$message = 'Invalid ' . $label . ' path.';
gsp_update_log($message . ' ' . gsp_path_debug_summary($path));
return array('success' => false, 'error' => $message);
}
if (is_dir($path)) {
if (!is_writable($path)) {
$message = ucfirst($label) . ' exists but is not writable: ' . $path;
gsp_update_log($message . ' [' . gsp_path_debug_summary($path) . ']');
return array('success' => false, 'error' => $message);
}
return array('success' => true, 'path' => $path, 'created' => false);
}
if (!@mkdir($path, 0755, true) && !is_dir($path)) {
$last = error_get_last();
$detail = isset($last['message']) ? $last['message'] : 'mkdir returned false';
$message = 'Cannot create ' . $label . ': ' . $path;
gsp_update_log($message . ' [' . $detail . '] [' . gsp_path_debug_summary($path) . ']');
return array('success' => false, 'error' => $message . ' (' . $detail . ')');
}
if (!is_writable($path)) {
$message = ucfirst($label) . ' was created but is not writable: ' . $path;
gsp_update_log($message . ' [' . gsp_path_debug_summary($path) . ']');
return array('success' => false, 'error' => $message);
}
return array('success' => true, 'path' => $path, 'created' => true);
}

function gsp_get_backup_base(array $update_cfg = null)
{
$update_cfg = $update_cfg ?: gsp_update_settings();
$base = !empty($update_cfg['backup_path']) ? rtrim((string)$update_cfg['backup_path'], '/') : GSP_BACKUP_BASE;
if ($base === '' || strpos($base, "\0") !== false || strpos($base, '..') !== false || strpos($base, '/') !== 0) {
$base = GSP_BACKUP_BASE;
}
return $base;
}

function gsp_log_update_to_db($channel, $branch, $status, $message, $backup_path = null, $db_backup_path = null, $file_backup_path = null, $started_at = null, $finished_at = null)
{
global $db;
if (!isset($db) || !is_object($db)) {
return;
}
if ($started_at === null) {
$started_at = date('Y-m-d H:i:s');
}
$channel = $db->real_escape_string((string)$channel);
$branch = $branch !== null ? "'" . $db->real_escape_string((string)$branch) . "'" : 'NULL';
$status = $db->real_escape_string((string)$status);
$message_esc = $message !== null ? "'" . $db->real_escape_string((string)$message) . "'" : 'NULL';
$backup_path_esc = $backup_path !== null ? "'" . $db->real_escape_string((string)$backup_path) . "'" : 'NULL';
$db_backup_esc = $db_backup_path !== null ? "'" . $db->real_escape_string((string)$db_backup_path) . "'" : 'NULL';
$file_backup_esc = $file_backup_path !== null ? "'" . $db->real_escape_string((string)$file_backup_path) . "'" : 'NULL';
$started_esc = "'" . $db->real_escape_string((string)$started_at) . "'";
$finished_esc = $finished_at !== null ? "'" . $db->real_escape_string((string)$finished_at) . "'" : 'NULL';
$db->query(
"INSERT INTO GSP_DB_PREFIXpanel_update_log"
. " (channel, branch, status, message, backup_path, db_backup_path, file_backup_path, started_at, finished_at)"
. " VALUES ('{$channel}', {$branch}, '{$status}', {$message_esc}, {$backup_path_esc}, {$db_backup_esc}, {$file_backup_esc}, {$started_esc}, {$finished_esc})"
);
}

function gsp_random_token($bytes = 16)
{
if (function_exists('random_bytes')) {
try {
return bin2hex(random_bytes($bytes));
} catch (Exception $e) {
}
}
if (function_exists('openssl_random_pseudo_bytes')) {
return bin2hex(openssl_random_pseudo_bytes($bytes));
}
return sha1(uniqid('gsp', true) . mt_rand());
}

function gsp_detect_repo_root()
{
$candidates = [
GSP_ROOT_DIR,
GSP_PANEL_DIR,
dirname(GSP_ROOT_DIR),
];
foreach ($candidates as $candidate) {
$gitPath = $candidate . '/.git';
if (is_dir($gitPath) || is_file($gitPath)) {
return realpath($candidate) ?: $candidate;
}
}
return null;
}

function gsp_get_panel_update_root()
{
$root = realpath(GSP_PANEL_DIR);
return $root !== false ? rtrim($root, '/') : rtrim(GSP_PANEL_DIR, '/');
}

if (!function_exists('update_badge')) {
function update_badge($current, $latest)
{
	if ($latest === '') {
		return 'No releases found';
	}
	if ($current === '' || $current === 'unknown') {
		return "<span style='color:#8a6d3b;'>Version unknown</span>";
	}
	if (version_compare(ltrim($latest, 'vV'), ltrim($current, 'vV'), '>')) {
		return "<span style='color:#8a6d3b;'><strong>Update available:</strong> " . htmlspecialchars($latest) . "</span>";
	}
	return 'Up to date';
}
}

if (!function_exists('tag_select')) {
function tag_select($name, $tags)
{
	$html = "<select name='" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "'>";
	if (!is_array($tags) || empty($tags)) {
		return $html . "<option value=''>No releases found</option></select>";
	}
	foreach ($tags as $tag) {
		$t = htmlspecialchars((string)$tag, ENT_QUOTES, 'UTF-8');
		$html .= "<option value='" . $t . "'>" . $t . "</option>";
	}
	return $html . "</select>";
}
}

if (!function_exists('update_panel')) {
function update_panel($db, array $update_cfg, $restart_nonce, $panel_tag)
{
	$panel_tag = trim((string)$panel_tag);
	if ($panel_tag === '') {
		print_failure('Panel update skipped: no release picked on the Panel row.');
		return;
	}
	$panel_source = gsp_release_source_settings('panel');
	$custom_release_zip_url = gsp_release_archive_url($panel_source['repo_url'], $panel_tag);
	$update_cfg['release_zip_url'] = $custom_release_zip_url;
	$errors = gsp_validate_update_settings($update_cfg);
	if (!empty($errors)) {
		$result = array('success' => false, 'error' => implode(' | ', $errors));
	} else {
		$db->setSettings(array(
			'gsp_update_channel' => $update_cfg['channel'],
			'gsp_update_release_zip_url' => $custom_release_zip_url,
			'gsp_update_branch' => $update_cfg['channel'],
		));
		if (!function_exists('gsp_do_configured_archive_update')) {
			$result = array('success' => false, 'error' => 'Configured release ZIP updater helper is unavailable. Deploy the current modules/administration/panel_update.php and reload.');
		} else {
			$result = gsp_do_configured_archive_update($update_cfg, $restart_nonce);
		}
	}
	if ($result['success']) {
		print_success('Panel updated to <strong>' . htmlspecialchars($panel_tag) . '</strong>. ' . intval($result['files_copied']) . ' file(s) copied. Backup: <code>' . htmlspecialchars($result['backup_name']) . '</code>.');
	} else {
		print_failure('Panel update failed: ' . htmlspecialchars($result['error']));
	}
}
}

if (!function_exists('update_agents')) {
function update_agents($db, array $ids, array $row_tags)
{
	if (!function_exists('gsp_agent_perform_release_update')) {
		require_once __DIR__ . '/../server/agent_helpers.php';
	}
	$ids = gsp_agent_sanitize_ids($ids);
	$checksums = array();
	$outcomes = array();
	foreach ($ids as $id) {
		$row = $db->getRemoteServerById($id);
		if (!$row) {
			$outcomes[] = array('name' => '#' . $id, 'success' => false, 'message' => 'Agent not found.');
			continue;
		}
		$snap = gsp_agent_get_snapshot($db, $id);
		$os = (string)gsp_agent_snapshot_field($snap, 'os', '');
		$group = gsp_agent_component_from_os($os);
		if ($group !== 'windows_agent' && $group !== 'linux_agent') {
			$outcomes[] = array('name' => $row['remote_server_name'], 'success' => false, 'message' => 'Operating system not recognised; cannot pick a release type.');
			continue;
		}
		$release_component = gsp_release_component_for_agent($group);
		$tag = isset($row_tags[$id]) ? trim((string)$row_tags[$id]) : '';
		if ($tag === '') {
			$outcomes[] = array('name' => $row['remote_server_name'], 'success' => false, 'message' => 'No release picked on this row.');
			continue;
		}
		$src = gsp_release_source_settings($release_component);
		$url = gsp_release_archive_url($src['repo_url'], $tag);
		if (!array_key_exists($url, $checksums)) {
			$checksums[$url] = gsp_release_archive_sha256($url);
		}
		if ($checksums[$url] === null) {
			$outcomes[] = array('name' => $row['remote_server_name'], 'success' => false, 'message' => 'Could not download the release archive to check it.');
			continue;
		}
		$outcomes[] = gsp_agent_perform_release_update($db, $row, $url, $checksums[$url], $tag);
	}
	echo "<h3>Agent Update Results</h3>\n";
	if (empty($outcomes)) {
		echo "<p class='failure'>No agents were selected.</p>\n";
	} else {
		echo "<table class='center'><tr><th>Agent</th><th>Result</th></tr>\n";
		foreach ($outcomes as $o) {
			$badge = $o['success'] ? "<span style='color:green;'>Started</span>" : "<span style='color:#a94442;'>Failed</span>";
			echo "<tr><td>" . htmlspecialchars((string)$o['name']) . "</td><td>" . $badge . " " . htmlspecialchars((string)$o['message']) . "</td></tr>\n";
		}
		echo "</table>\n";
	}
}
}

function gsp_get_current_version()
{
if (file_exists(GSP_VERSION_FILE)) {
$code = @file_get_contents(GSP_VERSION_FILE);
if (preg_match("/define\('GSP_VERSION',\s*'([^']+)'\)/", (string)$code, $m)) {
return $m[1];
}
}
return 'unknown';
}

function gsp_get_current_branch()
{
$repo_root = gsp_detect_repo_root();
if ($repo_root && function_exists('exec')) {
$out = [];
$ret = 0;
exec('git -C ' . escapeshellarg($repo_root) . ' rev-parse --abbrev-ref HEAD 2>/dev/null', $out, $ret);
if ($ret === 0 && !empty($out[0])) {
return trim($out[0]);
}
}
return 'unknown';
}

function gsp_get_git_commit()
{
$repo_root = gsp_detect_repo_root();
if (!$repo_root || !function_exists('exec')) {
return null;
}
$out = [];
$ret = 0;
exec('git -C ' . escapeshellarg($repo_root) . ' rev-parse HEAD 2>/dev/null', $out, $ret);
if ($ret === 0 && !empty($out[0])) {
$sha = trim($out[0]);
if (preg_match('/^[0-9a-f]{40,64}$/i', $sha)) {
return $sha;
}
}
return null;
}

function gsp_release_channel_url($channel)
{
$channel = (string)$channel;
if (!in_array($channel, ['stable', 'unstable'], true)) {
$channel = GSP_DEFAULT_RELEASE_CHANNEL;
}
return rtrim(GSP_DEFAULT_RELEASE_BASE_URL, '/') . '/' . $channel . '.zip';
}

function gsp_is_valid_release_channel($channel)
{
return in_array((string)$channel, ['stable', 'unstable'], true);
}

function gsp_is_valid_release_zip_url($url)
{
$url = trim((string)$url);
return preg_match('/^https?:\/\/[^\s]+\.zip(?:[?#][^\s]*)?$/i', $url) === 1;
}

function gsp_release_custom_url_value(array $cfg)
{
$url = isset($cfg['release_zip_url']) ? trim((string)$cfg['release_zip_url']) : '';
$derived = gsp_release_channel_url(isset($cfg['channel']) ? (string)$cfg['channel'] : GSP_DEFAULT_RELEASE_CHANNEL);
return ($url !== '' && $url !== $derived) ? $url : '';
}

function gsp_update_settings()
{
global $settings;
$panel_root = gsp_get_panel_update_root();
$repo_url = !empty($settings['gsp_update_repo_url']) ? (string)$settings['gsp_update_repo_url'] : GSP_DEFAULT_REPO_URL;
if (strpos($repo_url, 'forge.runlevelsystems.com/GSP/Panel.git') !== false
|| $repo_url === 'http://git.runlevelsystems.com/GSP/Panel.git'
|| $repo_url === 'https://git.runlevelsystems.com/GSP/Panel.git'
|| $repo_url === 'ssh://git@173.208.136.14/GSP/Panel.git') {
$repo_url = GSP_DEFAULT_REPO_URL;
}
$channel = !empty($settings['gsp_update_channel']) ? (string)$settings['gsp_update_channel'] : '';
if (!gsp_is_valid_release_channel($channel)) {
$legacy_branch = !empty($settings['gsp_update_branch']) ? (string)$settings['gsp_update_branch'] : '';
$channel = gsp_is_valid_release_channel($legacy_branch) ? $legacy_branch : GSP_DEFAULT_RELEASE_CHANNEL;
}
$release_zip_url = !empty($settings['gsp_update_release_zip_url']) ? trim((string)$settings['gsp_update_release_zip_url']) : '';
if ($release_zip_url === '') {
$release_zip_url = gsp_release_channel_url($channel);
}
return [
'repo_url' => $repo_url,
'branch' => !empty($settings['gsp_update_branch']) ? (string)$settings['gsp_update_branch'] : GSP_DEFAULT_BRANCH,
'channel' => $channel,
'release_zip_url' => $release_zip_url,
'repo_root' => $panel_root,
'panel_path' => $panel_root,
'panel_source_path' => GSP_DEFAULT_PANEL_SOURCE,
	'git_path' => 'git',
	'backup_path' => GSP_BACKUP_BASE,
	'panel_post_update_command' => '',
'backup_before_update' => '1',
];
}

function gsp_validate_update_settings(array $cfg)
{
$errors = [];
$channel = isset($cfg['channel']) ? (string)$cfg['channel'] : GSP_DEFAULT_RELEASE_CHANNEL;
if (!gsp_is_valid_release_channel($channel)) {
$errors[] = 'Release channel must be stable or unstable.';
}
$release_zip_url = isset($cfg['release_zip_url']) && trim((string)$cfg['release_zip_url']) !== ''
? trim((string)$cfg['release_zip_url'])
: gsp_release_channel_url($channel);
if (!gsp_is_valid_release_zip_url($release_zip_url)) {
$errors[] = 'Release ZIP URL must be an http(s) .zip URL.';
}
foreach (['repo_root', 'panel_path'] as $key) {
if (trim((string)$cfg[$key]) === '' || strpos((string)$cfg[$key], "\0") !== false || strpos((string)$cfg[$key], '..') !== false) {
$errors[] = ucfirst(str_replace('_', ' ', $key)) . ' is invalid.';
}
}
foreach (['backup_path'] as $key) {
if (isset($cfg[$key]) && (trim((string)$cfg[$key]) === '' || strpos((string)$cfg[$key], "\0") !== false || strpos((string)$cfg[$key], '..') !== false || strpos((string)$cfg[$key], '/') !== 0)) {
$errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be a safe absolute path.';
}
}
foreach (['panel_source_path'] as $key) {
$value = isset($cfg[$key]) ? trim((string)$cfg[$key], '/') : '';
if ($value === '' || strpos($value, "\0") !== false || strpos($value, '..') !== false || !preg_match('/^[A-Za-z0-9._\/-]+$/', $value)) {
$errors[] = ucfirst(str_replace('_', ' ', $key)) . ' is invalid.';
}
}
foreach (['panel_post_update_command'] as $key) {
if (isset($cfg[$key]) && (strpos((string)$cfg[$key], "\0") !== false || strpos((string)$cfg[$key], "\n") !== false || strpos((string)$cfg[$key], "\r") !== false)) {
$errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be a single-line admin command.';
}
}
if (rtrim((string)$cfg['panel_path'], '/') !== rtrim((string)$cfg['repo_root'], '/')) {
$errors[] = 'Panel Path must point to the repository root for the flattened Panel layout.';
}
return $errors;
}

function gsp_read_version_json()
{
if (!file_exists(GSP_VERSION_JSON)) {
return null;
}
$data = json_decode(@file_get_contents(GSP_VERSION_JSON), true);
return is_array($data) ? $data : null;
}

function gsp_write_version_file($version, $branch_or_type)
{
$content = "<?php\n";
$content .= "// GSP Panel Version - written by the panel update system. Do not edit manually.\n";
$content .= "define('GSP_VERSION', " . var_export($version, true) . ");\n";
$content .= "define('GSP_BRANCH', " . var_export($branch_or_type, true) . ");\n";
$content .= "define('GSP_UPDATE_TIME', " . var_export(date('Y-m-d H:i:s'), true) . ");\n";
@file_put_contents(GSP_VERSION_FILE, $content);
}

function gsp_write_version_json($installed_type, $installed_source, $installed_version, $installed_commit = null)
{
$data = [
'installed_type' => (string)$installed_type,
'installed_source' => (string)$installed_source,
'installed_version' => (string)$installed_version,
'installed_commit' => $installed_commit,
'installed_at' => date('Y-m-d H:i:s'),
];
@file_put_contents(GSP_VERSION_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function gsp_preflight_check(array $update_cfg = null)
{
$errors = [];
$warnings = [];
$update_cfg = $update_cfg ?: gsp_update_settings();
$cwd = getcwd();
$cwd_real = $cwd ? (realpath($cwd) ?: $cwd) : '';
	$root_path = rtrim((string)$update_cfg['repo_root'], '/');
	$panel_path = rtrim((string)$update_cfg['panel_path'], '/');
	$backup_base = gsp_get_backup_base($update_cfg);
	$root_real = realpath($root_path) ?: $root_path;
$panel_real = realpath($panel_path) ?: $panel_path;
$layout = [
'cwd' => $cwd,
'cwd_real' => $cwd_real,
'expected_root' => $root_path,
'expected_panel' => $panel_path,
'gsp_root' => $root_path,
'gsp_root_real' => $root_real,
'panel_dir' => $panel_path,
'panel_dir_real' => $panel_real,
	'backup_dir' => $backup_base,
'config_file' => $panel_path . '/includes/config.inc.php',
'destination_panel' => $panel_path,
];

if (!$layout['cwd']) {
$errors[] = 'Unable to read current working directory.';
} elseif (strpos($cwd_real, $panel_real) !== 0) {
$warnings[] = 'Current working directory is not under configured Panel path: ' . $panel_real;
}
if (!is_dir($root_path)) {
$errors[] = 'Detected GSP root path is missing.';
}
if (!is_dir($panel_path)) {
$errors[] = 'Panel directory is missing.';
}
if (!file_exists($layout['config_file'])) {
$errors[] = 'Panel includes/config.inc.php was not found and cannot be preserved.';
}
	$backup_ready = gsp_ensure_directory($backup_base, 'backup directory');
	if (!$backup_ready['success']) {
	$errors[] = $backup_ready['error'];
	}

	foreach ([$root_path, $panel_path, $backup_base] as $path) {
if (!is_writable($path)) {
$errors[] = 'Path is not writable: ' . $path;
}
}

gsp_update_log('Preflight layout: ' . json_encode($layout));
foreach ($warnings as $warning) {
gsp_update_log('Preflight warning: ' . $warning);
}
foreach ($errors as $error) {
gsp_update_log('Preflight error: ' . $error);
}

return [
'success' => empty($errors),
'errors' => $errors,
'warnings' => $warnings,
'layout' => $layout,
];
}

function gsp_load_panel_db_config()
{
$config_file = GSP_PANEL_DIR . '/includes/config.inc.php';
if (!is_readable($config_file)) {
return null;
}
$capture = static function ($__file) {
$db_host = 'localhost';
$db_port = '3306';
$db_user = '';
$db_pass = '';
$db_name = '';
@include $__file;
return [
'host' => (string)$db_host,
'port' => (string)$db_port,
'user' => (string)$db_user,
'pass' => (string)$db_pass,
'name' => (string)$db_name,
];
};
$cfg = $capture($config_file);
if (empty($cfg['user']) || empty($cfg['name'])) {
return null;
}
return $cfg;
}

function gsp_create_database_backup($backup_dir, $db_config)
{
$sql_file = $backup_dir . '/database.sql';
$check = [];
$ret = 0;
exec('command -v mysqldump 2>/dev/null', $check, $ret);
if ($ret !== 0 || empty($check[0])) {
return ['success' => false, 'error' => 'mysqldump is not available.'];
}
$creds_file = tempnam(sys_get_temp_dir(), 'gsp_db_');
if ($creds_file === false) {
return ['success' => false, 'error' => 'Cannot create temporary credential file.'];
}
$creds = "[client]\n"
. "user=" . addcslashes($db_config['user'], "\\\n") . "\n"
. "password=" . addcslashes($db_config['pass'], "\\\n") . "\n";
if (!empty($db_config['host'])) {
$creds .= "host=" . addcslashes($db_config['host'], "\\\n") . "\n";
}
if (!empty($db_config['port']) && $db_config['port'] !== '3306') {
$creds .= "port=" . addcslashes($db_config['port'], "\\\n") . "\n";
}
@file_put_contents($creds_file, $creds);
@chmod($creds_file, 0600);
$err_tmp = tempnam(sys_get_temp_dir(), 'gsp_db_err_');
if ($err_tmp === false) {
@unlink($creds_file);
return ['success' => false, 'error' => 'Cannot create temporary error capture file.'];
}
$cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($creds_file)
. ' --skip-opt --single-transaction --add-drop-table --create-options --extended-insert --quick --set-charset'
. ' ' . escapeshellarg($db_config['name'])
. ' > ' . escapeshellarg($sql_file)
. ' 2> ' . escapeshellarg($err_tmp);
$unused = [];
$ret = 0;
exec($cmd, $unused, $ret);
@unlink($creds_file);
$err = trim((string)@file_get_contents($err_tmp));
@unlink($err_tmp);
if ($ret !== 0) {
@unlink($sql_file);
return ['success' => false, 'error' => 'mysqldump failed: ' . $err];
}
if (!file_exists($sql_file) || filesize($sql_file) < 100) {
@unlink($sql_file);
return ['success' => false, 'error' => 'Database dump file missing or empty.'];
}
return ['success' => true, 'file' => $sql_file];
}

function gsp_create_archive($source_dir, $archive_file, array $excludes = [])
{
$check = [];
$ret = 0;
exec('command -v tar 2>/dev/null', $check, $ret);
if ($ret !== 0 || empty($check[0])) {
return ['success' => false, 'error' => 'tar is not available.'];
}
if (!is_dir($source_dir)) {
return ['success' => false, 'error' => 'Source directory missing: ' . $source_dir];
}
$exclude_args = '';
foreach ($excludes as $exclude) {
$exclude_args .= ' --exclude=' . escapeshellarg($exclude);
}
$cmd = 'tar -czf ' . escapeshellarg($archive_file)
. $exclude_args
. ' -C ' . escapeshellarg($source_dir)
. ' . 2>&1';
$out = [];
$ret = 0;
exec($cmd, $out, $ret);
if ($ret !== 0) {
@unlink($archive_file);
return ['success' => false, 'error' => 'tar failed: ' . implode(' | ', $out)];
}
if (!file_exists($archive_file) || filesize($archive_file) < 100) {
@unlink($archive_file);
return ['success' => false, 'error' => 'Archive missing or empty: ' . $archive_file];
}
return ['success' => true, 'file' => $archive_file];
}

function gsp_backup_apache_configs($backup_dir)
{
$available_source = '/etc/apache2/sites-available';
$enabled_source = '/etc/apache2/sites-enabled';
if (!is_dir($available_source)) {
return ['success' => false, 'error' => 'Apache sites-available path not found: ' . $available_source];
}
$dest = $backup_dir . '/apache-configs';
$dest_available = $dest . '/sites-available';
$dest_enabled = $dest . '/sites-enabled';
if (!@mkdir($dest_available, 0755, true) && !is_dir($dest_available)) {
return ['success' => false, 'error' => 'Cannot create apache backup directory.'];
}
if (!@mkdir($dest_enabled, 0755, true) && !is_dir($dest_enabled)) {
return ['success' => false, 'error' => 'Cannot create apache enabled backup directory.'];
}
$count = 0;
foreach ((glob($available_source . '/*.conf') ?: []) as $file) {
if (@copy($file, $dest_available . '/' . basename($file))) {
$count++;
}
}
if (is_dir($enabled_source)) {
foreach ((glob($enabled_source . '/*') ?: []) as $file) {
$dst = $dest_enabled . '/' . basename($file);
if (is_link($file)) {
$target = @readlink($file);
if ($target !== false) {
@symlink($target, $dst);
$count++;
}
} elseif (is_file($file) && @copy($file, $dst)) {
$count++;
}
}
}
return ['success' => true, 'path' => $dest, 'count' => $count];
}

function gsp_get_managed_backup_entries($backup_base = null)
{
	$backup_base = $backup_base ? rtrim((string)$backup_base, '/') : gsp_get_backup_base();
	$entries = array();
	if (!is_dir($backup_base)) {
		return $entries;
	}
	foreach ((array)scandir($backup_base) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		$dir = $backup_base . '/' . $entry;
		if (!is_dir($dir)) {
			continue;
		}
		$type = null;
		$stamp = null;
		if (preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})$/', $entry, $m)) {
			$type = 'full';
			$stamp = $m[1];
		} elseif (preg_match('/^([A-Za-z0-9._-]+)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})$/', $entry, $m)) {
			$type = $m[1];
			$stamp = $m[2];
		} elseif (preg_match('/^component_[A-Za-z0-9._-]+_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})$/', $entry, $m)) {
			$type = 'component';
			$stamp = $m[1];
		} else {
			continue;
		}
		$meta_file = $dir . '/backup.json';
		$meta = array();
		if (is_file($meta_file)) {
			$meta = json_decode((string)@file_get_contents($meta_file), true) ?: array();
		}
		$sort_time = strtotime(str_replace('_', ' ', $stamp));
		if ($sort_time === false) {
			$sort_time = @filemtime($dir) ?: 0;
		}
		$entries[] = array(
			'name' => $entry,
			'path' => $dir,
			'type' => $type,
			'ts' => $stamp,
			'sort_time' => $sort_time,
			'meta' => $meta,
		);
	}
	usort($entries, function ($a, $b) {
		if ($a['sort_time'] === $b['sort_time']) {
			return strcmp($b['name'], $a['name']);
		}
		return ($a['sort_time'] < $b['sort_time']) ? 1 : -1;
	});
	return $entries;
}

function gsp_prune_old_backups($max_backups = 5, $backup_base = null)
{
	$entries = gsp_get_managed_backup_entries($backup_base);
	if (count($entries) <= $max_backups) {
		return array('success' => true, 'deleted' => array());
	}
	$to_delete = array_slice($entries, $max_backups);
	$deleted = array();
	foreach ($to_delete as $entry) {
		gsp_rmdir_recursive($entry['path']);
		$deleted[] = $entry['name'];
		gsp_update_log('Pruned old backup: ' . $entry['path']);
	}
	return array('success' => true, 'deleted' => $deleted);
}

function gsp_create_full_backup($update_target_type, $update_target_version, $include_apache = false, array $update_cfg = null)
{
	$ts = date('Y-m-d_H-i-s');
	$name_prefix = preg_replace('/[^A-Za-z0-9._-]+/', '-', strtolower((string)$update_target_type));
	$name_prefix = trim((string)$name_prefix, '-');
	if ($name_prefix === '') {
		$name_prefix = 'panel-backup';
	}
	$backup_name = $name_prefix . '_' . $ts;
	$backup_base = gsp_get_backup_base($update_cfg);
	$backup_base_ready = gsp_ensure_directory($backup_base, 'backup base directory');
	if (!$backup_base_ready['success']) {
		return array('success' => false, 'error' => $backup_base_ready['error']);
	}
	$backup_dir = $backup_base . '/' . $backup_name;
	$backup_dir_ready = gsp_ensure_directory($backup_dir, 'backup directory');
	if (!$backup_dir_ready['success']) {
		return array('success' => false, 'error' => $backup_dir_ready['error']);
	}

	$meta = [
	'backup_name' => $backup_name,
	'backup_timestamp' => $ts,
	'backup_base' => $backup_base,
	'gsp_root' => GSP_ROOT_DIR,
'panel_root' => GSP_PANEL_DIR,
'update_target_type' => $update_target_type,
'update_target_version' => $update_target_version,
'created_at' => date('Y-m-d H:i:s'),
];

$db_config = gsp_load_panel_db_config();
if ($db_config !== null) {
$db_backup = gsp_create_database_backup($backup_dir, $db_config);
if (!$db_backup['success']) {
return ['success' => false, 'error' => $db_backup['error']];
}
$meta['database_backup'] = basename($db_backup['file']);
}

$panel_backup = gsp_create_archive(
GSP_PANEL_DIR,
$backup_dir . '/panel-files.tar.gz',
['./backups', './logs', './cache', './tmp', './node_modules', './vendor']
);
if (!$panel_backup['success']) {
return ['success' => false, 'error' => $panel_backup['error']];
}
$meta['panel_archive'] = basename($panel_backup['file']);

if (file_exists(GSP_VERSION_JSON)) {
@copy(GSP_VERSION_JSON, $backup_dir . '/version.json.bak');
$meta['version_json_backup'] = 'version.json.bak';
}

if ($include_apache) {
$apache_backup = gsp_backup_apache_configs($backup_dir);
if ($apache_backup['success']) {
$meta['apache_backup'] = $apache_backup['path'];
} else {
$meta['apache_backup_error'] = $apache_backup['error'];
}
	}

	@file_put_contents($backup_dir . '/backup.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	// Exactly one restore point: now that the new backup is complete, remove every
	// older one. The old backup is never deleted before the new one finishes.
	$prune = gsp_prune_old_backups(1, $backup_base);
	if (!empty($prune['deleted'])) {
		gsp_update_log('Replaced previous restore point in ' . $backup_base . ': ' . implode(', ', $prune['deleted']));
	}
	gsp_update_log('Restore point created: ' . $backup_dir);

	return [
	'success' => true,
	'backup_base' => $backup_base,
	'backup_dir' => $backup_dir,
	'backup_name' => $backup_name,
	'backup_ts' => $ts,
	];
}

function gsp_normalize_rel($path)
{
$path = str_replace('\\', '/', $path);
$path = ltrim($path, '/');
return $path;
}

function gsp_copy_file($src, $dst)
{
$parent = dirname($dst);
if (!is_dir($parent)) {
@mkdir($parent, 0755, true);
}
return @copy($src, $dst);
}

function gsp_runtime_state_paths()
{
return [
'includes/config.inc.php',
'includes/install.state.json',
'includes/install.maintenance',
'modules/billing/includes/config.inc.php',
'modules/billing/site_config.local.php',
'status/api/config.php',
'status_api_local.php',
];
}

function gsp_create_runtime_state_snapshot($panel_dir)
{
$snapshot_dir = sys_get_temp_dir() . '/gsp_runtime_state_' . getmypid() . '_' . gsp_random_token(8);
if (!@mkdir($snapshot_dir, 0700, true) && !is_dir($snapshot_dir)) {
return ['success' => false, 'error' => 'Cannot create temporary runtime-state snapshot directory.'];
}
$files = [];
foreach (gsp_runtime_state_paths() as $relative) {
$source = rtrim($panel_dir, '/') . '/' . $relative;
if (!is_file($source)) {
continue;
}
$dest = $snapshot_dir . '/' . $relative;
if (!gsp_copy_file($source, $dest)) {
gsp_rmdir_recursive($snapshot_dir);
return ['success' => false, 'error' => 'Cannot preserve runtime file before update: ' . $relative];
}
$files[] = $relative;
}
return ['success' => true, 'snapshot_dir' => $snapshot_dir, 'files' => $files];
}

function gsp_restore_runtime_state_snapshot(array $snapshot, $panel_dir)
{
if (empty($snapshot['snapshot_dir']) || !is_dir($snapshot['snapshot_dir'])) {
return ['success' => true, 'restored' => 0];
}
$restored = 0;
foreach ((array)$snapshot['files'] as $relative) {
$source = rtrim($snapshot['snapshot_dir'], '/') . '/' . $relative;
if (!is_file($source)) {
continue;
}
$dest = rtrim($panel_dir, '/') . '/' . $relative;
if (!gsp_copy_file($source, $dest)) {
return ['success' => false, 'error' => 'Cannot restore runtime file after update: ' . $relative, 'restored' => $restored];
}
$restored++;
}
return ['success' => true, 'restored' => $restored];
}

function gsp_cleanup_runtime_state_snapshot(array $snapshot)
{
if (!empty($snapshot['snapshot_dir']) && is_dir($snapshot['snapshot_dir'])) {
gsp_rmdir_recursive($snapshot['snapshot_dir']);
}
}

function gsp_write_last_update_markers($repo_root = null)
{
$line = 'Last Updated at ' . date('g:ia') . ' on ' . date('Y-m-d');
$last_update_file = rtrim((string)($repo_root ?: GSP_ROOT_DIR), '/') . '/LAST_UPDATE.txt';
$targets = [GSP_CANONICAL_TIMESTAMP_FILE, GSP_BILLING_TIMESTAMP_FILE, $last_update_file];
foreach ($targets as $target) {
$dir = dirname($target);
if (!is_dir($dir)) {
@mkdir($dir, 0775, true);
}
if (is_writable($dir) || is_writable($target)) {
@file_put_contents($target, $line . PHP_EOL, LOCK_EX);
}
}
gsp_update_log('Last-update marker files written: ' . implode(', ', $targets));
return $line;
}

function gsp_run_pending_migrations()
{
global $db;
if (!function_exists('gsp_migrations_run_pending')) {
return ['success' => false, 'error' => 'Migration runner is unavailable.'];
}
$run = gsp_migrations_run_pending($db, GSP_MIGRATIONS_DIR, 'gsp_update_log');
if (!$run['success']) {
return [
'success' => false,
'error' => 'Migration failure at ' . $run['failed_migration'] . ': ' . $run['error'],
'run' => $run,
];
}
return ['success' => true, 'run' => $run];
}

function gsp_checkout_update_source(array $update_cfg)
{
$channel = isset($update_cfg['channel']) ? (string)$update_cfg['channel'] : GSP_DEFAULT_RELEASE_CHANNEL;
$release_zip_url = !empty($update_cfg['release_zip_url']) ? trim((string)$update_cfg['release_zip_url']) : gsp_release_channel_url($channel);
if (!gsp_is_valid_release_channel($channel)) {
return ['success' => false, 'error' => 'Release channel must be stable or unstable.'];
}
if (!gsp_is_valid_release_zip_url($release_zip_url)) {
return ['success' => false, 'error' => 'Release ZIP URL must be an http(s) .zip URL.'];
}
$temp_dir = sys_get_temp_dir() . '/gsp_panel_release_' . getmypid() . '_' . gsp_random_token(8);
if (!@mkdir($temp_dir, 0700, true) && !is_dir($temp_dir)) {
return ['success' => false, 'error' => 'Cannot create temporary release extraction directory.'];
}
$zip_file = $temp_dir . '/panel-release.zip';
$extract_dir = $temp_dir . '/extract';
@mkdir($extract_dir, 0700, true);
gsp_update_log('Downloading Panel release ZIP for channel ' . $channel . ': ' . $release_zip_url);
$downloaded = false;
if (function_exists('curl_init')) {
$fh = @fopen($zip_file, 'wb');
if ($fh === false) {
gsp_rmdir_recursive($temp_dir);
return ['success' => false, 'error' => 'Cannot write temporary release ZIP.'];
}
$ch = curl_init($release_zip_url);
curl_setopt($ch, CURLOPT_FILE, $fh);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FAILONERROR, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
$downloaded = curl_exec($ch) === true;
$curl_error = curl_error($ch);
curl_close($ch);
fclose($fh);
if (!$downloaded) {
@unlink($zip_file);
gsp_rmdir_recursive($temp_dir);
return ['success' => false, 'error' => 'Release ZIP download failed: ' . $curl_error];
}
} else {
$data = @file_get_contents($release_zip_url);
if ($data !== false) {
$downloaded = @file_put_contents($zip_file, $data) !== false;
}
}
if (!$downloaded || !is_file($zip_file) || filesize($zip_file) <= 0) {
gsp_rmdir_recursive($temp_dir);
return ['success' => false, 'error' => 'Downloaded release ZIP is missing or empty.'];
}
require_once(GSP_PANEL_DIR . '/modules/update/unzip.php');
$extract = extractZip($zip_file, rtrim($extract_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '', '', '');
if (!is_array($extract)) {
gsp_rmdir_recursive($temp_dir);
return ['success' => false, 'error' => 'Release ZIP extraction failed: ' . trim((string)$extract)];
}
$payload = gsp_find_panel_payload_root($extract_dir);
if (!$payload['success']) {
gsp_rmdir_recursive($temp_dir);
return $payload;
}
gsp_update_log('Panel release ZIP extracted. Payload root: ' . $payload['source_root']);
return ['success' => true, 'temp_dir' => $temp_dir, 'source_root' => $payload['source_root'], 'output' => 'release zip extracted', 'updated_checkout' => false];
}

function gsp_find_panel_payload_root($extract_dir)
{
$roots = [];
if (!is_dir($extract_dir)) {
return ['success' => false, 'error' => 'Release extraction directory is missing.'];
}
$iter = new RecursiveIteratorIterator(
new RecursiveDirectoryIterator($extract_dir, RecursiveDirectoryIterator::SKIP_DOTS),
RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iter as $item) {
if (!$item->isFile() || $item->getFilename() !== 'install.php') {
continue;
}
$candidate = $item->getPath();
if (is_file($candidate . '/panel.sql')
&& is_file($candidate . '/includes/install_engine.php')
&& is_file($candidate . '/tools/install_panel_cli.php')) {
$real = realpath($candidate) ?: $candidate;
$roots[$real] = true;
}
}
$roots = array_keys($roots);
if (count($roots) === 0) {
return ['success' => false, 'error' => 'Release ZIP does not contain a valid Panel payload root.'];
}
if (count($roots) > 1) {
return ['success' => false, 'error' => 'Release ZIP contains multiple Panel payload roots: ' . implode(', ', $roots)];
}
return ['success' => true, 'source_root' => $roots[0]];
}

function gsp_fail_update_with_rollback($error, array $backup, array $update_cfg, array $runtime_snapshot = null, array $checkout = null)
{
if ($runtime_snapshot !== null) {
$restore_runtime = gsp_restore_runtime_state_snapshot($runtime_snapshot, $update_cfg['panel_path']);
if (!$restore_runtime['success']) {
$error .= ' Runtime-state restore failed: ' . $restore_runtime['error'];
}
gsp_cleanup_runtime_state_snapshot($runtime_snapshot);
}
if ($checkout !== null && !empty($checkout['temp_dir'])) {
gsp_rmdir_recursive($checkout['temp_dir']);
}
if (!empty($backup['backup_name']) && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
$rollback = gsp_do_revert($backup['backup_name'], false, $update_cfg);
if ($rollback['success']) {
$error .= ' Rolled back to backup ' . $backup['backup_name'] . '.';
} else {
$error .= ' Automatic rollback failed: ' . $rollback['error'];
}
}
gsp_update_log('Configured release ZIP panel update failed: ' . $error);
return ['success' => false, 'error' => $error, 'backup_dir' => isset($backup['backup_dir']) ? $backup['backup_dir'] : null];
}

function gsp_fix_permissions($root_dir)
{
@system(
'find ' . escapeshellarg($root_dir)
. ' -maxdepth 12 \( -name "*.php" -exec chmod 644 {} + \)'
. ' -o \( -type d -exec chmod 755 {} + \) 2>/dev/null'
);
}

function gsp_clear_panel_cache($panel_dir)
{
foreach (['cache', 'temp'] as $dir) {
$cache = $panel_dir . '/' . $dir;
if (!is_dir($cache)) {
continue;
}
foreach (glob($cache . '/*.php') ?: [] as $f) {
@unlink($f);
}
foreach (glob($cache . '/*.cache') ?: [] as $f) {
@unlink($f);
}
}
}

function gsp_do_configured_archive_update(array $update_cfg, $restart_nonce = '')
{
global $db;
$validation = gsp_validate_update_settings($update_cfg);
if (!empty($validation)) {
return ['success' => false, 'error' => implode(' | ', $validation)];
}
$preflight = gsp_preflight_check($update_cfg);
if (!$preflight['success']) {
return ['success' => false, 'error' => 'Preflight failed: ' . implode(' | ', $preflight['errors'])];
}

$panel_dest = rtrim((string)$update_cfg['panel_path'], '/');
if (strpos($panel_dest, '/') !== 0 || strpos($panel_dest, '..') !== false || strpos($panel_dest, "\0") !== false) {
return ['success' => false, 'error' => 'Panel destination path is invalid.'];
}

$backup = gsp_create_full_backup('panel-update', $update_cfg['channel'], false, $update_cfg);
if (!$backup['success']) {
return $backup;
}
gsp_update_log("Single backup created before panel release update to {$update_cfg['channel']}: {$backup['backup_dir']}");

$runtime_snapshot = gsp_create_runtime_state_snapshot($panel_dest);
if (!$runtime_snapshot['success']) {
return gsp_fail_update_with_rollback($runtime_snapshot['error'], $backup, $update_cfg);
}

$checkout = gsp_checkout_update_source($update_cfg);
if (!$checkout['success']) {
return gsp_fail_update_with_rollback($checkout['error'], $backup, $update_cfg, $runtime_snapshot, $checkout);
}

$panel_source = gsp_component_source_path($checkout['source_root'], $update_cfg['panel_source_path']);
if ($panel_source === false || !is_dir($panel_source)) {
return gsp_fail_update_with_rollback('Source Panel folder is invalid or missing: ' . $update_cfg['panel_source_path'], $backup, $update_cfg, $runtime_snapshot, $checkout);
}

if (!is_dir($panel_dest) && !@mkdir($panel_dest, 0755, true) && !is_dir($panel_dest)) {
return gsp_fail_update_with_rollback('Cannot create Panel destination directory: ' . $panel_dest, $backup, $update_cfg, $runtime_snapshot, $checkout);
}
$panel_source_real = realpath($panel_source) ?: $panel_source;
$panel_dest_real = realpath($panel_dest) ?: $panel_dest;
if ($panel_source_real === $panel_dest_real) {
$sync = ['success' => true, 'files_copied' => 0, 'skipped' => [], 'copied_files' => []];
gsp_update_log('Configured release update source is already the live Panel root; Panel copy step skipped.');
} else {
$sync = gsp_component_copy_tree($panel_source, $panel_dest, 'Panel');
}
if (!empty($checkout['temp_dir'])) {
gsp_rmdir_recursive($checkout['temp_dir']);
}
if (!$sync['success']) {
return gsp_fail_update_with_rollback($sync['error'], $backup, $update_cfg, $runtime_snapshot);
}
$runtime_restore = gsp_restore_runtime_state_snapshot($runtime_snapshot, $panel_dest);
gsp_cleanup_runtime_state_snapshot($runtime_snapshot);
if (!$runtime_restore['success']) {
return gsp_fail_update_with_rollback($runtime_restore['error'], $backup, $update_cfg);
}
$migrations = gsp_run_pending_migrations();
if (!$migrations['success']) {
return gsp_fail_update_with_rollback($migrations['error'], $backup, $update_cfg);
}
$commit_after = gsp_get_git_commit();
gsp_fix_permissions($panel_dest);
gsp_clear_panel_cache($panel_dest);
gsp_write_version_file($update_cfg['channel'], 'release-zip');
gsp_write_version_json('release-zip', $update_cfg['release_zip_url'], $update_cfg['channel'], $commit_after);
gsp_write_last_update_markers($update_cfg['repo_root']);
$db->setSettings(['gsp_version' => $update_cfg['channel'], 'version_type' => 'release-zip']);

if (file_exists($panel_dest . '/modules/modulemanager/module_handling.php')) {
require_once($panel_dest . '/modules/modulemanager/module_handling.php');
}
if (function_exists('updateAllPanelModules')) {
updateAllPanelModules();
}
if (function_exists('runPostUpdateOperations')) {
runPostUpdateOperations();
}

gsp_update_log("Configured release ZIP panel update complete: {$update_cfg['release_zip_url']} {$update_cfg['channel']}");
return [
'success' => true,
'files_copied' => $sync['files_copied'],
'panel_files_copied' => $sync['files_copied'],
'backup_dir' => $backup['backup_dir'],
'backup_name' => $backup['backup_name'],
'preserved' => array_values(array_unique(array_merge($sync['skipped'], (array)$runtime_snapshot['files']))),
'copied_files' => isset($sync['copied_files']) ? $sync['copied_files'] : [],
'migrations_applied' => $migrations['run']['applied'],
];
}

function gsp_do_configured_git_update(array $update_cfg, $restart_nonce = '')
{
return gsp_do_configured_archive_update($update_cfg, $restart_nonce);
}

function gsp_component_source_path($source_root, $relative)
{
$relative = trim((string)$relative, '/');
if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
return false;
}
$path = rtrim($source_root, '/') . '/' . $relative;
$real = realpath($path);
if ($real === false || strpos($real, realpath($source_root) ?: $source_root) !== 0) {
return false;
}
return $real;
}

function gsp_component_copy_tree($src_root, $dst_root, $component_label)
{
$copied = 0;
$skipped = [];
$copied_files = [];
if (!is_dir($src_root)) {
return ['success' => false, 'error' => 'Source component path not found: ' . $src_root];
}
if (!is_dir($dst_root) && !@mkdir($dst_root, 0755, true)) {
return ['success' => false, 'error' => 'Destination path could not be created: ' . $dst_root];
}
$iter = new RecursiveIteratorIterator(
new RecursiveDirectoryIterator($src_root, RecursiveDirectoryIterator::SKIP_DOTS),
RecursiveIteratorIterator::SELF_FIRST
);
$protected = [
'.git/',
'.github/',
'.devplus/',
'.vscode/',
'includes/config.inc.php',
'config.inc.php',
'Cfg/',
'ServerFiles/',
'Schedule/',
'logs/',
'log/',
'backups/',
'backup/',
'cache/',
'tmp/',
'temp/',
'uploads/',
'upload/',
'home/',
'homes/',
'servers/',
'game_servers/',
'steamcmd/',
'startups/',
'node_modules/',
'vendor/',
];
foreach ($iter as $item) {
$rel = gsp_normalize_rel(substr($item->getPathname(), strlen(rtrim($src_root, '/')) + 1));
$base_name = basename($rel);
if (preg_match('/\.log$/i', $base_name) || preg_match('/\.sql\.(gz|bz2|xz)$/i', $base_name)) {
$skipped[] = $component_label . '/' . $rel;
continue;
}
$is_protected = false;
foreach ($protected as $prefix) {
if ($rel === rtrim($prefix, '/') || strpos($rel, $prefix) === 0) {
$is_protected = true;
break;
}
}
if ($is_protected) {
$skipped[] = $component_label . '/' . $rel;
continue;
}
$dst = rtrim($dst_root, '/') . '/' . $rel;
if ($item->isDir()) {
if (!is_dir($dst)) {
@mkdir($dst, 0755, true);
}
continue;
}
if (gsp_copy_file($item->getPathname(), $dst)) {
$copied++;
if (count($copied_files) < 200) {
$copied_files[] = $component_label . '/' . $rel;
}
}
}
return ['success' => true, 'files_copied' => $copied, 'skipped' => array_values(array_unique($skipped)), 'copied_files' => $copied_files];
}

function gsp_run_admin_post_update_command($command, $cwd)
{
$command = trim((string)$command);
if ($command === '') {
return ['success' => true, 'skipped' => true, 'output' => ''];
}
if (strpos($command, "\0") !== false || strpos($command, "\n") !== false || strpos($command, "\r") !== false) {
return ['success' => false, 'error' => 'Post-update command must be a single line.'];
}
$out = [];
$ret = 0;
exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $out, $ret);
return ['success' => $ret === 0, 'exit_code' => $ret, 'output' => implode("\n", $out), 'error' => $ret === 0 ? '' : implode("\n", $out)];
}

function gsp_get_available_backups($backup_base = null)
{
	$backups = [];
	$backup_base = $backup_base ? rtrim((string)$backup_base, '/') : gsp_get_backup_base();
	if (!is_dir($backup_base)) {
	return $backups;
	}
	foreach ((array)scandir($backup_base) as $entry) {
if ($entry === '.' || $entry === '..') {
continue;
}
	$dir = $backup_base . '/' . $entry;
if (!is_dir($dir)) {
continue;
}
if (!gsp_is_safe_backup_name($entry)) {
continue;
}
$meta_file = $dir . '/backup.json';
$meta = [];
if (file_exists($meta_file)) {
$meta = json_decode(@file_get_contents($meta_file), true) ?: [];
}
$backups[] = ['name' => $entry, 'ts' => gsp_backup_timestamp_from_name($entry), 'meta' => $meta];
}
usort($backups, function ($a, $b) {
if ($a['ts'] === $b['ts']) {
return strcmp($b['name'], $a['name']);
}
return strcmp($b['ts'], $a['ts']);
});
return $backups;
}

function gsp_is_safe_backup_name($name)
{
return preg_match('/^(?:[A-Za-z0-9._-]+_)?\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', (string)$name) === 1;
}

function gsp_backup_timestamp_from_name($name)
{
if (preg_match('/(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})$/', (string)$name, $m)) {
return $m[1];
}
return (string)$name;
}

function gsp_restore_archive($archive, $target)
{
if (!file_exists($archive)) {
return ['success' => false, 'error' => 'Archive not found: ' . $archive];
}
$out = [];
$ret = 0;
exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($target) . ' 2>&1', $out, $ret);
if ($ret !== 0) {
return ['success' => false, 'error' => 'tar extract failed: ' . implode(' | ', $out)];
}
return ['success' => true];
}

function gsp_restore_database_from_backup($backup_dir)
{
$sql_file = $backup_dir . '/database.sql';
if (!file_exists($sql_file)) {
return ['success' => true, 'skipped' => true];
}
$db_config = gsp_load_panel_db_config();
if ($db_config === null) {
return ['success' => false, 'error' => 'Cannot load DB config for restore.'];
}
$creds_file = tempnam(sys_get_temp_dir(), 'gsp_db_');
if ($creds_file === false) {
return ['success' => false, 'error' => 'Cannot create temporary DB credential file.'];
}
$creds = "[client]\n"
. "user=" . addcslashes($db_config['user'], "\\\n") . "\n"
. "password=" . addcslashes($db_config['pass'], "\\\n") . "\n";
if (!empty($db_config['host'])) {
$creds .= "host=" . addcslashes($db_config['host'], "\\\n") . "\n";
}
if (!empty($db_config['port']) && $db_config['port'] !== '3306') {
$creds .= "port=" . addcslashes($db_config['port'], "\\\n") . "\n";
}
@file_put_contents($creds_file, $creds);
@chmod($creds_file, 0600);
$cmd = 'mysql --defaults-extra-file=' . escapeshellarg($creds_file)
. ' ' . escapeshellarg($db_config['name'])
. ' < ' . escapeshellarg($sql_file)
. ' 2>&1';
$out = [];
$ret = 0;
exec($cmd, $out, $ret);
@unlink($creds_file);
if ($ret !== 0) {
return ['success' => false, 'error' => 'MySQL restore failed: ' . implode(' | ', $out)];
}
return ['success' => true];
}

function gsp_do_revert($backup_ts, $restore_apache = false, array $update_cfg = null)
{
	global $db;
	if (!gsp_is_safe_backup_name($backup_ts)) {
	return ['success' => false, 'error' => 'Invalid backup name: ' . $backup_ts];
	}
	$backup_dir = gsp_get_backup_base($update_cfg) . '/' . $backup_ts;
if (!is_dir($backup_dir)) {
return ['success' => false, 'error' => 'Backup not found: ' . $backup_ts];
}

if (!isset($db) || !is_object($db)) {
return ['success' => false, 'error' => 'Database handle is unavailable for rollback.'];
}

$db_settings = $db->getSettings();
$had_maintenance = isset($db_settings['maintenance_mode']) && $db_settings['maintenance_mode'] == '1';
if (!$had_maintenance) {
$db->setSettings([
'maintenance_mode' => '1',
'maintenance_title' => 'Reverting...',
'maintenance_message' => 'The panel is being reverted to a previous backup. Please wait.',
]);
}

	$panel_restore = gsp_restore_archive($backup_dir . '/panel-files.tar.gz', GSP_PANEL_DIR);
	if (!$panel_restore['success']) {
	if (!$had_maintenance) {
	$db->setSettings(['maintenance_mode' => '0']);
	}
	return $panel_restore;
	}
	if (file_exists($backup_dir . '/version.json.bak')) {
	@copy($backup_dir . '/version.json.bak', GSP_VERSION_JSON);
	}
$db_restore = gsp_restore_database_from_backup($backup_dir);
if (!$db_restore['success']) {
gsp_update_log('Revert warning: ' . $db_restore['error']);
}

if ($restore_apache && (is_dir($backup_dir . '/apache-configs') || is_dir($backup_dir . '/apache-sites-available'))) {
$apache_backup_dir = is_dir($backup_dir . '/apache-configs') ? ($backup_dir . '/apache-configs') : ($backup_dir . '/apache-sites-available');
$apache_restore = gsp_restore_apache_backup($apache_backup_dir, true);
if (!$apache_restore['success']) {
gsp_update_log('Revert apache restore warning: ' . $apache_restore['error']);
}
}

gsp_fix_permissions(GSP_ROOT_DIR);
gsp_clear_panel_cache(GSP_PANEL_DIR);
if (!$had_maintenance) {
$db->setSettings(['maintenance_mode' => '0']);
}

gsp_update_log('Revert complete: ' . $backup_ts);
return ['success' => true, 'files_restored' => 0];
}

function gsp_get_apache_vhost_target_path($filename)
{
$name = strtolower((string)$filename);
if (strpos($name, 'panel.') === 0) {
return GSP_PANEL_DIR;
}
if (strpos($name, 'gameservers.world') !== false) {
return GSP_WEBSITE_DIR;
}
return null;
}

function gsp_is_apache_stale_path($path)
{
$path = trim((string)$path);
$panel_root = rtrim((string)GSP_PANEL_DIR, '/');
$billing_root = rtrim((string)GSP_WEBSITE_DIR, '/');
return ($path === $panel_root
|| $path === $billing_root
|| strpos($path, $panel_root . '/') === 0);
}

function gsp_parse_apache_cert_error_line($line)
{
$line = trim((string)$line);
if (preg_match("/(SSLCertificate(?:File|KeyFile)):\\s*file '([^']+)' (does not exist(?: or is empty)?|is empty)/i", $line, $m)) {
return [
'directive' => $m[1],
'path' => $m[2],
'reason' => $m[3],
'line' => $line,
];
}
return null;
}

function gsp_scan_apache_configs()
{
$base = '/etc/apache2/sites-available';
$result = [
'success' => true,
'available' => is_dir($base),
'base' => $base,
'files' => [],
'issues' => [],
'stale_issues' => [],
'ssl_issues' => [],
'planned_replacements' => [],
'recommendations' => [],
];
if (!$result['available']) {
$result['success'] = false;
$result['issues'][] = 'Apache sites-available directory not found.';
return $result;
}
$files = glob($base . '/*.conf') ?: [];
foreach ($files as $file) {
$lines = @file($file, FILE_IGNORE_NEW_LINES);
if (!is_array($lines)) {
continue;
}
$vhost = basename($file);
$target = gsp_get_apache_vhost_target_path($vhost);
$file_info = [
'file' => $file,
'vhost' => $vhost,
'target' => $target,
'document_roots' => [],
'directories' => [],
'stale_hits' => [],
'ssl_hits' => [],
];
foreach ($lines as $line_number => $line) {
if (preg_match('/^\s*DocumentRoot\s+(.+)$/i', $line, $m)) {
$path = trim($m[1], "\"' ");
$file_info['document_roots'][] = $path;
if ($target !== null && $path !== $target && gsp_is_apache_stale_path($path)) {
$msg = $vhost . ' stale DocumentRoot: ' . $path . ' -> ' . $target;
$result['stale_issues'][] = $msg;
$result['issues'][] = $msg;
$result['planned_replacements'][] = ['vhost' => $vhost, 'directive' => 'DocumentRoot', 'from' => $path, 'to' => $target];
$file_info['stale_hits'][] = $path;
}
}
if (preg_match('/^\s*<Directory\s+(.+)>/i', $line, $m)) {
$path = trim($m[1], "\"' ");
$file_info['directories'][] = $path;
if ($target !== null && $path !== $target && gsp_is_apache_stale_path($path)) {
$msg = $vhost . ' stale <Directory>: ' . $path . ' -> ' . $target;
$result['stale_issues'][] = $msg;
$result['issues'][] = $msg;
$result['planned_replacements'][] = ['vhost' => $vhost, 'directive' => '<Directory>', 'from' => $path, 'to' => $target];
$file_info['stale_hits'][] = $path;
}
}
if (preg_match('/^\s*(SSLCertificate(?:File|KeyFile))\s+(.+)$/i', $line, $m)) {
$directive = $m[1];
$path = trim($m[2], "\"' ");
$unverified = $path !== '' && !file_exists($path) && !is_dir(dirname($path));
if ($path !== '' && !$unverified && (!file_exists($path) || @filesize($path) === 0)) {
$reason = !file_exists($path) ? 'missing' : 'empty';
$msg = $vhost . ' ' . $directive . ' ' . $path . ' is ' . $reason;
$issue = ['vhost' => $vhost, 'directive' => $directive, 'path' => $path, 'reason' => $reason, 'line' => ($line_number + 1), 'message' => $msg];
$result['ssl_issues'][] = $issue;
$result['issues'][] = $msg;
$file_info['ssl_hits'][] = $issue;
}
}
}
if ($target !== null) {
$result['recommendations'][] = $vhost . ' should target ' . $target;
}
$result['files'][] = $file_info;
}
$result['stale_issues'] = array_values(array_unique($result['stale_issues']));
$result['issues'] = array_values(array_unique($result['issues']));
return $result;
}

function gsp_apache_configtest_only_cert_failures($configtest_output)
{
$lines = preg_split('/\r\n|\r|\n/', (string)$configtest_output);
$seen = 0;
foreach ((array)$lines as $line) {
$line = trim((string)$line);
if ($line === '') {
continue;
}
if (stripos($line, 'Syntax OK') !== false) {
continue;
}
if (preg_match('/^AH[0-9]+:\s+/i', $line) && stripos($line, 'Could not reliably determine') !== false) {
continue;
}
if (gsp_parse_apache_cert_error_line($line) !== null) {
$seen++;
continue;
}
return false;
}
return $seen > 0;
}

function gsp_extract_apache_configtest_cert_issues($configtest_output)
{
$issues = [];
$lines = preg_split('/\r\n|\r|\n/', (string)$configtest_output);
foreach ((array)$lines as $line) {
$parsed = gsp_parse_apache_cert_error_line($line);
if ($parsed !== null) {
$issues[] = $parsed;
}
}
return $issues;
}

function gsp_restore_apache_backup($backup_dir, $reload_apache)
{
$available_target = '/etc/apache2/sites-available';
$enabled_target = '/etc/apache2/sites-enabled';
if (!is_dir($backup_dir)) {
return ['success' => false, 'error' => 'Apache backup folder not found.'];
}
$available_backup = is_dir($backup_dir . '/sites-available') ? $backup_dir . '/sites-available' : $backup_dir;
$enabled_backup = $backup_dir . '/sites-enabled';
$restored = 0;
foreach ((glob($available_backup . '/*.conf') ?: []) as $file) {
if (@copy($file, $available_target . '/' . basename($file))) {
$restored++;
}
}
if (is_dir($enabled_backup)) {
foreach ((glob($enabled_backup . '/*') ?: []) as $file) {
$dst = $enabled_target . '/' . basename($file);
if (is_link($dst) || is_file($dst)) {
@unlink($dst);
}
if (is_link($file)) {
$link_target = @readlink($file);
if ($link_target !== false) {
@symlink($link_target, $dst);
$restored++;
}
} elseif (is_file($file) && @copy($file, $dst)) {
$restored++;
}
}
}
$test = gsp_apache_configtest();
if (!$test['success']) {
if (gsp_apache_configtest_only_cert_failures($test['output'])) {
return [
'success' => true,
'restored' => $restored,
'configtest' => $test,
'warnings' => ['Apache config restore completed, but SSL certificate file(s) are missing.'],
'ssl_issues' => gsp_extract_apache_configtest_cert_issues($test['output']),
];
}
return ['success' => false, 'error' => 'apache2ctl configtest failed after restore: ' . $test['output']];
}
$reload = ['success' => true, 'output' => 'Apache reload skipped'];
if ($reload_apache) {
$reload = gsp_apache_reload();
}
return ['success' => true, 'restored' => $restored, 'configtest' => $test, 'reload' => $reload];
}

function gsp_apache_configtest()
{
$out = [];
$ret = 0;
exec('apache2ctl configtest 2>&1', $out, $ret);
return [
'success' => ($ret === 0),
'output' => trim(implode("\n", $out)),
];
}

function gsp_apache_reload()
{
$out = [];
$ret = 0;
exec('apache2ctl graceful 2>&1', $out, $ret);
return [
'success' => ($ret === 0),
'output' => trim(implode("\n", $out)),
];
}

function gsp_fix_apache_paths($confirmed, $reload_apache)
{
	if (!$confirmed) {
	return ['success' => false, 'error' => 'Apache path fix requires explicit confirmation.'];
	}
	$scan = gsp_scan_apache_configs();
	if (!$scan['available']) {
	return ['success' => false, 'error' => 'Apache config folder not available.'];
}

$backup = gsp_create_full_backup('apache-fix', 'apache-path-repair', true);
if (!$backup['success']) {
return ['success' => false, 'error' => 'Could not create backup before apache fix: ' . $backup['error']];
}

$base = '/etc/apache2/sites-available';
$files = glob($base . '/*.conf') ?: [];
$changed = [];
$planned = [];
foreach ($files as $file) {
$orig = @file_get_contents($file);
if ($orig === false) {
continue;
}
$vhost = basename($file);
$target = gsp_get_apache_vhost_target_path($vhost);
if ($target === null) {
continue;
}
$new = preg_replace_callback('/(^\s*DocumentRoot\s+)(["\']?)([^"\']+)(\2\s*$)/mi', function ($m) use ($target, $vhost, &$planned) {
$current = trim((string)$m[3]);
if ($current === $target || !gsp_is_apache_stale_path($current)) {
return $m[0];
}
$planned[] = ['vhost' => $vhost, 'directive' => 'DocumentRoot', 'from' => $current, 'to' => $target];
return $m[1] . $m[2] . $target . $m[2];
}, $orig);
$new = preg_replace_callback('/(^\s*<Directory\s+)(["\']?)([^"\'>]+)(\2\s*>)/mi', function ($m) use ($target, $vhost, &$planned) {
$current = trim((string)$m[3]);
if ($current === $target || !gsp_is_apache_stale_path($current)) {
return $m[0];
}
$planned[] = ['vhost' => $vhost, 'directive' => '<Directory>', 'from' => $current, 'to' => $target];
return $m[1] . $m[2] . $target . $m[2] . '>';
}, $new);
if ($new !== $orig) {
@file_put_contents($file, $new);
$changed[] = $file;
}
}

$test = gsp_apache_configtest();
if (!$test['success']) {
$cert_only = gsp_apache_configtest_only_cert_failures($test['output']);
if (!$cert_only) {
$restore = gsp_restore_apache_backup($backup['backup_dir'] . '/apache-configs', false);
return [
'success' => false,
'error' => 'apache2ctl configtest failed; restored backup. Output: ' . $test['output']
. ($restore['success'] ? '' : (' | restore failed: ' . $restore['error'])),
];
}
gsp_update_log('Apache path fix completed but SSL certificates are missing: ' . $test['output']);
return [
'success' => true,
'warning' => 'Apache paths fixed, but SSL certificate is missing.',
'changed_files' => $changed,
'backup_dir' => $backup['backup_dir'],
'configtest' => $test,
'planned_replacements' => $planned,
'ssl_issues' => gsp_extract_apache_configtest_cert_issues($test['output']),
'reload' => ['success' => false, 'output' => 'Apache reload skipped because SSL certificate files are missing.'],
];
}

$reload = ['success' => true, 'output' => 'Apache reload skipped'];
if ($reload_apache) {
$reload = gsp_apache_reload();
}

gsp_update_log('Apache path fix changed ' . count($changed) . ' file(s).');
if (!empty($planned)) {
$samples = array_slice($planned, 0, 20);
foreach ($samples as $entry) {
gsp_update_log('Apache replacement planned/applied: ' . $entry['vhost'] . ' ' . $entry['directive'] . ' ' . $entry['from'] . ' => ' . $entry['to']);
}
}
return [
'success' => true,
'changed_files' => $changed,
'backup_dir' => $backup['backup_dir'],
'configtest' => $test,
'reload' => $reload,
	'planned_replacements' => $planned,
	];
}

function gsp_disable_ssl_vhost($vhost_file, $confirmed)
{
	if (!$confirmed) {
	return ['success' => false, 'error' => 'SSL vhost disable requires confirmation.'];
	}
	$vhost = basename((string)$vhost_file);
	if (!preg_match('/\.conf$/', $vhost) || strpos($vhost, '-ssl') === false) {
	return ['success' => false, 'error' => 'Only SSL vhost .conf files can be disabled from this action.'];
	}
	$enabled_path = '/etc/apache2/sites-enabled/' . $vhost;
	if (!file_exists($enabled_path) && !is_link($enabled_path)) {
	return ['success' => true, 'message' => $vhost . ' is already disabled.'];
	}
	if (!@unlink($enabled_path)) {
	return ['success' => false, 'error' => 'Failed to disable SSL site: ' . $vhost];
	}
	$test = gsp_apache_configtest();
	if (!$test['success']) {
	return ['success' => false, 'error' => 'Disabled site but apache2ctl configtest still failed: ' . $test['output']];
	}
	$reload = gsp_apache_reload();
	gsp_update_log('Disabled SSL vhost in sites-enabled: ' . $vhost);
	return ['success' => true, 'configtest' => $test, 'reload' => $reload, 'message' => 'Disabled SSL vhost: ' . $vhost];
}

function gsp_get_migration_overview()
{
global $db;
if (!function_exists('gsp_migrations_discover') || !function_exists('gsp_migrations_get_applied')) {
return ['available' => [], 'applied' => [], 'pending' => []];
}
$defs = gsp_migrations_discover(GSP_MIGRATIONS_DIR);
$applied = gsp_migrations_get_applied($db);
$pending = [];
foreach ($defs as $def) {
$id = (string)$def['id'];
if (!isset($applied[$id])) {
$pending[] = $id;
}
}
return [
'available' => $defs,
'applied' => $applied,
'pending' => $pending,
];
}

function gsp_rmdir_recursive($dir)
{
if (!is_dir($dir)) {
return;
}
$iter = new RecursiveIteratorIterator(
new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iter as $item) {
if ($item->isDir()) {
@rmdir($item->getPathname());
} else {
@unlink($item->getPathname());
}
}
@rmdir($dir);
}

function gsp_render_restart_form($action, $csrf_token, $nonce, $release_version = '')
{
echo "<form id='gsp_update_restart_form' method='POST'>";
echo "<input type='hidden' name='gsp_update_action' value='" . htmlspecialchars($action) . "'>";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>";
echo "<input type='hidden' name='gsp_restart_nonce' value='" . htmlspecialchars($nonce) . "'>";
if ($release_version !== '') {
echo "<input type='hidden' name='gsp_release_version' value='" . htmlspecialchars($release_version) . "'>";
}
echo "</form>";
echo "<script>setTimeout(function(){document.getElementById('gsp_update_restart_form').submit();}, 800);</script>";
}

function gsp_panel_update_section()
{
global $db, $settings;
if ($_SESSION['users_role'] !== 'admin') {
return;
}

$update_cfg = gsp_update_settings();

if (empty($_SESSION['gsp_update_csrf'])) {
$_SESSION['gsp_update_csrf'] = gsp_random_token();
}
$csrf_token = $_SESSION['gsp_update_csrf'];

$apache_scan_result = null;
$preflight_result = null;
$auto_restart_payload = null;

if (isset($_POST['gsp_update_action'])) {
$submitted_csrf = isset($_POST['gsp_update_csrf']) ? $_POST['gsp_update_csrf'] : '';
if (!hash_equals($csrf_token, $submitted_csrf)) {
print_failure('Invalid security token. Please reload and try again.');
} else {
	$action = $_POST['gsp_update_action'];
	$started_at = date('Y-m-d H:i:s');
	$restart_nonce = isset($_POST['gsp_restart_nonce']) ? trim($_POST['gsp_restart_nonce']) : '';
	set_time_limit(0);
	try {

if ($action === 'preflight') {
$preflight_result = gsp_preflight_check();
if ($preflight_result['success']) {
print_success('Preflight check passed.');
} else {
print_failure('Preflight failed: ' . htmlspecialchars(implode(' | ', $preflight_result['errors'])));
}
} elseif ($action === 'apply_migrations') {
$run = gsp_run_pending_migrations();
if ($run['success']) {
print_success('Pending migrations applied successfully.');
} else {
print_failure('Migration application failed: ' . htmlspecialchars($run['error']));
}
} elseif ($action === 'fix_apache') {
$apache_fix = gsp_fix_apache_paths(true, true);
if ($apache_fix['success']) {
if (!empty($apache_fix['warning'])) {
print_success(htmlspecialchars($apache_fix['warning']) . ' Updated files: ' . intval(count($apache_fix['changed_files'])) . '.');
echo "<p style='color:#8a6d3b;'><strong>Renew SSL certificate:</strong> <code>certbot --apache -d gameservers.world -d www.gameservers.world</code></p>\n";
} else {
print_success('Apache paths fixed successfully. Updated files: ' . intval(count($apache_fix['changed_files'])) . '.');
}
if (!empty($apache_fix['configtest']['output'])) {
echo "<p><strong>apache2ctl configtest output:</strong><br><pre style='white-space:pre-wrap;max-height:220px;overflow:auto;'>" . htmlspecialchars($apache_fix['configtest']['output']) . "</pre></p>\n";
}
} else {
print_failure('Apache path fix failed: ' . htmlspecialchars($apache_fix['error']));
}
} elseif ($action === 'disable_ssl_vhost') {
$vhost = isset($_POST['gsp_ssl_vhost']) ? trim($_POST['gsp_ssl_vhost']) : '';
$disable = gsp_disable_ssl_vhost($vhost, true);
if ($disable['success']) {
print_success(htmlspecialchars(isset($disable['message']) ? $disable['message'] : 'SSL vhost disabled.'));
} else {
print_failure('Disable SSL vhost failed: ' . htmlspecialchars($disable['error']));
}
} elseif ($action === 'backup_only') {
	$result = gsp_create_full_backup('backup-only', 'manual', false, $update_cfg);
if ($result['success']) {
print_success('Backup created: <code>' . htmlspecialchars($result['backup_dir']) . '</code>');
} else {
print_failure('Backup failed: ' . htmlspecialchars($result['error']));
}
} elseif ($action === 'save_settings') {
$new_cfg = gsp_update_settings();
$new_cfg['channel'] = isset($_POST['gsp_update_channel']) ? trim((string)$_POST['gsp_update_channel']) : GSP_DEFAULT_RELEASE_CHANNEL;
$custom_release_zip_url = isset($_POST['gsp_update_custom_release_zip_url']) ? trim((string)$_POST['gsp_update_custom_release_zip_url']) : '';
$new_cfg['release_zip_url'] = $custom_release_zip_url;
if ($new_cfg['release_zip_url'] === '') {
$new_cfg['release_zip_url'] = gsp_release_channel_url($new_cfg['channel']);
}
$errors = gsp_validate_update_settings($new_cfg);
if (!empty($errors)) {
print_failure('Update settings were not saved: ' . htmlspecialchars(implode(' | ', $errors)));
} else {
$db->setSettings([
'gsp_update_channel' => $new_cfg['channel'],
'gsp_update_release_zip_url' => $custom_release_zip_url,
'gsp_update_branch' => $new_cfg['channel'],
]);
$settings['gsp_update_channel'] = $new_cfg['channel'];
$settings['gsp_update_release_zip_url'] = $custom_release_zip_url;
$settings['gsp_update_branch'] = $new_cfg['channel'];
$update_cfg = gsp_update_settings();
print_success('Update settings saved.');
}
} elseif ($action === 'save_release_sources') {
$all_errors = [];
$to_save = [];
foreach (gsp_release_components() as $component) {
$cfg = [
'component' => $component,
'repo_url' => isset($_POST['gsp_release_' . $component . '_repo_url']) ? trim((string)$_POST['gsp_release_' . $component . '_repo_url']) : '',
'channel' => isset($_POST['gsp_release_' . $component . '_channel']) ? trim((string)$_POST['gsp_release_' . $component . '_channel']) : 'stable',
'token_file' => isset($_POST['gsp_release_' . $component . '_token_file']) ? trim((string)$_POST['gsp_release_' . $component . '_token_file']) : '',
];
$errors = gsp_validate_release_source_settings($cfg);
if (!empty($errors)) {
$all_errors = array_merge($all_errors, $errors);
continue;
}
$to_save['gsp_release_' . $component . '_repo_url'] = $cfg['repo_url'];
$to_save['gsp_release_' . $component . '_channel'] = $cfg['channel'];
$to_save['gsp_release_' . $component . '_token_file'] = $cfg['token_file'];
}
if (!empty($all_errors)) {
print_failure('Release source settings were not saved: ' . htmlspecialchars(implode(' | ', $all_errors)));
} else {
$db->setSettings($to_save);
foreach ($to_save as $key => $value) {
$settings[$key] = $value;
}
print_success('Release source settings saved.');
}
} elseif ($action === 'update_selected') {
$checked = isset($_POST['gsp_row_check']) ? array_map('strval', (array)$_POST['gsp_row_check']) : array();
$row_tags = (isset($_POST['gsp_row_tag']) && is_array($_POST['gsp_row_tag'])) ? $_POST['gsp_row_tag'] : array();
if (empty($checked)) {
echo "<p class='failure'>No rows selected. Tick the Panel or one or more agents first.</p>\n";
} else {
if (in_array('panel', $checked, true)) {
update_panel($db, $update_cfg, $restart_nonce, isset($row_tags['panel']) ? (string)$row_tags['panel'] : '');
}
$agent_ids = array();
foreach ($checked as $c) {
if ($c !== 'panel') {
$agent_ids[] = $c;
}
}
if (!empty($agent_ids)) {
update_agents($db, $agent_ids, $row_tags);
}
}
} elseif ($action === 'revert') {
$backup_ts = isset($_POST['gsp_revert_backup']) ? trim($_POST['gsp_revert_backup']) : '';
$restore_apache = !empty($_POST['gsp_restore_apache']);
if (!gsp_is_safe_backup_name($backup_ts)) {
print_failure('Invalid backup selected.');
} else {
	$result = gsp_do_revert($backup_ts, $restore_apache, $update_cfg);
if ($result['success']) {
print_success('Reverted to backup <strong>' . htmlspecialchars($backup_ts) . '</strong>.');
} else {
print_failure('Revert failed: ' . htmlspecialchars($result['error']));
}
}
	}

	$finished_at = date('Y-m-d H:i:s');
	gsp_log_update_to_db('panel-update', null, 'info', 'Admin action: ' . $action, null, null, null, $started_at, $finished_at);
	} catch (Throwable $e) {
	$finished_at = date('Y-m-d H:i:s');
	gsp_update_log('Update action failure [' . $action . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
	gsp_log_update_to_db('panel-update', null, 'error', 'Admin action failed: ' . $action . ' | ' . $e->getMessage(), null, null, null, $started_at, $finished_at);
	print_failure('Update action failed: ' . htmlspecialchars($e->getMessage()));
	}
	}
$_SESSION['gsp_update_csrf'] = gsp_random_token();
$csrf_token = $_SESSION['gsp_update_csrf'];
}

$current_version = gsp_get_current_version();
	$current_branch = gsp_get_current_branch();
	$git_commit = gsp_get_git_commit();
$last_layout = isset($_SESSION['gsp_last_update_layout']) && is_array($_SESSION['gsp_last_update_layout'])
? $_SESSION['gsp_last_update_layout']
: null;
	$vinfo = gsp_read_version_json();
	$latest_release = 'N/A';
	$backup_base = gsp_get_backup_base($update_cfg);
	$managed_backups = gsp_get_managed_backup_entries($backup_base);
	$backups = gsp_get_available_backups($backup_base);
	$migration_overview = gsp_get_migration_overview();
if ($apache_scan_result === null) {
$apache_scan_result = gsp_scan_apache_configs();
}
if ($preflight_result === null) {
$preflight_result = gsp_preflight_check($update_cfg);
}

$ssl_issue_count = !empty($apache_scan_result['ssl_issues']) ? count($apache_scan_result['ssl_issues']) : 0;
echo "<h2>Panel Updates</h2>\n";
echo "<table class='administration-table'><tr><td>\n";
if ($ssl_issue_count > 0) {
echo "<p style='color:#8a6d3b;'><strong>SSL warning:</strong> " . intval($ssl_issue_count) . " Apache SSL certificate issue(s) detected. Updates are not blocked by this. See Advanced Diagnostics for details.</p>\n";
}
if (!empty($preflight_result['errors'])) {
$pf_errors = $preflight_result['errors'];
echo "<p style='color:#a94442;'><strong>Preflight:</strong> " . htmlspecialchars($pf_errors[0]) . (count($pf_errors) > 1 ? ' (+' . (count($pf_errors) - 1) . ' more, see Preflight below)' : '') . "</p>\n";
}

global $db;
if (!function_exists('gsp_agent_perform_release_update')) {
	require_once __DIR__ . '/../server/agent_helpers.php';
}
$panel_tags = gsp_release_tag_options('panel');
$agent_tags = array(
	'agent_windows' => gsp_release_tag_options('agent_windows'),
	'agent_linux' => gsp_release_tag_options('agent_linux'),
);
$panel_version = (string)gsp_get_current_version();
$panel_known = ($panel_version !== '' && $panel_version !== 'unknown');
$panel_latest = (is_array($panel_tags) && !empty($panel_tags)) ? $panel_tags[0] : '';

echo "<details open><summary>Release sources (git repo URL per component)</summary>\n";
echo "<form method='POST' id='form_save_sources'>\n";
echo "<input type='hidden' name='gsp_update_action' value='save_release_sources'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<table class='center'>\n";
echo "<tr><th>Component</th><th>Git repo URL</th></tr>\n";
foreach (gsp_release_components() as $component) {
	$rcfg = gsp_release_source_settings($component);
	$repo_value = ($rcfg['repo_url'] !== '') ? $rcfg['repo_url'] : default_repo_url($component);
	echo "<tr><td><strong>" . htmlspecialchars(gsp_release_component_label($component)) . "</strong></td>";
	echo "<td><input type='text' name='gsp_release_{$component}_repo_url' value='" . htmlspecialchars($repo_value, ENT_QUOTES, 'UTF-8') . "' size='55'></td>";
	echo "<input type='hidden' name='gsp_release_{$component}_channel' value='stable'></td></tr>\n";
}
echo "</table>\n";
echo "<p><button type='submit'>Save sources</button></p>\n";
echo "</form>\n";
echo "</details>\n";
echo "<h3>Components</h3>\n";
echo "<p>Tick the rows to update, check the install version on each row, then press Update selected. The panel updates first, then the agents. Nothing updates until you press the button.</p>\n";
echo "<form method='POST' id='form_update_rows'>\n";
echo "<input type='hidden' name='gsp_update_action' value='update_selected'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<script>function gspToggleAllRows(b){document.querySelectorAll('#form_update_rows input[name=\"gsp_row_check[]\"]').forEach(function(c){c.checked=b.checked;});}</script>\n";
echo "<p><button type='submit' onclick=\"return confirm('Update the selected rows? The panel updates first, then the agents. Each agent stops, installs its release, and restarts.');\">Update selected</button></p>\n";
echo "<table class='center'>\n";
echo "<tr><th><input type='checkbox' title='Select all' onclick='gspToggleAllRows(this)'></th><th>Component</th><th>Current version</th><th>Install version</th><th>Update</th></tr>\n";
echo "<tr><td><input type='checkbox' name='gsp_row_check[]' value='panel'></td>";
echo "<td><strong>Panel</strong></td>";
echo "<td>" . htmlspecialchars($panel_known ? $panel_version : 'unknown') . "</td>";
echo "<td>" . tag_select('gsp_row_tag[panel]', $panel_tags) . "</td>";
echo "<td>" . update_badge($panel_known ? $panel_version : '', $panel_latest) . "</td></tr>\n";

$all_agents = (array)$db->getRemoteServers();
if (empty($all_agents)) {
	echo "<tr><td colspan='5'>No agents registered.</td></tr>\n";
}
foreach ($all_agents as $row) {
	$snap = gsp_agent_get_snapshot($db, $row['remote_server_id']);
	$os = (string)gsp_agent_snapshot_field($snap, 'os', '');
	if ($os === '') {
		$os = (string)($row['server_os'] ?? '');
	}
	$group = gsp_agent_component_from_os($os);
	$release_component = ($group === 'windows_agent' || $group === 'linux_agent') ? gsp_release_component_for_agent($group) : '';
	$tags = ($release_component !== '' && isset($agent_tags[$release_component])) ? $agent_tags[$release_component] : null;
	$latest = (is_array($tags) && !empty($tags)) ? $tags[0] : '';
	$ver = (string)gsp_agent_snapshot_field($snap, 'agent_version', '');
	$rid = (int)$row['remote_server_id'];
	$type_label = ($group === 'windows_agent') ? 'Windows agent' : (($group === 'linux_agent') ? 'Linux agent' : 'OS unknown');
	echo "<tr><td><input type='checkbox' name='gsp_row_check[]' value='" . $rid . "'></td>";
	echo "<td><strong>" . htmlspecialchars($row['remote_server_name']) . "</strong><br><small>" . htmlspecialchars($type_label) . " &middot; " . htmlspecialchars($row['agent_ip']) . ":" . (int)$row['agent_port'] . "</small></td>";
	echo "<td>" . htmlspecialchars($ver !== '' ? $ver : 'unknown') . "</td>";
	echo "<td>" . tag_select('gsp_row_tag[' . $rid . ']', $tags) . "</td>";
	echo "<td>" . update_badge($ver, $latest) . "</td></tr>\n";
}
echo "</table>\n";
echo "<p><button type='submit' onclick=\"return confirm('Update the selected rows? The panel updates first, then the agents. Each agent stops, installs its release, and restarts.');\">Update selected</button></p>\n";
echo "</form>\n";


echo "<h3>Backup</h3>\n";
echo "<form method='POST'>\n";
echo "<input type='hidden' name='gsp_update_action' value='backup_only'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<button type='submit'>Create Backup</button>\n";
echo "</form>\n";

if (!empty($backups)) {
echo "<h3>Rollback</h3>\n";
echo "<form method='POST'>\n";
echo "<input type='hidden' name='gsp_update_action' value='revert'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<select name='gsp_revert_backup'>\n";
foreach ($backups as $bk) {
$backup_name = isset($bk['name']) ? $bk['name'] : $bk['ts'];
$label = htmlspecialchars($backup_name);
if (!empty($bk['meta']['update_target_type']) && !empty($bk['meta']['update_target_version'])) {
$label .= ' (before ' . htmlspecialchars($bk['meta']['update_target_type']) . ': ' . htmlspecialchars($bk['meta']['update_target_version']) . ')';
}
echo "<option value='" . htmlspecialchars($backup_name) . "'>{$label}</option>\n";
}
echo "</select> ";
echo "<label><input type='checkbox' name='gsp_restore_apache' value='1'> restore Apache configs if backup contains them</label> ";
echo "<button type='submit' onclick='return confirm(\"Restore Panel, version.json, and database from selected backup?\");'>Rollback</button>\n";
echo "</form>\n";
}

echo "<details style='margin-top:18px;'>\n";
echo "<summary><strong>Advanced Diagnostics</strong></summary>\n";
echo "<h4>Preflight</h4>\n";
echo "<table class='center'>\n";
echo "<tr><td><strong>Status:</strong></td><td>" . ($preflight_result['success'] ? 'PASS' : 'FAIL') . "</td></tr>\n";
echo "<tr><td><strong>Pending Migrations:</strong></td><td>" . intval(count($migration_overview['pending'])) . "</td></tr>\n";
echo "<tr><td><strong>Migrations Directory:</strong></td><td><code>" . htmlspecialchars(GSP_MIGRATIONS_DIR) . "</code></td></tr>\n";
echo "</table>\n";
echo "<h4>Apache</h4>\n";
echo "<table class='center'>\n";
echo "<tr><td><strong>Config Directory:</strong></td><td><code>" . htmlspecialchars($apache_scan_result['base']) . "</code></td></tr>\n";
echo "<tr><td><strong>Configs Found:</strong></td><td>" . intval(count($apache_scan_result['files'])) . "</td></tr>\n";
echo "<tr><td><strong>Stale Path Hits:</strong></td><td>" . intval(count($apache_scan_result['stale_issues'])) . "</td></tr>\n";
echo "<tr><td><strong>SSL Certificate Issues:</strong></td><td>" . intval($ssl_issue_count) . "</td></tr>\n";
echo "</table>\n";
if (!empty($apache_scan_result['stale_issues'])) {
echo "<p style='color:#a94442;'><strong>Apache stale path issues:</strong><br>" . implode('<br>', array_map('htmlspecialchars', array_unique($apache_scan_result['stale_issues']))) . "</p>\n";
}
if (!empty($apache_scan_result['ssl_issues'])) {
echo "<p style='color:#8a6d3b;'><strong>SSL certificate issues:</strong><br>";
foreach ((array)$apache_scan_result['ssl_issues'] as $ssl_issue) {
echo htmlspecialchars($ssl_issue['vhost'] . ' ' . $ssl_issue['directive'] . ': ' . $ssl_issue['path'] . ' (' . $ssl_issue['reason'] . ')') . "<br>";
}
echo "</p>\n";
}
echo "<form method='POST' style='display:inline-block;margin-right:8px;'>\n";
echo "<input type='hidden' name='gsp_update_action' value='preflight'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<button type='submit'>Run Preflight Check</button>\n";
echo "</form>\n";
echo "<form method='POST' style='display:inline-block;margin-right:8px;'>\n";
echo "<input type='hidden' name='gsp_update_action' value='apply_migrations'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<button type='submit'>Apply Pending Migrations</button>\n";
echo "</form>\n";
echo "<form method='POST' style='display:inline-block;'>\n";
echo "<input type='hidden' name='gsp_update_action' value='fix_apache'>\n";
echo "<input type='hidden' name='gsp_update_csrf' value='" . htmlspecialchars($csrf_token) . "'>\n";
echo "<button type='submit' onclick='return confirm(\"Backup Apache configs, run configtest, and apply path fixes?\");'>Fix Apache Paths</button>\n";
echo "</form>\n";
echo "</details>\n";
echo "</td></tr></table>\n";

if ($auto_restart_payload) {
gsp_render_restart_form($auto_restart_payload['action'], $csrf_token, $auto_restart_payload['nonce'], isset($auto_restart_payload['version']) ? $auto_restart_payload['version'] : '');
}
return;
}
?>
