<?php
/**
 * Pure pricing calculator for the game catalog (DB_PREFIXcatalog_offer /
 * DB_PREFIXcatalog_tier). No database access, no side effects — the
 * database-facing code loads an offer row (plus its tiers, for the "tiers"
 * mode) into the arrays this file expects, calls catalog_calculate_price(),
 * and persists the result onto the order via DB_PREFIXcatalog_order_price.
 *
 * $offer keys used: pricing_mode, slot_min_qty, slot_max_qty,
 *   per_slot_price_{daily,monthly,year}, base_slots,
 *   base_price_{daily,monthly,year}, extra_price_per_slot_{daily,monthly,year}.
 * $tiers (only for pricing_mode === 'tiers'): list of
 *   ['tier_key' => ..., 'slot_count' => ..., 'price_daily'/'price_monthly'/'price_year' => ...]
 */

if (!function_exists('catalog_valid_periods')) {
    function catalog_valid_periods(): array
    {
        return ['daily', 'monthly', 'yearly'];
    }
}

if (!function_exists('catalog_period_price_column')) {
    function catalog_period_price_column(string $period, string $prefix): string
    {
        $suffix = ['daily' => 'daily', 'monthly' => 'monthly', 'yearly' => 'year'][$period] ?? 'monthly';
        return $prefix . $suffix;
    }
}

/**
 * Compute the unit price (per period) and total price for one catalog offer.
 *
 * $params:
 *   - 'slots' (int): required for per_slot and base_plus_extra modes.
 *   - 'tier_key' (string): required for tiers mode ('small'|'medium'|'large'|'full').
 *
 * Returns ['error' => string] on failure, or on success:
 *   ['slots' => int, 'unit_price' => float, 'total_price' => float,
 *    'quantity' => int, 'period' => string, 'pricing_mode' => string].
 */
if (!function_exists('catalog_calculate_price')) {
    function catalog_calculate_price(array $offer, string $period, int $quantity, array $params, array $tiers = []): array
    {
        if (!in_array($period, catalog_valid_periods(), true)) {
            return ['error' => 'invalid_period'];
        }
        if ($quantity < 1) {
            return ['error' => 'invalid_quantity'];
        }

        $mode = (string)($offer['pricing_mode'] ?? 'per_slot');
        $slotMin = (int)($offer['slot_min_qty'] ?? 1);
        $slotMax = (int)($offer['slot_max_qty'] ?? 1);

        switch ($mode) {
            case 'per_slot':
                $slots = (int)($params['slots'] ?? 0);
                if ($slots < $slotMin || $slots > $slotMax) {
                    return ['error' => 'slots_out_of_range'];
                }
                $col = catalog_period_price_column($period, 'per_slot_price_');
                $perSlot = (float)($offer[$col] ?? 0);
                $unitPrice = $perSlot * $slots;
                break;

            case 'tiers':
                $tierKey = (string)($params['tier_key'] ?? '');
                if (!in_array($tierKey, ['small', 'medium', 'large', 'full'], true)) {
                    return ['error' => 'invalid_tier'];
                }
                $tier = null;
                foreach ($tiers as $candidate) {
                    if ((string)($candidate['tier_key'] ?? '') === $tierKey) {
                        $tier = $candidate;
                        break;
                    }
                }
                if ($tier === null) {
                    return ['error' => 'tier_not_found'];
                }
                $slots = (int)($tier['slot_count'] ?? 0);
                $col = catalog_period_price_column($period, 'price_');
                $unitPrice = (float)($tier[$col] ?? 0);
                break;

            case 'base_plus_extra':
                $slots = (int)($params['slots'] ?? 0);
                if ($slots < $slotMin || $slots > $slotMax) {
                    return ['error' => 'slots_out_of_range'];
                }
                $baseSlots = (int)($offer['base_slots'] ?? 0);
                $baseCol = catalog_period_price_column($period, 'base_price_');
                $extraCol = catalog_period_price_column($period, 'extra_price_per_slot_');
                $basePrice = (float)($offer[$baseCol] ?? 0);
                $extraPerSlot = (float)($offer[$extraCol] ?? 0);
                $extraSlots = max(0, $slots - $baseSlots);
                $unitPrice = $basePrice + ($extraPerSlot * $extraSlots);
                break;

            default:
                return ['error' => 'invalid_pricing_mode'];
        }

        $totalPrice = round($unitPrice * $quantity, 2);
        $unitPrice = round($unitPrice, 2);

        return [
            'slots' => $slots,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'quantity' => $quantity,
            'period' => $period,
            'pricing_mode' => $mode,
        ];
    }
}

/**
 * Default tier slot counts (25/50/75/100% of max slots), used to seed
 * DB_PREFIXcatalog_tier rows from an offer's slot_max_qty.
 */
if (!function_exists('catalog_default_tier_slots')) {
    function catalog_default_tier_slots(int $maxSlots): array
    {
        $maxSlots = max(1, $maxSlots);
        return [
            'small'  => max(1, (int)round($maxSlots * 0.25)),
            'medium' => max(1, (int)round($maxSlots * 0.50)),
            'large'  => max(1, (int)round($maxSlots * 0.75)),
            'full'   => $maxSlots,
        ];
    }
}
