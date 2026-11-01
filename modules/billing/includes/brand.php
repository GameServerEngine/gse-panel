<?php
/*
 * Brand text for the storefront.
 *
 * To rebrand, set the 'brand' array in site_config.php (not in git):
 *   'brand' => ['name' => 'Your Store', 'company' => 'Your Company'],
 * To change the logo, replace the PNG files in images/brand/ and keep the file names.
 * Anything not set falls back to the defaults below.
 */

if (!function_exists('billing_brand')) {
    function billing_brand(string $key): string
    {
        $defaults = array(
            'name'    => 'GameServers.World',
            'company' => 'Run Level Systems',
            'tagline' => 'Game server hosting with human support from Run Level Systems.',
        );
        $cfg = (isset($GLOBALS['billing_runtime_site_config']['brand']) && is_array($GLOBALS['billing_runtime_site_config']['brand']))
            ? $GLOBALS['billing_runtime_site_config']['brand'] : array();
        if (isset($cfg[$key]) && (string)$cfg[$key] !== '') {
            return (string)$cfg[$key];
        }
        return isset($defaults[$key]) ? $defaults[$key] : '';
    }
}

if (!function_exists('billing_brand_e')) {
    function billing_brand_e(string $key): string
    {
        return htmlspecialchars(billing_brand($key), ENT_QUOTES, 'UTF-8');
    }
}
