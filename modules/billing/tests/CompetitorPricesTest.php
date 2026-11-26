<?php
require_once __DIR__ . '/../includes/competitor_prices.php';

$index = '<a class="x_tile" href="/en/gameserver/rent-rust-server"><div class="x_name">RUST</div></a>'
    . '<a class="x_tile" href="/en/gameserver/rent-conan-exiles-server"><div class="x_name">Conan Exiles</div></a>';
$games = competitor_parse_index($index);
if (count($games) !== 2 || $games[0]['name'] !== 'RUST' || $games[1]['slug'] !== 'rent-conan-exiles-server') {
    throw new RuntimeException('index not parsed');
}
$hits = competitor_find_games($games, 'conan ex');
if (count($hits) !== 1 || $hits[0]['name'] !== 'Conan Exiles') {
    throw new RuntimeException('search by words failed');
}
if (competitor_find_games($games, '') !== array()) {
    throw new RuntimeException('an empty search must find nothing');
}

// The page data is escaped JSON, like the real site.
$page = '{\"hosterSlug\":\"zap\",\"hosterName\":\"ZAP-Hosting\",\"slotsMin\":24,\"slotsMax\":500,\"price\":0.36,\"discountedPrice\":0.288,\"currency\":\"EUR\",\"pricePer\":\"SLOT\",\"platform\":\"PC\"}'
    . '{\"hosterSlug\":\"ram\",\"hosterName\":\"RamHost\",\"slotsMin\":0,\"slotsMax\":0,\"price\":1.5,\"discountedPrice\":null,\"currency\":\"EUR\",\"pricePer\":\"RAM\",\"platform\":\"PC\"}'
    . '{\"hosterSlug\":\"gp\",\"hosterName\":\"GPORTAL\",\"slotsMin\":20,\"slotsMax\":1000,\"price\":1.01,\"discountedPrice\":0.909,\"currency\":\"EUR\",\"pricePer\":\"SLOT\",\"platform\":\"PC\"}';
$rows = competitor_parse_offers($page);
if (count($rows) !== 3 || $rows[0]['hoster'] !== 'ZAP-Hosting' || $rows[0]['sale_price'] !== 0.288 || $rows[2]['sale_price'] !== null) {
    throw new RuntimeException('offers not parsed: ' . json_encode($rows));
}
$summary = competitor_summary($rows);
if ($summary['lowest']['hoster'] !== 'ZAP-Hosting') {
    throw new RuntimeException('lowest per-slot offer should skip per-RAM prices');
}
// 0.36 EUR = 0.3888 USD; 10% lower is 0.35; that rounds to 0.35, below theirs.
if (abs($summary['suggested'] - 0.35) > 0.001) {
    throw new RuntimeException('suggested price was ' . $summary['suggested']);
}
if (competitor_suggest_price(0.05) !== 0.05) {
    throw new RuntimeException('a very cheap competitor must still leave a positive price');
}
// A console offer is cheaper but we sell PC servers, so it is ignored.
$withConsole = array_merge($rows, array(array('hoster' => 'G', 'slots_min' => 1, 'slots_max' => 1, 'price' => 0.17,
    'sale_price' => null, 'currency' => 'EUR', 'per' => 'SLOT', 'platform' => 'PS4')));
if (competitor_summary($withConsole)['lowest']['hoster'] !== 'ZAP-Hosting') {
    throw new RuntimeException('console prices must not set our price');
}
if (competitor_suggest_price(0.0) !== 0.50) {
    throw new RuntimeException('no competitor price must give the 0.50 fallback');
}
echo "CompetitorPricesTest passed\n";
