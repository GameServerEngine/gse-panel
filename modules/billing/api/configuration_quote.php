<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/configuration_quote.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $db = billing_get_db();
    $offers = catalog_load_offers_for_game($db, $table_prefix, (int)($_GET['game'] ?? 0));
    echo json_encode(storefront_quote($db, $table_prefix, $offers, (int)($_GET['slots'] ?? 0),
        storefront_load_cities($db, $table_prefix)), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{}';
}
