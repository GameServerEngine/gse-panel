#!/usr/bin/env php
<?php
/*
 * Reader helper for install/requirements.json.
 *
 * Shell scripts cannot parse JSON reliably without extra tooling, but every
 * supported target already has PHP CLI installed (the Panel targets an existing
 * LAMP host). This helper is the shell-side accessor for the manifest so the
 * package/extension/module lists live in exactly one place.
 *
 * Usage:
 *   php install/read-requirements.php --list=apt_packages [--with-webserver] [--with-db-server] [--with-https]
 *   php install/read-requirements.php --list=php_extensions
 *   php install/read-requirements.php --list=php_extension_packages
 *   php install/read-requirements.php --list=commands
 *   php install/read-requirements.php --list=apache_modules [--with-https]
 *   php install/read-requirements.php --get=php_min_version
 *
 * Lists print one item per line so shell can read them with mapfile/while read.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "read-requirements.php must be run from the command line.\n");
    exit(1);
}

function rr_arg_value(array $argv, $name, $default = null)
{
    foreach ($argv as $index => $arg) {
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
        if ($arg === $name && isset($argv[$index + 1])) {
            return $argv[$index + 1];
        }
    }
    return $default;
}

function rr_has_arg(array $argv, $name)
{
    return in_array($name, $argv, true);
}

function requirements_path($base_dir = null)
{
    $base_dir = $base_dir ?: dirname(__DIR__);
    return rtrim($base_dir, '/\\') . '/install/requirements.json';
}

/**
 * Load and decode the manifest. Returns array on success, null on failure.
 * check.php includes this file and calls this function directly.
 */
function load_requirements($base_dir = null)
{
    static $cache = array();
    $path = requirements_path($base_dir);
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }

    $data = null;
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $cache[$path] = $data;
    return $data;
}

if (!defined('REQUIREMENTS_READER_LIB')) {
    // Allow check.php to `require_once` this file purely for the two functions
    // above without triggering the CLI behaviour below.
    define('REQUIREMENTS_READER_LIB', true);
}

// When included (not executed directly) stop here and expose only the helpers.
if (!isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$requirements = load_requirements(dirname(__DIR__));
if ($requirements === null) {
    fwrite(STDERR, "Unable to read or parse " . requirements_path(dirname(__DIR__)) . "\n");
    exit(1);
}

$with_https = rr_has_arg($argv, '--with-https');
$with_webserver = rr_has_arg($argv, '--with-webserver');
$with_db_server = rr_has_arg($argv, '--with-db-server');

$get = rr_arg_value($argv, '--get');
if ($get !== null) {
    switch ($get) {
        case 'php_min_version':
            echo $requirements['php']['min_version'] . "\n";
            exit(0);
        case 'php_install_hint':
            echo $requirements['php']['install_hint'] . "\n";
            exit(0);
        default:
            fwrite(STDERR, "Unknown --get key: {$get}\n");
            exit(1);
    }
}

$list = rr_arg_value($argv, '--list');
if ($list === null) {
    fwrite(STDERR, "Specify --list=<name> or --get=<key>. See the header of this file for supported names.\n");
    exit(1);
}

$items = array();
switch ($list) {
    case 'apt_packages':
        $items = $requirements['apt_packages']['always'];
        if ($with_webserver) {
            $items = array_merge(
                $items,
                $requirements['apt_packages']['webserver_optional'],
                $requirements['apt_packages']['apache_mod_php']
            );
        } else {
            // On an existing LAMP host we still need mod_php wired to the
            // already-installed Apache, but we must not pull in apache2 itself.
            $items = array_merge($items, $requirements['apt_packages']['apache_mod_php']);
        }
        if ($with_db_server) {
            $items = array_merge($items, $requirements['apt_packages']['database_server_optional']);
        }
        if ($with_https) {
            $items = array_merge($items, $requirements['apt_packages']['https_optional']);
        }
        break;

    case 'php_extensions':
        $items = array_keys($requirements['php_extensions']);
        break;

    case 'php_extension_packages':
        foreach ($requirements['php_extensions'] as $meta) {
            $items[] = $meta['apt_package'];
        }
        foreach ($requirements['php_libraries'] as $meta) {
            $items[] = $meta['apt_package'];
        }
        break;

    case 'commands':
        $items = array_keys($requirements['commands']);
        break;

    case 'command_packages':
        foreach ($requirements['commands'] as $meta) {
            $items[] = $meta['apt_package'];
        }
        break;

    case 'apache_modules':
        $items = $requirements['apache_modules']['required'];
        if ($with_https) {
            $items = array_merge($items, $requirements['apache_modules']['https_required']);
        }
        break;

    case 'https_packages':
        $items = $requirements['apt_packages']['https_optional'];
        break;

    default:
        fwrite(STDERR, "Unknown --list name: {$list}\n");
        exit(1);
}

$items = array_values(array_unique($items));
foreach ($items as $item) {
    echo $item . "\n";
}
exit(0);
