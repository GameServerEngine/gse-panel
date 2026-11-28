<?php
// Runs the production placement SQL. By default it uses an in-memory SQLite
// stand-in. With GSE_PLACEMENT_TEST_SOCKET (or _HOST) set it runs on a real
// MySQL server, in normal InnoDB tables with a unique prefix that are dropped
// at the end. See placement_mysql.inc for the connection variables.
$realMysql = getenv('GSE_PLACEMENT_TEST_SOCKET') !== false || getenv('GSE_PLACEMENT_TEST_HOST') !== false;
if (!$realMysql && class_exists('mysqli')) {
    // The stand-in replaces the mysqli class, so run again without that extension.
    passthru(escapeshellarg(PHP_BINARY) . ' -n -d extension=pdo -d extension=pdo_sqlite ' . escapeshellarg(__FILE__), $rc);
    exit($rc);
}
if (!class_exists('mysqli')) require __DIR__ . '/placement_sqlite.inc';
require_once __DIR__ . '/placement_mysql.inc';
require_once __DIR__ . '/../includes/configuration_quote.php';

$db = $realMysql ? placement_test_connect() : new mysqli();
$p = placement_test_prefix($realMysql);
if ($realMysql) register_shutdown_function(fn() => placement_test_drop($db, $p));
placement_test_create($db, $p, $realMysql);
$now = time();
placement_test_seed($db, $p, $now);

$offers = catalog_load_offers_for_game($db, $p, 10);
$cities = storefront_load_cities($db, $p);
$quote = fn($n) => storefront_quote($db, $p, $offers, $n, $cities);
expect_resource(array_column($offers,'city_location_id','remote_server_name') == ['game3'=>6,'game5'=>6,'game6'=>4],'actual billing joins');
expect_resource($quote(16)['locations'] == [6=>0,4=>109,12=>0], 'game5 disk full and game3 unapproved close Kansas City; eligible game6 opens NYC');
expect_resource(placement_evaluate($db,$p,108,16)==='insufficient disk_bytes','actual game5 rejection');
expect_resource(storefront_available_variant($db,$p,[10])===10,'catalog opens on a supported configuration');
$q = $quote(32);
expect_resource(!array_filter($q['locations']) && $q['preview']==64,'slot change disables all locations and still prices');
expect_resource(!storefront_offer_is_orderable($db,$p,109,32),'direct order sees same rejection');
$inv = placement_test_invoice(12);
expect_resource(!placement_reserve_invoices($db,$p,[['ip'=>8]+$inv],$now+900),'offer cannot move to forged host');
expect_resource(placement_reserve_invoices($db,$p,[$inv],$now+900),'reserve for first order');
expect_resource(!array_filter($quote(16)['locations']),'other customers see outstanding claim');
expect_resource(!placement_reserve_invoices($db,$p,[['invoice_id'=>13]+$inv],$now+900),'second checkout denied');
expect_resource(placement_reserve_invoices($db,$p,[$inv],PHP_INT_MAX),'capture and provisioning can reuse own hold');
$db->query("UPDATE {$p}status_sample SET sampled_at=1 WHERE remote_server_id=9");
expect_resource(!placement_reserve_invoices($db,$p,[$inv],PHP_INT_MAX),'capacity lost before provision');
expect_resource(storefront_available_variant($db,$p,[10])===null && $quote(16)['preview']==32,'all closed catalog keeps prices');
echo ($realMysql ? "Placement flow passed on real MySQL\n" : "Placement flow passed (SQLite stand-in)\n");
