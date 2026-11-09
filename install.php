<?php
/*
 * GSP - Game Server Panel (WDS)
 * Browser installer entrypoint.
 */

error_reporting(E_ALL);
$_GET['action'] = true;
define('MODULES', 'modules/');

session_start();

if (!isset($_SESSION['users_lang'])) {
    $_SESSION['users_lang'] = 'English';
}

if (isset($_GET['localeset'])) {
    $_SESSION['users_lang'] = $_GET['localeset'];
}

require_once('includes/helpers.php');
require_once('includes/view.php');
require_once('includes/lang.php');
require_once('includes/html_functions.php');
require_once('includes/functions.php');
require_once('includes/install_engine.php');
require_once('includes/lib_vhosts.php');

lang();

$view = new View();
$view->setCharset(get_lang('lang_charset'));

function install_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_value($name, $default = '')
{
    return install_h($_POST[$name] ?? $default);
}

function install_read_config_options($base_dir, $include_secret = false)
{
    $config_path = install_path($base_dir, 'includes/config.inc.php');
    if (!is_readable($config_path)) {
        return array();
    }

    $db_host = 'localhost';
    $db_port = '3306';
    $db_user = '';
    $db_pass = '';
    $db_name = '';
    $table_prefix = '';
    $db_type = 'mysql';
    require($config_path);

    $values = array(
        'db_type' => isset($db_type) ? (string)$db_type : 'mysql',
        'db_host' => isset($db_host) ? (string)$db_host : 'localhost',
        'db_port' => isset($db_port) ? (string)$db_port : '3306',
        'db_user' => isset($db_user) ? (string)$db_user : '',
        'db_name' => isset($db_name) ? (string)$db_name : '',
        'table_prefix' => isset($table_prefix) ? (string)$table_prefix : '',
    );

    if ($include_secret) {
        $values['db_pass'] = isset($db_pass) ? (string)$db_pass : '';
    }

    return $values;
}

function install_safe_form_fields()
{
    return array(
        'panel_domain',
        'panel_use_vhost',
        'panel_ssl',
        'storefront_domain',
        'https_mode',
        'db_host',
        'db_port',
        'db_user',
        'db_name',
        'table_prefix',
        'admin_user',
        'admin_email',
    );
}

function install_store_safe_form_state(array $source)
{
    $state = array();
    foreach (install_safe_form_fields() as $field) {
        if (in_array($field, array('panel_use_vhost', 'panel_ssl'), true)) {
            $state[$field] = !empty($source[$field]) ? '1' : '';
            continue;
        }
        if (array_key_exists($field, $source)) {
            $state[$field] = trim((string)$source[$field]);
        }
    }
    $_SESSION['install_form_state'] = $state;
    return $state;
}

function install_clear_safe_form_state()
{
    unset($_SESSION['install_form_state']);
}

function install_form_defaults($base_dir)
{
    $values = array(
        'panel_domain' => '',
        'panel_use_vhost' => '',
        'panel_ssl' => '',
        'storefront_domain' => '',
        'https_mode' => 'http-only',
        'db_host' => 'localhost',
        'db_port' => '3306',
        'db_user' => '',
        'db_name' => '',
        'table_prefix' => '',
        'admin_user' => 'admin',
        'admin_email' => 'admin@localhost',
    );

    $config_values = install_read_config_options($base_dir);
    foreach (array('db_host', 'db_port', 'db_user', 'db_name', 'table_prefix') as $field) {
        if (array_key_exists($field, $config_values)) {
            $values[$field] = $config_values[$field];
        }
    }

    if (!empty($_SESSION['install_form_state']) && is_array($_SESSION['install_form_state'])) {
        foreach (install_safe_form_fields() as $field) {
            if (array_key_exists($field, $_SESSION['install_form_state'])) {
                $values[$field] = (string)$_SESSION['install_form_state'][$field];
            }
        }
    }

    return $values;
}

function install_sanitize_progress_output($output, array $secrets = array())
{
    $text = html_entity_decode(strip_tags((string)$output), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    foreach ($secrets as $secret) {
        $secret = (string)$secret;
        if ($secret !== '') {
            $text = str_replace($secret, '[hidden]', $text);
        }
    }

    $lines = array();
    foreach (preg_split('/\R+/', $text) as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ($line === '') {
            continue;
        }
        if (preg_match('/(query_failed|query failed|database error|sql error|ERROR:)/i', $line)) {
            $line = 'A database or module installer step reported an error. Sensitive details are hidden from browser output.';
        }
        if (strlen($line) > 220) {
            $line = substr($line, 0, 217) . '...';
        }
        $lines[$line] = true;
    }

    return array_keys($lines);
}

function install_run_engine_for_browser(array $options)
{
    ob_start();
    $result = install_run($options);
    $captured = ob_get_clean();
    $result['progress_lines'] = install_sanitize_progress_output(
        $captured,
        array($options['db_pass'] ?? '', $options['admin_pass'] ?? '')
    );
    return $result;
}

function install_render_progress_panel(array $lines)
{
    if (empty($lines)) {
        return;
    }

    echo "<div class='progress-panel'>";
    echo "<h2>Installer Progress</h2>";
    echo "<ul>";
    foreach ($lines as $line) {
        echo '<li>' . install_h($line) . '</li>';
    }
    echo "</ul>";
    echo "</div>";
}

function install_render_recovery_actions()
{
    echo "<div class='form-actions'>";
    echo "<a href='?step=1' class='btn btn-primary btn-lg'>Return to Installation Settings</a>";
    echo "<a href='check.php' class='btn btn-secondary'>Run Dependency Check</a>";
    echo "<a href='?step=1#retry' class='btn btn-secondary'>Retry Install / Recovery</a>";
    echo "</div>";
}

function install_render_result_panel($title, $status_class, array $messages, array $progress_lines = array(), $success = false)
{
    echo "<section class='install-panel result-panel'>\n";
    echo "<div class='install-panel-header'><h1>" . install_h($title) . "</h1></div>\n";
    if (!empty($messages)) {
        echo "<div class='message-box " . ($success ? 'message-success' : 'message-error') . "'>";
        foreach ($messages as $message) {
            echo '<p class="' . install_h($status_class) . '">' . $message . '</p>';
        }
        echo "</div>";
    }
    install_render_progress_panel($progress_lines);
    if ($success) {
        echo "<div class='form-actions'><a href='home.php' class='btn btn-primary btn-lg'>Open Panel</a><a href='index.php' class='btn btn-secondary'>Log In</a></div>";
    } else {
        install_render_recovery_actions();
    }
    echo "</section>\n";
}

function install_render_settings_form($base_dir, array $errors = array(), array $notices = array())
{
    $values = install_form_defaults($base_dir);
    $mode = install_panel_appears_installed($base_dir) ? 'reinstall' : 'install';

    echo "<section class='install-panel'>\n";
    echo "<div class='install-panel-header'>\n";
    echo "<h1>Installation Settings</h1>\n";
    echo "<p>Enter the deployment target, database connection, first administrator account, and install options. Password fields are never preserved after errors.</p>\n";
    echo "</div>\n";
    if (!empty($errors)) {
        echo "<div class='message-box message-error'>";
        foreach ($errors as $error) {
            echo '<p>' . install_h($error) . '</p>';
        }
        echo "</div>";
    }
    if (!empty($notices)) {
        echo "<div class='message-box message-warning'>";
        foreach ($notices as $notice) {
            echo '<p>' . install_h($notice) . '</p>';
        }
        echo "</div>";
    }
    echo "<form name='setup' method='post' action='?step=2' class='install-form'>";
    echo "<input type='hidden' name='mode' value='" . install_h($mode) . "' />";
    echo "<fieldset class='form-section'><legend>Language</legend>";
    echo "<div class='form-grid'>\n";
    echo "<label><span>Installer Language</span><select name='localeset_select' onchange=\"window.location='?localeset='+this.value+'&step=1'\">";
    foreach (makefilelist('lang/', '.|..|.svn', true, 'folders') as $locale_name) {
        $selected = ($locale_name === $_SESSION['users_lang']) ? " selected='selected'" : '';
        echo "<option value='" . install_h($locale_name) . "'{$selected}>" . install_h($locale_name) . '</option>';
    }
    echo "</select></label>\n";
    echo "</div></fieldset>\n";
    echo "<fieldset class='form-section'><legend>Deployment Target</legend>";
    echo "<div class='form-grid'>\n";
    echo "<label><span>Panel Domain / Hostname</span><input type='text' value='" . install_h($values['panel_domain']) . "' name='panel_domain' placeholder='panel.example.com or server IP' /></label>";
    echo "<label><span>Storefront Domain / Hostname</span><input type='text' value='" . install_h($values['storefront_domain']) . "' name='storefront_domain' placeholder='Leave blank to share the panel domain at /store' /></label>";
    echo "<label class='checkbox-field'><input type='checkbox' name='panel_use_vhost' value='1'" . ($values['panel_use_vhost'] === '1' ? " checked='checked'" : '') . " /> <span>Use a dedicated web server vhost for this domain</span></label>";
    echo "<label class='checkbox-field'><input type='checkbox' name='panel_ssl' value='1'" . ($values['panel_ssl'] === '1' ? " checked='checked'" : '') . " /> <span>Use HTTPS / SSL for the public Panel URL</span></label>";
    echo "<label><span>HTTPS Mode</span><select name='https_mode'>";
    foreach (array('http-only' => 'HTTP only', 'letsencrypt' => "Let's Encrypt (via certbot)", 'existing-cert' => 'Existing certificate') as $mode_value => $mode_label) {
        $selected = ($values['https_mode'] === $mode_value) ? " selected='selected'" : '';
        echo "<option value='" . install_h($mode_value) . "'{$selected}>" . install_h($mode_label) . '</option>';
    }
    echo "</select><small>Vhosts and certificates are only previewed here; apply them with the root command shown after install.</small></label>";
    echo "</div></fieldset>\n";
    echo "<fieldset class='form-section'><legend>Database</legend>";
    echo "<div class='form-grid'>\n";
    echo "<label><span>" . get_lang('database_type') . "</span><input type='text' value='MySQL / MariaDB' disabled='disabled' /></label>";
    echo "<label><span>" . get_lang('database_hostname') . "</span><input type='text' value='" . install_h($values['db_host']) . "' name='db_host' /></label>";
    echo "<label><span>Database Port</span><input type='text' value='" . install_h($values['db_port']) . "' name='db_port' inputmode='numeric' /></label>";
    echo "<label><span>" . get_lang('database_username') . "</span><input type='text' value='" . install_h($values['db_user']) . "' name='db_user' /></label>";
    echo "<label><span>" . get_lang('database_password') . "</span><input type='password' value='' name='db_pass' autocomplete='new-password' /></label>";
    echo "<label><span>" . get_lang('database_name') . "</span><input type='text' value='" . install_h($values['db_name']) . "' name='db_name' /></label>";
    echo "</div>\n";
    echo "<details class='form-section'><summary>Advanced</summary><div class='form-grid' style='margin-top:12px;'>";
    echo "<label><span>" . get_lang('database_prefix') . "</span><input type='text' value='" . install_h($values['table_prefix']) . "' name='table_prefix' placeholder='' /><small>Leave empty for no prefix (the default). Only set this if you intentionally want a prefix.</small></label>";
    echo "</div></details>\n";
    echo "</fieldset>\n";
    echo "<fieldset class='form-section'><legend>Administrator</legend>";
    echo "<div class='form-grid'>\n";
    echo "<label><span>Admin Username</span><input type='text' value='" . install_h($values['admin_user']) . "' name='admin_user' /></label>";
    echo "<label><span>Admin Email</span><input type='text' value='" . install_h($values['admin_email']) . "' name='admin_email' /></label>";
    echo "<label><span>Admin Password</span><input type='password' value='' name='admin_pass' autocomplete='new-password' /><small>Minimum 8 characters</small></label>";
    echo "<label><span>Confirm Password</span><input type='password' value='' name='admin_pass_confirm' autocomplete='new-password' /></label>";
    echo "</div></fieldset>\n";
    if ($mode === 'reinstall') {
        echo "<fieldset class='form-section'><legend>Reinstall Confirmation</legend>";
        echo "<label class='checkbox-row'><span>This database already has Panel tables. Type the exact database name (<code>" . install_h($values['db_name']) . "</code>) to confirm you want to drop and recreate its Panel tables. Unrelated tables are never dropped.</span><input type='text' value='' name='confirm_db_name' autocomplete='off' /></label>";
        echo "</fieldset>";
    }
    echo "<div class='form-actions'><input type='submit' name='next' value='" . ($mode === 'reinstall' ? 'Reinstall Panel' : 'Install Panel') . "' class='btn btn-primary btn-lg' /><a href='check.php' class='btn btn-secondary'>Back to Dependency Check</a></div></form>";
    echo "</section>\n";
}

function install_render_partial_recovery($base_dir, array $install_status)
{
    echo '<section class="install-panel recovery-panel">';
    echo '<div class="install-panel-header"><h1>Partial Installation Detected</h1></div>';
    echo '<div class="message-box message-warning">';
    echo '<p><strong>' . install_h($install_status['reason'] ?: 'The installer found partial configuration or state, but the schema/admin checks are not complete.') . '</strong></p>';
    if (!empty($install_status['missing_tables'])) {
        echo '<p>Missing required tables: <code>' . install_h(implode(', ', $install_status['missing_tables'])) . '</code></p>';
    }
    echo '<p>Use the saved database configuration to retry canonical schema creation, or return to the settings form to correct connection details. Passwords and raw database errors are not displayed here.</p>';
    echo '</div>';

    if (!empty($install_status['config_readable']) && !empty($install_status['db_connected'])) {
        $values = install_form_defaults($base_dir);
        echo "<form method='post' action='?step=2' class='install-form recovery-form'>";
        echo "<input type='hidden' name='use_existing_config' value='1' />";
        echo "<input type='hidden' name='mode' value='reinstall' />";
        echo "<div class='form-grid'>";
        echo "<label><span>Admin Username</span><input type='text' value='" . install_h($values['admin_user']) . "' name='admin_user' /></label>";
        echo "<label><span>Admin Email</span><input type='text' value='" . install_h($values['admin_email']) . "' name='admin_email' /></label>";
        echo "<label><span>Admin Password</span><input type='password' value='' name='admin_pass' autocomplete='new-password' /><small>Minimum 8 characters</small></label>";
        echo "<label><span>Confirm Password</span><input type='password' value='' name='admin_pass_confirm' autocomplete='new-password' /></label>";
        echo "</div>";
        echo "<label class='checkbox-row'><span>This is a partial/incomplete install recovery. Type the exact database name (<code>" . install_h($values['db_name']) . "</code>) to confirm resetting its Panel tables. Unrelated tables are not dropped automatically.</span><input type='text' value='' name='confirm_db_name' autocomplete='off' /></label>";
        echo "<div class='form-actions'><input type='submit' value='Retry Install / Recovery' class='btn btn-primary btn-lg' /><a href='?step=1' class='btn btn-secondary'>Return to Installation Settings</a><a href='check.php' class='btn btn-secondary'>Run Dependency Check</a></div>";
        echo "</form>";
    } else {
        install_render_recovery_actions();
    }

    echo '</section>';
}

function install_installed_gate($render_partial = true)
{
    $base_dir = __DIR__;
    $install_status = install_get_installation_status($base_dir);
    if (empty($install_status['complete'])) {
        if ($render_partial && (!empty($install_status['state_exists']) || !empty($install_status['config_exists']))) {
            install_render_partial_recovery($base_dir, $install_status);
        }
        return true;
    }

    $is_admin = install_current_session_is_admin($base_dir);
    if (!$is_admin) {
        http_response_code(403);
        echo '<div id="install-title">Installer Locked</div>';
        echo '<p class="failure">This panel is already installed. Anonymous web access cannot run the installer.</p>';
        echo '<p><a href="index.php">Go to Panel Login</a></p>';
        return false;
    }

    if (!install_maintenance_gate_open($base_dir)) {
        http_response_code(403);
        echo '<div id="install-title">Maintenance Gate Required</div>';
        echo '<p class="failure">You are authenticated as a panel admin, but web reinstall and repair also require a short-lived filesystem maintenance gate.</p>';
        echo '<p>Run this on the server, then reload this page within one hour:</p>';
        echo '<pre>sudo touch includes/install.maintenance && sudo chown www-data:www-data includes/install.maintenance && sudo chmod 660 includes/install.maintenance</pre>';
        echo '<p>For root/sudo maintenance, prefer <code>scripts/install-panel.sh --repair-permissions</code> or the CLI installer.</p>';
        echo '<p><a href="home.php">Return to Panel</a></p>';
        return false;
    }

    echo '<div class="message-box message-warning">';
    echo '<strong>Maintenance gate is open.</strong> Continuing will drop existing tables for the configured prefix in the selected database, create the canonical schema, and register built-in modules.';
    echo '</div>';
    return true;
}

?>
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
    margin: 0;
    max-width: 100%;
    overflow-x: hidden;
    background: #1a1a2e;
    color: #e0e0e0;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 14px;
}
#install-content {
    width: 100%;
    max-width: 960px;
    margin: 24px auto;
    padding: 0 18px 24px;
    overflow-x: hidden;
}
#install-title {
    background: #16213e;
    border: 1px solid #0f3460;
    border-radius: 8px;
    margin: 0 0 14px;
    padding: 16px 20px;
    font-size: 20px;
    color: #fff;
    font-weight: 700;
}
.lang { width: 100%; margin: 0 0 18px; border-collapse: collapse; background: #12122a; border: 1px solid #2a2a4a; border-radius: 8px; overflow: hidden; }
.lang td { padding: 10px 12px; }
.lang a { color: #7aaaf5; }
li { list-style-type: square; }
a { color: #7aaaf5; }
code, pre { font-family: "Courier New", monospace; }
pre { white-space: pre-wrap; background: #0d0d22; color: #cfd7ff; padding: 10px; border: 1px solid #2a2a4a; border-radius: 6px; overflow:auto; }
.install-panel, .welcome-panel, .message-box, .note {
    background: #12122a;
    border: 1px solid #2a2a4a;
    border-radius: 8px;
}
.install-panel, .welcome-panel { padding: 20px; max-width: 100%; overflow-wrap: anywhere; }
.install-panel-header h1, .welcome-panel h1 { color: #fff; font-size: 24px; margin: 0 0 6px; }
.install-panel-header p, .welcome-panel p { color: #aaa; margin: 0 0 14px; line-height: 1.5; }
.install-form { width: 100%; min-width: 0; }
.form-section {
    margin: 0 0 18px;
    padding: 0;
    border: 0;
    min-width: 0;
}
.form-section legend {
    color: #ffffff;
    font-size: 15px;
    font-weight: 700;
    margin: 0 0 10px;
}
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 14px 16px; width: 100%; min-width: 0; }
.form-grid > label { min-width: 0; }
.recovery-form .form-grid { grid-template-columns: 1fr; max-width: 540px; }
.install-form label { display: flex; flex-direction: column; gap: 6px; color: #d9d9e8; font-weight: 600; min-width: 0; }
.install-form label span { font-size: 13px; }
.install-form input[type="text"], .install-form input[type="password"] {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    min-height: 42px;
    background: #0d0d22;
    color: #f4f4ff;
    border: 1px solid #3a3a66;
    border-radius: 6px;
    padding: 9px 11px;
    font: inherit;
}
.install-form input:disabled { color: #d7dcec; background: #252a3f; opacity: .75; }
.install-form small { color: #c9d1e6; font-weight: 400; line-height: 1.35; }
.checkbox-row {
    display: grid !important;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: flex-start;
    gap: 10px !important;
    margin-top: 16px;
    padding: 12px;
    background: #18182f;
    border: 1px solid #33335d;
    border-radius: 6px;
}
.checkbox-row input { margin-top: 3px; }
.checkbox-row span { min-width: 0; overflow-wrap: anywhere; line-height: 1.45; }
.checkbox-field {
    display: grid !important;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    gap: 10px !important;
    min-height: 42px;
    padding: 10px 12px;
    background: #18182f;
    border: 1px solid #33335d;
    border-radius: 6px;
}
.checkbox-field span { min-width: 0; overflow-wrap: anywhere; line-height: 1.35; }
.form-actions, .welcome-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 18px; }
.btn, button.btn, input[type="submit"].btn, input[type="button"].btn {
    display: inline-block;
    padding: 9px 18px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    min-height: 44px;
    color: #fff;
    line-height: 1.2;
    text-align: center;
    -webkit-appearance: none;
    appearance: none;
    white-space: normal;
    overflow-wrap: anywhere;
}
    .btn:visited, .btn:hover, .btn:focus, .btn:active,
    a.btn, a.btn:visited, a.btn:hover, a.btn:focus, a.btn:active,
    button.btn, button.btn:hover, button.btn:focus, button.btn:active,
    input[type="submit"].btn, input[type="submit"].btn:hover, input[type="submit"].btn:focus, input[type="submit"].btn:active,
    input[type="button"].btn, input[type="button"].btn:hover, input[type="button"].btn:focus, input[type="button"].btn:active { color: #fff; }
    .btn:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
    .install-form input[type="submit"].btn { min-height: 48px; white-space: normal; }
    .btn-lg { padding: 14px 24px; font-size: 16px; }
    .btn-primary, input[type="submit"].btn-primary { background: #1d4ed8; color: #fff; border: 1px solid #93c5fd; }
    .btn-primary:hover, input[type="submit"].btn-primary:hover { background: #2563eb; color: #fff; }
    .btn-primary:disabled, .btn-primary[disabled], .btn-secondary:disabled, .btn-secondary[disabled], .btn-danger:disabled, .btn-danger[disabled],
    input[type="submit"].btn:disabled, input[type="button"].btn:disabled, button.btn:disabled { background: #4b5563; color: #f9fafb; border-color: #6b7280; cursor: not-allowed; opacity: .72; }
    .btn-secondary { background: #2d344d; color: #f4f7ff; border: 1px solid #6b78a8; }
    .btn-secondary:hover { background: #3a3a6a; color: #fff; }
    .btn-danger { background: #7f1d1d; color: #fff; border: 1px solid #fecaca; }
.btn-danger:hover { background: #991b1b; color: #fff; }
.message-box { padding: 12px 14px; margin: 12px 0; }
.message-box p { margin: 4px 0; }
.message-warning, .warning-box { border-color: #d6a830; background: #4a3500; color: #fff7d6; }
.message-error, .error-box { border-color: #e06a6a; background: #4a1515; color: #fff7f7; }
.message-success { border-color: #45a96b; background: #0f3d2e; color: #e7fff2; }
.message-info { border-color: #5da2ff; background: #0e2f4f; color: #eaf4ff; }
.failure { color: #fff7f7; font-weight: 700; }
.success { color: #e7fff2; font-weight: 700; }
.note { padding: 12px 14px; margin: 12px 0; color: #e5e7eb; }
.progress-panel {
    margin: 14px 0;
    padding: 12px 14px;
    background: #0d0d22;
    border: 1px solid #2a2a4a;
    border-radius: 6px;
}
.progress-panel h2 { margin: 0 0 8px; font-size: 16px; color: #fff; }
.progress-panel ul { margin: 0; padding-left: 20px; }
.progress-panel li { margin: 4px 0; overflow-wrap: anywhere; }
table.install { width: 100%; border-collapse: collapse; }
table.install td { padding: 0; }
@media (max-width: 820px) {
    #install-content { margin: 12px auto; padding: 0 12px 18px; }
    .form-grid { grid-template-columns: 1fr; }
    .btn, .btn-lg, .form-actions a, .form-actions input { width: 100%; text-align: center; }
    .lang td { display: block; width: 100% !important; }
}
@media (max-width: 420px) {
    .install-panel, .welcome-panel { padding: 14px; }
    .checkbox-row { grid-template-columns: 20px minmax(0, 1fr); }
}
</style>
<div id="install-content">
<?php
install();

function install()
{
    $step = $_REQUEST['step'] ?? '0';
    $base_dir = __DIR__;

    if (!install_installed_gate($step !== '2')) {
        echo '</div>';
        return;
    }

    if ($step === '0') {
        $locale_files = makefilelist('lang/', '.|..|.svn', true, 'folders');
        $columns = 3;
        $width = round(100 / $columns);

        echo '<div id="install-title">' . get_lang('install_lang') . '</div>';
        echo "<table class='lang' style='margin-bottom:10px;'>\n<tr>";
        $counter = 0;
        for ($i = 0; $i < count($locale_files); $i++) {
            if ($counter !== 0 && ($counter % $columns === 0)) {
                echo "</tr>\n<tr>\n";
            }
            echo "<td style='width:" . $width . "%'>";
            if ($locale_files[$i] === $_SESSION['users_lang']) {
                echo '<li><b>' . install_h($locale_files[$i]) . '</b></li>';
            } else {
                echo "<li><a href='?localeset=" . install_h($locale_files[$i]) . "'>" . install_h($locale_files[$i]) . '</a></li>';
            }
            echo "</td>\n";
            $counter++;
        }
        echo "</tr>\n</table>\n";

        echo '<section class="welcome-panel">';
        echo '<h1>Gameserver Engine Panel Installer</h1>';
        echo '<p>Run the dependency check before entering database settings. When required checks pass, it will show an <strong>Install Now</strong> button for the settings form.</p>';
        echo '<p>The installer writes <code>includes/config.inc.php</code>, drops existing tables in the selected reinstall scope, creates the canonical GSE schema, registers built-in modules, creates an initial admin account, and writes <code>' . INSTALL_STATE_FILE . '</code>.</p>';
        echo "<div class='welcome-actions'><a href='check.php' class='btn btn-primary btn-lg'>Run Dependency Check</a></div>";
        echo '</section>';
        echo "</div>\n";
        return;
    }

    if ($step === '1') {
        install_render_settings_form($base_dir);
        return;
    }

    if ($step === '2') {
        $using_existing_config = !empty($_POST['use_existing_config']);
        if (!$using_existing_config && !isset($_POST['db_host'])) {
            install_render_settings_form($base_dir, array('No form data received. Please fill in the installation settings.'));
            return;
        }

        $config_options = array();
        if ($using_existing_config) {
            $config_options = install_read_config_options($base_dir, true);
            if (empty($config_options)) {
                install_render_settings_form($base_dir, array('Saved installer config is not readable. Re-enter the installation settings.'));
                return;
            }
        }

        $safe_state_source = $using_existing_config ? array_merge($config_options, $_POST) : $_POST;
        install_store_safe_form_state($safe_state_source);

        if (($_POST['admin_pass'] ?? '') !== ($_POST['admin_pass_confirm'] ?? '')) {
            install_render_settings_form($base_dir, array('Admin passwords do not match.'));
            return;
        }

        $mode = ($_POST['mode'] ?? '') === 'reinstall' ? 'reinstall' : 'install';

        $options = array(
            'base_dir' => $base_dir,
            'mode' => $mode,
            'panel_domain' => $using_existing_config ? trim((string)($_POST['panel_domain'] ?? '')) : trim((string)($_POST['panel_domain'] ?? '')),
            'panel_use_vhost' => !empty($_POST['panel_use_vhost']),
            'panel_ssl' => !empty($_POST['panel_ssl']),
            'storefront_domain' => trim((string)($_POST['storefront_domain'] ?? '')),
            'https_mode' => trim((string)($_POST['https_mode'] ?? 'http-only')),
            'db_type' => $using_existing_config ? ($config_options['db_type'] ?? 'mysql') : 'mysql',
            'db_host' => $using_existing_config ? trim((string)$config_options['db_host']) : trim((string)$_POST['db_host']),
            'db_port' => $using_existing_config ? trim((string)($config_options['db_port'] ?: '3306')) : trim((string)($_POST['db_port'] ?: '3306')),
            'db_user' => $using_existing_config ? trim((string)$config_options['db_user']) : trim((string)$_POST['db_user']),
            'db_pass' => $using_existing_config ? (string)($config_options['db_pass'] ?? '') : (string)($_POST['db_pass'] ?? ''),
            'db_name' => $using_existing_config ? trim((string)$config_options['db_name']) : trim((string)$_POST['db_name']),
            'table_prefix' => $using_existing_config ? trim((string)$config_options['table_prefix']) : trim((string)($_POST['table_prefix'] ?? '')),
            'admin_user' => trim((string)$_POST['admin_user']),
            'admin_pass' => (string)$_POST['admin_pass'],
            'admin_email' => trim((string)($_POST['admin_email'] ?: 'admin@localhost')),
            'install_via' => 'web',
            'installed_by' => $_SESSION['users_login'] ?? 'web-anonymous',
            'confirm_db_name' => trim((string)($_POST['confirm_db_name'] ?? '')),
        );

        $result = install_run_engine_for_browser($options);
        if (!$result['success']) {
            $messages = array();
            foreach ($result['errors'] as $error) {
                $messages[] = install_h($error);
            }
            install_render_result_panel('Installation Recovery Failed', 'failure', $messages, $result['progress_lines'] ?? array(), false);
            return;
        }

        $messages = array();
        $messages[] = install_h(get_lang('config_written'));
        if (!empty($result['drop']['dropped'])) {
            $messages[] = 'Dropped existing table(s): <code>' . install_h(implode(', ', $result['drop']['dropped'])) . '</code>.';
        } else {
            $messages[] = 'No existing tables matched the reinstall scope.';
        }
        if (!empty($result['migration']['renamed'])) {
            $messages[] = 'Migrated ' . (int)$result['migration']['renamed'] . ' table(s) to the configured prefix.';
        }
        $messages[] = install_h(get_lang('database_created'));
        $messages[] = 'Built-in modules registered: <strong>' . (int)($result['modules_registered'] ?? 0) . '</strong>.';
        if (!empty($result['admin_created'])) {
            $messages[] = 'Admin account created: <strong>' . install_h($options['admin_user']) . '</strong>.';
        } else {
            $messages[] = 'Admin user already exists - skipped creation.';
        }
        if (!empty($options['panel_domain'])) {
            $public_scheme = !empty($options['panel_ssl']) ? 'https' : 'http';
            $messages[] = 'Public Panel URL recorded: <code>' . install_h($public_scheme . '://' . $options['panel_domain'] . '/') . '</code>.';
        }
        $messages[] = 'Installer lock/state file written: <code>' . install_h($result['state_file']) . '</code>.';
        $messages[] = 'Secure <code>includes/config.inc.php</code> and keep <code>' . install_h(INSTALL_STATE_FILE) . '</code> in place.';
        install_render_result_panel('Installation Complete', 'success', $messages, $result['progress_lines'] ?? array(), true);

        if (!empty($options['panel_domain'])) {
            $vhost_preview = vhost_build_previews($base_dir, array(
                'panel_domain' => $options['panel_domain'],
                'storefront_domain' => $options['storefront_domain'] ?? '',
                'https_mode' => $options['https_mode'] ?? 'http-only',
                'document_root' => $base_dir,
            ));
            echo "<section class='install-panel'>";
            echo "<div class='install-panel-header'><h1>Finish on the server (root required)</h1></div>";
            echo "<p>The web installer cannot write to <code>/etc/apache2/sites-available</code>. Run this on the server to write the vhost(s) below, enable them, reload Apache, and (if requested) obtain certificates:</p>";
            echo "<pre>sudo scripts/install-panel.sh --configure-vhosts --panel-domain " . install_h($options['panel_domain']) .
                (!$vhost_preview['shared_domain'] ? ' --storefront-domain ' . install_h($options['storefront_domain']) : '') .
                ' --https-mode ' . install_h($vhost_preview['https_mode']) . "</pre>";
            echo '<h3>' . install_h($vhost_preview['panel']['filename']) . '</h3>';
            echo '<pre>' . install_h($vhost_preview['panel']['contents']) . '</pre>';
            if ($vhost_preview['shared_domain']) {
                echo '<p>No separate storefront domain was configured, so the storefront (modules/billing) shares the panel domain and is expected at <code>' . install_h($vhost_preview['storefront_subpath']) . '</code> under it rather than getting its own vhost file.</p>';
            } else {
                echo '<h3>' . install_h($vhost_preview['storefront']['filename']) . '</h3>';
                echo '<pre>' . install_h($vhost_preview['storefront']['contents']) . '</pre>';
            }
            echo '</section>';
        }
        install_clear_safe_form_state();
        echo "</div>\n";
        return;
    }

    echo "<p>Unknown step. <a href='?step=0'>Start over</a></p>";
}

$view->printView();
?>
