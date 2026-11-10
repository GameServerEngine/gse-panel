#!/usr/bin/env php
<?php
/*
 * CLI post-update runner for release ZIP or developer Git Panel deployments.
 *
 * This intentionally reuses the Panel's existing post-update path:
 * runPostUpdateOperations() -> updateAllPanelModules() -> update_module().
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This post-update runner must be run from the command line.\n");
    exit(1);
}

function cli_usage()
{
    $script = basename(__FILE__);
    echo "Usage:\n";
    echo "  php tools/{$script} [--base /path/to/panel] [--dry-run]\n";
    echo "\n";
    echo "Runs module db_version migrations and existing post-update cleanup after Panel files are updated.\n";
}

function cli_arg_value(array $argv, $name, $default = null)
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return $argv[$index + 1];
        }
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return $default;
}

function cli_has_arg(array $argv, $name)
{
    return in_array($name, $argv, true);
}

function cli_required_file($base_dir, $relative)
{
    $path = rtrim($base_dir, '/') . '/' . ltrim($relative, '/');
    if (!is_file($path)) {
        fwrite(STDERR, "Required file is missing: {$relative}\n");
        return false;
    }
    return true;
}

if (cli_has_arg($argv, '--help') || cli_has_arg($argv, '-h')) {
    cli_usage();
    exit(0);
}

$base_dir = cli_arg_value($argv, '--base', dirname(__DIR__));
$base_dir = realpath($base_dir) ?: $base_dir;
$dry_run = cli_has_arg($argv, '--dry-run');

if (!is_dir($base_dir)) {
    fwrite(STDERR, "Panel path not found: {$base_dir}\n");
    exit(1);
}

$required = array(
    'includes/functions.php',
    'includes/helpers.php',
    'includes/html_functions.php',
    'includes/lang.php',
    'modules/modulemanager/module_handling.php',
);

foreach ($required as $relative) {
    if (!cli_required_file($base_dir, $relative)) {
        exit(1);
    }
}

if ($dry_run) {
    echo "Dry run passed. Post-update runner files are present; no database or files changed.\n";
    exit(0);
}

if (!cli_required_file($base_dir, 'includes/config.inc.php')) {
    fwrite(STDERR, "Run this only after the Panel is installed and includes/config.inc.php exists.\n");
    exit(1);
}

chdir($base_dir);

$_GET = array();
$_POST = array();
$_REQUEST = array();
$_SERVER['PHP_SELF'] = 'tools/post_update_cli.php';
$_SESSION = array(
    'user_id' => 0,
    'users_lang' => 'English',
    'users_role' => 'admin',
    'users_api_key' => 'cli-post-update',
);

defined('CONFIG_FILE') || define('CONFIG_FILE', 'includes/config.inc.php');
defined('MODULES') || define('MODULES', 'modules/');
defined('INCLUDES') || define('INCLUDES', 'includes/');
defined('IMAGES') || define('IMAGES', 'images/');

require_once('includes/functions.php');
require_once('includes/helpers.php');
require_once('includes/html_functions.php');
require_once('includes/lang.php');
require_once(CONFIG_FILE);

$db = createDatabaseConnection(
    $db_type,
    $db_host,
    $db_user,
    $db_pass,
    $db_name,
    $table_prefix,
    isset($db_port) ? $db_port : null
);

$error_text = '';
if (function_exists('get_db_error_text') && get_db_error_text($db, $error_text)) {
    fwrite(STDERR, "Database connection failed: {$error_text}\n");
    exit(1);
}

$GLOBALS['db'] = $db;
$settings = $db->getSettings();
$GLOBALS['settings'] = $settings;
if (!empty($settings['panel_language'])) {
    $GLOBALS['panel_language'] = $settings['panel_language'];
}
if (!empty($settings['time_zone'])) {
    date_default_timezone_set($settings['time_zone']);
}
if (function_exists('lang')) {
    lang();
}

ob_start();
try {
    $post_update = runPostUpdateOperations();
    $legacy_output = trim(strip_tags(ob_get_clean()));
} catch (Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, "Post-update operations failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$post_update['success']) {
    fwrite(STDERR, "Post-update operations failed: " . $post_update['error'] . "\n");
    exit(1);
}

if ($legacy_output !== '') {
    foreach (explode("\n", $legacy_output) as $line) {
        $line = trim($line);
        if ($line !== '') {
            echo $line . "\n";
        }
    }
}

echo "Post-update operations complete.\n";
exit(0);
?>
