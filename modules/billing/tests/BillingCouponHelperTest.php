<?php
require_once __DIR__ . '/../includes/coupon_helper.php';

function assertCouponSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$invoices = [
    [
        'invoice_id' => 10,
        'service_id' => 1,
        'home_name' => 'Alpha',
        'description' => 'Arma 3 Linux server',
        'total_due' => 20.00,
    ],
    [
        'invoice_id' => 11,
        'service_id' => 2,
        'home_name' => 'Bravo',
        'description' => 'Minecraft server',
        'total_due' => 10.00,
    ],
];
$serviceIndex = [
    1 => ['service_name' => 'Arma 3', 'game_key' => 'arma3_linux64', 'home_cfg_file' => 'arma3_linux64.xml'],
    2 => ['service_name' => 'Minecraft', 'game_key' => 'minecraft', 'home_cfg_file' => 'minecraft_linux.xml'],
];

$specificCoupon = [
    'coupon_id' => 1,
    'is_active' => 1,
    'discount_percent' => 50,
    'game_filter_type' => 'specific_games',
    'game_filter_list' => json_encode(['arma3']),
    'current_uses' => 0,
    'max_uses' => null,
    'expires' => null,
];

$validation = billing_coupon_validate_for_invoices($specificCoupon, $invoices, $serviceIndex);
assertCouponSame(true, $validation['valid'], 'specific-game coupon validates when one invoice matches');

$discounts = billing_coupon_discount_map($invoices, $specificCoupon, $serviceIndex);
assertCouponSame(1000, $discounts[10], 'specific-game coupon discounts matching invoice');
assertCouponSame(0, $discounts[11], 'specific-game coupon does not discount non-matching invoice');

$expiredCoupon = $specificCoupon;
$expiredCoupon['expires'] = '2026-01-01 00:00:00';
$expired = billing_coupon_validate_for_invoices($expiredCoupon, $invoices, $serviceIndex);
assertCouponSame(false, $expired['valid'], 'expired coupon rejected');
assertCouponSame('expired', $expired['reason'], 'expired reason returned');

$limitedCoupon = $specificCoupon;
$limitedCoupon['max_uses'] = 5;
$limitedCoupon['current_uses'] = 5;
$limited = billing_coupon_validate_for_invoices($limitedCoupon, $invoices, $serviceIndex);
assertCouponSame(false, $limited['valid'], 'usage-limit coupon rejected');
assertCouponSame('usage_limit', $limited['reason'], 'usage-limit reason returned');

$nonMatchingCoupon = $specificCoupon;
$nonMatchingCoupon['game_filter_list'] = json_encode(['rust']);
$nonMatching = billing_coupon_validate_for_invoices($nonMatchingCoupon, $invoices, $serviceIndex);
assertCouponSame(false, $nonMatching['valid'], 'non-matching specific-game coupon rejected');
assertCouponSame('game_filter', $nonMatching['reason'], 'game-filter reason returned');

echo "BillingCouponHelperTest passed\n";

