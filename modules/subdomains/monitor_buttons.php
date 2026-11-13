<?php
// Adds an "Address" button to the game monitor and keeps DNS in step with the server's ip:port.
require_once __DIR__ . '/includes/subdomain_lib.php';

$module_buttons = array();
$sdbBtn = new SdDb($db, DB_PREFIX);
$sdSettings = sd_settings($sdbBtn);
if (sd_is_ready($sdSettings)) {
    $sdRow = sd_row_for_home($sdbBtn, $server_home['home_id']);
    $sdLabel = $sdRow ? htmlspecialchars(sd_fqdn($sdRow), ENT_QUOTES, 'UTF-8') : 'Free server address';
    if ($sdRow) {
        list($sdIp, $sdPort) = sd_home_address($sdbBtn, $server_home['home_id']);
        if ($sdIp !== '' && ($sdRow['ip'] !== $sdIp || (int)$sdRow['port'] !== $sdPort || $sdRow['status'] !== 'active')) {
            sd_resync_if_changed($sdbBtn, new SdCpanel($sdSettings), $sdSettings, $server_home['home_id'], $sdIp, $sdPort, sd_home_game_key($sdbBtn, $server_home['home_id']));
        }
    }
    $module_buttons[] = "<a class='monitorbutton' href='?m=subdomains&p=edit&home_id=" . (int)$server_home['home_id'] . "'>" . $sdLabel . "</a>";
}
