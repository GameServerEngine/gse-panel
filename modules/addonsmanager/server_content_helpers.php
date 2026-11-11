<?php
/*
 *
 * GSP - Server Content helpers (addonsmanager)
 *
 */

require_once(dirname(__DIR__, 2) . "/includes/game_path_helpers.php");
require_once(dirname(__DIR__, 2) . "/includes/lifecycle_contract_helpers.php");


function scm_get_home_for_user($db, $home_id, $user_id)
{
	$home_id = (int)$home_id;
	$user_id = (int)$user_id;
	if ($home_id <= 0 || $user_id <= 0) {
		return false;
	}
	if ($db->isAdmin($user_id)) {
		return $db->getGameHome($home_id);
	}
	return $db->getUserGameHome($user_id, $home_id);
}


function scm_h($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function scm_is_windows_home(array $home_info)
{
	$game_key = isset($home_info['game_key']) ? strtolower((string)$home_info['game_key']) : '';
	$cfg_file = isset($home_info['home_cfg_file']) ? strtolower((string)$home_info['home_cfg_file']) : '';
	return (strpos($game_key, 'win') !== false) || (strpos($cfg_file, 'win') !== false);
}

function scm_path_is_under_home($home_path, $candidate_path)
{
	$home_path = rtrim(clean_path((string)$home_path), '/');
	$candidate_path = clean_path((string)$candidate_path);
	if ($home_path === '' || $candidate_path === '') {
		return false;
	}
	if (function_exists('remote_path_has_parent_segment') &&
		(remote_path_has_parent_segment($home_path) || remote_path_has_parent_segment($candidate_path))) {
		return false;
	}
	return strpos($candidate_path.'/', $home_path.'/') === 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 2 helpers – schema guard, cache mode, manifest, install history
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the allowed values for the server_content_cache_mode panel setting.
 *
 * disabled              – Always install from the configured source. No scanning,
 *                         no shared cache. (DEFAULT – safest choice)
 * search_existing_servers – Agent may scan other local game-server folders for
 *                         matching cacheable content and copy directly if safe.
 * shared_cache          – Agent may store cacheable content in a shared cache
 *                         folder and reuse it on future installs.
 * shared_cache_and_search – Both shared_cache and search_existing_servers are
 *                         active simultaneously.
 *
 * Security note: only content explicitly marked is_cacheable=1 on the addon
 * record may ever be shared or cached.  Private configs, user-edited files,
 * saves, databases, logs, and credentials must never be included.
 *
 * @return array<string,string> key => human-readable label
 */
function scm_get_valid_cache_modes()
{
	return array(
		'disabled'                 => 'Disabled (always install from source)',
		'search_existing_servers'  => 'Search existing servers (copy from local installs)',
		'shared_cache'             => 'Shared cache (store and reuse cached copies)',
		'shared_cache_and_search'  => 'Shared cache + search existing servers',
	);
}

/**
 * Reads the current server_content_cache_mode panel setting.
 * Returns 'disabled' if not set.
 *
 * @param  object $db  Panel DB handle
 * @return string      One of the scm_get_valid_cache_modes() keys
 */
function scm_get_cache_mode($db)
{
	$valid = scm_get_valid_cache_modes();
	$value = '';
	if (method_exists($db, 'getSetting')) {
		$value = (string)$db->getSetting('server_content_cache_mode');
	}
	return array_key_exists($value, $valid) ? $value : 'disabled';
}

function scm_server_bot_supported_os_values(array $home_info)
{
	return scm_is_windows_home($home_info) ? array('any', 'windows', 'win') : array('any', 'linux');
}

function scm_server_bot_os_filter_sql(array $home_info)
{
	$values = scm_server_bot_supported_os_values($home_info);
	$quoted = array();
	foreach ($values as $value) {
		$quoted[] = "'" . $value . "'";
	}
	return " AND (install_method <> 'server_bot' OR bot_os_support IS NULL OR bot_os_support IN (" . implode(',', $quoted) . "))";
}

function scm_resolve_server_bot_runtime_fields(array $fields, array $home_info, $server_xml, array $request = array())
{
	$server_context = array(
		'exe_location' => isset($server_xml->exe_location) ? (string)$server_xml->exe_location : '',
		'modlist' => isset($request['bot_modlist']) ? trim((string)$request['bot_modlist']) : '',
	);
	$home_info['ip'] = isset($home_info['ip']) ? $home_info['ip'] : (isset($request['ip']) ? $request['ip'] : '');
	$home_info['port'] = isset($home_info['port']) ? $home_info['port'] : (isset($request['port']) ? $request['port'] : '');
	$placeholder_map = scm_build_placeholder_map($home_info, $server_context);
	$resolved = array();
	foreach (array('bot_working_dir', 'bot_executable', 'bot_arguments', 'bot_install_folder') as $key) {
		$value = isset($fields[$key]) ? (string)$fields[$key] : '';
		if ($key === 'bot_arguments' && array_key_exists('bot_arguments', $request)) {
			$value = (string)$request['bot_arguments'];
		}
		$resolved[$key] = scm_apply_placeholders($value, $placeholder_map);
	}
	return $resolved;
}

function scm_seed_server_bot_catalog($db)
{
	$prefix = DB_PREFIX;
	$packages = array(
		array(
			'name' => 'BEC - BattlEye Extended Controls',
			'game_keys' => array('arma2oa_win32', 'arma3_win64', 'dayzmod_win32', 'epochmod_win32'),
			'version' => 'template',
			'description' => 'Managed Server Bot template for existing BEC installs. Install or place BEC under the configured server folder, then enable this content to generate _alsoRun.cmd.',
			'url' => '',
			'path' => 'bec',
			'bot_install_folder' => 'bec',
			'bot_working_dir' => 'bec',
			'bot_executable' => 'bec.exe',
			'bot_arguments' => '--dsc --dec -f config.cfg',
			'bot_os_support' => 'windows',
			'bot_enable_default' => 1,
			'checksum_sha256' => '',
		),
		array(
			'name' => 'DZSA Launcher Sidecar',
			'game_keys' => array('dayz_win64'),
			'version' => '0.1',
			'description' => 'Optional dzsal-node-mod-server sidecar. DayZServer_x64.exe remains the main game executable; this bot reports DayZ mods to DZSA Launcher.',
			'url' => 'https://github.com/maca134/dzsal-node-mod-server/releases/download/0.1/node-mod-server.exe',
			'path' => '.',
			'bot_install_folder' => '.',
			'bot_working_dir' => '{GAME_ROOT}',
			'bot_executable' => 'node-mod-server.exe',
			'bot_arguments' => '"-port={PORT}" "{MODLIST}"',
			'bot_os_support' => 'windows',
			'bot_enable_default' => 0,
			'checksum_sha256' => '',
		),
	);

	foreach ($packages as $package) {
		$keys = array();
		foreach ($package['game_keys'] as $game_key) {
			$keys[] = "'" . $db->realEscapeSingle($game_key) . "'";
		}
		$sql = "INSERT INTO `{$prefix}addons`
			    (`name`,`url`,`path`,`addon_type`,`home_cfg_id`,`post_script`,`group_id`,
			     `install_method`,`content_version`,`requires_stop`,`backup_before_install`,
			     `restart_after_install`,`is_cacheable`,`description`,`bot_working_dir`,
			     `bot_executable`,`bot_arguments`,`bot_install_folder`,`bot_os_support`,
			     `bot_enable_default`,`bot_uninstall_script`,`checksum_sha256`)
			 SELECT '" . $db->realEscapeSingle($package['name']) . "',
			        '" . $db->realEscapeSingle($package['url']) . "',
			        '" . $db->realEscapeSingle($package['path']) . "',
			        'server_bot',
			        ch.home_cfg_id,
			        '',
			        0,
			        'server_bot',
			        '" . $db->realEscapeSingle($package['version']) . "',
			        0,
			        0,
			        0,
			        0,
			        '" . $db->realEscapeSingle($package['description']) . "',
			        '" . $db->realEscapeSingle($package['bot_working_dir']) . "',
			        '" . $db->realEscapeSingle($package['bot_executable']) . "',
			        '" . $db->realEscapeSingle($package['bot_arguments']) . "',
			        '" . $db->realEscapeSingle($package['bot_install_folder']) . "',
			        '" . $db->realEscapeSingle($package['bot_os_support']) . "',
			        " . (int)$package['bot_enable_default'] . ",
			        '',
			        '" . $db->realEscapeSingle($package['checksum_sha256']) . "'
			   FROM `{$prefix}config_homes` ch
			  WHERE ch.game_key IN (" . implode(',', $keys) . ")
			    AND NOT EXISTS (
			        SELECT 1 FROM `{$prefix}addons` a
			         WHERE a.home_cfg_id = ch.home_cfg_id
			           AND a.install_method = 'server_bot'
			           AND a.name = '" . $db->realEscapeSingle($package['name']) . "'
			    )";
		if (!$db->query($sql)) {
			return false;
		}
	}
	return true;
}

/**
 * Returns allowed install_method values and their display labels.
 *
 * @return array<string,string>
 */
function scm_get_install_methods()
{
	return array(
		'download_zip'   => 'Downloadable Mod',
		'config_edit'    => 'Configuration Package',
		'post_script'    => 'Scripted Installer',
		'server_bot'     => 'Server Bot',
	);
}

function scm_get_install_method_help_text()
{
	return array(
		'download_zip'   => 'Download and extract a ZIP, RAR, or archive file.',
		'config_edit'    => 'Install configuration files, profiles, or templates.',
		'post_script'    => 'Run a custom scripted installation process.',
		'server_bot'     => 'Register a managed companion process that starts with this server.',
	);
}

function scm_get_install_method_required_fields()
{
	return array(
		'download_zip'   => array('url'),
		'post_script'    => array('post_script'),
		'config_edit'    => array('path', 'config_edit_rule'),
		'server_bot'     => array('bot_working_dir', 'bot_executable'),
	);
}

function scm_get_install_method_validation_errors()
{
	return array(
		'download_zip'   => 'Please enter a download URL.',
		'config_edit'    => 'Please enter the config target and edit action.',
		'post_script'    => 'Please enter the installer script/action.',
		'server_bot'     => 'Please enter the bot working directory and executable/command.',
	);
}

function scm_get_install_method_default($value = '')
{
	$value = trim((string)$value);
	if ($value === 'download_file') {
		$value = 'download_zip';
	}
	if ($value === 'create_folder') {
		$value = 'config_edit';
	}
	$methods = scm_get_install_methods();
	return isset($methods[$value]) ? $value : 'download_zip';
}

function scm_get_install_payload_keys()
{
	return array(
		'url',
		'path',
		'workshop_item_id',
		'workshop_app_id',
		'target_path_template',
		'optional_folder_name',
		'post_script',
		'config_edit_rule',
		'launch_param_additions',
		'bot_working_dir',
		'bot_executable',
		'bot_arguments',
		'bot_install_folder',
		'bot_os_support',
		'bot_enable_default',
		'bot_uninstall_script',
		'checksum_sha256',
	);
}

function scm_collect_install_payload(array $defaults = array(), array $request = array(), array $override_keys = array())
{
	$payload = array();
	foreach (scm_get_install_payload_keys() as $key) {
		$payload[$key] = isset($defaults[$key]) ? trim((string)$defaults[$key]) : '';
	}
	foreach ($override_keys as $key) {
		if (array_key_exists($key, $request)) {
			$payload[$key] = trim((string)$request[$key]);
		}
	}
	return $payload;
}

function scm_validate_numeric_content_value($value, $error_message, &$message, $allow_blank = false)
{
	$value = trim((string)$value);
	if ($value === '') {
		if ($allow_blank) {
			$message = '';
			return true;
		}
		$message = $error_message;
		return false;
	}
	if (!preg_match('/^[0-9]+$/', $value)) {
		$message = $error_message;
		return false;
	}
	$message = '';
	return true;
}

function scm_validate_download_content(array $payload, &$message = '')
{
	$url = isset($payload['url']) ? trim((string)$payload['url']) : '';
	if ($url === '') {
		$message = 'Please enter a download URL.';
		return false;
	}
	$message = '';
	return true;
}

function scm_validate_scripted_installer(array $payload, &$message = '')
{
	$script = isset($payload['post_script']) ? trim((string)$payload['post_script']) : '';
	if ($script === '') {
		$message = 'Please enter the installer script/action.';
		return false;
	}
	$message = '';
	return true;
}

function scm_validate_configuration_package(array $payload, &$message = '')
{
	$path = isset($payload['path']) ? trim((string)$payload['path']) : '';
	$rule = isset($payload['config_edit_rule']) ? trim((string)$payload['config_edit_rule']) : '';
	if ($path === '' || $rule === '') {
		$message = 'Please enter the config target and edit action.';
		return false;
	}
	$message = '';
	return true;
}

function scm_validate_server_bot_content(array $payload, &$message = '')
{
	$workdir = isset($payload['bot_working_dir']) ? trim((string)$payload['bot_working_dir']) : '';
	$executable = isset($payload['bot_executable']) ? trim((string)$payload['bot_executable']) : '';
	$arguments = isset($payload['bot_arguments']) ? trim((string)$payload['bot_arguments']) : '';
	$install_folder = isset($payload['bot_install_folder']) ? trim((string)$payload['bot_install_folder']) : '';
	$os_support = isset($payload['bot_os_support']) ? trim((string)$payload['bot_os_support']) : '';
	foreach (array($workdir, $executable, $arguments, $install_folder, $os_support) as $value) {
		if (strpos($value, "\0") !== false || strpos($value, "\r") !== false || strpos($value, "\n") !== false) {
			$message = 'Server Bot fields must be single-line values.';
			return false;
		}
	}
	if ($os_support !== '' && !in_array($os_support, array('any', 'linux', 'windows', 'win'), true)) {
		$message = 'Server Bot OS support must be any, linux, or windows.';
		return false;
	}
	if ($workdir === '' || $executable === '') {
		$message = 'Please enter the bot working directory and executable/command.';
		return false;
	}
	$message = '';
	return true;
}

function scm_validate_install_method_payload($install_method, array $payload, &$message = '')
{
	$install_method = scm_get_install_method_default($install_method);
	if (!isset(scm_get_install_method_required_fields()[$install_method])) {
		$message = 'Invalid install/content type selected.';
		return false;
	}

	if ($install_method === 'download_zip') {
		return scm_validate_download_content($payload, $message);
	}
	if ($install_method === 'post_script') {
		return scm_validate_scripted_installer($payload, $message);
	}
	if ($install_method === 'config_edit') {
		return scm_validate_configuration_package($payload, $message);
	}
	if ($install_method === 'server_bot') {
		return scm_validate_server_bot_content($payload, $message);
	}
	$message = '';
	return true;
}

function scm_build_placeholder_map(array $home_info, array $server_context = array(), array $overrides = array())
{
	$home_id = (int)(isset($home_info['home_id']) ? $home_info['home_id'] : 0);
	$management_home = rtrim(clean_path((string)(isset($home_info['home_path']) ? $home_info['home_path'] : '')), '/');
	$game_root = isset($home_info['game_root']) ? rtrim(clean_path((string)$home_info['game_root']), '/') : '';
	if ($game_root === '' && isset($home_info['game_key'])) {
		$path_error = '';
		$resolved_game_root = resolve_game_root($management_home, (string)$home_info['game_key'], $path_error);
		if ($resolved_game_root !== false) {
			$game_root = $resolved_game_root;
		}
	}
	if ($game_root === '') {
		$game_root = $management_home;
	}
	$map = array(
		'{HOME_ID}' => (string)$home_id,
		'{SERVER_ROOT}' => $game_root,
		'{GAME_ROOT}' => $game_root,
		'{MANAGEMENT_HOME}' => $management_home,
		'{HOME_PATH}' => $management_home,
		'{IP}' => isset($home_info['ip']) ? (string)$home_info['ip'] : '',
		'{PORT}' => isset($home_info['port']) ? (string)$home_info['port'] : '',
		'{QUERY_PORT}' => isset($home_info['query_port']) ? (string)$home_info['query_port'] : '',
		'{MODLIST}' => isset($server_context['modlist']) ? (string)$server_context['modlist'] : '',
		'{WORKSHOP_ID}' => '',
		'{WORKSHOP_APP_ID}' => '',
		'{STEAM_APP_ID}' => '',
		'{FOLDER_NAME}' => '',
		'{MOD_FOLDER}' => '',
	);
	foreach ($overrides as $key => $value) {
		$token = '{' . strtoupper(trim((string)$key, '{}')) . '}';
		$map[$token] = (string)$value;
	}
	return $map;
}

function scm_apply_placeholders($template, array $placeholder_map)
{
	$template = (string)$template;
	if ($template === '') {
		return '';
	}
	return str_replace(array_keys($placeholder_map), array_values($placeholder_map), $template);
}

function scm_content_logs_dir()
{
	return dirname(__FILE__) . '/logs';
}

function scm_content_log_file()
{
	return scm_content_logs_dir() . '/content_install.log';
}

function scm_log_content_install_action(array $context)
{
	$dir = scm_content_logs_dir();
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
	$context['logged_at'] = date('Y-m-d H:i:s');
	$line = json_encode($context);
	if ($line === false) {
		$line = '{"logged_at":"' . date('Y-m-d H:i:s') . '","error":"json_encode_failed"}';
	}
	@error_log($line . PHP_EOL, 3, scm_content_log_file());
	return true;
}

/**
 * Idempotently ensures the Phase 2 schema is present.
 * Called from pages that use manifest / history data so that existing
 * installs that have not yet run the module updater are covered.
 *
 * @param  object $db  Panel DB handle
 * @return bool
 */
function scm_ensure_phase2_schema($db)
{
	static $phase2_checked = false;
	if ($phase2_checked) {
		return true;
	}
	$phase2_checked = true;
	$prefix = DB_PREFIX;

	// ── Extend addons table ───────────────────────────────────────────────────
	$new_columns = array(
		'install_method'        => "VARCHAR(32) NOT NULL DEFAULT 'download_zip'",
		'content_version'       => "VARCHAR(64) NULL",
		'requires_stop'         => "TINYINT(1) NOT NULL DEFAULT 1",
		'backup_before_install' => "TINYINT(1) NOT NULL DEFAULT 1",
		'restart_after_install' => "TINYINT(1) NOT NULL DEFAULT 0",
		'is_cacheable'          => "TINYINT(1) NOT NULL DEFAULT 0",
		'description'           => "TEXT NULL",
		'workshop_item_id'      => "VARCHAR(64) NULL",
		'workshop_app_id'       => "VARCHAR(32) NULL",
		'target_path_template'  => "VARCHAR(255) NULL",
		'optional_folder_name'  => "VARCHAR(255) NULL",
		'config_edit_rule'      => "TEXT NULL",
		'launch_param_additions'=> "VARCHAR(255) NULL",
		'allow_user_workshop_ids' => "TINYINT(1) NOT NULL DEFAULT 1",
		'max_workshop_ids'      => "INT NULL",
		'required_workshop_ids' => "TEXT NULL",
		'blocked_workshop_ids'  => "TEXT NULL",
		'bot_working_dir'       => "VARCHAR(255) NULL",
		'bot_executable'        => "VARCHAR(255) NULL",
		'bot_arguments'         => "TEXT NULL",
		'bot_install_folder'    => "VARCHAR(255) NULL",
		'bot_os_support'        => "VARCHAR(32) NOT NULL DEFAULT 'any'",
		'bot_enable_default'    => "TINYINT(1) NOT NULL DEFAULT 1",
		'bot_uninstall_script'  => "TEXT NULL",
		'checksum_sha256'       => "VARCHAR(64) NULL",
	);
	foreach ($new_columns as $col => $definition) {
		$escaped_col   = $db->realEscapeSingle($col);
		$escaped_table = $db->realEscapeSingle($prefix . 'addons');
		$check = $db->resultQuery(
			"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE()
			    AND TABLE_NAME   = '{$escaped_table}'
			    AND COLUMN_NAME  = '{$escaped_col}'"
		);
		if (empty($check)) {
			if (!$db->query("ALTER TABLE `{$prefix}addons` ADD COLUMN `{$col}` {$definition}")) {
				return false;
			}
		}
	}

	// ── Per-server manifest ───────────────────────────────────────────────────
	$db->query(
		"CREATE TABLE IF NOT EXISTS `{$prefix}server_content_manifest` (
			`id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`home_id`         INT NOT NULL,
			`addon_id`        INT NOT NULL,
			`install_method`  VARCHAR(32)  NOT NULL DEFAULT 'download_zip',
			`content_version` VARCHAR(64)  NULL,
			`install_state`   VARCHAR(32)  NOT NULL DEFAULT 'installed',
			`checksum_sha256` VARCHAR(64)  NULL,
			`source_url`      VARCHAR(255) NULL,
			`installed_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`installed_by`    INT          NULL,
			`updated_at`      DATETIME     NULL,
			`notes`           TEXT         NULL,
			UNIQUE KEY `uniq_home_addon`  (`home_id`, `addon_id`),
			KEY `idx_home_id`             (`home_id`),
			KEY `idx_addon_id`            (`addon_id`),
			KEY `idx_install_state`       (`install_state`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
	);
	$manifest_table = $db->realEscapeSingle($prefix . 'server_content_manifest');
	$manifest_columns = array(
		'content_type'    => "VARCHAR(32) NOT NULL DEFAULT 'file_download' AFTER `addon_id`",
		'enabled'         => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `install_state`",
		'pending_action'  => "VARCHAR(32) NULL AFTER `enabled`",
		'install_path'    => "VARCHAR(512) NULL AFTER `pending_action`",
		'bot_working_dir' => "VARCHAR(255) NULL AFTER `notes`",
		'bot_executable'  => "VARCHAR(255) NULL AFTER `bot_working_dir`",
		'bot_arguments'   => "TEXT NULL AFTER `bot_executable`",
	);
	foreach ($manifest_columns as $col => $definition) {
		$escaped_col = $db->realEscapeSingle($col);
		$check = $db->resultQuery(
			"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE()
			    AND TABLE_NAME   = '{$manifest_table}'
			    AND COLUMN_NAME  = '{$escaped_col}'"
		);
		if (empty($check)) {
			if (!$db->query("ALTER TABLE `{$prefix}server_content_manifest` ADD COLUMN `{$col}` {$definition}")) {
				return false;
			}
		}
	}

	// ── Install history ───────────────────────────────────────────────────────
	$db->query(
		"CREATE TABLE IF NOT EXISTS `{$prefix}server_content_install_history` (
			`id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`home_id`         INT          NOT NULL,
			`addon_id`        INT          NOT NULL,
			`installed_by`    INT          NULL,
			`started_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`completed_at`    DATETIME     NULL,
			`install_state`   VARCHAR(32)  NOT NULL DEFAULT 'started',
			`install_method`  VARCHAR(32)  NULL,
			`content_version` VARCHAR(64)  NULL,
			`source_url`      VARCHAR(255) NULL,
			`cache_mode_used` VARCHAR(32)  NULL,
			`result_code`     INT          NULL,
			`log_output`      MEDIUMTEXT   NULL,
			KEY `idx_home_id`    (`home_id`),
			KEY `idx_addon_id`   (`addon_id`),
			KEY `idx_started_at` (`started_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
	);

	return scm_seed_server_bot_catalog($db);
}

/**
 * Returns all manifest rows for a given server home.
 *
 * @param  object $db
 * @param  int    $home_id
 * @return array
 */
function scm_get_manifest_rows($db, $home_id)
{
	$home_id = (int)$home_id;
	if ($home_id <= 0 || !scm_ensure_phase2_schema($db)) {
		return array();
	}
	$rows = $db->resultQuery(
		"SELECT m.*, a.name AS addon_name, a.addon_type, a.install_method AS addon_install_method,
		        a.content_version AS addon_content_version,
		        a.bot_working_dir AS addon_bot_working_dir,
		        a.bot_executable AS addon_bot_executable,
		        a.bot_arguments AS addon_bot_arguments
		   FROM `".DB_PREFIX."server_content_manifest` m
		   LEFT JOIN `".DB_PREFIX."addons` a ON a.addon_id = m.addon_id
		  WHERE m.home_id = {$home_id}
		  ORDER BY m.install_state ASC, m.installed_at DESC"
	);
	return is_array($rows) ? $rows : array();
}

/**
 * Creates a new install history row and returns its insert ID.
 * Returns 0 on failure.
 *
 * @param  object $db
 * @param  int    $home_id
 * @param  int    $addon_id
 * @param  int    $user_id
 * @param  string $source_url
 * @param  string $content_version
 * @param  string $install_method
 * @param  string $cache_mode_used
 * @return int    history row ID, or 0 on failure
 */
function scm_record_install_start($db, $home_id, $addon_id, $user_id, $source_url = '', $content_version = '', $install_method = 'download_zip', $cache_mode_used = 'disabled')
{
	$home_id         = (int)$home_id;
	$addon_id        = (int)$addon_id;
	$user_id         = (int)$user_id;
	$source_url      = $db->realEscapeSingle((string)$source_url);
	$content_version = $db->realEscapeSingle((string)$content_version);
	$install_method  = $db->realEscapeSingle((string)$install_method);
	$cache_mode_used = $db->realEscapeSingle((string)$cache_mode_used);

	if (!scm_ensure_phase2_schema($db)) {
		return 0;
	}
	$id = $db->resultInsertId(
		'server_content_install_history',
		array(
			'home_id'         => $home_id,
			'addon_id'        => $addon_id,
			'installed_by'    => $user_id,
			'install_state'   => 'started',
			'install_method'  => $install_method,
			'content_version' => $content_version,
			'source_url'      => $source_url,
			'cache_mode_used' => $cache_mode_used,
		)
	);
	return is_numeric($id) ? (int)$id : 0;
}

/**
 * Updates an existing install history row with the final result.
 *
 * @param  object $db
 * @param  int    $history_id
 * @param  string $state       'installed' | 'failed' | 'cancelled'
 * @param  int    $result_code Exit code (0 = success)
 * @param  string $log_output  Script/download log snippet
 * @return bool
 */
function scm_record_install_done($db, $history_id, $state = 'installed', $result_code = 0, $log_output = '')
{
	$history_id  = (int)$history_id;
	$state       = $db->realEscapeSingle((string)$state);
	$result_code = (int)$result_code;
	$log_output  = $db->realEscapeSingle((string)$log_output);
	if ($history_id <= 0) {
		return false;
	}
	return (bool)$db->query(
		"UPDATE `".DB_PREFIX."server_content_install_history`
		    SET install_state = '{$state}',
		        result_code   = {$result_code},
		        log_output    = '{$log_output}',
		        completed_at  = NOW()
		  WHERE id = {$history_id}"
	);
}

/**
 * Inserts or updates a server_content_manifest row for a successful install.
 *
 * @param  object $db
 * @param  int    $home_id
 * @param  int    $addon_id
 * @param  array  $fields  Optional overrides: install_method, content_version,
 *                          install_state, source_url, checksum_sha256, installed_by
 * @return bool
 */
function scm_upsert_manifest($db, $home_id, $addon_id, array $fields = array())
{
	$home_id  = (int)$home_id;
	$addon_id = (int)$addon_id;
	if ($home_id <= 0 || $addon_id <= 0 || !scm_ensure_phase2_schema($db)) {
		return false;
	}
	$install_method  = $db->realEscapeSingle((string)(isset($fields['install_method'])  ? $fields['install_method']  : 'download_zip'));
	$default_content_type = function_exists('scm_get_addon_type_from_install_method') ? scm_get_addon_type_from_install_method(isset($fields['install_method']) ? $fields['install_method'] : 'download_zip') : 'file_download';
	$content_type    = $db->realEscapeSingle((string)(isset($fields['content_type']) ? $fields['content_type'] : $default_content_type));
	$content_version = $db->realEscapeSingle((string)(isset($fields['content_version']) ? $fields['content_version'] : ''));
	$install_state   = $db->realEscapeSingle((string)(isset($fields['install_state'])   ? $fields['install_state']   : 'installed'));
	$source_url      = $db->realEscapeSingle((string)(isset($fields['source_url'])      ? $fields['source_url']      : ''));
	$checksum        = $db->realEscapeSingle((string)(isset($fields['checksum_sha256']) ? $fields['checksum_sha256'] : ''));
	$installed_by    = isset($fields['installed_by']) ? (int)$fields['installed_by'] : 'NULL';
	$enabled         = isset($fields['enabled']) ? (int)$fields['enabled'] : 1;
	$pending_action  = $db->realEscapeSingle((string)(isset($fields['pending_action']) ? $fields['pending_action'] : ''));
	$install_path    = $db->realEscapeSingle((string)(isset($fields['install_path']) ? $fields['install_path'] : ''));
	$bot_working_dir = $db->realEscapeSingle((string)(isset($fields['bot_working_dir']) ? $fields['bot_working_dir'] : ''));
	$bot_executable  = $db->realEscapeSingle((string)(isset($fields['bot_executable']) ? $fields['bot_executable'] : ''));
	$bot_arguments   = $db->realEscapeSingle((string)(isset($fields['bot_arguments']) ? $fields['bot_arguments'] : ''));
	if ($installed_by !== 'NULL' && $installed_by <= 0) {
		$installed_by = 'NULL';
	}

	return (bool)$db->query(
		"INSERT INTO `".DB_PREFIX."server_content_manifest`
		    (`home_id`,`addon_id`,`content_type`,`install_method`,`content_version`,`install_state`,`enabled`,`pending_action`,`install_path`,`source_url`,`checksum_sha256`,`installed_by`,`installed_at`,`updated_at`,`bot_working_dir`,`bot_executable`,`bot_arguments`)
		 VALUES
		    ({$home_id},{$addon_id},'{$content_type}','{$install_method}','{$content_version}','{$install_state}',{$enabled}," . ($pending_action === '' ? "NULL" : "'{$pending_action}'") . ",'{$install_path}','{$source_url}','{$checksum}',{$installed_by},NOW(),NOW(),'{$bot_working_dir}','{$bot_executable}','{$bot_arguments}')
		 ON DUPLICATE KEY UPDATE
		    content_type    = VALUES(content_type),
		    install_method  = VALUES(install_method),
		    content_version = VALUES(content_version),
		    install_state   = VALUES(install_state),
		    enabled         = VALUES(enabled),
		    pending_action  = VALUES(pending_action),
		    install_path    = VALUES(install_path),
		    source_url      = VALUES(source_url),
		    checksum_sha256 = VALUES(checksum_sha256),
		    bot_working_dir = VALUES(bot_working_dir),
		    bot_executable  = VALUES(bot_executable),
		    bot_arguments   = VALUES(bot_arguments),
		    installed_at    = NOW(),
		    updated_at      = NOW()"
	);
}

function scm_server_bot_generated_marker()
{
	return '# SERVER_CONTENT_SERVER_BOT_GENERATED';
}

function scm_server_bot_also_run_path(array $home_info, $server_xml)
{
	$home_path = rtrim(clean_path((string)(isset($home_info['home_path']) ? $home_info['home_path'] : '')), '/');
	if ($home_path === '') {
		return false;
	}
	$also_run_path = clean_path($home_path . '/_alsoRun.cmd');
	if (!scm_path_is_under_home($home_path, $also_run_path)) {
		return false;
	}
	return $also_run_path;
}

function scm_server_bot_normalize_row(array $row)
{
	return array(
		'name' => isset($row['addon_name']) ? (string)$row['addon_name'] : (isset($row['name']) ? (string)$row['name'] : 'Server Bot'),
		'workdir' => trim((string)(isset($row['bot_working_dir']) && $row['bot_working_dir'] !== '' ? $row['bot_working_dir'] : (isset($row['addon_bot_working_dir']) ? $row['addon_bot_working_dir'] : ''))),
		'executable' => trim((string)(isset($row['bot_executable']) && $row['bot_executable'] !== '' ? $row['bot_executable'] : (isset($row['addon_bot_executable']) ? $row['addon_bot_executable'] : ''))),
		'arguments' => trim((string)(isset($row['bot_arguments']) && $row['bot_arguments'] !== '' ? $row['bot_arguments'] : (isset($row['addon_bot_arguments']) ? $row['addon_bot_arguments'] : ''))),
	);
}

function scm_server_bot_row_enabled(array $row)
{
	return (!isset($row['enabled']) || (int)$row['enabled'] === 1)
		&& isset($row['install_state'])
		&& in_array((string)$row['install_state'], array('installed', 'updated'), true);
}

function scm_server_bot_sort_rows(array $rows)
{
	usort($rows, function ($a, $b) {
		$a_addon = isset($a['addon_id']) ? (int)$a['addon_id'] : 0;
		$b_addon = isset($b['addon_id']) ? (int)$b['addon_id'] : 0;
		if ($a_addon !== $b_addon) {
			return $a_addon < $b_addon ? -1 : 1;
		}
		$a_id = isset($a['id']) ? (int)$a['id'] : 0;
		$b_id = isset($b['id']) ? (int)$b['id'] : 0;
		if ($a_id === $b_id) {
			return 0;
		}
		return $a_id < $b_id ? -1 : 1;
	});
	return $rows;
}

function scm_server_bot_enabled_rows_from_manifest_rows(array $rows)
{
	$enabled = array();
	foreach ($rows as $row) {
		$is_server_bot = ((isset($row['addon_install_method']) && $row['addon_install_method'] === 'server_bot') || (isset($row['addon_type']) && $row['addon_type'] === 'server_bot'));
		if ($is_server_bot && scm_server_bot_row_enabled($row)) {
			$enabled[] = $row;
		}
	}
	return scm_server_bot_sort_rows($enabled);
}

function scm_server_bot_build_also_run_contents(array $bot_rows)
{
	if (empty($bot_rows)) {
		return '';
	}
	$bot = scm_server_bot_normalize_row($bot_rows[0]);
	if ($bot['workdir'] === '' || $bot['executable'] === '') {
		return false;
	}
	foreach (array($bot['workdir'], $bot['executable'], $bot['arguments']) as $value) {
		if (strpos($value, "\0") !== false || strpos($value, "\r") !== false || strpos($value, "\n") !== false) {
			return false;
		}
	}
	$wd = (strpos($bot['workdir'], '/') === 0) ? $bot['workdir'] : '%~dp0' . $bot['workdir'];
	$lines = array(
		'@echo off',
		'REM ' . scm_server_bot_generated_marker(),
		'REM Active Server Bot: ' . $bot['name'],
		'cd /d "' . $wd . '"',
		'"' . $bot['executable'] . '" ' . $bot['arguments'],
	);
	return implode("\r\n", $lines) . "\r\n";
}

function scm_server_bot_remote_file_is_managed($remote, $path)
{
	$existing = '';
	$read = (int)$remote->remote_readfile($path, $existing);
	if ($read === 0) {
		return true;
	}
	if ($read !== 1) {
		return false;
	}
	return strpos((string)$existing, scm_server_bot_generated_marker()) !== false;
}

function scm_generate_server_bot_also_run_file($remote, array $home_info, $server_xml, array $bot_rows, &$error = '')
{
	$error = '';
	$path = scm_server_bot_also_run_path($home_info, $server_xml);
	if ($path === false) {
		$error = 'Unable to resolve the server bot launch file path.';
		return false;
	}
	if (!scm_server_bot_remote_file_is_managed($remote, $path)) {
		$error = 'A non-generated _alsoRun.cmd already exists. Remove or migrate it before enabling Server Bot content.';
		return false;
	}
	if (empty($bot_rows)) {
		$remote->exec('rm -f ' . escapeshellarg($path));
		return true;
	}
	$contents = scm_server_bot_build_also_run_contents($bot_rows);
	if ($contents === false) {
		$error = 'Installed Server Bot entries do not contain a valid working directory and executable.';
		return false;
	}
	$remote->exec('mkdir -p ' . escapeshellarg(dirname($path)));
	if ((int)$remote->remote_writefile($path, $contents) !== 1) {
		$error = 'Failed to write generated Server Bot launch file.';
		return false;
	}
	return true;
}

function scm_get_enabled_server_bot_rows($db, $home_id)
{
	$home_id = (int)$home_id;
	if ($home_id <= 0 || !scm_ensure_phase2_schema($db)) {
		return array();
	}
	$rows = $db->resultQuery(
		"SELECT m.*, a.name AS addon_name, a.addon_type, a.install_method AS addon_install_method,
		        a.bot_working_dir AS addon_bot_working_dir,
		        a.bot_executable AS addon_bot_executable,
		        a.bot_arguments AS addon_bot_arguments
		   FROM `".DB_PREFIX."server_content_manifest` m
		   INNER JOIN `".DB_PREFIX."addons` a ON a.addon_id = m.addon_id
		  WHERE m.home_id = {$home_id}
		    AND (m.enabled = 1 OR m.enabled IS NULL)
		    AND m.install_state IN ('installed','updated')
		    AND (a.install_method = 'server_bot' OR a.addon_type = 'server_bot')
		  ORDER BY m.addon_id ASC, m.id ASC"
	);
	return is_array($rows) ? $rows : array();
}

function scm_regenerate_server_bot_also_run($db, $remote, array $home_info, $server_xml, &$error = '')
{
	$bot_rows = scm_get_enabled_server_bot_rows($db, (int)$home_info['home_id']);
	return scm_generate_server_bot_also_run_file($remote, $home_info, $server_xml, $bot_rows, $error);
}

function scm_generate_server_bot_also_run_for_manifest_rows($remote, array $home_info, $server_xml, array $rows, &$error = '')
{
	return scm_generate_server_bot_also_run_file($remote, $home_info, $server_xml, scm_server_bot_enabled_rows_from_manifest_rows($rows), $error);
}

function scm_server_bot_run_optional_script($remote, array $home_info, $script, &$message = '')
{
	$script = trim((string)$script);
	$message = '';
	if ($script === '') {
		return true;
	}
	if (strpos($script, "\0") !== false) {
		$message = 'Server Bot script contains an invalid character.';
		return false;
	}
	$home_path = isset($home_info['home_path']) ? (string)$home_info['home_path'] : '';
	if ($home_path === '') {
		$message = 'Unable to resolve server home for Server Bot script.';
		return false;
	}
	$output = $remote->exec("cd " . escapeshellarg($home_path) . " && /bin/bash -lc " . escapeshellarg($script) . " ; echo __SCRIPT_EXIT:$?");
	if (!is_string($output) || !preg_match('/__SCRIPT_EXIT:(\d+)/', $output, $matches) || (int)$matches[1] !== 0) {
		$message = 'Server Bot script failed.';
		return false;
	}
	return true;
}

function scm_server_bot_resolve_install_path(array $home_info, $install_folder)
{
	$home_path = rtrim(clean_path((string)(isset($home_info['home_path']) ? $home_info['home_path'] : '')), '/');
	$game_root = rtrim(clean_path((string)(isset($home_info['game_root']) ? $home_info['game_root'] : '')), '/');
	if ($game_root === '' && isset($home_info['game_key'])) {
		$path_error = '';
		$resolved_game_root = resolve_game_root($home_path, (string)$home_info['game_key'], $path_error);
		if ($resolved_game_root !== false) {
			$game_root = $resolved_game_root;
		}
	}
	$install_folder = trim((string)$install_folder);
	if ($home_path === '') {
		return false;
	}
	if ($game_root === '') {
		$game_root = $home_path;
	}
	if ($install_folder === '' || $install_folder === '.') {
		return $game_root;
	}
	$path = clean_path($install_folder);
	if (strpos($path, '/') !== 0) {
		$path = clean_path($game_root . '/' . ltrim($path, '/'));
	}
	if (!scm_path_is_under_home($home_path, $path)) {
		return false;
	}
	return $path;
}

function scm_server_bot_run_download_action($remote, array $home_info, array $addon_info, array $runtime_fields, &$install_path = '', &$message = '')
{
	$message = '';
	$install_path = '';
	$url = trim((string)(isset($addon_info['url']) ? $addon_info['url'] : ''));
	if ($url === '') {
		$install_path = scm_server_bot_resolve_install_path($home_info, isset($runtime_fields['bot_install_folder']) ? $runtime_fields['bot_install_folder'] : '');
		return $install_path !== false;
	}
	if (!preg_match('#^https?://#i', $url)) {
		$message = 'Server Bot download URL must be HTTP or HTTPS.';
		return false;
	}
	$install_path = scm_server_bot_resolve_install_path($home_info, isset($runtime_fields['bot_install_folder']) ? $runtime_fields['bot_install_folder'] : (isset($addon_info['path']) ? $addon_info['path'] : ''));
	if ($install_path === false) {
		$message = 'Server Bot install path is outside the server home.';
		return false;
	}
	$filename = basename(parse_url($url, PHP_URL_PATH));
	if ($filename === '' || $filename === '.' || $filename === '/') {
		$filename = basename((string)(isset($runtime_fields['bot_executable']) ? $runtime_fields['bot_executable'] : 'server-bot.bin'));
	}
	if ($filename === '' || strpos($filename, "\0") !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
		$message = 'Server Bot download filename is invalid.';
		return false;
	}
	$target = clean_path($install_path . '/' . $filename);
	if (!scm_path_is_under_home((string)$home_info['home_path'], $target)) {
		$message = 'Server Bot download target is outside the server home.';
		return false;
	}
	$cmd = "mkdir -p " . escapeshellarg($install_path)
		. " && (curl -fsSL " . escapeshellarg($url) . " -o " . escapeshellarg($target)
		. " || wget -q " . escapeshellarg($url) . " -O " . escapeshellarg($target) . ")"
		. " && chmod 755 " . escapeshellarg($target) . " >/dev/null 2>&1 || true; test -f " . escapeshellarg($target) . " && echo __DOWNLOAD_OK";
	$output = $remote->exec($cmd);
	if (!is_string($output) || strpos($output, '__DOWNLOAD_OK') === false) {
		$message = 'Server Bot download failed.';
		return false;
	}
	return true;
}

function scm_server_bot_manifest_action($db, $remote, array $home_info, $server_xml, $manifest_id, $action, $user_id, &$message = '')
{
	$manifest_id = (int)$manifest_id;
	$home_id = (int)$home_info['home_id'];
	$action = trim((string)$action);
	$message = '';
	if ($manifest_id <= 0 || $home_id <= 0 || !scm_ensure_phase2_schema($db)) {
		$message = 'Invalid installed content record.';
		return false;
	}
	$rows = $db->resultQuery(
		"SELECT m.*, a.name AS addon_name, a.install_method AS addon_install_method, a.addon_type,
		        a.bot_working_dir AS addon_bot_working_dir,
		        a.bot_executable AS addon_bot_executable,
		        a.bot_arguments AS addon_bot_arguments,
		        a.bot_uninstall_script,
		        a.url AS addon_url,
		        a.path AS addon_path,
		        a.content_version AS addon_content_version,
		        a.checksum_sha256 AS addon_checksum_sha256
		   FROM `".DB_PREFIX."server_content_manifest` m
		   INNER JOIN `".DB_PREFIX."addons` a ON a.addon_id = m.addon_id
		  WHERE m.id = {$manifest_id} AND m.home_id = {$home_id}
		  LIMIT 1"
	);
	if (!is_array($rows) || empty($rows)) {
		$message = 'Installed content record was not found for this server.';
		return false;
	}
	$row = $rows[0];
	if ($row['addon_install_method'] !== 'server_bot' && $row['addon_type'] !== 'server_bot') {
		$message = 'Only Server Bot content supports this action.';
		return false;
	}
	$bot = scm_server_bot_normalize_row($row);
	if (($action === 'enable' || $action === 'update') && ($bot['workdir'] === '' || $bot['executable'] === '')) {
		$message = 'Server Bot record is missing working directory or executable.';
		return false;
	}
	$updated_install_path = isset($row['install_path']) ? (string)$row['install_path'] : '';
	if ($action === 'update') {
		$download_error = '';
		$download_fields = array(
			'bot_install_folder' => $updated_install_path !== '' ? $updated_install_path : (isset($row['addon_path']) ? $row['addon_path'] : ''),
			'bot_executable' => $bot['executable'],
		);
		if (!scm_server_bot_run_download_action($remote, $home_info, $row, $download_fields, $updated_install_path, $download_error)) {
			$message = $download_error !== '' ? $download_error : 'Server Bot update action failed.';
			return false;
		}
	}
	$all_rows = scm_get_manifest_rows($db, $home_id);
	$prospective_rows = array();
	foreach ($all_rows as $manifest_row) {
		if ((int)$manifest_row['id'] !== $manifest_id) {
			$prospective_rows[] = $manifest_row;
			continue;
		}
		if ($action === 'uninstall') {
			continue;
		}
		if ($action === 'enable') {
			$manifest_row['enabled'] = 1;
			$manifest_row['install_state'] = 'installed';
		}
		elseif ($action === 'disable') {
			$manifest_row['enabled'] = 0;
			$manifest_row['install_state'] = 'disabled';
		}
		elseif ($action === 'update') {
			$manifest_row['enabled'] = 1;
			$manifest_row['install_state'] = 'updated';
			$manifest_row['content_version'] = isset($row['addon_content_version']) ? $row['addon_content_version'] : $manifest_row['content_version'];
			$manifest_row['install_path'] = $updated_install_path;
			$manifest_row['bot_working_dir'] = $bot['workdir'];
			$manifest_row['bot_executable'] = $bot['executable'];
			$manifest_row['bot_arguments'] = $bot['arguments'];
		}
		$prospective_rows[] = $manifest_row;
	}
	$error = '';
	if (!scm_generate_server_bot_also_run_for_manifest_rows($remote, $home_info, $server_xml, $prospective_rows, $error)) {
		$message = $error;
		return false;
	}
	if ($action === 'enable') {
		$db_ok = $db->query("UPDATE `".DB_PREFIX."server_content_manifest` SET enabled=1, install_state='installed', pending_action=NULL, updated_at=NOW() WHERE id={$manifest_id}");
	}
	elseif ($action === 'disable') {
		$db_ok = $db->query("UPDATE `".DB_PREFIX."server_content_manifest` SET enabled=0, install_state='disabled', pending_action=NULL, updated_at=NOW() WHERE id={$manifest_id}");
	}
	elseif ($action === 'update') {
		$db_ok = $db->query(
			"UPDATE `".DB_PREFIX."server_content_manifest`
			    SET install_state='updated',
			        content_version='".$db->realEscapeSingle(isset($row['addon_content_version']) ? $row['addon_content_version'] : $row['content_version'])."',
			        checksum_sha256='".$db->realEscapeSingle(isset($row['addon_checksum_sha256']) ? $row['addon_checksum_sha256'] : $row['checksum_sha256'])."',
			        install_path='".$db->realEscapeSingle($updated_install_path)."',
			        bot_working_dir='".$db->realEscapeSingle($bot['workdir'])."',
			        bot_executable='".$db->realEscapeSingle($bot['executable'])."',
			        bot_arguments='".$db->realEscapeSingle($bot['arguments'])."',
			        pending_action=NULL,
			        updated_at=NOW()
			  WHERE id={$manifest_id}"
		);
	}
	elseif ($action === 'uninstall') {
		$script_error = '';
		if (!scm_server_bot_run_optional_script($remote, $home_info, isset($row['bot_uninstall_script']) ? $row['bot_uninstall_script'] : '', $script_error)) {
			$message = $script_error;
			scm_regenerate_server_bot_also_run($db, $remote, $home_info, $server_xml, $error);
			return false;
		}
		$db_ok = $db->query("DELETE FROM `".DB_PREFIX."server_content_manifest` WHERE id={$manifest_id} AND home_id={$home_id}");
	}
	else {
		$message = 'Invalid Server Bot action.';
		return false;
	}
	if (empty($db_ok)) {
		scm_regenerate_server_bot_also_run($db, $remote, $home_info, $server_xml, $error);
		$message = 'Failed to update installed Server Content state.';
		return false;
	}
	$db->logger("server_bot_content home_id={$home_id} manifest_id={$manifest_id} action={$action} user_id=" . (int)$user_id);
	$labels = array(
		'enable' => 'enabled',
		'disable' => 'disabled',
		'update' => 'updated',
		'uninstall' => 'uninstalled',
	);
	$message = 'Server Bot content has been ' . (isset($labels[$action]) ? $labels[$action] : 'updated') . '.';
	return true;
}

function scm_render_installed_content_table($db, array $home_info, $home_id, $mod_id, $ip, $port)
{
	$rows = scm_get_manifest_rows($db, $home_id);
	if (empty($rows)) {
		return '';
	}
	$out = "<h3>Installed Server Content</h3>\n";
	$out .= "<table class='center'>\n";
	$out .= "<tr><th>Installed Content ID</th><th>Content</th><th>Type</th><th>Status</th><th>Version</th><th>Install Path</th><th>Actions</th></tr>\n";
	foreach ($rows as $row) {
		$is_server_bot = ((isset($row['addon_install_method']) && $row['addon_install_method'] === 'server_bot') || (isset($row['addon_type']) && $row['addon_type'] === 'server_bot'));
		$state = isset($row['install_state']) ? (string)$row['install_state'] : '';
		$enabled = !isset($row['enabled']) || (int)$row['enabled'] === 1;
		$installed_version = isset($row['content_version']) ? (string)$row['content_version'] : '';
		$catalog_version = isset($row['addon_content_version']) ? (string)$row['addon_content_version'] : '';
		$has_update = ($catalog_version !== '' && $catalog_version !== $installed_version);
		$status = ucfirst($state !== '' ? $state : 'installed') . ' / ' . ($enabled ? 'Enabled' : 'Disabled');
		if ($has_update) {
			$status .= ' / Update available';
		}
		$out .= "<tr>\n";
		$out .= "<td>" . (int)$row['id'] . "</td>\n";
		$out .= "<td>" . scm_h(isset($row['addon_name']) ? $row['addon_name'] : ('Content #' . (int)$row['addon_id'])) . "</td>\n";
		$out .= "<td>" . scm_h(isset($row['addon_install_method']) ? $row['addon_install_method'] : '') . "</td>\n";
		$out .= "<td>" . scm_h($status) . "</td>\n";
		$out .= "<td>" . scm_h($installed_version === '' ? '-' : $installed_version) . "</td>\n";
		$out .= "<td><small>" . scm_h(isset($row['install_path']) ? $row['install_path'] : '') . "</small></td>\n";
		$out .= "<td>";
		if ($is_server_bot && $state !== 'uninstalled') {
			$out .= "<form action='?m=addonsmanager&amp;p=user_addons&amp;home_id=" . (int)$home_id .
				"&amp;mod_id=" . (int)$mod_id . "&amp;ip=" . scm_h($ip) . "&amp;port=" . scm_h($port) . "' method='post' style='display:inline;'>\n";
			$out .= "<input type='hidden' name='manifest_id' value='" . (int)$row['id'] . "' />\n";
			if ($enabled) {
				$out .= "<button type='submit' name='scm_manifest_action' value='disable'>Disable</button> ";
			} else {
				$out .= "<button type='submit' name='scm_manifest_action' value='enable'>Enable</button> ";
			}
			$out .= "<button type='submit' name='scm_manifest_action' value='update'>" . ($has_update ? 'Update' : 'Reinstall') . "</button> ";
			$out .= "<button type='submit' name='scm_manifest_action' value='uninstall'>Uninstall</button>\n";
			$out .= "</form>";
		}
		else {
			$out .= "&nbsp;";
		}
		$out .= "</td>\n";
		$out .= "</tr>\n";
	}
	$out .= "</table>\n<br>\n";
	return $out;
}
