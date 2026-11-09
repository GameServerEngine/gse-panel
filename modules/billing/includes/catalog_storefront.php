<?php
require_once __DIR__ . '/storefront_locations.php';
/**
 * Database-facing helpers for the panel-owned game catalog
 * (TABLE_PREFIX_PLACEHOLDERcatalog_offer / TABLE_PREFIX_PLACEHOLDERcatalog_tier). Pure pricing math
 * lives in catalog_pricing.php; this file only loads rows.
 */

require_once __DIR__ . '/catalog_pricing.php';

if (!function_exists('catalog_load_enabled_games')) {
    /**
     * One row per game that has at least one enabled catalog_offer, with the
     * lowest monthly starting price across its enabled offers (for display on
     * the catalog grid). Mirrors the join shape serverlist.php used to build
     * from billing_services.
     */
    function catalog_load_enabled_games(mysqli $db, string $prefix): array
    {
        $sql = "SELECT ch.home_cfg_id, ch.game_name, ch.game_key, ch.home_cfg_file,
                       MIN(
                           CASE co.pricing_mode
                               WHEN 'per_slot' THEN co.per_slot_price_monthly
                               WHEN 'base_plus_extra' THEN co.base_price_monthly
                               ELSE NULL
                           END
                       ) AS starting_price_monthly,
                       MIN(co.slot_min_qty) AS slot_min_qty,
                       MAX(co.slot_max_qty) AS slot_max_qty
                FROM `{$prefix}catalog_offer` co
                INNER JOIN `{$prefix}config_homes` ch ON ch.home_cfg_id = co.home_cfg_id
                WHERE co.enabled = 1
                GROUP BY ch.home_cfg_id, ch.game_name, ch.game_key, ch.home_cfg_file
                ORDER BY ch.game_name";
        $res = $db->query($sql);
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('catalog_load_offers_for_game')) {
    /** All enabled offers for one game, one row per location, with remote server name. */
    function catalog_load_offers_for_game(mysqli $db, string $prefix, int $homeCfgId): array
    {
        $homeCfgId = (int)$homeCfgId;
        $sql = "SELECT co.*, rs.remote_server_name, rs.location_id AS city_location_id
                FROM `{$prefix}catalog_offer` co
                INNER JOIN `{$prefix}remote_servers` rs ON rs.remote_server_id = co.remote_server_id
                WHERE co.enabled = 1 AND co.home_cfg_id = {$homeCfgId}
                ORDER BY rs.remote_server_name";
        $res = $db->query($sql);
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('catalog_load_offer_by_id')) {
    function catalog_load_offer_by_id(mysqli $db, string $prefix, int $offerId): ?array
    {
        $offerId = (int)$offerId;
        $res = $db->query("SELECT * FROM `{$prefix}catalog_offer` WHERE offer_id = {$offerId} AND enabled = 1 LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        return $row ?: null;
    }
}

if (!function_exists('catalog_load_tiers_for_offer')) {
    function catalog_load_tiers_for_offer(mysqli $db, string $prefix, int $offerId): array
    {
        $offerId = (int)$offerId;
        $res = $db->query("SELECT * FROM `{$prefix}catalog_tier` WHERE offer_id = {$offerId}");
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('catalog_offer_display_price')) {
    /** Lowest achievable monthly unit price for an offer, used for catalog/card display. */
    function catalog_offer_display_price(array $offer, array $tiers = []): float
    {
        switch ((string)($offer['pricing_mode'] ?? 'per_slot')) {
            case 'tiers':
                $prices = array_map(static fn($t) => (float)($t['price_monthly'] ?? 0), $tiers);
                return !empty($prices) ? min($prices) : 0.0;
            case 'base_plus_extra':
                return (float)($offer['base_price_monthly'] ?? 0);
            default:
                $slotMin = max(1, (int)($offer['slot_min_qty'] ?? 1));
                return (float)($offer['per_slot_price_monthly'] ?? 0) * $slotMin;
        }
    }
}
