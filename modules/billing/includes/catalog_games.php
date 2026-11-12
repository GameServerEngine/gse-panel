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
        array $images
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

            $newAgents = catalog_new_agent_ids($homeCfgId, $agentIds, $existingKeys);
            $counts['already_set_up'] += count($agentIds) - count($newAgents);
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
