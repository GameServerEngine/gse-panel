<?php
// Builds the subdomain helpers for pages that run inside the panel.
require_once __DIR__ . '/subdomain_lib.php';

function sd_panel_context()
{
    global $db;
    $sdb = new SdDb($db, DB_PREFIX);
    $settings = sd_settings($sdb);
    $provider = new SdCpanel($settings);
    return array($sdb, $settings, $provider);
}

function sd_panel_home_access($home_id)
{
    global $db;
    $home_id = (int)$home_id;
    if ($home_id < 1) {
        return false;
    }
    if ($db->isAdmin($_SESSION['user_id'])) {
        return $db->getGameHome($home_id);
    }
    return $db->getUserGameHome($_SESSION['user_id'], $home_id);
}
