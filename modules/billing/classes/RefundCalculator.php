<?php
require_once __DIR__ . '/BillingLifecycleService.php';
final class RefundCalculator
{
    public static function calculate(float $paid, string $start, string $end, $now, int $fullDays = 30): float
    {
        $paid = max(0.0, $paid); $s=strtotime($start); $e=strtotime($end); $n=is_numeric($now)?(int)$now:strtotime((string)$now);
        if (!$s || !$e || !$n || $e <= $s) return 0.00;
        $total = max(1, (int)round(($e-$s)/86400));
        $remaining = max(0, (int)ceil(($e-$n)/86400));
        $amount = ($n >= $s && $n <= $s + max(0,$fullDays)*86400) ? $paid : $paid * $remaining / $total;
        return min($paid, max(0.0, round($amount, 2)));
    }
}
