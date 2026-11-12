<?php
/**
 * Plain helpers for the Game Catalog admin page.
 *
 * Nothing in this file touches the database, so tests can load it on its own.
 * The database code is in catalog_admin.php.
 *
 * Pricing is set per game. Saving a game writes the same pricing to every
 * agent the game is available on (one catalog_offer row per game and agent).
 */

require_once __DIR__ . '/catalog_pricing.php';

if (!function_exists('catalog_clean_text')) {
    /** Plain text for a name or description: no tags, trimmed, cut to $maxLength characters. */
    function catalog_clean_text($value, int $maxLength): string
    {
        $text = trim(strip_tags((string)$value));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }
        return substr($text, 0, $maxLength);
    }
}

if (!function_exists('catalog_period_prices')) {
    /** Daily and yearly rates come from the monthly rate: month / 30 and month x 12. */
    function catalog_period_prices(float $monthly): array
    {
        return array(
            'daily' => round($monthly / 30, 4),
            'monthly' => $monthly,
            'year' => round($monthly * 12, 4),
        );
    }
}

if (!function_exists('catalog_clamp_slots')) {
    /** Returns array(min, max) where min is at least 1 and max is at least min. */
    function catalog_clamp_slots($min, $max): array
    {
        $min = max(1, (int)$min);
        $max = max($min, (int)$max);
        return array($min, $max);
    }
}

if (!function_exists('catalog_list_images')) {
    /** Sorted image file names found in $dir (the folder the storefront serves game pictures from). */
    function catalog_list_images(string $dir): array
    {
        $files = array();
        if (!is_dir($dir)) {
            return $files;
        }
        foreach (scandir($dir) as $name) {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
                $files[] = $name;
            }
        }
        natcasesort($files);
        return array_values($files);
    }
}

if (!function_exists('catalog_pick_image')) {
    /** Returns $wanted when it is one of the $available file names, otherwise an empty string. */
    function catalog_pick_image($wanted, array $available): string
    {
        $wanted = (string)$wanted;
        return in_array($wanted, $available, true) ? $wanted : '';
    }
}

if (!function_exists('catalog_read_settings')) {
    /**
     * Reads the pricing part of the game form into one clean array.
     * Only monthly prices are typed in; daily and yearly are worked out when saving.
     */
    function catalog_read_settings(array $post): array
    {
        $mode = (string)($post['pricing_mode'] ?? 'per_slot');
        if (!in_array($mode, array('per_slot', 'tiers', 'base_plus_extra'), true)) {
            $mode = 'per_slot';
        }
        $period = (string)($post['period_default'] ?? 'monthly');
        if (!in_array($period, array('daily', 'monthly', 'yearly'), true)) {
            $period = 'monthly';
        }
        list($slotMin, $slotMax) = catalog_clamp_slots($post['slot_min_qty'] ?? 1, $post['slot_max_qty'] ?? 1);

        return array(
            'mode' => $mode,
            'period' => $period,
            'slot_min' => $slotMin,
            'slot_max' => $slotMax,
            'per_slot_monthly' => max(0.0, (float)($post['per_slot_price_monthly'] ?? 0)),
            'base_slots' => max(0, (int)($post['base_slots'] ?? 0)),
            'base_monthly' => max(0.0, (float)($post['base_price_monthly'] ?? 0)),
            'extra_monthly' => max(0.0, (float)($post['extra_price_per_slot_monthly'] ?? 0)),
            'tiers' => catalog_read_tiers((array)($post['tier'] ?? array()), $slotMax),
        );
    }
}

if (!function_exists('catalog_read_tiers')) {
    /** The four tiers (small, medium, large, full) from the form, with default slot counts filled in. */
    function catalog_read_tiers(array $input, int $slotMax): array
    {
        $defaults = catalog_default_tier_slots($slotMax);
        $tiers = array();
        foreach (array('small', 'medium', 'large', 'full') as $key) {
            $row = (array)($input[$key] ?? array());
            $tiers[$key] = array(
                'slot_count' => max(1, (int)($row['slot_count'] ?? $defaults[$key])),
                'monthly' => max(0.0, (float)($row['price_monthly'] ?? 0)),
            );
        }
        return $tiers;
    }
}

if (!function_exists('catalog_legacy_skip_reason')) {
    /**
     * Why an old billing_services row cannot be imported, or an empty string when it can.
     * 'no_config'  the game is no longer in the game configs.
     * 'no_price'   the old row has no monthly price. It is skipped so nothing becomes free by accident.
     */
    function catalog_legacy_skip_reason(array $row, array $gameIdSet): string
    {
        if (!isset($gameIdSet[(int)$row['home_cfg_id']])) {
            return 'no_config';
        }
        if ((float)$row['price_monthly'] <= 0) {
            return 'no_price';
        }
        return '';
    }
}

if (!function_exists('catalog_new_agent_ids')) {
    /** The ticked agents that do not already have an offer for this game. */
    function catalog_new_agent_ids(int $homeCfgId, array $agentIds, array $existingKeys): array
    {
        $new = array();
        foreach ($agentIds as $agentId) {
            if (!isset($existingKeys[$homeCfgId . ':' . $agentId])) {
                $new[] = (int)$agentId;
            }
        }
        return $new;
    }
}

if (!function_exists('catalog_saved_agent_ids')) {
    /** The agent ids in an old comma-separated remote_server_id list that are real agents. */
    function catalog_saved_agent_ids(string $csv, array $validAgentIds): array
    {
        $valid = array_flip(array_map('intval', $validAgentIds));
        $ids = array();
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && isset($valid[(int)$part])) {
                $ids[(int)$part] = (int)$part;
            }
        }
        return array_values($ids);
    }
}

if (!function_exists('catalog_plan_legacy_import')) {
    /**
     * Works out what importing the old billing_services rows would do. No database access.
     *
     * $legacyRows    billing_services rows that were enabled, oldest first
     * $gameIdSet     map home_cfg_id => true for every game config
     * $agentIds      the agents the admin ticked
     * $existingKeys  map "homeCfgId:agentId" => true for offers that already exist
     * $detailIdSet   map home_cfg_id => true for games that already have a catalog_game row
     * $images        image file names the storefront can show
     * $useSavedAgents  also use the agents saved on each old row (its remote_server_id list)
     * $validAgentIds   the agents that exist, so a saved id for a removed agent is ignored
     *
     * Existing offers and existing game details are never changed.
     * Returns array('games' => list of planned games, 'counts' => numbers for the preview).
     */
    function catalog_plan_legacy_import(
        array $legacyRows,
        array $gameIdSet,
        array $agentIds,
        array $existingKeys,
        array $detailIdSet,
        array $images,
        bool $useSavedAgents = false,
        array $validAgentIds = array()
    ): array {
        $counts = array('games' => 0, 'offers' => 0, 'no_config' => 0, 'no_price' => 0, 'already_set_up' => 0);
        $games = array();
        $seen = array();

        foreach ($legacyRows as $row) {
            $homeCfgId = (int)$row['home_cfg_id'];
            if (isset($seen[$homeCfgId])) {
                continue;
            }
            $seen[$homeCfgId] = true;

            $reason = catalog_legacy_skip_reason($row, $gameIdSet);
            if ($reason !== '') {
                $counts[$reason]++;
                continue;
            }

            $wantedAgents = $agentIds;
            if ($useSavedAgents) {
                $saved = catalog_saved_agent_ids((string)($row['remote_server_id'] ?? ''), $validAgentIds);
                $wantedAgents = array_values(array_unique(array_merge($wantedAgents, $saved)));
                sort($wantedAgents);
            }
            $newAgents = catalog_new_agent_ids($homeCfgId, $wantedAgents, $existingKeys);
            $counts['already_set_up'] += count($wantedAgents) - count($newAgents);
            if (empty($newAgents)) {
                continue;
            }

            list($slotMin, $slotMax) = catalog_clamp_slots($row['slot_min_qty'], $row['slot_max_qty']);
            $image = isset($detailIdSet[$homeCfgId]) ? '' : catalog_pick_image($row['img_url'], $images);
            $games[] = array(
                'home_cfg_id' => $homeCfgId,
                'name' => (string)$row['service_name'],
                'img_url' => $image,
                'slot_min' => $slotMin,
                'slot_max' => $slotMax,
                'price_monthly' => (float)$row['price_monthly'],
                'agent_ids' => $newAgents,
            );
            $counts['games']++;
            $counts['offers'] += count($newAgents);
        }

        return array('games' => $games, 'counts' => $counts);
    }
}

if (!function_exists('catalog_image_key')) {
    /** A picture or config name reduced to lowercase letters and digits, for matching. */
    function catalog_image_key(string $name): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $name));
    }
}

if (!function_exists('catalog_guess_image')) {
    /**
     * The picture whose file name matches a game config's file name (7daystodie.xml and
     * 7daystodie.jpg). Returns '' when no picture matches.
     */
    function catalog_guess_image(string $configFile, array $available): string
    {
        $key = catalog_image_key(pathinfo($configFile, PATHINFO_FILENAME));
        if ($key === '') {
            return '';
        }
        foreach ($available as $name) {
            if (catalog_image_key(pathinfo((string)$name, PATHINFO_FILENAME)) === $key) {
                return (string)$name;
            }
        }
        return '';
    }
}

if (!function_exists('catalog_group_key')) {
    /** Games group by their config file. A row without a file is its own group. */
    function catalog_group_key(array $row): string
    {
        $file = strtolower(trim((string)($row['home_cfg_file'] ?? '')));
        return $file !== '' ? 'file:' . $file : 'id:' . (int)$row['home_cfg_id'];
    }
}

if (!function_exists('catalog_group_admin_games')) {
    /**
     * One row per game config file for the Game Catalog list. A config file can hold several
     * platform variants, each with its own home_cfg_id. The row shown is the variant with the most
     * published agents (then the lowest home_cfg_id), so its edit screen opens. Agent counts are
     * added up and the lowest price is kept.
     */
    function catalog_group_admin_games(array $rows): array
    {
        $groups = array();
        foreach ($rows as $row) {
            $key = catalog_group_key($row);
            $id = (int)$row['home_cfg_id'];
            $agents = (int)($row['published_agents'] ?? 0);
            $price = isset($row['from_price']) ? (float)$row['from_price'] : null;
            if (!isset($groups[$key])) {
                $groups[$key] = array('rep' => $row, 'ids' => array(), 'agents' => 0, 'price' => null);
            }
            $group = $groups[$key];
            $group['ids'][] = $id;
            $group['agents'] += $agents;
            if ($price !== null && ($group['price'] === null || $price < $group['price'])) {
                $group['price'] = $price;
            }
            $repAgents = (int)($group['rep']['published_agents'] ?? 0);
            if ($agents > $repAgents || ($agents === $repAgents && $id < (int)$group['rep']['home_cfg_id'])) {
                $group['rep'] = $row;
            }
            $groups[$key] = $group;
        }
        $out = array();
        foreach ($groups as $group) {
            $row = $group['rep'];
            $row['variant_ids'] = $group['ids'];
            $row['published_agents'] = $group['agents'];
            $row['from_price'] = $group['price'];
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('catalog_group_storefront_games')) {
    /**
     * One storefront card per game config file. The card links to the variant with the lowest
     * starting price (then the lowest home_cfg_id). Price and slot range cover every variant.
     * A card without a picture of its own uses the picture named after its config file.
     */
    function catalog_group_storefront_games(array $rows, array $images): array
    {
        $groups = array();
        foreach ($rows as $row) {
            $key = catalog_group_key($row);
            $id = (int)$row['home_cfg_id'];
            $price = isset($row['starting_price_monthly']) ? (float)$row['starting_price_monthly'] : null;
            if (!isset($groups[$key])) {
                $groups[$key] = array('rep' => $row, 'rep_price' => $price, 'ids' => array(), 'price' => null, 'slot_min' => null, 'slot_max' => null);
            }
            $group = $groups[$key];
            $group['ids'][] = $id;
            if ($price !== null && ($group['price'] === null || $price < $group['price'])) {
                $group['price'] = $price;
            }
            if (isset($row['slot_min_qty'])) {
                $min = (int)$row['slot_min_qty'];
                $group['slot_min'] = $group['slot_min'] === null ? $min : min($group['slot_min'], $min);
            }
            if (isset($row['slot_max_qty'])) {
                $max = (int)$row['slot_max_qty'];
                $group['slot_max'] = $group['slot_max'] === null ? $max : max($group['slot_max'], $max);
            }
            $repId = (int)$group['rep']['home_cfg_id'];
            $better = ($price !== null && ($group['rep_price'] === null || $price < $group['rep_price']))
                || ($price === $group['rep_price'] && $id < $repId);
            if ($better) {
                $group['rep'] = $row;
                $group['rep_price'] = $price;
            }
            $groups[$key] = $group;
        }
        $out = array();
        foreach ($groups as $group) {
            $row = $group['rep'];
            $row['variant_ids'] = $group['ids'];
            $row['starting_price_monthly'] = $group['price'];
            $row['slot_min_qty'] = $group['slot_min'];
            $row['slot_max_qty'] = $group['slot_max'];
            if (trim((string)($row['img_url'] ?? '')) === '') {
                $row['img_url'] = catalog_guess_image((string)($row['home_cfg_file'] ?? ''), $images);
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('catalog_default_settings')) {
    /** Pricing settings shown for a game that has no offers yet. */
    function catalog_default_settings(): array
    {
        return catalog_read_settings(array('slot_min_qty' => 1, 'slot_max_qty' => 32));
    }
}

if (!function_exists('catalog_settings_from_offers')) {
    /**
     * The game-wide pricing settings for the form, taken from the game's existing offers.
     * An enabled offer is preferred over a disabled one. With no offers, the defaults are used.
     *
     * $offers         catalog_offer rows for one game
     * $tiersByOffer   map offer_id => tier_key => catalog_tier row
     */
    function catalog_settings_from_offers(array $offers, array $tiersByOffer): array
    {
        $offer = null;
        foreach ($offers as $candidate) {
            if ($offer === null || ((int)$candidate['enabled'] === 1 && (int)$offer['enabled'] !== 1)) {
                $offer = $candidate;
            }
        }
        if ($offer === null) {
            return catalog_default_settings();
        }

        $tierInput = array();
        foreach ((array)($tiersByOffer[(int)$offer['offer_id']] ?? array()) as $key => $tier) {
            $tierInput[$key] = array('slot_count' => $tier['slot_count'], 'price_monthly' => $tier['price_monthly']);
        }
        return catalog_read_settings(array(
            'pricing_mode' => $offer['pricing_mode'],
            'period_default' => $offer['period_default'],
            'slot_min_qty' => $offer['slot_min_qty'],
            'slot_max_qty' => $offer['slot_max_qty'],
            'per_slot_price_monthly' => $offer['per_slot_price_monthly'],
            'base_slots' => $offer['base_slots'],
            'base_price_monthly' => $offer['base_price_monthly'],
            'extra_price_per_slot_monthly' => $offer['extra_price_per_slot_monthly'],
            'tier' => $tierInput,
        ));
    }
}

if (!function_exists('catalog_settings_are_free')) {
    /** True when the settings would let customers order for nothing (any price that is 0). */
    function catalog_settings_are_free(array $settings): bool
    {
        if ($settings['mode'] === 'tiers') {
            foreach ($settings['tiers'] as $tier) {
                if ($tier['monthly'] <= 0) {
                    return true;
                }
            }
            return false;
        }
        if ($settings['mode'] === 'base_plus_extra') {
            return $settings['base_monthly'] <= 0;
        }
        return $settings['per_slot_monthly'] <= 0;
    }
}

if (!function_exists('catalog_read_headroom')) {
    /**
     * Reads the placement headroom form: cpu, ram and disk, each a whole number from 1 to 100.
     * Returns array('cpu' => int, 'ram' => int, 'disk' => int), or null when any value is not valid.
     */
    function catalog_read_headroom(array $post): ?array
    {
        $out = array();
        foreach (array('cpu', 'ram', 'disk') as $key) {
            $raw = isset($post['headroom_' . $key . '_pct']) ? trim((string)$post['headroom_' . $key . '_pct']) : '';
            if ($raw === '' || !ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 100) {
                return null;
            }
            $out[$key] = (int)$raw;
        }
        return $out;
    }
}
