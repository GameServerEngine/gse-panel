<?php

require_once __DIR__ . '/function.php';

// Customer backup screen for one game home. Routes POST actions from the forms in function.php.
function exec_gsp_module()
{
    $home_id = isset($_REQUEST['home_id']) ? (int)$_REQUEST['home_id'] : 0;
    if ($home_id <= 0) {
        echo '<center><h3>There is no game server selected.</h3></center>';
        return;
    }

    $cfg = restic_load_home($home_id);
    if ($cfg === FALSE) {
        echo '<center><h3>You do not have access to this game server.</h3></center>';
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        restic_render_home($cfg, $home_id);
        return;
    }

    $action = isset($_POST['dothis']) ? (string)$_POST['dothis'] : '';
    if ($action === 'cancel') {
        header('Location: home.php?m=gamemanager&p=game_monitor&home_id=' . $home_id);
        exit;
    }

    if (!restic_csrf_ok()) {
        restic_render_home($cfg, $home_id, 'Your form expired. Please try again.', FALSE);
        return;
    }

    switch ($action) {
        case 'backupNow':
            list($ok, $message) = restic_do_backup($cfg, $home_id);
            restic_render_home($cfg, $home_id, $message, $ok);
            break;
        case 'restoreConfirm':
            restic_render_restore_confirm($cfg, $home_id);
            break;
        case 'restoreNow':
            list($ok, $message) = restic_do_restore($cfg, $home_id);
            restic_render_home($cfg, $home_id, $message, $ok);
            break;
        case 'deleteConfirm':
            restic_render_delete_confirm($cfg, $home_id);
            break;
        case 'deleteNow':
            list($ok, $message) = restic_do_delete($cfg, $home_id);
            restic_render_home($cfg, $home_id, $message, $ok);
            break;
        default:
            restic_render_home($cfg, $home_id);
    }
}
