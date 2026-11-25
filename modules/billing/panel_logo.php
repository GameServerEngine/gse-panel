<?php
/**
 * Serves the panel logo to the storefront.
 *
 * The panel saves its logo to images/logo.png at the panel root. The storefront's
 * document root is modules/billing, so it cannot reach that file directly. This
 * script streams it, so the panel and the storefront show the same logo.
 */
$logoFile = dirname(__DIR__, 2) . '/images/logo.png';
if (!is_file($logoFile)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . filesize($logoFile));
header('Cache-Control: public, max-age=3600');
readfile($logoFile);
