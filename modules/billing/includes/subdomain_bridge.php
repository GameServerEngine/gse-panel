<?php
// Connects the storefront and provisioning to the optional "subdomains" module.
// Everything here is a no-op when the module is missing or not configured.

// Returns array($sdb, $settings, $provider) or null.
function billing_sd_load($dbHandle, $prefix)
{
    $lib = __DIR__ . '/../../subdomains/includes/subdomain_lib.php';
    if (!is_file($lib) || !$dbHandle) {
        return null;
    }
    require_once $lib;
    try {
        $sdb = new SdDb($dbHandle, $prefix);
        $have = $sdb->rows("SHOW TABLES LIKE '" . $sdb->esc($prefix . 'subdomain_settings') . "'");
        if (!$have) {
            return null;
        }
        $settings = sd_settings($sdb);
        if (!sd_is_ready($settings)) {
            return null;
        }
        return array($sdb, $settings, new SdCpanel($settings));
    } catch (Exception $e) {
        return null;
    }
}

// After a paid order was provisioned: create the DNS for the name reserved on the invoice.
function billing_sd_activate_after_provision($dbHandle, $prefix, $invoiceId, $homeId)
{
    $ctx = billing_sd_load($dbHandle, $prefix);
    if (!$ctx || (int)$invoiceId < 1 || (int)$homeId < 1) {
        return;
    }
    list($sdb, $settings, $provider) = $ctx;
    try {
        list($ip, $port) = sd_home_address($sdb, (int)$homeId);
        if ($ip === '') {
            return;
        }
        sd_activate_invoice($sdb, $provider, $settings, (int)$invoiceId, (int)$homeId, $ip, $port, sd_home_game_key($sdb, (int)$homeId));
    } catch (Exception $e) {
        error_log('billing subdomain activate failed: ' . $e->getMessage());
    }
}
