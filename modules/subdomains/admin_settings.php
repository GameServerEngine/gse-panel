<?php
// Admin settings for free subdomains (cPanel DNS API).
require_once __DIR__ . '/includes/panel_context.php';

function sd_ah($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function exec_module()
{
    list($sdb, $settings, $provider) = sd_panel_context();
    echo "<h2>Subdomains</h2>";
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $keys = array('cpanel_host', 'cpanel_port', 'cpanel_user', 'domains', 'ttl', 'srv_games', 'cooldown');
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                sd_save_setting($sdb, $k, trim($_POST[$k]));
            }
        }
        sd_save_setting($sdb, 'enabled', isset($_POST['enabled']) ? '1' : '0');
        sd_save_setting($sdb, 'verify_tls', isset($_POST['verify_tls']) ? '1' : '0');
        if (isset($_POST['cpanel_token']) && trim($_POST['cpanel_token']) !== '') {
            sd_save_setting($sdb, 'cpanel_token', trim($_POST['cpanel_token']));
        }
        $settings = sd_settings($sdb);
        $msg = 'Settings saved.';
        if (isset($_POST['test'])) {
            $provider = new SdCpanel($settings);
            $out = array();
            foreach (sd_domains($settings) as $d) {
                try {
                    $z = $provider->zone($d, true);
                    $out[] = $d . ': OK (' . count($z['records']) . ' records)';
                } catch (Exception $e) {
                    $out[] = $d . ': ' . $e->getMessage();
                }
            }
            $msg .= ' Test: ' . implode('; ', $out);
        }
    }
    if ($msg !== '') {
        echo "<p><b>" . sd_ah($msg) . "</b></p>";
    }
    echo "<form method='post'><table class='center'>";
    echo "<tr><td>Enabled</td><td><input type='checkbox' name='enabled'" . ($settings['enabled'] === '1' ? ' checked' : '') . "></td></tr>";
    echo "<tr><td>cPanel host</td><td><input type='text' name='cpanel_host' value='" . sd_ah($settings['cpanel_host']) . "' size='40'></td></tr>";
    echo "<tr><td>cPanel port</td><td><input type='text' name='cpanel_port' value='" . sd_ah($settings['cpanel_port']) . "' size='6'></td></tr>";
    echo "<tr><td>cPanel user</td><td><input type='text' name='cpanel_user' value='" . sd_ah($settings['cpanel_user']) . "' size='24'></td></tr>";
    echo "<tr><td>API token</td><td><input type='password' name='cpanel_token' value='' size='40' autocomplete='new-password'> "
        . ($settings['cpanel_token'] !== '' ? '(saved, leave blank to keep)' : '(not set)') . "</td></tr>";
    echo "<tr><td>Domains (comma separated)</td><td><input type='text' name='domains' value='" . sd_ah($settings['domains']) . "' size='40'></td></tr>";
    echo "<tr><td>Record TTL (seconds)</td><td><input type='text' name='ttl' value='" . sd_ah($settings['ttl']) . "' size='6'></td></tr>";
    echo "<tr><td>SRV record for game keys</td><td><input type='text' name='srv_games' value='" . sd_ah($settings['srv_games']) . "' size='40'></td></tr>";
    echo "<tr><td>Name change cooldown (seconds)</td><td><input type='text' name='cooldown' value='" . sd_ah($settings['cooldown']) . "' size='6'></td></tr>";
    echo "<tr><td>Verify TLS certificate</td><td><input type='checkbox' name='verify_tls'" . ($settings['verify_tls'] === '1' ? ' checked' : '') . "></td></tr>";
    echo "</table><br><input type='submit' value='Save'> <input type='submit' name='test' value='Save and test connection'></form>";

    $rows = $sdb->rows('SELECT * FROM ' . $sdb->t('subdomains') . ' ORDER BY id DESC LIMIT 100');
    echo "<h3>Names</h3><table class='center'><tr><th>Name</th><th>Server</th><th>Status</th><th>IP:port</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>" . sd_ah(sd_fqdn($r)) . "</td><td>" . ((int)$r['home_id'] ?: 'order ' . (int)$r['invoice_id']) . "</td><td>"
            . sd_ah($r['status']) . "</td><td>" . sd_ah($r['ip'] . ($r['port'] ? ':' . $r['port'] : '')) . "</td></tr>";
    }
    echo "</table>";
}
