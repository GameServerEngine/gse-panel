<?php
/**
 * Competitor prices for the Game Catalog admin page, read from gameservercheck.com.
 *
 * The site has an index of games (https://gameservercheck.com/en/gameserver) and one page per
 * game (.../rent-<name>-server) that lists what each hoster charges. Prices are in euros, per
 * player slot or per GB of RAM. Only per-slot prices can be compared with ours.
 * Pages are cached for a few hours so searching does not hit the site on every click.
 */

const COMPETITOR_BASE_URL = 'https://gameservercheck.com';
const COMPETITOR_INDEX_PATH = '/en/gameserver';
const COMPETITOR_CACHE_SECONDS = 21600;
// Euros to dollars. A rough rate is fine: the result is only a starting point for our price.
const COMPETITOR_EUR_TO_USD = 1.08;

/** Download a page of the price site, from the cache when it is fresh. Returns '' on failure. */
function competitor_fetch(string $path): string
{
    $dir = sys_get_temp_dir() . '/gse_competitor_cache';
    @mkdir($dir, 0700, true);
    $file = $dir . '/' . md5($path) . '.html';
    if (is_file($file) && (time() - filemtime($file)) < COMPETITOR_CACHE_SECONDS) {
        return (string)file_get_contents($file);
    }
    $ch = curl_init(COMPETITOR_BASE_URL . $path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GSE-Panel price check)',
    ));
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($html) || $html === '') {
        // An old copy is better than nothing when the site is down.
        return is_file($file) ? (string)file_get_contents($file) : '';
    }
    @file_put_contents($file, $html);
    return $html;
}

/** The games on the index page: array of array('slug' => 'rent-rust-server', 'name' => 'RUST'). */
function competitor_parse_index(string $html): array
{
    $games = array();
    $pattern = '#href="/en/gameserver/(rent-[a-z0-9-]+)"(.*?)_name">([^<]+)<#s';
    if (preg_match_all($pattern, $html, $found, PREG_SET_ORDER)) {
        foreach ($found as $m) {
            // The lazy match must not run into the next tile.
            if (strpos($m[2], 'href="/en/gameserver/rent-') !== false) {
                continue;
            }
            $games[$m[1]] = array('slug' => $m[1], 'name' => trim(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8')));
        }
    }
    return array_values($games);
}

/** Games whose name contains every word typed in the search box. */
function competitor_find_games(array $index, string $query): array
{
    $words = preg_split('/[\s\-_]+/', strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return array();
    }
    $hits = array();
    foreach ($index as $game) {
        $name = strtolower($game['name'] . ' ' . $game['slug']);
        $all = true;
        foreach ($words as $word) {
            if (strpos($name, $word) === false) {
                $all = false;
                break;
            }
        }
        if ($all) {
            $hits[] = $game;
        }
    }
    return $hits;
}

/**
 * The hoster offers on a game page. The page carries its data as escaped JSON, so each offer
 * is found by its "hosterSlug" key and the fields after it are read until the next offer starts.
 * Returns rows of array('hoster', 'slots_min', 'slots_max', 'price', 'sale_price', 'currency', 'per', 'platform').
 */
function competitor_parse_offers(string $html): array
{
    $text = str_replace('\\"', '"', $html);
    $rows = array();
    if (!preg_match_all('/"hosterSlug":"[^"]+"/', $text, $starts, PREG_OFFSET_CAPTURE)) {
        return $rows;
    }
    $count = count($starts[0]);
    for ($i = 0; $i < $count; $i++) {
        $from = $starts[0][$i][1];
        $to = $i + 1 < $count ? $starts[0][$i + 1][1] : $from + 9000;
        $chunk = substr($text, $from, min($to - $from, 9000));
        $field = function (string $key, string $valuePattern) use ($chunk) {
            return preg_match('/"' . $key . '":' . $valuePattern . '/', $chunk, $m) ? $m[1] : null;
        };
        $price = $field('price', '([0-9.]+)');
        $per = $field('pricePer', '"([A-Za-z]+)"');
        if ($price === null || $per === null) {
            continue;
        }
        $rows[] = array(
            'hoster' => (string)$field('hosterName', '"([^"]*)"'),
            'slots_min' => (int)$field('slotsMin', '([0-9]+)'),
            'slots_max' => (int)$field('slotsMax', '([0-9]+)'),
            'price' => (float)$price,
            'sale_price' => $field('discountedPrice', '([0-9.]+)') !== null ? (float)$field('discountedPrice', '([0-9.]+)') : null,
            'currency' => (string)($field('currency', '"([A-Z]+)"') ?? 'EUR'),
            'per' => strtoupper($per),
            'platform' => (string)$field('platform', '"([^"]*)"'),
        );
    }
    usort($rows, function ($a, $b) {
        return $a['price'] <=> $b['price'];
    });
    return $rows;
}

/** Our price from the lowest per-slot competitor price: 10% lower, rounded to $0.05, never equal to theirs. */
function competitor_suggest_price(float $lowestUsdPerSlot): float
{
    if ($lowestUsdPerSlot <= 0) {
        return 0.50;
    }
    $price = round($lowestUsdPerSlot * 0.9 / 0.05) * 0.05;
    if ($price >= $lowestUsdPerSlot) {
        $price -= 0.05;
    }
    return round(max(0.05, $price), 2);
}

/**
 * Everything the admin page shows for one game page: the rows, the lowest per-slot price and our suggestion.
 * Console offers (PS4, Xbox) are ignored for the suggestion when a PC offer exists, because we sell PC servers.
 */
function competitor_summary(array $rows): array
{
    $perSlot = array();
    foreach ($rows as $row) {
        if ($row['per'] === 'SLOT' && $row['currency'] === 'EUR') {
            $perSlot[] = $row;
        }
    }
    $pc = array_values(array_filter($perSlot, function ($row) {
        return strtoupper($row['platform']) === 'PC';
    }));
    $candidates = $pc ? $pc : $perSlot;
    $lowest = null;
    foreach ($candidates as $row) {
        if ($lowest === null || $row['price'] < $lowest['price']) {
            $lowest = $row;
        }
    }
    $lowestUsd = $lowest ? round($lowest['price'] * COMPETITOR_EUR_TO_USD, 4) : 0.0;
    return array(
        'rows' => $rows,
        'lowest' => $lowest,
        'lowest_usd' => $lowestUsd,
        'suggested' => $lowest ? competitor_suggest_price($lowestUsd) : 0.0,
    );
}
