<?php
require_once __DIR__.'/../classes/RefundCalculator.php';
function refund_assert($ok,$msg){if(!$ok)throw new Exception($msg);}
refund_assert(RefundCalculator::calculate(100,'2026-01-01','2026-02-01','2026-01-15')===100.0,'full refund');
refund_assert(RefundCalculator::calculate(100,'2026-01-01','2026-02-01','2026-01-20', 0)===38.71,'prorated refund');
refund_assert(RefundCalculator::calculate(100,'2026-01-01','2026-02-01','2026-03-01')===0.0,'bounds');
echo "RefundCalculatorTest passed\n";
