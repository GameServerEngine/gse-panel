<?php
/*
 * Gameserver Engine Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 * Gameserver Engine customizations
 *
 * GSP remains the legacy code namespace for compatibility.
 * https://git.runlevelsystems.com/GSE/Panel
 *
 * Dependency Check Page — check.php
 * Safe to run before install or at any time. Never blocks installation.
 */

// Never leak PHP errors in output (log them server-side instead).
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Safely escape a value for HTML output.
 *
 * @param mixed $v
 * @return string
 */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Try to run a shell command via shell_exec.
 * Returns the trimmed output, or NULL when shell_exec is not available / the
 * command produced no output.
 *
 * @param string $cmd The command to run (must already be safe / validated).
 * @return string|null
 */
function safe_shell(?string $cmd): ?string {
    if (!function_exists('shell_exec')) return null;
    $out = @shell_exec($cmd);
    return ($out !== null && $out !== '') ? trim($out) : null;
}

/**
 * Check whether a Linux command exists via "command -v".
 * Returns "ok", "missing", or "unknown".
 *
 * @param string $cmd Binary name, alphanumeric + dashes only.
 * @return string
 */
function check_command(string $cmd): string {
    if (!function_exists('shell_exec')) return 'unknown';
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cmd);
    $result = @shell_exec('command -v ' . escapeshellarg($safe) . ' 2>/dev/null');
    $result = ($result === null) ? '' : trim($result);
    return ($result !== '') ? 'ok' : 'missing';
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define('CHECK_BASE', __DIR__);

// The dependency manifest is the single source of truth shared with the shell
// installer (via install/read-requirements.php). Never hardcode these lists
// here again - they drifted from scripts/install-panel.sh once already.
require_once CHECK_BASE . '/install/read-requirements.php';
$gsp_requirements = gsp_load_requirements(CHECK_BASE);
if ($gsp_requirements === null) {
    $gsp_requirements = array(
        'php' => array('min_version' => '8.3.0', 'install_hint' => 'sudo apt install php php-cli libapache2-mod-php -y'),
        'php_extensions' => array(),
        'php_libraries' => array(),
        'commands' => array(),
        'apache_modules' => array('required' => array('rewrite', 'headers'), 'https_required' => array('ssl')),
    );
    $gsp_requirements_error = 'install/requirements.json is missing or unreadable. Dependency lists below are incomplete.';
}

define('PANEL_VERSION_MIN', $gsp_requirements['php']['min_version']);

// ---------------------------------------------------------------------------
// Run all checks and collect rows
// ---------------------------------------------------------------------------

$rows = [];

function check_add_row(array &$rows, string $section, string $name, string $status, string $current, string $fix, string $notes): void {
    $rows[] = [
        'section' => $section,
        'name'    => $name,
        'status'  => $status,
        'current' => $current,
        'fix'     => $fix,
        'notes'   => $notes,
    ];
}

// ── PHP version ─────────────────────────────────────────────────────────────
$php_ver = PHP_VERSION;
$php_ok  = version_compare($php_ver, PANEL_VERSION_MIN, '>=');
$rows[] = [
    'section' => 'PHP Runtime',
    'name'    => 'PHP Version',
    'status'  => $php_ok ? 'ok' : 'warning',
    'current' => $php_ver,
    'fix'     => $php_ok ? '' : $gsp_requirements['php']['install_hint'],
    'notes'   => 'Minimum recommended: PHP ' . PANEL_VERSION_MIN . '. Declared in install/requirements.json. The root installer installs distro PHP packages on Debian/Ubuntu.',
];

if (isset($gsp_requirements_error)) {
    $rows[] = [
        'section' => 'PHP Runtime',
        'name'    => 'Dependency manifest',
        'status'  => 'missing',
        'current' => 'install/requirements.json unreadable',
        'fix'     => 'Restore install/requirements.json from the release archive.',
        'notes'   => $gsp_requirements_error,
    ];
}

// ── Required PHP extensions (from install/requirements.json) ────────────────
foreach ($gsp_requirements['php_extensions'] as $ext => $meta) {
    $loaded = extension_loaded($ext);
    $rows[] = [
        'section' => 'PHP Extensions',
        'name'    => 'ext/' . $ext,
        'status'  => $loaded ? 'ok' : 'missing',
        'current' => $loaded ? 'Loaded' : 'Not loaded',
        'fix'     => $loaded ? '' : 'sudo apt install ' . $meta['apt_package'] . ' -y',
        'notes'   => $meta['description'],
    ];
}

// ── PHP libraries (from install/requirements.json) ──────────────────────────
foreach ($gsp_requirements['php_libraries'] as $lib_name => $meta) {
    $lib_path = stream_resolve_include_path($meta['include_file']);
    $rows[] = [
        'section' => 'PHP Libraries',
        'name'    => $lib_name,
        'status'  => $lib_path !== false ? 'ok' : 'missing',
        'current' => $lib_path !== false ? $lib_path : 'Not found',
        'fix'     => $lib_path !== false ? '' : 'sudo apt install ' . $meta['apt_package'] . ' -y',
        'notes'   => $meta['description'],
    ];
}

// ── Writable / readable paths ────────────────────────────────────────────────
// Paths marked 'required_writable' will show as 'error' (not just 'warning')
// when they are not writable, because the installer WILL crash without them.
$paths_to_check = [
    'includes/'                  => ['note' => 'Config directory (must be writable at install time)',       'required_writable' => true],
    'modules/'                   => ['note' => 'Modules directory',                                         'required_writable' => false],
    'upload/'                    => ['note' => 'Upload directory',                                          'required_writable' => false, 'runtime_dir' => true],
    'cache/'                     => ['note' => 'Cache directory',                                           'required_writable' => false, 'runtime_dir' => true],
    'log/'                       => ['note' => 'Log directory',                                             'required_writable' => false, 'runtime_dir' => true],
    'temp/'                      => ['note' => 'Temp directory',                                            'required_writable' => false, 'runtime_dir' => true],
    'includes/config.inc.php'    => ['note' => 'Panel config file',                                         'required_writable' => true, 'generated_config' => true],
];

foreach ($paths_to_check as $rel => $meta) {
    $note             = $meta['note'];
    $required_writable = $meta['required_writable'];
    $runtime_dir      = $meta['runtime_dir'] ?? false;
    $generated_config = $meta['generated_config'] ?? false;
    $abs              = CHECK_BASE . '/' . $rel;

    if (!file_exists($abs)) {
        if ($runtime_dir) {
            $created = @mkdir($abs, 0775, true);
            if ($created) {
                @chmod($abs, 0775);
            }
            if (is_dir($abs)) {
                $readable = is_readable($abs);
                $writable = is_writable($abs);
                check_add_row(
                    $rows,
                    'Filesystem',
                    $rel,
                    ($readable && $writable) ? 'ok' : 'warning',
                    ($created ? 'Created' : 'Exists after create attempt') . ' — readable: ' . ($readable ? 'yes' : 'no') . ', writable: ' . ($writable ? 'yes' : 'no'),
                    $writable ? '' : 'sudo chmod -R 2775 ' . escapeshellarg($rel) . ' && sudo chown -R www-data:www-data ' . escapeshellarg($rel),
                    $note . '. Missing runtime directories are created automatically when possible.'
                );
                continue;
            }

            check_add_row(
                $rows,
                'Filesystem',
                $rel,
                'warning',
                'Create failed',
                'sudo mkdir -p ' . escapeshellarg(rtrim($rel, '/')) . ' && sudo chmod 2775 ' . escapeshellarg(rtrim($rel, '/')) . ' && sudo chown -R www-data:www-data ' . escapeshellarg(rtrim($rel, '/')),
                $note . '. The dependency check tried to create this runtime directory but could not.'
            );
            continue;
        }

        if ($generated_config) {
            $parent_writable = is_writable(dirname($abs));
            check_add_row(
                $rows,
                'Filesystem',
                $rel,
                $parent_writable ? 'ok' : 'error',
                $parent_writable ? 'Will be created during installation' : 'Does not exist and parent directory is not writable',
                $parent_writable ? '' : 'sudo chmod 2775 ' . escapeshellarg(dirname($rel)) . ' && sudo chown -R www-data:www-data ' . escapeshellarg(dirname($rel)),
                $note . '. This is normal on a fresh install; install.php creates it after you submit database settings.'
            );
            continue;
        }

        // For required-writable files that don't exist yet, the parent directory
        // must be writable. Flag as error only if the parent dir is also not writable.
        if ($required_writable && !is_dir($abs)) {
            $parent_writable = is_writable(dirname($abs));
            $not_exist_status = $parent_writable ? 'warning' : 'error';
        } else {
            $not_exist_status = 'missing';
        }
        $rows[] = [
            'section' => 'Filesystem',
            'name'    => $rel,
            'status'  => $not_exist_status,
            'current' => 'Does not exist',
            'fix'     => is_dir(dirname($abs))
                ? 'sudo touch ' . escapeshellarg($rel) . ' && sudo chmod 664 ' . escapeshellarg($rel) . ' && sudo chown www-data:www-data ' . escapeshellarg($rel)
                : 'sudo mkdir -p ' . escapeshellarg(rtrim($rel, '/')) . ' && sudo chown -R www-data:www-data ' . escapeshellarg(rtrim($rel, '/')),
            'notes'   => $note,
        ];
        continue;
    }

    $is_dir   = is_dir($abs);
    $readable = is_readable($abs);
    $writable = is_writable($abs);

    if ($is_dir) {
        $status = ($readable && $writable) ? 'ok' : ($required_writable ? 'error' : 'warning');
        $cur    = 'Exists — readable: ' . ($readable ? 'yes' : 'no') . ', writable: ' . ($writable ? 'yes' : 'no');
        $fix    = (!$writable) ? 'sudo chmod -R 2775 ' . escapeshellarg($rel) . ' && sudo chown -R www-data:www-data ' . escapeshellarg($rel) : '';
    } else {
        // It's a file
        $status = ($readable && $writable) ? 'ok' : ($required_writable ? 'error' : 'warning');
        $cur    = 'Exists — readable: ' . ($readable ? 'yes' : 'no') . ', writable: ' . ($writable ? 'yes' : 'no');
        $fix    = (!$writable) ? 'sudo chmod 664 ' . escapeshellarg($rel) . ' && sudo chown www-data:www-data ' . escapeshellarg($rel) : '';
    }

    $rows[] = [
        'section' => 'Filesystem',
        'name'    => $rel,
        'status'  => $status,
        'current' => $cur,
        'fix'     => $fix,
        'notes'   => $note,
    ];
}

// ── Linux commands ───────────────────────────────────────────────────────────
// Sourced from install/requirements.json so check.php and the shell installer
// cannot drift apart.
$commands = [];
foreach ($gsp_requirements['commands'] as $cmd_name => $cmd_meta) {
    $commands[$cmd_name] = [
        'package'        => $cmd_meta['apt_package'],
        'missing_status' => $cmd_meta['missing_status'],
        'notes'          => $cmd_meta['description'],
    ];
}
$shell_available = function_exists('shell_exec');

foreach ($commands as $cmd => $meta) {
    $status_str = check_command($cmd);
    if (!$shell_available) {
        $status_str = 'unknown';
        $cur        = 'shell_exec disabled';
        $fix        = 'Enable shell_exec in php.ini';
    } else {
        $cur = $status_str === 'ok' ? safe_shell('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null') ?? $cmd : 'Not found in PATH';
        if ($status_str === 'missing') {
            $status_str = $meta['missing_status'];
        }
        $fix = ($status_str === 'missing' || $status_str === 'warning') ? 'sudo apt install ' . escapeshellarg($meta['package']) . ' -y' : '';
    }

    $rows[] = [
        'section' => 'Linux Commands',
        'name'    => $cmd,
        'status'  => $status_str,
        'current' => $cur,
        'fix'     => $fix,
        'notes'   => $meta['notes'],
    ];
}

// ── Apache modules ───────────────────────────────────────────────────────────
$apache_required_mods = $gsp_requirements['apache_modules']['required'];
$apache_https_mods    = $gsp_requirements['apache_modules']['https_required'];
$apache_mods_loaded   = function_exists('apache_get_modules') ? apache_get_modules() : null;

$apache_mod_notes = [
    'rewrite' => 'Required for clean panel URLs and the HTTP to HTTPS redirect in generated vhosts.',
    'headers' => 'Required by the generated vhosts for security headers (HSTS).',
    'ssl'     => 'Required only when the Panel or storefront vhost is configured for HTTPS.',
];

foreach ($apache_required_mods as $mod) {
    $note = $apache_mod_notes[$mod] ?? 'Required by the generated Panel vhosts.';
    if ($apache_mods_loaded === null) {
        $rows[] = [
            'section' => 'Apache',
            'name'    => 'mod_' . $mod,
            'status'  => 'unknown',
            'current' => 'apache_get_modules() unavailable (CGI/FPM mode or non-Apache?)',
            'fix'     => "sudo a2enmod {$mod}\nsudo systemctl reload apache2",
            'notes'   => $note . ' Verify manually if not using mod_php.',
        ];
        continue;
    }
    $loaded = in_array('mod_' . $mod, $apache_mods_loaded, true);
    $rows[] = [
        'section' => 'Apache',
        'name'    => 'mod_' . $mod,
        'status'  => $loaded ? 'ok' : 'warning',
        'current' => $loaded ? 'Enabled' : 'Not enabled',
        'fix'     => $loaded ? '' : "sudo a2enmod {$mod}\nsudo systemctl reload apache2",
        'notes'   => $note,
    ];
}

foreach ($apache_https_mods as $mod) {
    $loaded = $apache_mods_loaded !== null && in_array('mod_' . $mod, $apache_mods_loaded, true);
    $rows[] = [
        'section' => 'Apache',
        'name'    => 'mod_' . $mod . ' (HTTPS only)',
        'status'  => $apache_mods_loaded === null ? 'unknown' : ($loaded ? 'ok' : 'warning'),
        'current' => $apache_mods_loaded === null
            ? 'apache_get_modules() unavailable (CGI/FPM mode or non-Apache?)'
            : ($loaded ? 'Enabled' : 'Not enabled'),
        'fix'     => $loaded ? '' : "sudo a2enmod {$mod}\nsudo systemctl reload apache2",
        'notes'   => $apache_mod_notes[$mod] ?? 'Required for HTTPS vhosts.',
    ];
}

// ── Optional DB connectivity test ────────────────────────────────────────────
$config_path = CHECK_BASE . '/includes/config.inc.php';
if (is_readable($config_path)) {
    // Extract credentials using regex instead of executing the config file,
    // to avoid running arbitrary PHP code from the config.
    $raw = file_get_contents($config_path);
    $cfg = [];
    foreach (['db_host', 'db_port', 'db_user', 'db_pass', 'db_name'] as $var) {
        if ($raw !== false && preg_match('/\$' . $var . '\s*=\s*"([^"]*)"/', $raw, $m)) {
            $cfg[$var] = $m[1];
        }
    }

    $db_host_cfg = $cfg['db_host'] ?? null;
    $db_user_cfg = $cfg['db_user'] ?? null;
    $db_pass_cfg = $cfg['db_pass'] ?? null;
    $db_name_cfg = $cfg['db_name'] ?? null;
    $db_port_cfg = isset($cfg['db_port']) ? (int)$cfg['db_port'] : 3306;

    if ($db_host_cfg !== null && $db_user_cfg !== null && $db_name_cfg !== null) {
        $conn = @mysqli_connect($db_host_cfg, $db_user_cfg, $db_pass_cfg ?? '', $db_name_cfg, $db_port_cfg);
        if ($conn) {
            $db_status  = 'ok';
            $db_current = 'Connected to ' . $db_host_cfg . ':' . $db_port_cfg . ' / ' . $db_name_cfg;
            $db_fix     = '';
            mysqli_close($conn);
        } else {
            $db_status  = 'warning';
            $db_current = 'Connection failed — ' . (mysqli_connect_error() ?? 'unknown error');
            $db_fix     = 'Check credentials in includes/config.inc.php';
        }

        $rows[] = [
            'section' => 'Database',
            'name'    => 'MySQL connection',
            'status'  => $db_status,
            'current' => $db_current,
            'fix'     => $db_fix,
            'notes'   => 'Host: ' . $db_host_cfg . ' | Port: ' . $db_port_cfg . ' | DB: ' . $db_name_cfg . ' | User: ' . $db_user_cfg,
        ];
    } else {
        $rows[] = [
            'section' => 'Database',
            'name'    => 'MySQL connection',
            'status'  => 'warning',
            'current' => 'config.inc.php present but incomplete (missing host/user/db)',
            'fix'     => 'Run the installer at install.php',
            'notes'   => 'Cannot test connection without full credentials',
        ];
    }
} else {
    $rows[] = [
        'section' => 'Database',
        'name'    => 'MySQL connection',
        'status'  => 'ok',
        'current' => 'Not configured yet — includes/config.inc.php will be created during installation',
        'fix'     => '',
        'notes'   => 'This is normal before first install. The installer writes database credentials after you submit the settings form.',
    ];
}

// ---------------------------------------------------------------------------
// Summary counts
// ---------------------------------------------------------------------------
$count_ok      = 0;
$count_warning = 0;
$count_error   = 0;
$count_missing = 0;
$count_unknown = 0;

foreach ($rows as $r) {
    switch ($r['status']) {
        case 'ok':      $count_ok++;      break;
        case 'warning': $count_warning++; break;
        case 'error':   $count_error++;   break;
        case 'missing': $count_missing++; break;
        default:        $count_unknown++; break;
    }
}

// ---------------------------------------------------------------------------
// Render HTML
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gameserver Engine - Dependency Check</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 14px;
            background: #1a1a2e;
            color: #e0e0e0;
            padding: 20px;
        }
        h1 { font-size: 22px; margin-bottom: 4px; color: #ffffff; }
        h2 { font-size: 15px; font-weight: 600; margin: 18px 0 6px; color: #ccc; border-bottom: 1px solid #333; padding-bottom: 4px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { background: #16213e; border-radius: 8px; padding: 18px 22px; margin-bottom: 20px; border: 1px solid #0f3460; }
        .header p { color: #aaa; margin-top: 6px; font-size: 13px; }
        .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .summary-box { flex: 1 1 130px; border-radius: 8px; padding: 14px 18px; text-align: center; }
        .summary-box .num { font-size: 32px; font-weight: bold; }
        .summary-box .lbl { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; margin-top: 4px; }
        .summary-ok      { background: #0f3d2e; border: 1px solid #45a96b; color: #e7fff2; }
        .summary-warning { background: #4a3500; border: 1px solid #d6a830; color: #fff3bf; }
        .summary-error   { background: #4a1515; border: 1px solid #e06a6a; color: #fff4f4; }
        .summary-missing { background: #4a1515; border: 1px solid #e06a6a; color: #fff4f4; }
        .summary-unknown { background: #252535; border: 1px solid #6b78a8; color: #eef2ff; }
        .actions { margin-bottom: 22px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .btn {
            display: inline-block; padding: 9px 20px; border-radius: 6px; text-decoration: none;
            font-size: 13px; font-weight: 700; border: none; cursor: pointer; min-height: 44px;
            line-height: 1.2; text-align: center; color: #fff; -webkit-appearance: none; appearance: none;
            white-space: normal; overflow-wrap: anywhere;
        }
        .btn:visited, .btn:hover, .btn:focus, .btn:active,
        a.btn, a.btn:visited, a.btn:hover, a.btn:focus, a.btn:active,
        button.btn, button.btn:hover, button.btn:focus, button.btn:active,
        input[type="submit"].btn, input[type="submit"].btn:hover, input[type="submit"].btn:focus, input[type="submit"].btn:active,
        input[type="button"].btn, input[type="button"].btn:hover, input[type="button"].btn:focus, input[type="button"].btn:active { color: #fff; }
        .btn:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
        .btn-lg { padding: 14px 24px; font-size: 16px; }
        .btn-primary { background: #1d4ed8; color: #fff; border: 1px solid #93c5fd; }
        .btn-primary:hover { background: #2563eb; color: #fff; }
        .btn-primary:disabled, .btn-primary[disabled], .btn-secondary:disabled, .btn-secondary[disabled], .btn-danger:disabled, .btn-danger[disabled],
        input[type="submit"].btn:disabled, input[type="button"].btn:disabled, button.btn:disabled { background: #4b5563; color: #f9fafb; border-color: #6b7280; cursor: not-allowed; opacity: .72; }
        .btn-secondary { background: #2d344d; color: #f4f7ff; border: 1px solid #6b78a8; }
        .btn-secondary:hover { background: #3a3a6a; color: #fff; }
        .btn-danger { background: #7f1d1d; color: #fff; border: 1px solid #fecaca; }
        .btn-danger:hover { background: #991b1b; color: #fff; }
        table { width: 100%; border-collapse: collapse; background: #12122a; border-radius: 8px; overflow: hidden; border: 1px solid #2a2a4a; margin-bottom: 20px; }
        thead th { background: #0d0d28; color: #aab; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; padding: 10px 12px; text-align: left; border-bottom: 1px solid #2a2a5a; }
        tbody tr { border-bottom: 1px solid #1e1e3a; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #1a1a38; }
        tbody td { padding: 9px 12px; vertical-align: top; }
        .td-name { font-weight: 600; font-family: "Courier New", monospace; font-size: 13px; white-space: nowrap; }
        .td-status { white-space: nowrap; text-align: center; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .badge-ok      { background: #0f3d2e; color: #e7fff2; border: 1px solid #45a96b; }
        .badge-warning { background: #4a3500; color: #fff3bf; border: 1px solid #d6a830; }
        .badge-error   { background: #4a1515; color: #fff4f4; border: 1px solid #e06a6a; }
        .badge-missing { background: #4a1515; color: #fff4f4; border: 1px solid #e06a6a; }
        .badge-unknown { background: #252535; color: #eef2ff; border: 1px solid #6b78a8; }
        .td-fix code, .td-current code { font-family: "Courier New", monospace; font-size: 12px; background: #0d0d22; padding: 2px 6px; border-radius: 4px; color: #aaf; word-break: break-all; display: inline-block; }
        .td-fix pre  { font-family: "Courier New", monospace; font-size: 12px; background: #0d0d22; padding: 6px 8px; border-radius: 4px; color: #aaf; white-space: pre-wrap; word-break: break-all; }
        .td-notes { color: #c7cede; font-size: 12px; }
        .section-header td { background: #0d1430; color: #7a9fd4; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; padding: 6px 12px; }
        .note-box { background: #0f3d2e; border: 1px solid #45a96b; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; color: #e7fff2; font-size: 13px; }
        .note-box strong { color: #ffffff; }
        .note-box a { color: #ffffff; text-decoration: underline; }
        .note-box-error { background:#4a1515; border-color:#e06a6a; color:#fff4f4; }
        .note-box-warning { background:#4a3500; border-color:#d6a830; color:#fff3bf; }
        footer { text-align: center; color: #555; font-size: 11px; margin-top: 30px; }

    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>Gameserver Engine - Dependency Check</h1>
        <p>This page checks the server environment for Gameserver Engine Panel compatibility.
           Items marked <strong>Missing</strong> are required for current operation.
           Items marked <strong>Error</strong> will prevent install or repair work from completing.
           Items marked <strong>Warning</strong> are compatibility or optional follow-up items.
           Fix Missing and Error items before running
           <a href="install.php" style="color:#7aaaf5">install.php</a>.</p>
    </div>

    <!-- Summary boxes -->
    <div class="summary">
        <div class="summary-box summary-ok">
            <div class="num"><?= $count_ok ?></div>
            <div class="lbl">OK</div>
        </div>
        <div class="summary-box summary-warning">
            <div class="num"><?= $count_warning ?></div>
            <div class="lbl">Warning</div>
        </div>
        <div class="summary-box summary-error">
            <div class="num"><?= $count_error ?></div>
            <div class="lbl">Error</div>
        </div>
        <div class="summary-box summary-missing">
            <div class="num"><?= $count_missing ?></div>
            <div class="lbl">Missing</div>
        </div>
        <div class="summary-box summary-unknown">
            <div class="num"><?= $count_unknown ?></div>
            <div class="lbl">Unknown</div>
        </div>
    </div>

    <!-- Action buttons -->
    <div class="actions">
        <?php if ($count_error === 0 && $count_missing === 0): ?>
            <a href="install.php?step=1" class="btn btn-primary btn-lg">Install Now</a>
        <?php else: ?>
            <a href="install.php" class="btn btn-secondary">Back to Installer</a>
        <?php endif; ?>
        <a href="check.php"   class="btn btn-secondary">↺ Refresh Check</a>
    </div>

    <?php if ($count_error > 0): ?>
    <div class="note-box note-box-error">
        <strong>⚠ <?= $count_error ?> critical error<?= $count_error > 1 ? 's' : '' ?> found.</strong>
        Items marked <strong>Error</strong> will prevent the installer from completing.
        Apply the suggested fixes before running <a href="install.php">install.php</a>.
    </div>
    <?php elseif ($count_missing > 0): ?>
    <div class="note-box note-box-error">
        <strong><?= $count_missing ?> required item<?= $count_missing > 1 ? 's are' : ' is' ?> missing.</strong>
        Install the missing packages before using the Panel in production. XML-RPC and PEAR are required by the current agent communication path.
    </div>
    <?php elseif ($count_missing === 0 && $count_warning === 0): ?>
    <div class="note-box">
        <strong>✔ All checks passed.</strong>
        Your server environment looks good for GSP installation.
    </div>
    <?php endif; ?>

    <!-- Results table -->
    <table>
        <thead>
            <tr>
                <th style="width:160px">Name</th>
                <th style="width:90px;text-align:center">Status</th>
                <th style="width:220px">Current Value</th>
                <th>Recommended Fix</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $last_section = null;
        foreach ($rows as $row):
            if ($row['section'] !== $last_section):
                $last_section = $row['section'];
        ?>
            <tr class="section-header">
                <td colspan="5"><?= h($row['section']) ?></td>
            </tr>
        <?php endif; ?>
            <tr>
                <td class="td-name"><code><?= h($row['name']) ?></code></td>
                <td class="td-status">
                    <span class="badge badge-<?= h($row['status']) ?>">
                        <?= h($row['status']) ?>
                    </span>
                </td>
                <td class="td-current"><?= h($row['current']) ?></td>
                <td class="td-fix">
                    <?php if (!empty($row['fix'])): ?>
                        <?php if (strpos($row['fix'], "\n") !== false): ?>
                            <pre><?= h($row['fix']) ?></pre>
                        <?php else: ?>
                            <code><?= h($row['fix']) ?></code>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#555">—</span>
                    <?php endif; ?>
                </td>
                <td class="td-notes"><?= h($row['notes']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Suggested apt install block -->
    <h2>Debian/Ubuntu Prerequisite Install</h2>
    <table>
        <thead><tr><th>Copy/paste prerequisites only. This does not run the Panel installer or touch databases.</th></tr></thead>
        <tbody>
            <tr><td><pre><?= h(
'sudo apt update
sudo apt install apache2 mysql-client unzip tar screen sudo git rsync \
    php php-cli php-common php-mysql php-gd php-curl php-mbstring php-zip \
    php-xml php-xmlrpc php-pear libapache2-mod-php -y
sudo a2enmod rewrite headers
sudo systemctl reload apache2'
            ) ?></pre></td></tr>
            <tr><td><pre><?= h(
'sudo bash scripts/install-panel-prereqs.sh'
            ) ?></pre></td></tr>
        </tbody>
    </table>

    <footer>Gameserver Engine Dependency Checker - safe to run at any time &mdash; generated at <?= h(gmdate('Y-m-d H:i:s')) ?> UTC</footer>
</div>
</body>
</html>
