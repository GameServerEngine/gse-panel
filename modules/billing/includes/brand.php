<?php
/*
 * Brand text for the storefront.
 *
 * Name: set it in the panel under Settings > Panel name. The storefront header shows it only
 *       while Settings > Show site name next to the logo is on. Emails and invoices always use it.
 *       If it is empty, the 'brand' name in site_config is used for page text.
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

if (!function_exists('billing_header_site_name')) {
    /**
     * The site name beside the logo in the storefront header: the Panel name, shown only while
     * Settings > Show site name next to the logo is on. Empty when hidden or not set.
     */
    function billing_header_site_name(): string
    {
        $settings = function_exists('billing_get_panel_settings') ? @billing_get_panel_settings() : array();
        if (!is_array($settings)) {
            return '';
        }
        if (isset($settings['panel_show_site_name']) && (string)$settings['panel_show_site_name'] === '0') {
            return '';
        }
        return trim((string)($settings['panel_name'] ?? ''));
    }
}

if (!function_exists('billing_logo_html')) {
    /**
     * The panel logo, scaled by the Panel logo size setting (a percentage of its normal size).
     * Falls back to the bundled storefront mark when no panel logo has been uploaded.
     */
    function billing_logo_html(string $class, int $width, int $height): string
    {
        // brand.php lives in modules/billing/includes, so the panel root is three levels up.
        $panelLogo = dirname(__DIR__, 3) . '/images/logo.png';
        $zoom = 1;
        if (is_file($panelLogo)) {
            $src = billing_url('panel_logo.php') . '?v=' . filemtime($panelLogo);
            $settings = function_exists('billing_get_panel_settings') ? @billing_get_panel_settings() : array();
            $size = isset($settings['panel_logo_size']) ? (int)$settings['panel_logo_size'] : 100;
            $zoom = max(10, min(300, $size)) / 100;
        } else {
            $src = billing_url('images/brand/gse-icon-mark.png');
        }
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" class="' . $class . '" width="' . $width . '" height="' . $height . '" style="zoom:' . $zoom . '">';
    }
}
