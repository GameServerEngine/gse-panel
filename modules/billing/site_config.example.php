<?php
// Copy to site_config.local.php (not tracked in git) and change what you need.
return [
    // Billing URL base path. Leave empty when the storefront has its own host.
    // Use '/panel/modules/billing' only when it is served from inside the panel site.
    'base_path' => '',

    // Absolute path to the panel root when billing is copied outside the panel tree.
    // Example: '/var/www/html/GSE/Panel'
    'panel_path' => '',

    // Panel address, used for the Control Panel link and shared pictures and themes.
    // Example: 'https://panel.example.com'
    'panel_base' => '',

    // Optional canonical timestamp source file.
    'legacy_timestamp_path' => '',

    // Absolute path outside the web root of the key that encrypts the stored PayPal secret.
    'paypal_key_file' => '',

    // Text shown on the storefront. Anything left out uses the default in includes/brand.php.
    'brand' => [
        'name'        => '',
        'company'     => '',
        'tagline'     => '',
        'email'       => '',
        'discord_url' => '',
    ],
];
