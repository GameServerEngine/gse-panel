<?php
/*
 * Shared GSP Panel installation engine.
 *
 * This file contains the install operations used by both install.php and the
 * root CLI installer. Keep database/config/module/admin creation here so the
 * browser and shell entrypoints do not diverge.
 */

if (!defined('INSTALL_STATE_FILE')) {
    define('INSTALL_STATE_FILE', 'includes/install.state.json');
}

if (!defined('INSTALL_MAINTENANCE_FILE')) {
    define('INSTALL_MAINTENANCE_FILE', 'includes/install.maintenance');
}

if (!defined('INSTALL_MAINTENANCE_TTL')) {
    define('INSTALL_MAINTENANCE_TTL', 3600);
}

function install_path($base_dir, $relative_path)
{
    return rtrim($base_dir, '/\\') . '/' . ltrim($relative_path, '/\\');
}

function install_state_path($base_dir)
{
    return install_path($base_dir, INSTALL_STATE_FILE);
}

function install_maintenance_path($base_dir)
{
    return install_path($base_dir, INSTALL_MAINTENANCE_FILE);
}

function install_state_exists($base_dir)
{
    return is_readable(install_state_path($base_dir));
}

function install_core_tables($table_prefix)
{
    return array(
        $table_prefix . 'users',
        $table_prefix . 'settings',
        $table_prefix . 'modules',
        $table_prefix . 'remote_servers',
        $table_prefix . 'server_homes',
    );
}

function install_required_tables($table_prefix)
{
    return array_merge(
        install_core_tables($table_prefix),
        array(
            $table_prefix . 'port_pools',
            $table_prefix . 'port_pool_games',
            $table_prefix . 'game_port_offsets',
            $table_prefix . 'static_port_reservations',
            $table_prefix . 'home_port_blocks',
        )
    );
}

function install_table_exists($db, $table_name)
{
    $rows = $db->resultQuery(
        "SELECT COUNT(*) AS table_count FROM information_schema.TABLES " .
        "WHERE TABLE_SCHEMA = DATABASE() " .
        "AND TABLE_TYPE = 'BASE TABLE' " .
        "AND TABLE_NAME = '" . $db->realEscapeSingle($table_name) . "'"
    );
    if (!$rows || !is_array($rows)) {
        return false;
    }
    $row = $rows[0];
    return isset($row['table_count']) && (int)$row['table_count'] > 0;
}

function install_count_admins($db, $table_prefix)
{
    $users_table = $table_prefix . 'users';
    if (!install_table_exists($db, $users_table)) {
        return 0;
    }

    $rows = $db->resultQuery(
        "SELECT COUNT(*) AS admin_count FROM " . install_quoted_identifier($users_table) .
        " WHERE `users_role` = 'admin'"
    );
    if (!$rows || !is_array($rows)) {
        return 0;
    }
    $row = $rows[0];
    return isset($row['admin_count']) ? (int)$row['admin_count'] : 0;
}

function install_completion_errors($db, $table_prefix)
{
    $errors = array();
    $missing_tables = array();
    foreach (install_required_tables($table_prefix) as $table) {
        if (!install_table_exists($db, $table)) {
            $missing_tables[] = $table;
        }
    }

    if (!empty($missing_tables)) {
        $errors[] = 'Installation did not complete because required database tables are missing: ' . implode(', ', $missing_tables) . '.';
    } elseif (install_count_admins($db, $table_prefix) < 1) {
        $errors[] = 'Installation did not complete because no admin user was found.';
    }

    return $errors;
}

function install_get_installation_status($base_dir)
{
    $status = array(
        'complete' => false,
        'state_exists' => install_state_exists($base_dir),
        'config_exists' => false,
        'config_readable' => false,
        'db_connected' => false,
        'missing_tables' => array(),
        'admin_count' => 0,
        'reason' => '',
    );

    if (install_state_exists($base_dir)) {
        $status['reason'] = 'Installer state file exists.';
    }

    $config_path = install_path($base_dir, 'includes/config.inc.php');
    $status['config_exists'] = file_exists($config_path);
    $status['config_readable'] = is_readable($config_path) && filesize($config_path) > 0;
    if (!$status['config_readable']) {
        if ($status['state_exists']) {
            $status['reason'] = 'Installer state exists, but config is missing or unreadable.';
        }
        return $status;
    }

    require($config_path);
    if (!isset($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix)) {
        $status['reason'] = 'Config exists, but required database settings are incomplete.';
        return $status;
    }

    $db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : null);
    if (!$db instanceof Database) {
        $status['reason'] = 'Config exists, but database connection failed.';
        return $status;
    }
    $status['db_connected'] = true;

    foreach (install_required_tables($table_prefix) as $table) {
        if (!install_table_exists($db, $table)) {
            $status['missing_tables'][] = $table;
        }
    }

    $status['admin_count'] = install_count_admins($db, $table_prefix);
    if (!empty($status['missing_tables'])) {
        $status['reason'] = 'Config exists, but required database tables are incomplete.';
        return $status;
    }
    if ($status['admin_count'] < 1) {
        $status['reason'] = 'Config and schema exist, but no admin user was found.';
        return $status;
    }

    $status['complete'] = true;
    $status['reason'] = 'Config, schema, modules, server tables, and an admin user were found.';
    return $status;
}

function install_panel_appears_installed($base_dir)
{
    $status = install_get_installation_status($base_dir);
    return !empty($status['complete']);
}

function install_read_state($base_dir)
{
    $path = install_state_path($base_dir);
    if (!is_readable($path)) {
        return array();
    }

    $raw = file_get_contents($path);
    $decoded = json_decode($raw ?: '', true);
    return is_array($decoded) ? $decoded : array();
}

function install_maintenance_gate_open($base_dir)
{
    $path = install_maintenance_path($base_dir);
    if (!is_file($path)) {
        return false;
    }

    $mtime = filemtime($path);
    return $mtime !== false && (time() - $mtime) <= INSTALL_MAINTENANCE_TTL;
}

function install_write_state($base_dir, array $options)
{
    $panel_domain = trim((string)($options['panel_domain'] ?? ''));
    $panel_ssl = install_bool($options['panel_ssl'] ?? false);
    $panel_public_url = '';
    if ($panel_domain !== '') {
        $panel_public_url = ($panel_ssl ? 'https://' : 'http://') . $panel_domain . '/';
    }

    $state = array(
        'installed' => true,
        'installed_at' => gmdate('c'),
        'installed_via' => $options['install_via'] ?? 'unknown',
        'installed_by' => $options['installed_by'] ?? '',
        'db_host' => $options['db_host'] ?? '',
        'db_port' => (string)($options['db_port'] ?? '3306'),
        'db_name' => $options['db_name'] ?? '',
        'table_prefix' => $options['table_prefix'] ?? '',
        'admin_user' => $options['admin_user'] ?? 'admin',
        'panel_domain' => $panel_domain,
        'panel_use_vhost' => install_bool($options['panel_use_vhost'] ?? false),
        'panel_ssl' => $panel_ssl,
        'panel_public_url' => $panel_public_url,
        'repo_url' => $options['repo_url'] ?? '',
        'repo_branch' => $options['repo_branch'] ?? '',
    );

    $path = install_state_path($base_dir);
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . "\n") === false) {
        return false;
    }
    @chmod($path, 0660);
    return true;
}

function install_remove_maintenance_gate($base_dir)
{
    $path = install_maintenance_path($base_dir);
    if (is_file($path)) {
        @unlink($path);
    }
}

function install_bool($value)
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function install_escape_config($value)
{
    return addcslashes((string)$value, "\\\"");
}

function install_identifier($identifier)
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$identifier);
}

function install_quoted_identifier($identifier)
{
    return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function install_validate_options(array $options)
{
    $errors = array();
    $required = array('db_host', 'db_user', 'db_name', 'admin_user', 'admin_pass');
    foreach ($required as $field) {
        if (!isset($options[$field]) || trim((string)$options[$field]) === '') {
            $errors[] = $field . ' is required.';
        }
    }

    $db_port = $options['db_port'] ?? '3306';
    if (!ctype_digit((string)$db_port) || (int)$db_port < 1 || (int)$db_port > 65535) {
        $errors[] = 'db_port must be a valid TCP port.';
    }

    $prefix = $options['table_prefix'] ?? '';
    if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        $errors[] = 'table_prefix may be blank or contain only letters, numbers, and underscores.';
    }

    $db_name = $options['db_name'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $db_name)) {
        $errors[] = 'db_name may contain only letters, numbers, underscores, and hyphens.';
    }

    $admin_user = $options['admin_user'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_.@-]+$/', $admin_user)) {
        $errors[] = 'admin_user contains unsupported characters.';
    }

    if (strlen((string)($options['admin_pass'] ?? '')) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }

    $panel_domain = trim((string)($options['panel_domain'] ?? ''));
    if ($panel_domain !== '' && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*(?::[0-9]{1,5})?$/', $panel_domain)) {
        $errors[] = 'panel_domain must be a hostname, domain, or IP address without a URL scheme or path.';
    }
    if ($panel_domain !== '' && strpos($panel_domain, ':') !== false) {
        $domain_port = substr($panel_domain, strrpos($panel_domain, ':') + 1);
        if (!ctype_digit($domain_port) || (int)$domain_port < 1 || (int)$domain_port > 65535) {
            $errors[] = 'panel_domain port must be in range 1-65535.';
        }
    }

    return $errors;
}

function install_config_contents(array $options)
{
    /*
     * Structurally mirrors includes/config.inc.php.example: same credential
     * block, same DEBUG_MODE define, same debug.php require. Keep this in
     * exact sync with that template by hand — see install_write_config()
     * for a runtime assertion that the two agree on which symbols are
     * defined.
     */
    $db_type = $options['db_type'] ?? 'mysql';
    $debug_mode = install_bool($options['debug_mode'] ?? false);
    return "<?php\n" .
        "###############################################\n" .
        "# Site configuration\n" .
        "###############################################\n" .
        "\$db_host=\"" . install_escape_config($options['db_host']) . "\";\n" .
        "\$db_port=\"" . install_escape_config($options['db_port'] ?? '3306') . "\";\n" .
        "\$db_user=\"" . install_escape_config($options['db_user']) . "\";\n" .
        "\$db_pass=\"" . install_escape_config($options['db_pass'] ?? '') . "\";\n" .
        "\$db_name=\"" . install_escape_config($options['db_name']) . "\";\n" .
        "\$table_prefix=\"" . install_escape_config($options['table_prefix'] ?? '') . "\";\n" .
        "\$db_type=\"" . install_escape_config($db_type) . "\";\n" .
        "\n" .
        "###############################################\n" .
        "# Debug mode\n" .
        "# Set to true on dev/staging, false on production.\n" .
        "###############################################\n" .
        "define('DEBUG_MODE', " . ($debug_mode ? 'true' : 'false') . ");\n" .
        "\n" .
        "// Load the debug system immediately after credentials are known\n" .
        "require_once __DIR__ . '/debug.php';\n";
}

/*
 * Detects the web server user to chown includes/config.inc.php to when the
 * installer runs as root (e.g. scripts/install-panel.sh via sudo). Mirrors
 * the default used by tools/install_panel_cli.php --repair-permissions
 * (--owner www-data --group www-data). Falls back through the common
 * distro web user names and finally to 'www-data' if none are found.
 */
function install_detect_web_user()
{
    if (!function_exists('posix_getpwnam')) {
        return 'www-data';
    }
    foreach (array('www-data', 'apache', 'nginx', 'httpd') as $candidate) {
        if (@posix_getpwnam($candidate) !== false) {
            return $candidate;
        }
    }
    return 'www-data';
}

function install_config_write_permission_errors($base_dir)
{
    $includes_dir = install_path($base_dir, 'includes');
    $config_path = install_path($base_dir, 'includes/config.inc.php');
    $errors = array();
    $guidance = 'The web server user must have write permission. For the panel-test deployment, use owner/group www-data:www-data with group write, and make sure deploy user dev is a member of the www-data group.';

    if (!is_dir($includes_dir)) {
        $errors[] = "Config directory is missing: {$includes_dir}. {$guidance}";
        return $errors;
    }

    if (!is_writable($includes_dir)) {
        $errors[] = "Config directory is not writable: {$includes_dir}. {$guidance}";
    }

    if (file_exists($config_path) && !is_writable($config_path)) {
        $errors[] = "Config file is not writable: {$config_path}. {$guidance}";
    }

    if (!file_exists($config_path) && !is_writable($includes_dir)) {
        $errors[] = "Config file cannot be created: {$config_path}. The parent directory is not writable. {$guidance}";
    }

    return array_values(array_unique($errors));
}

function install_write_config($base_dir, array $options)
{
    $path = install_path($base_dir, 'includes/config.inc.php');
    if (!is_dir(dirname($path))) {
        return false;
    }

    $result = file_put_contents($path, install_config_contents($options));
    if ($result === false) {
        return false;
    }
    @chmod($path, 0640);

    // When run as root (e.g. scripts/install-panel.sh via sudo), hand the
    // config file to the detected web user so the web server can still read
    // it under the tighter 0640 permissions.
    if (function_exists('posix_getuid') && posix_getuid() === 0 && function_exists('chown')) {
        $web_user = install_detect_web_user();
        @chown($path, $web_user);
        @chgrp($path, $web_user);
    }

    return true;
}

function install_create_module_tables($db, $table_prefix)
{
    $db->query("DROP TABLE IF EXISTS `" . $table_prefix . "modules`");
    $db->query("CREATE TABLE IF NOT EXISTS `" . $table_prefix . "modules` (
        `id` smallint(5) unsigned NOT NULL auto_increment,
        `title` varchar(100) NOT NULL default '',
        `folder` varchar(100) NOT NULL default '',
        `version` varchar(10) NOT NULL default '0',
        `db_version` int(10) NOT NULL default '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `folder` (`folder`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");

    $db->query("DROP TABLE IF EXISTS `" . $table_prefix . "module_menus`");
    $db->query("CREATE TABLE IF NOT EXISTS `" . $table_prefix . "module_menus` (
        `module_id` int(11) NOT NULL COMMENT 'This references to modules.id',
        `subpage` varchar(64) NOT NULL default '',
        `group` varchar(32) NOT NULL,
        `menu_name` varchar(128) NOT NULL,
        `pos` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`module_id`, `subpage`, `group`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");
}

function install_split_sql_statements($sql)
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
    $sql = preg_replace('/\/\*![\s\S]*?\*\//', '', $sql);
    $statements = array();
    $buffer = '';
    $quote = null;
    $escaped = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $buffer .= $char;

        if ($escaped) {
            $escaped = false;
            continue;
        }
        if ($char === '\\') {
            $escaped = true;
            continue;
        }
        if ($quote !== null) {
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim(substr($buffer, 0, -1));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function install_rewrite_schema_sql($sql, $table_prefix)
{
    $sql = preg_replace('/\bCREATE\s+TABLE\s+`/i', 'CREATE TABLE IF NOT EXISTS `', (string)$sql);
    $sql = str_replace('DB_PREFIX', (string)$table_prefix, (string)$sql);
    return $sql;
}

function install_schema_error_is_safe_duplicate($error)
{
    return preg_match('/Duplicate column|Duplicate key name|Multiple primary key|already exists/i', (string)$error) === 1;
}

function install_apply_schema_query($db, $query, array &$errors)
{
    $query = trim((string)$query);
    if ($query === '') {
        return true;
    }

    if ($db->query($query)) {
        return true;
    }

    $error = $db->getError();
    if (install_schema_error_is_safe_duplicate($error)) {
        return true;
    }

    $errors[] = 'Canonical schema step failed. Sensitive database details are hidden.';
    error_log('GSE installer schema query failed: ' . $error . ' SQL: ' . substr($query, 0, 500));
    return false;
}

function install_apply_schema_step($db, $step, array &$errors)
{
    if (is_callable($step)) {
        $ok = (bool)call_user_func($step, $db);
        if (!$ok) {
            $errors[] = 'Canonical schema guard failed. Sensitive database details are hidden.';
            error_log('GSE installer callable schema guard failed: ' . $db->getError());
        }
        return $ok;
    }

    return install_apply_schema_query($db, $step, $errors);
}

function install_load_module_metadata($base_dir, $module)
{
    $path = install_path($base_dir, 'modules/' . $module . '/module.php');
    if (!is_readable($path)) {
        return null;
    }

    unset($module_title, $module_version, $db_version, $module_required, $module_menus, $module_access_rights, $install_queries);
    include($path);
    if (!isset($module_title, $module_version)) {
        return null;
    }

    return array(
        'title' => (string)$module_title,
        'version' => (string)$module_version,
        'db_version' => isset($db_version) ? (int)$db_version : 0,
        'required' => !empty($module_required),
        'menus' => isset($module_menus) && is_array($module_menus) ? $module_menus : array(),
        'access_rights' => isset($module_access_rights) && is_array($module_access_rights) ? $module_access_rights : array(),
        'install_queries' => isset($install_queries) && is_array($install_queries) ? $install_queries : array(),
    );
}

/*
 * Modules that can never be disabled via Module Manager (see
 * module_cannot_be_disabled() in includes/helpers.php for the
 * authoritative list; kept here as a thin wrapper for install-time code).
 */
if (!function_exists('module_cannot_be_disabled')) {
    require_once(dirname(__FILE__) . '/helpers.php');
}

function install_core_modules()
{
    return array('modulemanager', 'settings', 'user_admin');
}

function install_module_is_core($folder)
{
    return module_cannot_be_disabled((string)$folder);
}

function install_builtin_modules($base_dir = null)
{
    /*
     * Every module found under modules/ (i.e. every directory that has a
     * module.php) is discovered and installed. This mirrors the discovery
     * logic in tools/build_gse_panel_schema.php: skip ".bak" copies and any
     * directory without a module.php (e.g. the orphan modules/mods dir).
     */
    $base_dir = $base_dir ?: dirname(__DIR__);
    $modules_dir = install_path($base_dir, 'modules');
    $module_dirs = array();

    if (is_dir($modules_dir)) {
        foreach (scandir($modules_dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Skip stray backup copies of modules left on disk (e.g.
            // "steam_workshop.bak.20260609-145834") — these are not real
            // modules and must never be registered/installed.
            if (preg_match('/\.bak(\.|$)/i', $entry)) {
                continue;
            }
            $module_php = $modules_dir . '/' . $entry . '/module.php';
            if (is_file($module_php)) {
                $module_dirs[] = $entry;
            }
        }
    }

    sort($module_dirs);
    return $module_dirs;
}

/*
 * Imports the canonical schema dump (install/gse_panel.sql) into the target
 * database, rewriting the baked-in `gsp_` table prefix to the configured
 * table_prefix if one is set. install/gse_panel.sql is generated ahead of
 * time by scripts/build-schema.sh (tools/build_gse_panel_schema.php) from
 * panel.sql plus every module's install_queries (all versions) — see that
 * script for how it is kept in sync. install/reinstall modes call this;
 * upgrade mode instead applies versioned migrations from sql/ against an
 * existing, populated database and must not touch this file.
 */
function install_import_canonical_schema($db, $base_dir, $table_prefix)
{
    $errors = array();
    $schema_sql = install_path($base_dir, 'install/gse_panel.sql');
    if (!is_readable($schema_sql)) {
        return array('success' => false, 'errors' => array('Canonical schema file install/gse_panel.sql is missing or unreadable.'));
    }

    $sql = install_rewrite_schema_sql(file_get_contents($schema_sql), $table_prefix);
    foreach (install_split_sql_statements($sql) as $statement) {
        if (!install_apply_schema_query($db, $statement, $errors)) {
            return array('success' => false, 'errors' => $errors);
        }
    }

    return array('success' => true, 'errors' => array());
}

function install_create_canonical_schema($db, $base_dir, $table_prefix)
{
    return install_import_canonical_schema($db, $base_dir, $table_prefix);
}

function install_register_builtin_modules($db, $base_dir)
{
    $registered = 0;
    foreach (install_builtin_modules($base_dir) as $module) {
        $metadata = install_load_module_metadata($base_dir, $module);
        if (!$metadata) {
            continue;
        }

        $title = $db->realEscapeSingle($metadata['title']);
        $folder = $db->realEscapeSingle($module);
        $version = $db->realEscapeSingle($metadata['version']);
        $db_version = (int)$metadata['db_version'];
        $query = "INSERT INTO `" . DB_PREFIX . "modules` (`title`,`folder`,`version`,`db_version`,`enabled`) " .
            "VALUES ('{$title}','{$folder}','{$version}',{$db_version},1) " .
            "ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `version`=VALUES(`version`), `db_version`=VALUES(`db_version`)";
        if (!$db->query($query)) {
            return array('success' => false, 'errors' => array('Built-in module registration failed. Sensitive database details are hidden.'));
        }

        $rows = $db->resultQuery("SELECT `id` FROM `" . DB_PREFIX . "modules` WHERE `folder` = '{$folder}' LIMIT 1");
        if (!$rows || !isset($rows[0]['id'])) {
            return array('success' => false, 'errors' => array('Built-in module registration could not verify module row.'));
        }
        $module_id = (int)$rows[0]['id'];

        $db->delModuleMenu($module_id);
        foreach ($metadata['menus'] as $menu) {
            $db->addModuleMenu(
                $module_id,
                isset($menu['subpage']) ? $menu['subpage'] : '',
                isset($menu['group']) ? $menu['group'] : 'user',
                isset($menu['name']) ? $menu['name'] : $metadata['title']
            );
        }

        $db->clearModuleAccessRights($module_id);
        foreach ($metadata['access_rights'] as $flag => $description) {
            $db->setModuleAccessRight($module_id, $flag, $description);
        }
        $registered++;
    }

    return array('success' => true, 'registered' => $registered);
}

function install_selected_database($db)
{
    $rows = $db->resultQuery("SELECT DATABASE() AS selected_database");
    if (!$rows || !is_array($rows)) {
        return null;
    }
    $row = $rows[0];
    return $row['selected_database'] ?? null;
}

function install_list_base_tables($db)
{
    $rows = $db->resultQuery("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if (!$rows || !is_array($rows)) {
        return array();
    }

    $tables = array();
    foreach ($rows as $row) {
        $values = array_values($row);
        if (isset($values[0]) && $values[0] !== '') {
            $tables[] = $values[0];
        }
    }
    sort($tables);
    return $tables;
}

function install_tables_for_reinstall(array $tables, $table_prefix)
{
    if ($table_prefix === '') {
        return $tables;
    }

    $matched = array();
    foreach ($tables as $table) {
        if (strpos($table, $table_prefix) === 0) {
            $matched[] = $table;
        }
    }
    return $matched;
}

function install_extract_panel_table_suffixes($text)
{
    $suffixes = array();

    if (preg_match_all('/DB_PREFIX(?:"\s*\.\s*"|\'\s*\.\s*\'|\s*\.\s*"|\s*\.\s*\'|`)?([a-zA-Z0-9_]+)/', (string)$text, $matches)) {
        foreach ($matches[1] as $suffix) {
            $suffixes[$suffix] = true;
        }
    }

    return array_keys($suffixes);
}

function install_known_panel_tables($base_dir, $table_prefix)
{
    $suffixes = array();
    foreach (install_core_tables('') as $table) {
        $suffixes[$table] = true;
    }
    $suffixes['module_menus'] = true;

    foreach (array('install/gse_panel.sql', 'panel.sql') as $schema_relative_path) {
        $schema_path = install_path($base_dir, $schema_relative_path);
        if (is_readable($schema_path)) {
            foreach (install_extract_panel_table_suffixes(file_get_contents($schema_path)) as $suffix) {
                $suffixes[$suffix] = true;
            }
        }
    }

    $modules_dir = install_path($base_dir, 'modules');
    if (is_dir($modules_dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modules_dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $name = $file->getFilename();
            if ($name !== 'module.php' && substr($name, -4) !== '.sql') {
                continue;
            }
            if (!is_readable($path)) {
                continue;
            }
            foreach (install_extract_panel_table_suffixes(file_get_contents($path)) as $suffix) {
                $suffixes[$suffix] = true;
            }
        }
    }

    $tables = array();
    foreach (array_keys($suffixes) as $suffix) {
        if ($suffix === '' || $suffix === 'table_name') {
            continue;
        }
        $tables[] = $table_prefix . $suffix;
    }
    sort($tables);
    return $tables;
}

function install_tables_for_reinstall_scope(array $tables, $table_prefix, $base_dir)
{
    if ($table_prefix !== '') {
        return array(
            'tables_to_drop' => install_tables_for_reinstall($tables, $table_prefix),
            'unmatched' => array(),
            'scope' => "tables prefixed with {$table_prefix}",
        );
    }

    $known = array_fill_keys(install_known_panel_tables($base_dir, ''), true);
    $tables_to_drop = array();
    $unmatched = array();
    foreach ($tables as $table) {
        if (isset($known[$table])) {
            $tables_to_drop[] = $table;
        } else {
            $unmatched[] = $table;
        }
    }

    return array(
        'tables_to_drop' => $tables_to_drop,
        'unmatched' => $unmatched,
        'scope' => 'known unprefixed Panel tables',
    );
}

function install_drop_existing_tables($db, $db_name, $table_prefix, $allow_drop_all_tables = false, $base_dir = null)
{
    $base_dir = $base_dir ?: dirname(__DIR__);
    $result = array(
        'success' => false,
        'selected_database' => null,
        'available_count' => 0,
        'dropped' => array(),
        'failed' => array(),
        'unmatched' => array(),
        'requires_confirmation' => false,
        'scope' => $table_prefix === '' ? 'known unprefixed Panel tables' : "tables prefixed with {$table_prefix}",
        'errors' => array(),
    );

    $selected_database = install_selected_database($db);
    $result['selected_database'] = $selected_database;
    if ($selected_database !== $db_name) {
        $result['errors'][] = "Installer connected to database '{$selected_database}', but expected '{$db_name}'. Installation stopped before dropping tables.";
        return $result;
    }

    $tables = install_list_base_tables($db);
    $result['available_count'] = count($tables);
    $scope = install_tables_for_reinstall_scope($tables, $table_prefix, $base_dir);
    $tables_to_drop = $scope['tables_to_drop'];
    $result['unmatched'] = $scope['unmatched'];
    $result['scope'] = $scope['scope'];

    if ($table_prefix === '' && count($tables_to_drop) > 0 && !$allow_drop_all_tables) {
        $result['requires_confirmation'] = true;
        $message = 'Table prefix is blank. The installer found existing unprefixed Panel table(s) that would be dropped: ' . implode(', ', $tables_to_drop) . '. Confirm blank-prefix Panel table reset to continue.';
        if (!empty($result['unmatched'])) {
            $message .= ' Unrecognized table(s) will not be dropped automatically: ' . implode(', ', $result['unmatched']) . '.';
        }
        $result['errors'][] = $message;
        return $result;
    }

    if (count($tables_to_drop) === 0) {
        $result['success'] = true;
        return $result;
    }

    $safe_db = install_quoted_identifier($db_name);
    $fk_disabled = $db->query("SET FOREIGN_KEY_CHECKS = 0");
    if (!$fk_disabled) {
        $result['errors'][] = 'Unable to disable foreign key checks before dropping existing tables.';
        return $result;
    }

    foreach ($tables_to_drop as $table) {
        $safe_table = install_quoted_identifier($table);
        $ok = $db->query("DROP TABLE IF EXISTS {$safe_db}.{$safe_table}");
        if ($ok) {
            $result['dropped'][] = $table;
        } else {
            $result['failed'][] = $table;
        }
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");

    if (!empty($result['failed'])) {
        $result['errors'][] = 'Failed to drop existing table(s): ' . implode(', ', $result['failed']) . '. Installation stopped before importing schema.';
        return $result;
    }

    $result['success'] = true;
    return $result;
}

function install_default_settings()
{
    return array(
        'title' => 'Gameserver Engine Panel',
        'slogan' => function_exists('get_lang') ? get_lang('slogan') : 'Gameserver Engine',
        'panel_version' => '0',
        'version_type' => 'GSP',
        'theme' => 'SimpleBootstrap',
        'welcome_title' => '1',
        'welcome_title_message' => '<h0>' . (function_exists('get_lang') ? get_lang('default_welcome_title_message') : 'Welcome') . '</h0>',
        'page_auto_refresh' => '1',
        'port_block_size' => '10',
    );
}

/*
 * Four install modes (item E of the installer rework):
 *   - install:      fresh install into an empty database.
 *   - reinstall:    same as install, but the target DB already has tables.
 *                   Requires options['confirm_db_name'] to exactly match
 *                   options['db_name'] before anything is dropped.
 *   - reconfigure:  rewrites includes/config.inc.php (db creds, prefix,
 *                   DEBUG_MODE) and domain/https settings ONLY. Never runs
 *                   DROP or CREATE TABLE.
 *   - upgrade:      applies pending versioned migrations from sql/ against
 *                   an existing, populated database. Does not touch config.
 *
 * install/reinstall use install_run_install_or_reinstall(); reconfigure
 * and upgrade have their own dedicated functions below. This function is
 * the single public entry point used by both install.php and the CLI
 * installer.
 */
function install_run(array $options)
{
    $mode = $options['mode'] ?? 'install';
    switch ($mode) {
        case 'reconfigure':
            return install_run_reconfigure($options);
        case 'upgrade':
            return install_run_upgrade($options);
        case 'install':
        case 'reinstall':
            return install_run_install_or_reinstall($options, $mode);
        default:
            return array('success' => false, 'errors' => array(
                "Unknown install mode '{$mode}'. Expected one of: install, reinstall, reconfigure, upgrade."
            ));
    }
}

function install_run_install_or_reinstall(array $options, $mode = 'install')
{
    $base_dir = $options['base_dir'] ?? dirname(__DIR__);
    $dry_run = install_bool($options['dry_run'] ?? false);
    $options['db_type'] = $options['db_type'] ?? 'mysql';
    $options['db_port'] = (string)($options['db_port'] ?? '3306');
    $options['table_prefix'] = $options['table_prefix'] ?? '';
    $options['admin_email'] = $options['admin_email'] ?? 'admin@localhost';
    $options['install_via'] = $options['install_via'] ?? 'unknown';
    $options['debug_mode'] = install_bool($options['debug_mode'] ?? false);

    $errors = install_validate_options($options);
    if (!empty($errors)) {
        return array('success' => false, 'errors' => $errors);
    }

    if ($mode === 'reinstall') {
        $confirm_db_name = (string)($options['confirm_db_name'] ?? '');
        if ($confirm_db_name === '' || $confirm_db_name !== $options['db_name']) {
            return array('success' => false, 'errors' => array(
                'Reinstall requires confirm_db_name (CLI: --confirm-db-name=<db_name>; web: type the exact database name) to match the configured database name exactly.'
            ));
        }
        // A confirmed reinstall is allowed to drop whatever tables are in
        // its known drop-scope (prefix-matched, or the known panel table
        // list for a blank prefix — see install_drop_existing_tables).
        $options['allow_drop_all_tables'] = true;
    } else {
        $options['allow_drop_all_tables'] = false;
    }

    if ($dry_run) {
        return array(
            'success' => true,
            'dry_run' => true,
            'messages' => array('Validated installer options. No files or database state changed.'),
        );
    }

    $old_cwd = getcwd();
    chdir($base_dir);

    $config_permission_errors = install_config_write_permission_errors($base_dir);
    if (!empty($config_permission_errors)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array('success' => false, 'errors' => $config_permission_errors);
    }

    if (!install_write_config($base_dir, $options)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array('success' => false, 'errors' => array(
            'Unable to write includes/config.inc.php. Confirm the path is writable by the web server user. For panel-test, expected ownership is www-data:www-data, directories should be 2775, writable files should be 664 or 660 for sensitive config/state files, and deploy user dev should be in the www-data group.'
        ));
    }

    $db = createDatabaseConnection(
        $options['db_type'],
        $options['db_host'],
        $options['db_user'],
        $options['db_pass'] ?? '',
        $options['db_name'],
        $options['table_prefix'],
        (int)$options['db_port']
    );

    $error_text = '';
    if (get_db_error_text($db, $error_text)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array('success' => false, 'errors' => array($error_text));
    }
    $GLOBALS['db'] = $db;

    if ($mode === 'install') {
        $existing_tables = install_list_base_tables($db);
        $scope = install_tables_for_reinstall_scope($existing_tables, $options['table_prefix'], $base_dir);
        if (!empty($scope['tables_to_drop'])) {
            if ($old_cwd !== false) {
                chdir($old_cwd);
            }
            return array('success' => false, 'errors' => array(
                'Database already has Panel table(s): ' . implode(', ', $scope['tables_to_drop']) . '. ' .
                'Use mode=reinstall (with confirm_db_name) to reset an existing database, or point install at an empty database.'
            ));
        }
    }

    $drop = install_drop_existing_tables($db, $options['db_name'], $options['table_prefix'], $options['allow_drop_all_tables'], $base_dir);
    if (empty($drop['success'])) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array(
            'success' => false,
            'errors' => !empty($drop['errors']) ? $drop['errors'] : array('Existing database tables could not be dropped. Installation stopped before modifying schema.'),
            'drop' => $drop,
        );
    }
    $migration = array('renamed' => 0, 'skipped' => 0);
    $schema = install_create_canonical_schema($db, $base_dir, $options['table_prefix']);
    if (empty($schema['success'])) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array(
            'success' => false,
            'drop' => $drop,
            'migration' => $migration,
            'errors' => !empty($schema['errors']) ? $schema['errors'] : array('Canonical schema creation failed.'),
        );
    }

    $module_registration = install_register_builtin_modules($db, $base_dir);
    if (empty($module_registration['success'])) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array(
            'success' => false,
            'drop' => $drop,
            'migration' => $migration,
            'errors' => !empty($module_registration['errors']) ? $module_registration['errors'] : array('Built-in module registration failed.'),
        );
    }

    $db->setSettings(install_default_settings());

    $existing_admin = $db->getUser($options['admin_user']);
    if (!$existing_admin) {
        $db->addUser($options['admin_user'], $options['admin_pass'], 'admin', $options['admin_email']);
    }

    if (function_exists('updateGameConfigsPostInstall')) {
        updateGameConfigsPostInstall();
    }

    $completion_errors = install_completion_errors($db, $options['table_prefix']);
    if (!empty($completion_errors)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array(
            'success' => false,
            'drop' => $drop,
            'migration' => $migration,
            'modules_registered' => $module_registration['registered'] ?? 0,
            'admin_created' => !$existing_admin,
            'errors' => $completion_errors,
        );
    }

    $state_written = install_write_state($base_dir, $options);
    install_remove_maintenance_gate($base_dir);

    if ($old_cwd !== false) {
        chdir($old_cwd);
    }

    return array(
        'success' => $state_written,
        'drop' => $drop,
        'migration' => $migration,
        'modules_registered' => $module_registration['registered'] ?? 0,
        'admin_created' => !$existing_admin,
        'state_file' => INSTALL_STATE_FILE,
        'errors' => $state_written ? array() : array('Installation completed, but lock/state file could not be written.'),
    );
}

/*
 * reconfigure mode: rewrites includes/config.inc.php (db creds, table
 * prefix, DEBUG_MODE) and, if provided, domain/https settings rows. Never
 * runs DROP or CREATE TABLE — if a database connection is needed at all
 * (only when domain/https settings were supplied) it is used solely for a
 * settings UPDATE/INSERT, never schema DDL.
 */
function install_run_reconfigure(array $options)
{
    $base_dir = $options['base_dir'] ?? dirname(__DIR__);
    $options['db_type'] = $options['db_type'] ?? 'mysql';
    $options['db_port'] = (string)($options['db_port'] ?? '3306');
    $options['table_prefix'] = $options['table_prefix'] ?? '';
    $options['debug_mode'] = install_bool($options['debug_mode'] ?? false);

    $errors = array();
    foreach (array('db_host', 'db_user', 'db_name') as $field) {
        if (!isset($options[$field]) || trim((string)$options[$field]) === '') {
            $errors[] = $field . ' is required.';
        }
    }
    if (!ctype_digit((string)$options['db_port']) || (int)$options['db_port'] < 1 || (int)$options['db_port'] > 65535) {
        $errors[] = 'db_port must be a valid TCP port.';
    }
    if ($options['table_prefix'] !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $options['table_prefix'])) {
        $errors[] = 'table_prefix may be blank or contain only letters, numbers, and underscores.';
    }
    if (!empty($errors)) {
        return array('success' => false, 'errors' => $errors);
    }

    $dry_run = install_bool($options['dry_run'] ?? false);
    if ($dry_run) {
        return array(
            'success' => true,
            'dry_run' => true,
            'messages' => array('Validated reconfigure options. No files or database state changed.'),
        );
    }

    $old_cwd = getcwd();
    chdir($base_dir);

    $config_permission_errors = install_config_write_permission_errors($base_dir);
    if (!empty($config_permission_errors)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array('success' => false, 'errors' => $config_permission_errors);
    }

    if (!install_write_config($base_dir, $options)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array('success' => false, 'errors' => array('Unable to write includes/config.inc.php.'));
    }

    $settings_updated = array();
    $domain_fields = array('panel_domain', 'storefront_domain', 'https_mode');
    $has_domain_fields = false;
    foreach ($domain_fields as $field) {
        if (isset($options[$field]) && trim((string)$options[$field]) !== '') {
            $has_domain_fields = true;
            break;
        }
    }

    if ($has_domain_fields) {
        $db = createDatabaseConnection(
            $options['db_type'],
            $options['db_host'],
            $options['db_user'],
            $options['db_pass'] ?? '',
            $options['db_name'],
            $options['table_prefix'],
            (int)$options['db_port']
        );
        $error_text = '';
        if (get_db_error_text($db, $error_text)) {
            if ($old_cwd !== false) {
                chdir($old_cwd);
            }
            return array(
                'success' => false,
                'errors' => array($error_text),
                'messages' => array('includes/config.inc.php was rewritten, but domain/https settings could not be updated because the database connection failed.'),
            );
        }

        $settings = array();
        foreach ($domain_fields as $field) {
            if (isset($options[$field]) && trim((string)$options[$field]) !== '') {
                $settings[$field] = (string)$options[$field];
            }
        }
        $db->setSettings($settings);
        $settings_updated = array_keys($settings);
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
    } else {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
    }

    return array(
        'success' => true,
        'settings_updated' => $settings_updated,
        'messages' => array(
            'Reconfigured includes/config.inc.php.' .
            (!empty($settings_updated) ? ' Updated settings: ' . implode(', ', $settings_updated) . '.' : '')
        ),
        'errors' => array(),
    );
}

/*
 * Lists versioned migration files in sql/ named "<number>_description.sql",
 * keyed by their numeric version, sorted ascending. Non-numeric-prefixed
 * files in sql/ (e.g. one-off ad-hoc scripts) are ignored by the automatic
 * upgrade runner and must continue to be applied manually.
 */
function install_list_migration_files($base_dir)
{
    $sql_dir = install_path($base_dir, 'sql');
    $migrations = array();
    if (!is_dir($sql_dir)) {
        return $migrations;
    }
    foreach (scandir($sql_dir) as $entry) {
        if (preg_match('/^(\d+)_.*\.sql$/', $entry, $matches)) {
            $migrations[(int)$matches[1]] = $entry;
        }
    }
    ksort($migrations, SORT_NUMERIC);
    return $migrations;
}

/*
 * upgrade mode: applies pending versioned migrations from sql/ against an
 * existing, populated database. Tracks progress via a `schema_migration_version`
 * row in DB_PREFIXsettings so re-running upgrade is idempotent. Does not touch
 * config.inc.php or vhosts.
 */
function install_run_upgrade(array $options)
{
    $base_dir = $options['base_dir'] ?? dirname(__DIR__);
    $options['db_type'] = $options['db_type'] ?? 'mysql';
    $options['db_port'] = (string)($options['db_port'] ?? '3306');
    $options['table_prefix'] = $options['table_prefix'] ?? '';

    $errors = array();
    foreach (array('db_host', 'db_user', 'db_name') as $field) {
        if (!isset($options[$field]) || trim((string)$options[$field]) === '') {
            $errors[] = $field . ' is required.';
        }
    }
    if (!empty($errors)) {
        return array('success' => false, 'errors' => $errors);
    }

    $dry_run = install_bool($options['dry_run'] ?? false);

    $old_cwd = getcwd();
    chdir($base_dir);

    $db = createDatabaseConnection(
        $options['db_type'],
        $options['db_host'],
        $options['db_user'],
        $options['db_pass'] ?? '',
        $options['db_name'],
        $options['table_prefix'],
        (int)$options['db_port']
    );
    $error_text = '';
    if (get_db_error_text($db, $error_text)) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array('success' => false, 'errors' => array($error_text));
    }
    $GLOBALS['db'] = $db;

    $current_version = 0;
    $settings = method_exists($db, 'getSettings') ? $db->getSettings() : array();
    if (is_array($settings) && isset($settings['schema_migration_version'])) {
        $current_version = (int)$settings['schema_migration_version'];
    }

    $pending = array();
    foreach (install_list_migration_files($base_dir) as $version => $filename) {
        if ($version > $current_version) {
            $pending[$version] = $filename;
        }
    }

    if ($dry_run) {
        if ($old_cwd !== false) {
            chdir($old_cwd);
        }
        return array(
            'success' => true,
            'dry_run' => true,
            'current_version' => $current_version,
            'pending' => $pending,
            'messages' => array(count($pending) . ' pending migration(s).'),
        );
    }

    $applied = array();
    foreach ($pending as $version => $filename) {
        $path = $base_dir . '/sql/' . $filename;
        $sql = @file_get_contents($path);
        if ($sql === false) {
            $errors[] = "Unable to read migration file: sql/{$filename}";
            break;
        }

        $sql = install_rewrite_schema_sql($sql, $options['table_prefix']);
        $migration_ok = true;
        foreach (install_split_sql_statements($sql) as $statement) {
            if (!install_apply_schema_query($db, $statement, $errors)) {
                $migration_ok = false;
                break;
            }
        }
        if (!$migration_ok) {
            $errors[] = "Migration sql/{$filename} failed. Stopped before applying any later migrations.";
            break;
        }

        $db->setSettings(array('schema_migration_version' => (string)$version));
        $applied[] = $filename;
        $current_version = $version;
    }

    if ($old_cwd !== false) {
        chdir($old_cwd);
    }

    if (!empty($errors)) {
        return array('success' => false, 'errors' => $errors, 'applied' => $applied, 'current_version' => $current_version);
    }

    return array(
        'success' => true,
        'applied' => $applied,
        'current_version' => $current_version,
        'messages' => array(empty($applied) ? 'No pending migrations.' : ('Applied ' . count($applied) . ' migration(s): ' . implode(', ', $applied) . '.')),
        'errors' => array(),
    );
}

function install_current_session_is_admin($base_dir)
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $config_path = install_path($base_dir, 'includes/config.inc.php');
    if (!is_readable($config_path)) {
        return false;
    }

    require_once($config_path);
    $db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix, isset($db_port) ? $db_port : null);
    if (!$db instanceof Database) {
        return false;
    }

    return $db->isAdmin($_SESSION['user_id']) === true;
}
?>
