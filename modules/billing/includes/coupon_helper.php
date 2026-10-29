<?php

if (!function_exists('billing_money_to_cents_value')) {
    function billing_money_to_cents_value($amount): int
    {
        return (int)round(((float)$amount) * 100);
    }
}

if (!function_exists('billing_cents_to_money_value')) {
    function billing_cents_to_money_value(int $cents): float
    {
        return $cents / 100;
    }
}

if (!function_exists('billing_coupon_clear_session')) {
    function billing_coupon_clear_session(): void
    {
        unset($_SESSION['cart_coupon_code'], $_SESSION['cart_coupon_id']);
    }
}

if (!function_exists('billing_coupon_line_amount_cents')) {
    function billing_coupon_line_amount_cents(array $invoice): int
    {
        return max(0, billing_money_to_cents_value($invoice['total_due'] ?? $invoice['amount'] ?? 0));
    }
}

if (!function_exists('billing_coupon_build_service_index')) {
    function billing_coupon_build_service_index(mysqli $db, string $tablePrefix, array $invoices): array
    {
        $serviceIds = [];
        foreach ($invoices as $invoice) {
            $serviceId = (int)($invoice['service_id'] ?? 0);
            if ($serviceId > 0) {
                $serviceIds[$serviceId] = true;
            }
        }
        if (empty($serviceIds)) {
            return [];
        }

        $ids = array_keys($serviceIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT bs.service_id, bs.service_name, ch.game_key, ch.home_cfg_file
                FROM `{$tablePrefix}billing_services` bs
                LEFT JOIN `{$tablePrefix}config_homes` ch ON ch.home_cfg_id = bs.home_cfg_id
                WHERE bs.service_id IN ({$placeholders})";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $index = [];
        while ($row = $result->fetch_assoc()) {
            $index[(int)$row['service_id']] = $row;
        }
        $stmt->close();
        return $index;
    }
}

if (!function_exists('billing_coupon_invoice_tokens')) {
    function billing_coupon_invoice_tokens(array $invoice, array $serviceIndex = []): array
    {
        $serviceId = (int)($invoice['service_id'] ?? 0);
        $service = $serviceIndex[$serviceId] ?? [];
        $values = [
            $invoice['game_key'] ?? '',
            $invoice['game_name'] ?? '',
            $invoice['home_name'] ?? '',
            $invoice['description'] ?? '',
            $service['game_key'] ?? '',
            $service['home_cfg_file'] ?? '',
            $service['service_name'] ?? '',
        ];

        $tokens = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string)$value));
            if ($value !== '') {
                $tokens[$value] = true;
            }
        }
        return array_keys($tokens);
    }
}

if (!function_exists('billing_coupon_match_token')) {
    function billing_coupon_match_token(string $allowedGame, string $token): bool
    {
        if ($allowedGame === '' || $token === '') {
            return false;
        }
        if ($token === $allowedGame || strpos($token, $allowedGame) !== false || strpos($allowedGame, $token) !== false) {
            return true;
        }

        $allowedCompact = preg_replace('/[^a-z0-9]+/', '', $allowedGame);
        $tokenCompact = preg_replace('/[^a-z0-9]+/', '', $token);
        return $allowedCompact !== ''
            && $tokenCompact !== ''
            && ($tokenCompact === $allowedCompact
                || strpos($tokenCompact, $allowedCompact) !== false
                || strpos($allowedCompact, $tokenCompact) !== false);
    }
}

if (!function_exists('billing_coupon_applies_to_invoice')) {
    function billing_coupon_applies_to_invoice(?array $coupon, array $invoice, array $serviceIndex = []): bool
    {
        if (!$coupon) {
            return false;
        }
        if (($coupon['game_filter_type'] ?? 'all_games') !== 'specific_games') {
            return true;
        }

        $allowed = json_decode((string)($coupon['game_filter_list'] ?? ''), true);
        if (!is_array($allowed) || empty($allowed)) {
            return false;
        }

        $tokens = billing_coupon_invoice_tokens($invoice, $serviceIndex);
        foreach ($allowed as $allowedGame) {
            $allowedGame = strtolower(trim((string)$allowedGame));
            if ($allowedGame === '') {
                continue;
            }
            foreach ($tokens as $token) {
                if (billing_coupon_match_token($allowedGame, $token)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('billing_coupon_validate_for_invoices')) {
    function billing_coupon_validate_for_invoices(?array $coupon, array $invoices, array $serviceIndex = []): array
    {
        if (!$coupon) {
            return ['valid' => false, 'reason' => 'coupon_not_found'];
        }
        if (empty($invoices)) {
            return ['valid' => false, 'reason' => 'empty_cart'];
        }
        if (empty($coupon['is_active'])) {
            return ['valid' => false, 'reason' => 'inactive'];
        }
        if (!empty($coupon['expires'])) {
            $expires = strtotime((string)$coupon['expires']);
            if ($expires !== false && $expires < time()) {
                return ['valid' => false, 'reason' => 'expired'];
            }
        }
        if (!empty($coupon['max_uses']) && (int)$coupon['current_uses'] >= (int)$coupon['max_uses']) {
            return ['valid' => false, 'reason' => 'usage_limit'];
        }
        $discountPercent = (float)($coupon['discount_percent'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            return ['valid' => false, 'reason' => 'invalid_discount'];
        }

        $eligible = [];
        foreach ($invoices as $invoice) {
            $invoiceId = (int)($invoice['invoice_id'] ?? 0);
            if ($invoiceId > 0 && billing_coupon_applies_to_invoice($coupon, $invoice, $serviceIndex)) {
                $eligible[] = $invoiceId;
            }
        }
        if (empty($eligible)) {
            return ['valid' => false, 'reason' => 'game_filter'];
        }

        return ['valid' => true, 'eligible_invoice_ids' => $eligible];
    }
}

if (!function_exists('billing_coupon_discount_map')) {
    function billing_coupon_discount_map(array $invoices, ?array $coupon, array $serviceIndex = []): array
    {
        $discounts = [];
        foreach ($invoices as $invoice) {
            $invoiceId = (int)($invoice['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $discounts[$invoiceId] = 0;
            }
        }

        if (!$coupon) {
            return $discounts;
        }

        $discountPercent = max(0.0, min(100.0, (float)($coupon['discount_percent'] ?? 0)));
        if ($discountPercent <= 0.0) {
            return $discounts;
        }

        foreach ($invoices as $invoice) {
            $invoiceId = (int)($invoice['invoice_id'] ?? 0);
            if ($invoiceId <= 0 || !billing_coupon_applies_to_invoice($coupon, $invoice, $serviceIndex)) {
                continue;
            }
            $lineCents = billing_coupon_line_amount_cents($invoice);
            $discounts[$invoiceId] = min($lineCents, (int)round($lineCents * ($discountPercent / 100.0)));
        }

        return $discounts;
    }
}
