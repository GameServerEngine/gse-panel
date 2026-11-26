<?php
require_once __DIR__ . '/../includes/billing_autopay.php';

function schedExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Expected: ' . $message);
    }
}

// due on Dec 10; charge from 3 days before (Dec 7) through Dec 9; never on/after Dec 10
$due = '2026-12-10 15:00:00';
$on = array('enabled' => true, 'paid' => false, 'due' => $due, 'attempted_today' => false);
$at = function ($day) { return strtotime($day . ' 03:00:00'); };
schedExpect(billing_autopay_should_charge($on, $at('2026-12-06'), 3)[0] === false, 'too early 4 days before');
schedExpect(billing_autopay_should_charge($on, $at('2026-12-07'), 3)[0] === true, 'first try 3 days before');
schedExpect(billing_autopay_should_charge($on, $at('2026-12-09'), 3)[0] === true, 'still trying the day before');
schedExpect(billing_autopay_should_charge($on, $at('2026-12-10'), 3)[0] === false, 'never on the due date');
schedExpect(billing_autopay_should_charge($on, $at('2026-12-11'), 3)[0] === false, 'never after the due date');
schedExpect(billing_autopay_should_charge(array('attempted_today' => true) + $on, $at('2026-12-08'), 3)[0] === false, 'once a day');
schedExpect(billing_autopay_should_charge(array('enabled' => false) + $on, $at('2026-12-08'), 3)[0] === false, 'not when auto-renew is off');
schedExpect(billing_autopay_should_charge(array('paid' => true) + $on, $at('2026-12-08'), 3)[0] === false, 'not when already paid');
schedExpect(billing_autopay_should_charge(array('due' => 'nonsense') + $on, $at('2026-12-08'), 3)[0] === false, 'not without a due date');
schedExpect(billing_autopay_should_charge($on, $at('2026-12-03'), 7)[0] === true, 'a 7-day setting starts on Dec 3');

schedExpect(billing_autopay_charge_date($due, 3) === '2026-12-07', 'charge date is 3 days before');
schedExpect(billing_autopay_charge_date('bad', 3) === '', 'no date for a bad due date');

$c = billing_schedule_clamp(array('invoice_days' => 0, 'autopay_days' => 9, 'delete_days' => 999));
schedExpect($c['invoice_days'] === 1 && $c['autopay_days'] === 1 && $c['delete_days'] === 365, 'settings are kept in range; charging never starts before the invoice exists');
$c = billing_schedule_clamp(array('invoice_days' => 7, 'autopay_days' => 3, 'delete_days' => 7));
schedExpect($c['invoice_days'] === 7 && $c['autopay_days'] === 3 && $c['delete_days'] === 7, 'defaults pass unchanged');

$notice = billing_autopay_notice(array('enabled' => 1, 'gateway' => 'stripe'), $due, 3);
schedExpect(strpos($notice, 'December 7, 2026') !== false && strpos($notice, 'saved card (Stripe)') !== false && strpos($notice, 'turn auto-renew off') !== false, 'the invoice note names the method, the date and how to turn it off');
schedExpect(billing_autopay_notice(array('enabled' => 0, 'gateway' => 'stripe'), $due, 3) === '', 'no note when auto-renew is off');
schedExpect(billing_autopay_notice(null, $due, 3) === '', 'no note without auto-renew');

echo 'BillingScheduleTest passed' . PHP_EOL;
