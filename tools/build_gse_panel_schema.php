<?php
/**
 * Builds install/gse_panel.sql from panel.sql + every module's install_queries.
 *
 * This is the single source of truth generator described in the installer
 * rework: it replays panel.sql (the baseline dump) and then every module's
 * versioned install_queries (ALL modules, ALL versions, in module-name
 * order) against a throwaway database, adds the module `enabled` column,
 * registers every module + its menus/access rights, seeds default settings,
 * then dumps the resulting schema+seed rows to install/gse_panel.sql.
 *
 * Usage:
 *   php tools/build_gse_panel_schema.php --host=127.0.0.1 --port=33061 \
 *       --user=root --pass=buildpass --db=gse_schema_build [--keep]
 *
 * The target database must already exist and be empty (or will be dropped
 * and recreated if --drop is passed). Requires the `mysql` and `mysqldump`
 * client binaries on PATH.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$base_dir = dirname(__DIR__);
chdir($base_dir);

$opts = getopt('', array('host:', 'port:', 'user:', 'pass:', 'db:', 'drop', 'keep', 'out:'));
$db_host = $opts['host'] ?? '127.0.0.1';
$db_port = (int)($opts['port'] ?? 3306);
$db_user = $opts['user'] ?? 'root';
$db_pass = $opts['pass'] ?? '';
$db_name = $opts['db'] ?? 'gse_schema_build';
$out_path = $opts['out'] ?? ($base_dir . '/install/gse_panel.sql');
$table_prefix = 'gsp_';

if (!isset($opts['db']) || strpos($db_name, 'gse_schema_build') === false) {
    fwrite(STDERR, "Refusing to run against a database whose name does not contain 'gse_schema_build'. " .
        "This tool applies schema-altering statements and must only ever target a disposable scratch database.\n");
    exit(1);
}

require_once($base_dir . '/includes/database.php');
require_once($base_dir . '/includes/database_mysqli.php');
require_once($base_dir . '/includes/install_engine.php');

function run_client_command(array $command_parts, $stdin = null)
{
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $cmd = implode(' ', array_map('escapeshellarg', $command_parts));
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start: $cmd\n");
        exit(1);
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed ($code): $cmd\n$stderr\n");
        exit(1);
    }
    return $stdout;
}

echo "[1/7] Resetting database `$db_name`...\n";
run_client_command(array(
    'mysql', '-h', $db_host, '-P', (string)$db_port, '-u', $db_user, '-p' . $db_pass,
    '-e', "DROP DATABASE IF EXISTS `$db_name`; CREATE DATABASE `$db_name` DEFAULT CHARACTER SET utf8mb4;",
));

echo "[2/7] Importing panel.sql baseline...\n";
$panel_sql = file_get_contents($base_dir . '/panel.sql');
// panel.sql contains at least one duplicate CREATE TABLE (GSP_DB_PREFIXban_list); make the
// import idempotent the same way the existing installer's import already does.
$panel_sql = preg_replace('/CREATE TABLE `/', 'CREATE TABLE IF NOT EXISTS `', $panel_sql);
run_client_command(array('mysql', '-h', $db_host, '-P', (string)$db_port, '-u', $db_user, '-p' . $db_pass, $db_name), $panel_sql);

$db = new GSPDatabaseMySQL();
$connect_result = $db->connect($db_host, $db_user, $db_pass, $db_name, $table_prefix, $db_port);
if ($connect_result !== true) {
    fwrite(STDERR, "Failed to connect to scratch database (code $connect_result)\n");
    exit(1);
}

echo "[3/7] Adding module `enabled` column...\n";
$db->query("ALTER TABLE `{$table_prefix}modules` ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `db_version`");

echo "[4/7] Discovering modules and applying every module's install_queries (all versions)...\n";
$module_dirs = array();
foreach (scandir($base_dir . '/modules') as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    // Skip stray backup copies of modules left on disk (e.g. "steam_workshop.bak.20260609-145834") —
    // these are not real modules and must never be registered/installed.
    if (preg_match('/\.bak(\.|$)/i', $entry)) {
        continue;
    }
    $module_php = $base_dir . '/modules/' . $entry . '/module.php';
    if (is_file($module_php)) {
        $module_dirs[] = $entry;
    }
}
sort($module_dirs);

$module_metadata = array();
$pending_steps = array(); // list of ['module' => .., 'version' => .., 'step' => ..]
foreach ($module_dirs as $module) {
    $metadata = gsp_install_load_module_metadata($base_dir, $module);
    if (!$metadata) {
        echo "  - skip $module (no module_title/module_version)\n";
        continue;
    }
    $module_metadata[$module] = $metadata;

    $versions = array_keys($metadata['install_queries']);
    sort($versions, SORT_NUMERIC);
    foreach ($versions as $version) {
        foreach ((array)$metadata['install_queries'][$version] as $step) {
            $pending_steps[] = array('module' => $module, 'version' => $version, 'step' => $step);
        }
    }
}

// Some modules' install_queries depend on columns/tables added by a different
// module (e.g. billing's steps reference server_homes.billing_enabled, which
// is actually added by gamemanager). Since modules are otherwise independent,
// apply repeated passes in declared order and retry whatever fails, until a
// full pass makes no further progress — this tolerates cross-module ordering
// without having to hand-maintain a dependency graph.
$pass = 0;
while (!empty($pending_steps)) {
    $pass++;
    $still_pending = array();
    $applied_this_pass = 0;
    foreach ($pending_steps as $item) {
        $errors = array();
        if (gsp_install_apply_schema_step($db, $item['step'], $errors)) {
            $applied_this_pass++;
        } else {
            $item['last_error'] = $db->getError();
            $still_pending[] = $item;
        }
    }
    echo "  - pass $pass: applied $applied_this_pass, " . count($still_pending) . " remaining\n";
    if ($applied_this_pass === 0) {
        fwrite(STDERR, "No progress on pass $pass; the following steps could not be applied:\n");
        foreach ($still_pending as $item) {
            fwrite(STDERR, "  ! {$item['module']} version {$item['version']}: {$item['last_error']}\n");
        }
        exit(1);
    }
    $pending_steps = $still_pending;
}
foreach ($module_metadata as $module => $metadata) {
    echo "  - applied $module (" . count($metadata['install_queries']) . " version step(s))\n";
}

echo "[5/7] Registering all modules (enabled=1) with menus and access rights...\n";
foreach ($module_metadata as $module => $metadata) {
    $title = $db->realEscapeSingle($metadata['title']);
    $folder = $db->realEscapeSingle($module);
    $version = $db->realEscapeSingle($metadata['version']);
    $db_version = (int)$metadata['db_version'];
    $query = "INSERT INTO `{$table_prefix}modules` (`title`,`folder`,`version`,`db_version`,`enabled`) " .
        "VALUES ('{$title}','{$folder}','{$version}',{$db_version},1) " .
        "ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `version`=VALUES(`version`), `db_version`=VALUES(`db_version`)";
    if (!$db->query($query)) {
        fwrite(STDERR, "Module registration failed for $module: " . $db->getError() . "\n");
        exit(1);
    }
    $rows = $db->resultQuery("SELECT `id` FROM `{$table_prefix}modules` WHERE `folder` = '{$folder}' LIMIT 1");
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
}

echo "[6/7] Seeding default settings...\n";
foreach (gsp_install_default_settings() as $setting => $value) {
    $setting_esc = $db->realEscapeSingle($setting);
    $value_esc = $db->realEscapeSingle($value);
    $db->query("INSERT INTO `{$table_prefix}settings` (`setting`,`value`) VALUES ('{$setting_esc}','{$value_esc}') " .
        "ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
}

echo "[7/7] Dumping schema + seed rows to $out_path...\n";
$dump = run_client_command(array(
    'mysqldump', '-h', $db_host, '-P', (string)$db_port, '-u', $db_user, '-p' . $db_pass,
    '--no-tablespaces', '--skip-comments', '--skip-dump-date', '--compact',
    '--complete-insert', $db_name,
));

$header = <<<SQL
-- install/gse_panel.sql
-- Canonical GSE Panel schema — generated by scripts/build-schema.sh / tools/build_gse_panel_schema.php
-- DO NOT EDIT BY HAND. Regenerate with scripts/build-schema.sh whenever panel.sql or any
-- modules/*/module.php install_queries change.
--
-- Table prefix baked into this file is `gsp_`. The installer rewrites this prefix at
-- import time if a non-default table_prefix is configured.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


SQL;

$footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n";

file_put_contents($out_path, $header . $dump . $footer);

if (!isset($opts['keep'])) {
    run_client_command(array('mysql', '-h', $db_host, '-P', (string)$db_port, '-u', $db_user, '-p' . $db_pass,
        '-e', "DROP DATABASE IF EXISTS `$db_name`;"));
    echo "Scratch database dropped.\n";
} else {
    echo "Scratch database kept ($db_name) — pass without --keep to auto-drop.\n";
}

echo "Done. Wrote $out_path (" . number_format(filesize($out_path)) . " bytes)\n";
