<?php
/*
 * Validate the installer's fresh-install module policy without needing a DB.
 */

$base_dir = dirname(__DIR__);
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}
require_once $base_dir . '/includes/install_engine.php';

$default_modules = install_builtin_modules();
$default_lookup = array_fill_keys($default_modules, true);

$required_defaults = array(
    'modulemanager',
    'server',
    'settings',
    'gamemanager',
    'config_games',
    'administration',
    'user_games',
    'user_admin',
    'update',
    'dashboard',
    'cron',
    'ftp',
    'litefm',
    'register',
    'lostpwd',
    'support',
    'addonsmanager',
    'steam_workshop',
    'billing',
    'subusers',
);

$excluded_defaults = array(
    'tickets',
    'tshock',
    'TS3Admin',
    'teamspeak3',
    'lgsl_with_img_mod',
    'dsi',
    'reseller',
);

$expected_optional_dirs = array(
    'tickets',
    'tshock',
    'TS3Admin',
    'teamspeak3',
    'lgsl_with_img_mod',
    'dsi',
    'reseller',
);

$excluded_menu_names = array(
    'Support Tickets',
    'tshock',
    'ts3admin',
    'LGSL',
    'DSi Settings',
    'Reseller Packs',
);

$errors = array();

foreach ($required_defaults as $module) {
    if (!isset($default_lookup[$module])) {
        $errors[] = "Required default module is missing: {$module}";
    }
}

foreach ($excluded_defaults as $module) {
    if (isset($default_lookup[$module])) {
        $errors[] = "Specialty module is still registered by default: {$module}";
    }
}

foreach ($expected_optional_dirs as $module) {
    if (!is_dir($base_dir . '/modules/' . $module)) {
        $errors[] = "Optional module directory is missing: modules/{$module}";
    }
}

foreach ($default_modules as $module) {
    $metadata = install_load_module_metadata($base_dir, $module);
    if (!$metadata) {
        $errors[] = "Default module metadata could not be loaded: {$module}";
        continue;
    }

    foreach ($metadata['menus'] as $menu) {
        $menu_name = isset($menu['name']) ? (string)$menu['name'] : '';
        if (in_array($menu_name, $excluded_menu_names, true)) {
            $errors[] = "Excluded menu '{$menu_name}' would still be registered by {$module}.";
        }
    }
}

if (count($default_modules) !== count(array_unique($default_modules))) {
    $errors[] = 'Default module list contains duplicate entries.';
}

if (!empty($errors)) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "Default module policy OK: " . implode(', ', $default_modules) . PHP_EOL;
