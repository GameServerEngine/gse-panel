<?php
// Admin side of ntfy push notifications: the server address, the enable switch, the install button
// and the list of topics customers can subscribe to. The ntfy server itself is started by
// scripts/install-ntfy.sh (run as root through a sudo rule for the web user).
require_once __DIR__ . '/ntfy_helpers.php';

if (!defined('NTFY_NOTICE_TOPIC')) {
    define('NTFY_NOTICE_TOPIC', 'gse-notices');
}

function ntfy_admin_setting($db, $key)
{
    $rows = $db->resultQuery("SELECT value FROM `DB_PREFIXsettings` WHERE setting = '" . $db->real_escape_string($key) . "' LIMIT 1");
    return ($rows && isset($rows[0]['value'])) ? (string)$rows[0]['value'] : '';
}

function ntfy_admin_save_setting($db, $key, $value)
{
    $k = $db->real_escape_string($key);
    $v = $db->real_escape_string($value);
    return $db->query("INSERT INTO `DB_PREFIXsettings` (setting, value) VALUES ('$k', '$v') ON DUPLICATE KEY UPDATE value = '$v'");
}

function ntfy_admin_base_url($raw)
{
    return rtrim(trim((string)$raw), '/');
}

// Checks the ntfy health endpoint. Returns array(bool running, string message).
function ntfy_admin_health($baseUrl)
{
    if ($baseUrl === '') {
        return array(false, 'No server address is set yet.');
    }
    $ch = curl_init($baseUrl . '/v1/health');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return array(false, 'Nothing answers at ' . $baseUrl . ($err !== '' ? ' (' . $err . ')' : '') . '.');
    }
    if ($code === 200 && strpos((string)$body, '"healthy":true') !== false) {
        return array(true, 'ntfy is running at ' . $baseUrl . '.');
    }
    return array(false, 'Something answers at ' . $baseUrl . ' but it is not ntfy (HTTP ' . $code . ').');
}

// Saves the address typed as IP (or host name) and port.
function ntfy_admin_save_address($db, $host, $port)
{
    $host = trim((string)$host);
    $port = (int)$port;
    if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
        return array(false, 'Enter an IP address or host name.');
    }
    if ($port < 1 || $port > 65535) {
        return array(false, 'The port must be between 1 and 65535.');
    }
    ntfy_admin_save_setting($db, 'ntfy_base_url', 'http://' . $host . ':' . $port);
    return array(true, 'Server address saved.');
}

// Runs the installer as root. Needs the sudo rule for the web user (see the notes on the install button).
function ntfy_admin_install($port)
{
    $port = (int)$port;
    if ($port < 1 || $port > 65535) {
        return array(false, 'The port must be between 1 and 65535.');
    }
    // Root-owned copy of scripts/install-ntfy.sh, put there by the deploy. The web user may run only this path.
    $script = '/usr/local/sbin/gse-install-ntfy';
    if (!is_file($script)) {
        return array(false, 'The installer is not on this server yet (' . $script . '). An administrator has to deploy it.');
    }
    $cmd = 'sudo -n ' . escapeshellarg($script) . ' --port ' . $port . ' 2>&1';
    exec($cmd, $out, $code);
    return array($code === 0, implode("\n", $out));
}

// Topics customers can subscribe to: the admin topic, the notices topic and one topic per game server.
function ntfy_admin_topics($db, $baseUrl)
{
    $list = array(
        array('label' => 'Admin: every event', 'topic' => NTFY_ADMIN_TOPIC),
        array('label' => 'Notices sent from Notifications', 'topic' => NTFY_NOTICE_TOPIC),
    );
    $homes = $db->resultQuery("SELECT home_id, ip, port FROM `DB_PREFIXserver_homes` ORDER BY home_id");
    foreach ((array)$homes as $home) {
        $list[] = array(
            'label' => 'Server ' . $home['ip'] . ':' . $home['port'] . ' (ID ' . (int)$home['home_id'] . ')',
            'topic' => ntfy_topic_for_home((int)$home['home_id']),
        );
    }
    foreach ($list as &$row) {
        $row['url'] = $baseUrl === '' ? '' : $baseUrl . '/' . rawurlencode($row['topic']);
    }
    unset($row);
    return $list;
}

// Publishes one message as JSON to a topic. Returns true when ntfy accepted it.
function ntfy_admin_post($baseUrl, $topic, $title, $text, $priority = 3)
{
    $payload = json_encode(array('topic' => $topic, 'title' => $title, 'message' => $text, 'priority' => $priority));
    $ch = curl_init($baseUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ));
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// Called when an admin sends a notification. Does nothing unless push is enabled and an address is set.
function ntfy_admin_publish_notice($db, $subject, $message)
{
    if (ntfy_admin_setting($db, 'ntfy_enabled') !== '1') {
        return false;
    }
    $baseUrl = ntfy_admin_base_url(ntfy_admin_setting($db, 'ntfy_base_url'));
    if ($baseUrl === '') {
        return false;
    }
    $title = trim(strip_tags((string)$subject));
    $text = trim(html_entity_decode(strip_tags((string)$message), ENT_QUOTES, 'UTF-8'));
    if ($title === '') {
        $title = 'Notice';
    }
    return ntfy_admin_post($baseUrl, NTFY_NOTICE_TOPIC, $title, $text);
}

// Handles the two forms on the page. Returns array of messages to show.
function ntfy_admin_handle_post($db)
{
    $messages = array();
    if (!isset($_SESSION['ntfy_csrf']) || !hash_equals((string)$_SESSION['ntfy_csrf'], (string)($_POST['ntfy_csrf'] ?? ''))) {
        return array(array(false, 'The page expired. Reload it and try again.'));
    }
    $action = (string)($_POST['ntfy_action'] ?? '');
    if ($action === 'save_ntfy') {
        $messages[] = ntfy_admin_save_address($db, $_POST['ntfy_ip'] ?? '', $_POST['ntfy_port'] ?? 0);
        ntfy_admin_save_setting($db, 'ntfy_enabled', isset($_POST['ntfy_enabled']) ? '1' : '0');
        $messages[] = array(true, isset($_POST['ntfy_enabled']) ? 'ntfy messages are on.' : 'ntfy messages are off.');
    } elseif ($action === 'install_ntfy') {
        $result = ntfy_admin_install($_POST['ntfy_install_port'] ?? 0);
        $messages[] = array($result[0], $result[1] !== '' ? $result[1] : ($result[0] ? 'ntfy installed.' : 'The install did not finish.'));
    }
    return $messages;
}

function ntfy_admin_render($db)
{
    if (empty($_SESSION['ntfy_csrf'])) {
        $_SESSION['ntfy_csrf'] = bin2hex(random_bytes(16));
    }
    $csrf = $_SESSION['ntfy_csrf'];
    $baseUrl = ntfy_admin_base_url(ntfy_admin_setting($db, 'ntfy_base_url'));
    $enabled = ntfy_admin_setting($db, 'ntfy_enabled') === '1';
    list($running, $statusText) = ntfy_admin_health($baseUrl);

    $host = '';
    $port = '2586';
    if (preg_match('#^https?://([^:/]+):(\d+)$#', $baseUrl, $m)) {
        $host = $m[1];
        $port = $m[2];
    }

    echo '<div id="ntfy_admin" style="margin:0 0 18px 0;">';
    echo '<h3>Push notifications (ntfy)</h3>';
    echo '<p>' . ($running ? '<b>Running.</b> ' : '<b>Not running.</b> ') . htmlspecialchars($statusText, ENT_QUOTES) . '<br>';
    echo 'Messages: <b>' . ($enabled ? 'on' : 'off') . '</b></p>';

    echo '<form method="post" action="?m=notifications">';
    echo '<input type="hidden" name="ntfy_csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
    echo '<input type="hidden" name="ntfy_action" value="save_ntfy">';
    echo 'IP or host name: <input type="text" name="ntfy_ip" value="' . htmlspecialchars($host, ENT_QUOTES) . '" size="18"> ';
    echo 'Port: <input type="text" name="ntfy_port" value="' . htmlspecialchars($port, ENT_QUOTES) . '" size="6" inputmode="numeric"> ';
    echo '<label><input type="checkbox" name="ntfy_enabled" value="1"' . ($enabled ? ' checked' : '') . '> Enable ntfy messages</label> ';
    echo '<button type="submit">Save</button>';
    echo '</form>';

    if (!$running) {
        echo '<form method="post" action="?m=notifications" style="margin-top:10px;" onsubmit="return confirm(\'Install ntfy on this machine now? It runs the installer as root.\');">';
        echo '<input type="hidden" name="ntfy_csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
        echo '<input type="hidden" name="ntfy_action" value="install_ntfy">';
        echo 'Install on port: <input type="text" name="ntfy_install_port" value="2587" size="6" inputmode="numeric"> ';
        echo '<button type="submit">Install ntfy</button> ';
        echo '<span class="muted">Needs Docker and the sudo rule for the installer. Nothing is installed until you press this.</span>';
        echo '</form>';
    }

    echo '<h4 style="margin-top:14px;">Topics</h4>';
    echo '<table><thead><tr><th>Who it is for</th><th>Topic</th><th>Subscribe at</th></tr></thead><tbody>';
    foreach (ntfy_admin_topics($db, $baseUrl) as $row) {
        echo '<tr><td>' . htmlspecialchars($row['label'], ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars($row['topic'], ENT_QUOTES) . '</td>'
            . '<td>' . ($row['url'] === '' ? '<span class="muted">set the address first</span>' : '<a href="' . htmlspecialchars($row['url'], ENT_QUOTES) . '" target="_blank">' . htmlspecialchars($row['url'], ENT_QUOTES) . '</a>') . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}
