<?php
/**
 * Database code for the Game Catalog admin page.
 * The plain helpers (reading the form, planning the import) are in catalog_games.php.
 *
 * Tables used:
 *   catalog_game                  name, description and picture of a game
 *   catalog_offer, catalog_tier   what is sold: one offer per game and agent, with its pricing
 *   billing_service_requirements  minimum machine size for a game
 *   billing_services              the old services list, only read by the import
 */

require_once __DIR__ . '/catalog_games.php';

/** Runs a SELECT and returns every row. */
function catalog_admin_rows(mysqli $db, string $sql): array
{
    $rows = array();
    $result = $db->query($sql);
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}

/** Runs one INSERT or UPDATE with bound values. Throws when it fails so the caller can roll back. */
function catalog_admin_run(mysqli $db, string $sql, string $types, array $values): void
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();
}

function catalog_admin_table_exists(mysqli $db, string $table): bool
{
    $name = $db->real_escape_string($table);
    $rows = catalog_admin_rows($db, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$name}' LIMIT 1");
    return !empty($rows);
}

/** Every agent (remote server) a game could be sold on. */
function catalog_admin_agents(mysqli $db, string $prefix): array
{
    return catalog_admin_rows(
        $db,
        "SELECT rs.remote_server_id, rs.remote_server_name, rs.server_os, rs.install_enabled, c.name AS city_name
         FROM `{$prefix}remote_servers` rs
         LEFT JOIN `{$prefix}billing_locations` c ON c.location_id = rs.location_id AND c.kind = 'city'
         ORDER BY rs.remote_server_name"
    );
}

/**
 * One row per game config for the list page, with how many agents it is published on
 * and its lowest monthly price. $status is '' (all), 'published' or 'draft'.
 */
function catalog_admin_game_list(mysqli $db, string $prefix, string $search, string $status): array
{
    $like = $db->real_escape_string('%' . $search . '%');
    $price = "CASE co.pricing_mode
                WHEN 'per_slot' THEN co.per_slot_price_monthly
                WHEN 'base_plus_extra' THEN co.base_price_monthly
                ELSE (SELECT MIN(t.price_monthly) FROM `{$prefix}catalog_tier` t WHERE t.offer_id = co.offer_id)
              END";
    $having = $status === 'published' ? 'HAVING published_agents > 0' : ($status === 'draft' ? 'HAVING published_agents = 0' : '');
    $sql = "SELECT ch.home_cfg_id, ch.game_name, ch.home_cfg_file, cg.display_name,
                   COUNT(CASE WHEN co.enabled = 1 THEN 1 END) AS published_agents,
                   MIN(CASE WHEN co.enabled = 1 THEN {$price} END) AS from_price
            FROM `{$prefix}config_homes` ch
            LEFT JOIN `{$prefix}catalog_game` cg ON cg.home_cfg_id = ch.home_cfg_id
            LEFT JOIN `{$prefix}catalog_offer` co ON co.home_cfg_id = ch.home_cfg_id
            WHERE ch.game_name LIKE '{$like}' OR cg.display_name LIKE '{$like}'
            GROUP BY ch.home_cfg_id, ch.game_name, ch.home_cfg_file, cg.display_name
            {$having}
            ORDER BY ch.game_name";

    return catalog_admin_rows($db, $sql);
}

/** One game config with its storefront details, or null when the game does not exist. */
function catalog_admin_game(mysqli $db, string $prefix, int $homeCfgId): ?array
{
    $rows = catalog_admin_rows(
        $db,
        "SELECT ch.home_cfg_id, ch.game_name, ch.home_cfg_file,
                COALESCE(cg.display_name, '') AS display_name,
                COALESCE(cg.description, '') AS description,
                COALESCE(cg.img_url, '') AS img_url
         FROM `{$prefix}config_homes` ch
         LEFT JOIN `{$prefix}catalog_game` cg ON cg.home_cfg_id = ch.home_cfg_id
         WHERE ch.home_cfg_id = {$homeCfgId} LIMIT 1"
    );
    return $rows[0] ?? null;
}

/** The game's offers keyed by agent id. */
function catalog_admin_offers(mysqli $db, string $prefix, int $homeCfgId): array
{
    $offers = array();
    foreach (catalog_admin_rows($db, "SELECT * FROM `{$prefix}catalog_offer` WHERE home_cfg_id = {$homeCfgId}") as $row) {
        $offers[(int)$row['remote_server_id']] = $row;
    }
    return $offers;
}

/** Tier rows for some offers, as offer_id => tier_key => row. */
function catalog_admin_tiers(mysqli $db, string $prefix, array $offerIds): array
{
    $tiers = array();
    if (empty($offerIds)) {
        return $tiers;
    }
    $list = implode(',', array_map('intval', $offerIds));
    foreach (catalog_admin_rows($db, "SELECT * FROM `{$prefix}catalog_tier` WHERE offer_id IN ({$list})") as $row) {
        $tiers[(int)$row['offer_id']][(string)$row['tier_key']] = $row;
    }
    return $tiers;
}

/** Minimum machine size for a game: cpu cores, ram in MB, disk in GB. */
function catalog_admin_requirements(mysqli $db, string $prefix, int $homeCfgId): array
{
    $rows = catalog_admin_rows(
        $db,
        "SELECT min_cpu_cores, min_ram_mb, min_disk_gb FROM `{$prefix}billing_service_requirements` WHERE home_cfg_id = {$homeCfgId}"
    );
    $row = $rows[0] ?? array('min_cpu_cores' => 0, 'min_ram_mb' => 0, 'min_disk_gb' => 0);
    return array(
        'cpu' => (float)$row['min_cpu_cores'],
        'ram' => (int)$row['min_ram_mb'],
        'disk' => (int)$row['min_disk_gb'],
    );
}

/** Reads the minimum machine size from the form. */
function catalog_admin_read_requirements(array $post): array
{
    return array(
        'cpu' => max(0.0, (float)($post['min_cpu_cores'] ?? 0)),
        'ram' => max(0, (int)($post['min_ram_mb'] ?? 0)),
        'disk' => max(0, (int)($post['min_disk_gb'] ?? 0)),
    );
}

function catalog_admin_save_details(mysqli $db, string $prefix, int $homeCfgId, array $details): void
{
    catalog_admin_run(
        $db,
        "INSERT INTO `{$prefix}catalog_game` (home_cfg_id, display_name, description, img_url, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), description = VALUES(description),
                                 img_url = VALUES(img_url), updated_at = NOW()",
        'isss',
        array($homeCfgId, $details['display_name'], $details['description'], $details['img_url'])
    );
}

function catalog_admin_save_requirements(mysqli $db, string $prefix, int $homeCfgId, array $need): void
{
    catalog_admin_run(
        $db,
        "INSERT INTO `{$prefix}billing_service_requirements` (home_cfg_id, min_cpu_cores, min_ram_mb, min_disk_gb)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE min_cpu_cores = VALUES(min_cpu_cores), min_ram_mb = VALUES(min_ram_mb),
                                 min_disk_gb = VALUES(min_disk_gb)",
        'idii',
        array($homeCfgId, $need['cpu'], $need['ram'], $need['disk'])
    );
}

/** Creates or updates the offer for one game on one agent and publishes it. */
function catalog_admin_publish_offer(mysqli $db, string $prefix, int $homeCfgId, int $agentId, array $s): void
{
    $slot = catalog_period_prices($s['per_slot_monthly']);
    $base = catalog_period_prices($s['base_monthly']);
    $extra = catalog_period_prices($s['extra_monthly']);
    catalog_admin_run(
        $db,
        "INSERT INTO `{$prefix}catalog_offer`
            (home_cfg_id, remote_server_id, enabled, pricing_mode, period_default, currency, slot_min_qty, slot_max_qty,
             per_slot_price_daily, per_slot_price_monthly, per_slot_price_year,
             base_slots, base_price_daily, base_price_monthly, base_price_year,
             extra_price_per_slot_daily, extra_price_per_slot_monthly, extra_price_per_slot_year, updated_at)
         VALUES (?, ?, 1, ?, ?, 'USD', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            enabled = 1, pricing_mode = VALUES(pricing_mode), period_default = VALUES(period_default),
            slot_min_qty = VALUES(slot_min_qty), slot_max_qty = VALUES(slot_max_qty),
            per_slot_price_daily = VALUES(per_slot_price_daily), per_slot_price_monthly = VALUES(per_slot_price_monthly),
            per_slot_price_year = VALUES(per_slot_price_year), base_slots = VALUES(base_slots),
            base_price_daily = VALUES(base_price_daily), base_price_monthly = VALUES(base_price_monthly),
            base_price_year = VALUES(base_price_year),
            extra_price_per_slot_daily = VALUES(extra_price_per_slot_daily),
            extra_price_per_slot_monthly = VALUES(extra_price_per_slot_monthly),
            extra_price_per_slot_year = VALUES(extra_price_per_slot_year), updated_at = NOW()",
        'iissiidddidddddd',
        array(
            $homeCfgId, $agentId, $s['mode'], $s['period'], $s['slot_min'], $s['slot_max'],
            $slot['daily'], $slot['monthly'], $slot['year'],
            $s['base_slots'], $base['daily'], $base['monthly'], $base['year'],
            $extra['daily'], $extra['monthly'], $extra['year'],
        )
    );
}

/** Stops selling a game on an agent. The row stays, so its pricing is kept if it is turned on again. */
function catalog_admin_unpublish_offer(mysqli $db, string $prefix, int $homeCfgId, int $agentId): void
{
    catalog_admin_run(
        $db,
        "UPDATE `{$prefix}catalog_offer` SET enabled = 0, updated_at = NOW() WHERE home_cfg_id = ? AND remote_server_id = ?",
        'ii',
        array($homeCfgId, $agentId)
    );
}

/** Saves the four tiers of an offer. Used when the pricing mode is "tiers". */
function catalog_admin_save_tiers(mysqli $db, string $prefix, int $homeCfgId, int $agentId, array $tiers): void
{
    $rows = catalog_admin_rows(
        $db,
        "SELECT offer_id FROM `{$prefix}catalog_offer` WHERE home_cfg_id = {$homeCfgId} AND remote_server_id = {$agentId} LIMIT 1"
    );
    if (empty($rows)) {
        throw new RuntimeException('The offer was not found after saving it.');
    }
    $offerId = (int)$rows[0]['offer_id'];
    foreach ($tiers as $key => $tier) {
        $price = catalog_period_prices($tier['monthly']);
        catalog_admin_run(
            $db,
            "INSERT INTO `{$prefix}catalog_tier` (offer_id, tier_key, slot_count, price_daily, price_monthly, price_year)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE slot_count = VALUES(slot_count), price_daily = VALUES(price_daily),
                                     price_monthly = VALUES(price_monthly), price_year = VALUES(price_year)",
            'isiddd',
            array($offerId, $key, $tier['slot_count'], $price['daily'], $price['monthly'], $price['year'])
        );
    }
}

/**
 * Saves a whole game: its details, its price on every ticked agent, and its minimum machine size.
 * Agents that are not ticked stop selling the game. All or nothing.
 * Returns an empty string on success, or the error text.
 */
function catalog_admin_save_game(
    mysqli $db,
    string $prefix,
    int $homeCfgId,
    array $details,
    array $settings,
    array $tickedAgentIds,
    array $allAgentIds,
    array $need
): string {
    $db->begin_transaction();
    try {
        catalog_admin_save_details($db, $prefix, $homeCfgId, $details);
        foreach ($allAgentIds as $agentId) {
            if (in_array($agentId, $tickedAgentIds, true)) {
                catalog_admin_publish_offer($db, $prefix, $homeCfgId, $agentId, $settings);
                if ($settings['mode'] === 'tiers') {
                    catalog_admin_save_tiers($db, $prefix, $homeCfgId, $agentId, $settings['tiers']);
                }
            } else {
                catalog_admin_unpublish_offer($db, $prefix, $homeCfgId, $agentId);
            }
        }
        catalog_admin_save_requirements($db, $prefix, $homeCfgId, $need);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return 'Nothing was saved: ' . $e->getMessage();
    }
    return '';
}

/* ---------------------------------------------------------------------------
   Import from the old services list (billing_services)
--------------------------------------------------------------------------- */

/** The old services that were enabled, oldest first. Empty when the old table is gone. */
function catalog_admin_legacy_rows(mysqli $db, string $prefix): array
{
    if (!catalog_admin_table_exists($db, $prefix . 'billing_services')) {
        return array();
    }
    return catalog_admin_rows(
        $db,
        "SELECT service_id, home_cfg_id, service_name, img_url, slot_min_qty, slot_max_qty, price_monthly, remote_server_id
         FROM `{$prefix}billing_services` WHERE enabled = 1 ORDER BY service_id"
    );
}

/** Builds the import plan for the ticked agents. Reads the database, changes nothing. */
function catalog_admin_import_plan(mysqli $db, string $prefix, array $agentIds, array $images, bool $useSavedAgents = false): array
{
    $gameIdSet = array();
    foreach (catalog_admin_rows($db, "SELECT home_cfg_id FROM `{$prefix}config_homes`") as $row) {
        $gameIdSet[(int)$row['home_cfg_id']] = true;
    }
    $existingKeys = array();
    foreach (catalog_admin_rows($db, "SELECT home_cfg_id, remote_server_id FROM `{$prefix}catalog_offer`") as $row) {
        $existingKeys[$row['home_cfg_id'] . ':' . $row['remote_server_id']] = true;
    }
    $detailIdSet = array();
    foreach (catalog_admin_rows($db, "SELECT home_cfg_id FROM `{$prefix}catalog_game`") as $row) {
        $detailIdSet[(int)$row['home_cfg_id']] = true;
    }
    $legacyRows = catalog_admin_legacy_rows($db, $prefix);
    $validAgentIds = array_map('intval', array_column(catalog_admin_agents($db, $prefix), 'remote_server_id'));
    return catalog_plan_legacy_import(
        $legacyRows, $gameIdSet, $agentIds, $existingKeys, $detailIdSet, $images, $useSavedAgents, $validAgentIds
    );
}

/** Writes one planned game: its picture (if any) and its offers. Existing rows are left alone. */
function catalog_admin_import_game(mysqli $db, string $prefix, array $game, int $enabled): void
{
    if ($game['img_url'] !== '') {
        catalog_admin_run(
            $db,
            "INSERT IGNORE INTO `{$prefix}catalog_game` (home_cfg_id, display_name, description, img_url, updated_at)
             VALUES (?, '', '', ?, NOW())",
            'is',
            array($game['home_cfg_id'], $game['img_url'])
        );
    }
    $price = catalog_period_prices($game['price_monthly']);
    foreach ($game['agent_ids'] as $agentId) {
        catalog_admin_run(
            $db,
            "INSERT IGNORE INTO `{$prefix}catalog_offer`
                (home_cfg_id, remote_server_id, enabled, pricing_mode, period_default, currency, slot_min_qty, slot_max_qty,
                 per_slot_price_daily, per_slot_price_monthly, per_slot_price_year, updated_at)
             VALUES (?, ?, ?, 'per_slot', 'monthly', 'USD', ?, ?, ?, ?, ?, NOW())",
            'iiiiiddd',
            array($game['home_cfg_id'], $agentId, $enabled, $game['slot_min'], $game['slot_max'],
                  $price['daily'], $price['monthly'], $price['year'])
        );
    }
}

/**
 * Writes a whole import plan. $publish = true makes the new offers visible on the storefront,
 * false creates them as drafts. All or nothing. Returns an empty string on success, or the error text.
 */
function catalog_admin_apply_import(mysqli $db, string $prefix, array $plan, bool $publish): string
{
    $db->begin_transaction();
    try {
        foreach ($plan['games'] as $game) {
            catalog_admin_import_game($db, $prefix, $game, $publish ? 1 : 0);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return 'Nothing was imported: ' . $e->getMessage();
    }
    return '';
}

/** Tables the Game Catalog page needs that are not in the database yet (a migration has not run). */
function catalog_admin_missing_tables(mysqli $db, string $prefix): array
{
    $needed = array(
        'config_homes', 'remote_servers', 'billing_locations', 'billing_service_requirements',
        'catalog_offer', 'catalog_tier', 'catalog_game',
    );
    $missing = array();
    foreach ($needed as $table) {
        if (!catalog_admin_table_exists($db, $prefix . $table)) {
            $missing[] = $prefix . $table;
        }
    }
    return $missing;
}

/** Placement headroom (the share of an agent that may be used before it is skipped). Defaults 70/70/85. */
function catalog_admin_headroom(mysqli $db, string $prefix): array
{
    $out = array('cpu' => 70, 'ram' => 70, 'disk' => 85);
    if (!catalog_admin_table_exists($db, $prefix . 'billing_config')) {
        return $out;
    }
    $rows = catalog_admin_rows(
        $db,
        "SELECT headroom_cpu_pct, headroom_ram_pct, headroom_disk_pct
         FROM `{$prefix}billing_config` ORDER BY config_id ASC LIMIT 1"
    );
    if (!empty($rows)) {
        $out = array(
            'cpu' => (int)$rows[0]['headroom_cpu_pct'],
            'ram' => (int)$rows[0]['headroom_ram_pct'],
            'disk' => (int)$rows[0]['headroom_disk_pct'],
        );
    }
    return $out;
}

/** Saves the placement headroom on the first billing_config row, creating it when there is none. Returns an error text or ''. */
function catalog_admin_save_headroom(mysqli $db, string $prefix, int $cpu, int $ram, int $disk): string
{
    try {
        $rows = catalog_admin_rows($db, "SELECT config_id FROM `{$prefix}billing_config` ORDER BY config_id ASC LIMIT 1");
        if (!empty($rows)) {
            catalog_admin_run(
                $db,
                "UPDATE `{$prefix}billing_config` SET headroom_cpu_pct = ?, headroom_ram_pct = ?, headroom_disk_pct = ?
                 WHERE config_id = ?",
                'iiii',
                array($cpu, $ram, $disk, (int)$rows[0]['config_id'])
            );
        } else {
            catalog_admin_run(
                $db,
                "INSERT INTO `{$prefix}billing_config` (game_key, headroom_cpu_pct, headroom_ram_pct, headroom_disk_pct)
                 VALUES ('__global__', ?, ?, ?)",
                'iii',
                array($cpu, $ram, $disk)
            );
        }
    } catch (Throwable $e) {
        return 'The headroom was not saved: ' . $e->getMessage();
    }
    return '';
}
