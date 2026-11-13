<?php
/*
 * The status frame's contents as an HTML fragment, read from the cached snapshot.
 * The support page fetches this every 30 seconds. It never talks to an agent.
 */
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/status_snapshot.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=15');

$snapshot = null;
$db = function_exists('billing_get_db') ? billing_get_db() : null;
if ($db instanceof mysqli) {
    $snapshot = billing_status_load($db, (string)($table_prefix ?? ''));
}
echo billing_status_render($snapshot);
