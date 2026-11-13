<?php
/*
 * Web app manifest, so the storefront can be installed on a phone's home screen.
 * It is a PHP file (not manifest.json) because the storefront blocks .json files and because
 * the name and the paths follow the brand settings and the site's base path.
 */
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/billing_bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name = billing_brand('name');
echo json_encode(array(
    'id' => billing_url(''),
    'name' => $name,
    'short_name' => strlen($name) <= 12 ? $name : (explode(' ', trim($name))[0] ?: substr($name, 0, 12)),
    'description' => billing_brand('tagline'),
    'start_url' => billing_url(''),
    'scope' => billing_url(''),
    'display' => 'standalone',
    'background_color' => '#07080c',
    'theme_color' => '#07080c',
    'icons' => array(
        array('src' => billing_url('images/brand/gse-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
        array('src' => billing_url('images/brand/gse-icon-mark.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
    ),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
