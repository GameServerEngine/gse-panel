<?php
return [
    // Billing URL base path (leave empty for domain root).
    // Examples: '', '/billing', '/panel/modules/billing'
    'base_path' => '/panel/modules/billing',

    // Absolute path to panel root when billing is copied outside panel tree.
    // Example: '/var/www/html/panel'
    'panel_path' => '',

    // Optional canonical timestamp source file.
    // Example: '/var/www/html/GSE/modules/billing/timestamp.txt'
    'legacy_timestamp_path' => '',

    // Absolute path outside the repository containing the PayPal encryption key.
    'paypal_key_file' => '',
];
