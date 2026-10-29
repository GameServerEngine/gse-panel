#!/usr/bin/env php
<?php
/*
 * Root/sudo CLI wrapper for the shared GSP Panel install engine.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This installer wrapper must be run from the command line.\n");
    exit(1);
}

function cli_usage()
{
    $script = basename(__FILE__);
    echo "Usage:\n";
    echo "  php tools/{$script} --config /path/to/install.json [--base /path/to/panel] [--dry-run]\n";
    echo "  php tools/{$script} --repair-permissions --owner USER --group GROUP [--base /path/to/panel]\n";
    echo "  # For panel-test, use --owner www-data --group www-data and keep deploy user dev in www-data.\n";
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

function cli_run($cmd)
{
    passthru($cmd, $code);
    return $code === 0;
}

$base_dir = cli_arg_value($argv, '--base', dirname(__DIR__));
$base_dir = realpath($base_dir) ?: $base_dir;

if (cli_has_arg($argv, '--help')) {
    cli_usage();
    exit(0);
}

if (!is_dir($base_dir)) {
    fwrite(STDERR, "Panel path not found: {$base_dir}\n");
    exit(1);
}

if (!chdir($base_dir)) {
    fwrite(STDERR, "Unable to change to Panel path: {$base_dir}\n");
    exit(1);
}

if (cli_has_arg($argv, '--repair-permissions')) {
    $owner = cli_arg_value($argv, '--owner');
    $group = cli_arg_value($argv, '--group', 'www-data');
    if (!$owner || !$group) {
        fwrite(STDERR, "--owner and --group are required for --repair-permissions.\n");
        exit(1);
    }

    $path = escapeshellarg($base_dir);
    $owner_group = escapeshellarg($owner . ':' . $group);
    $ok = true;
    $ok = cli_run("chown -R {$owner_group} {$path}") && $ok;
    $ok = cli_run("find {$path} -type d -exec chmod 2775 {} +") && $ok;
    $ok = cli_run("find {$path} -type f -exec chmod 664 {} +") && $ok;
    foreach (array('includes/config.inc.php', 'includes/install.state.json') as $rel) {
        $target = $base_dir . '/' . $rel;
        if (file_exists($target)) {
            $ok = cli_run('chmod 660 ' . escapeshellarg($target)) && $ok;
        }
    }
    exit($ok ? 0 : 1);
}

$config_file = cli_arg_value($argv, '--config');
if (!$config_file) {
    cli_usage();
    exit(1);
}

if (!is_readable($config_file)) {
    fwrite(STDERR, "Config JSON is not readable.\n");
    exit(1);
}

$raw = file_get_contents($config_file);
$options = json_decode($raw ?: '', true);
if (!is_array($options)) {
    fwrite(STDERR, "Config JSON is invalid.\n");
    exit(1);
}

$_GET['action'] = true;
define('MODULES', 'modules/');
$_SESSION = array('users_lang' => $options['admin_lang'] ?? 'English');

require_once($base_dir . '/includes/helpers.php');
require_once($base_dir . '/includes/lang.php');
require_once($base_dir . '/includes/html_functions.php');
require_once($base_dir . '/includes/functions.php');
require_once($base_dir . '/includes/install_engine.php');

gspLang();

$options['base_dir'] = $base_dir;
$options['install_via'] = 'cli';
$options['installed_by'] = getenv('SUDO_USER') ?: getenv('USER') ?: 'root';
$options['dry_run'] = cli_has_arg($argv, '--dry-run') || !empty($options['dry_run']);

ob_start();
$result = gsp_install_run($options);
$legacy_output = trim(strip_tags(ob_get_clean()));
if ($legacy_output !== '') {
    foreach (explode("\n", $legacy_output) as $line) {
        $line = trim($line);
        if ($line !== '') {
            echo $line . "\n";
        }
    }
}

if (!$result['success']) {
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

if (!empty($result['dry_run'])) {
    echo "Dry run passed. No files or database state changed.\n";
    exit(0);
}

if (!empty($result['drop']['dropped'])) {
    echo "Dropped existing table(s): " . implode(', ', $result['drop']['dropped']) . "\n";
} else {
    echo "No existing tables matched the reinstall scope.\n";
}
if (!empty($result['admin_created'])) {
    echo "Initial admin account created.\n";
} else {
    echo "Initial admin account already existed; creation skipped.\n";
}
echo "Installation complete. Lock/state file: " . $result['state_file'] . "\n";
exit(0);
?>
