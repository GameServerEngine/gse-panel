<?php
require_once __DIR__ . '/storefront_locations.php';
/**
 * Database-facing helpers for the panel-owned game catalog
 * (DB_PREFIXcatalog_offer / DB_PREFIXcatalog_tier). Pure pricing math
 * lives in catalog_pricing.php; this file only loads rows.
 */

require_once __DIR__ . '/catalog_pricing.php';
require_once __DIR__ . '/catalog_games.php';

if (!function_exists('catalog_load_enabled_games')) {
    /**
     * One row per game that has at least one enabled catalog_offer, with the
     * lowest monthly starting price across its enabled offers (for display on
     * the catalog grid). Mirrors the join shape serverlist.php used to build
     * from billing_services.
     */
    function catalog_load_enabled_games(mysqli $db, string $prefix): array
    {
        $hasDetails = catalog_storefront_has_game_details($db, $prefix);
        $detailColumns = $hasDetails
            ? "COALESCE(NULLIF(cg.display_name, ''), ch.game_name) AS display_name, COALESCE(cg.description, '') AS description, COALESCE(cg.img_url, '') AS img_url"
            : "ch.game_name AS display_name, '' AS description, '' AS img_url";
        $detailJoin = $hasDetails ? "LEFT JOIN `{$prefix}catalog_game` cg ON cg.home_cfg_id = ch.home_cfg_id" : '';
        $detailGroup = $hasDetails ? ', cg.display_name, cg.description, cg.img_url' : '';
        $sql = "SELECT ch.home_cfg_id, ch.game_name, ch.game_key, ch.home_cfg_file, {$detailColumns},
                       MIN(
                           CASE co.pricing_mode
                               WHEN 'per_slot' THEN co.per_slot_price_monthly
                               WHEN 'base_plus_extra' THEN co.base_price_monthly
                               ELSE (SELECT MIN(t.price_monthly) FROM `{$prefix}catalog_tier` t WHERE t.offer_id = co.offer_id)
                           END
                       ) AS starting_price_monthly,
                       MIN(co.slot_min_qty) AS slot_min_qty,
                       MAX(co.slot_max_qty) AS slot_max_qty
                FROM `{$prefix}catalog_offer` co
                INNER JOIN `{$prefix}config_homes` ch ON ch.home_cfg_id = co.home_cfg_id
                {$detailJoin}
                WHERE co.enabled = 1
                GROUP BY ch.home_cfg_id, ch.game_name, ch.game_key, ch.home_cfg_file{$detailGroup}
                ORDER BY display_name";
        $res = $db->query($sql);
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return catalog_group_storefront_games($rows, catalog_list_images(__DIR__ . '/../../../images/games'));
    }
}

if (!function_exists('catalog_storefront_has_game_details')) {
    /** True once migration 0008 has created the game details table. Lets the storefront work before it runs. */
    function catalog_storefront_has_game_details(mysqli $db, string $prefix): bool
    {
        $name = $db->real_escape_string($prefix . 'catalog_game');
        $res = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$name}' LIMIT 1");
        return $res !== false && $res->num_rows > 0;
    }
}

if (!function_exists('catalog_load_game_details')) {
    /** The storefront name, description and picture for one game. Falls back to the game config name. */
    function catalog_load_game_details(mysqli $db, string $prefix, int $homeCfgId): array
    {
        $details = array('display_name' => '', 'description' => '', 'img_url' => '');
        if (!catalog_storefront_has_game_details($db, $prefix)) {
            return $details;
        }
        $res = $db->query("SELECT display_name, description, img_url FROM `{$prefix}catalog_game` WHERE home_cfg_id = " . (int)$homeCfgId . ' LIMIT 1');
        $row = $res ? $res->fetch_assoc() : null;
        return $row ? array_merge($details, $row) : $details;
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
