<?php
/*
 * Brand text for the storefront.
 *
 * Name: set it in the panel under Settings > Panel name. That value is used on the
 *       storefront too. If it is empty, the 'brand' name in site_config is used.
 * Company: set 'brand' => ['company' => '...'] in site_config.local.php.
 * Support contact: 'brand' => ['email' => '...', 'discord_url' => 'https://discord.gg/...'] in
 *       site_config.local.php. The support page shows Discord only when discord_url is set.
 * Logo: set it in the panel under Settings > Panel logo (images/logo.png).
 */

if (!function_exists('billing_brand')) {
    function billing_brand(string $key): string
    {
        static $panelName = null;
        if ($key === 'name') {
            if ($panelName === null) {
                $panelName = '';
                if (function_exists('billing_get_panel_settings')) {
                    $settings = @billing_get_panel_settings();
                    if (is_array($settings) && !empty($settings['panel_name'])) {
                        $panelName = (string)$settings['panel_name'];
                    }
                }
            }
            if ($panelName !== '') {
                return $panelName;
            }
        }

        $defaults = array(
            'name'    => 'GameServer Engine',
            'company' => 'Run Level Systems',
            'tagline' => 'Game server hosting with human support from Run Level Systems.',
            'email'   => 'runlevelsystems@gmail.com',
            'discord_url' => '',
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
