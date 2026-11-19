<?php
// JSON availability check used while typing a name. Called with type=cleared.
require_once __DIR__ . '/includes/panel_context.php';

function exec_module()
{
    header('Content-Type: application/json');
    $home_id = isset($_GET['home_id']) ? (int)$_GET['home_id'] : 0;
    if ($home_id > 0 && !sd_panel_home_access($home_id)) {
        echo json_encode(array('ok' => false, 'message' => 'Not allowed.'));
        return;
    }
    // simple rate limit: one check per 0.3s per session
    $now = microtime(true);
    if (isset($_SESSION['sd_last_check']) && ($now - $_SESSION['sd_last_check']) < 0.3) {
        echo json_encode(array('ok' => false, 'message' => 'Slow down...'));
        return;
    }
    $_SESSION['sd_last_check'] = $now;
    list($sdb, $settings, $provider) = sd_panel_context();
    if (!sd_is_ready($settings)) {
        echo json_encode(array('ok' => false, 'message' => 'Subdomains are not available.'));
        return;
    }
    $name = isset($_GET['name']) ? $_GET['name'] : '';
    $domain = isset($_GET['domain']) ? $_GET['domain'] : '';
    list($ok, $msg) = sd_check_available($sdb, $provider, $settings, $name, $domain, $home_id);
    echo json_encode(array('ok' => $ok, 'message' => $msg));
}
