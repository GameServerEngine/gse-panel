<?php
// Change or set the free subdomain of one game server.
require_once __DIR__ . '/includes/panel_context.php';

function sd_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function exec_module()
{
    global $db;
    $home_id = isset($_GET['home_id']) ? (int)$_GET['home_id'] : 0;
    $home = sd_panel_home_access($home_id);
    if (!$home) {
        echo "<p>Server not found.</p>";
        return;
    }
    list($sdb, $settings, $provider) = sd_panel_context();
    echo "<h2>Server address</h2>";
    if (!sd_is_ready($settings)) {
        echo "<p>Free server addresses are not available yet.</p>";
        return;
    }
    $isAdmin = $db->isAdmin($_SESSION['user_id']);
    list($ip, $port) = sd_home_address($sdb, $home_id);
    $gameKey = sd_home_game_key($sdb, $home_id);
    $msg = '';
    $msgOk = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['sd_action']) ? $_POST['sd_action'] : '';
        if ($ip === '') {
            $msg = 'This server has no IP and port yet.';
            $msgOk = false;
        } elseif ($action === 'set') {
            list($msgOk, $msg) = sd_set_for_home($sdb, $provider, $settings, $home_id, (int)$_SESSION['user_id'],
                isset($_POST['sd_name']) ? $_POST['sd_name'] : '', isset($_POST['sd_domain']) ? $_POST['sd_domain'] : '',
                $ip, $port, $gameKey, $isAdmin);
            if ($msgOk) {
                $msg = 'Saved. DNS changes can take a few minutes to show up everywhere.';
            }
        } elseif ($action === 'refresh') {
            $row = sd_row_for_home($sdb, $home_id);
            if ($row) {
                list($msgOk, $msg) = sd_sync_row($sdb, $provider, $settings, $row, $ip, $port, $gameKey);
                if ($msgOk) {
                    $msg = 'DNS refreshed.';
                }
            }
        } elseif ($action === 'remove' && $isAdmin) {
            $msgOk = sd_release_home($sdb, $provider, $home_id);
            $msg = $msgOk ? 'Name removed.' : 'Could not remove the DNS records.';
        }
    }
    $row = sd_row_for_home($sdb, $home_id);
    if ($msg !== '') {
        echo "<p style='color:" . ($msgOk ? '#2e9b3a' : '#c0392b') . "'>" . sd_h($msg) . "</p>";
    }
    if ($row) {
        echo "<p>Your address: <b>" . sd_h(sd_fqdn($row)) . "</b>";
        if (sd_wants_srv($settings, $gameKey)) {
            echo " (players can join with just this name)";
        } elseif ($port > 0) {
            echo " &mdash; use port " . (int)$port;
        }
        echo "</p>";
        if ($row['status'] !== 'active') {
            echo "<p style='color:#c0392b'>DNS is not in place yet. Press Refresh to retry.</p>";
        }
    } else {
        echo "<p>This server has no name yet. Pick a free one below.</p>";
    }
    $selDomain = $row ? $row['domain'] : '';
    echo "<form method='post'><input type='hidden' name='sd_action' value='set'>";
    echo "<input type='text' id='sd_name' name='sd_name' maxlength='32' value='" . sd_h($row ? $row['name'] : '') . "' autocomplete='off' placeholder='my-server'> ";
    echo "<select id='sd_domain' name='sd_domain'>";
    foreach (sd_domains($settings) as $d) {
        echo "<option value='" . sd_h($d) . "'" . ($d === $selDomain ? ' selected' : '') . ">." . sd_h($d) . "</option>";
    }
    echo "</select> <span id='sd_status'></span><br><br>";
    echo "<input type='submit' value='Save name'></form>";
    echo "<form method='post' style='display:inline'><input type='hidden' name='sd_action' value='refresh'><input type='submit' value='Refresh DNS'></form> ";
    if ($isAdmin && $row) {
        echo "<form method='post' style='display:inline' onsubmit=\"return confirm('Remove this name?')\"><input type='hidden' name='sd_action' value='remove'><input type='submit' value='Remove name'></form>";
    }
    echo sd_live_check_js('?m=subdomains&p=check&type=cleared&home_id=' . $home_id, 'sd_name', 'sd_domain', 'sd_status');
    echo "<p><a href='javascript:history.back()'>Back</a></p>";
}
