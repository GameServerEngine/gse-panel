<?php
require_once __DIR__ . '/../includes/services_catalog.php';

function assertServiceSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

// --- slugs ---
assertServiceSame('custom-development', billing_service_slug('Custom Development'), 'slug from a name');
assertServiceSame('server-setup-2', billing_service_slug('  Server Setup #2!! '), 'slug trims and collapses');

// --- stages ---
$one = array(array('label' => 'Payment', 'percent' => 100));
assertServiceSame($one, billing_service_parse_stages(''), 'empty stages means one payment');
assertServiceSame($one, billing_service_parse_stages('not json'), 'bad json means one payment');
assertServiceSame($one, billing_service_parse_stages(json_encode(array(array('label' => 'A', 'percent' => 60)))), 'stages that do not add to 100 fall back');
$two = array(array('label' => 'Deposit', 'percent' => 50), array('label' => 'Delivery', 'percent' => 50));
assertServiceSame($two, billing_service_parse_stages(json_encode($two)), 'two good stages are kept');

list($stages, $err) = billing_service_stages_from_text("Deposit | 50\nDelivery | 50%\n");
assertServiceSame('', $err, 'admin stage text is accepted');
assertServiceSame($two, $stages, 'admin stage text is parsed');
list(, $err) = billing_service_stages_from_text("Deposit | 40\nDelivery | 40");
assertServiceSame(true, $err !== '', 'stages not adding to 100 are refused');
list(, $err) = billing_service_stages_from_text('Deposit');
assertServiceSame(true, $err !== '', 'a stage without a percent is refused');
list($stages, $err) = billing_service_stages_from_text('');
assertServiceSame($one, $stages, 'no stage text means one payment');
assertServiceSame("Deposit | 50\nDelivery | 50", billing_service_stages_to_text($two), 'stages round-trip to text');

// --- stage amounts: whole cents, always adding up to the total ---
assertServiceSame(array(100.0), billing_service_stage_amounts(100.0, $one), 'one stage is the whole price');
assertServiceSame(array(50.0, 50.0), billing_service_stage_amounts(100.0, $two), 'a 50/50 split');
$thirds = array(array('label' => 'A', 'percent' => 33), array('label' => 'B', 'percent' => 33), array('label' => 'C', 'percent' => 34));
$amounts = billing_service_stage_amounts(100.01, $thirds);
assertServiceSame(10001, (int)round(array_sum($amounts) * 100), 'the stages add up to the total in cents');

// --- order form questions ---
$fields = billing_service_fields_from_text("Game | text | required\nNotes | textarea | optional\nPlatform | select | required | PC; Console\nBroken | select | optional");
assertServiceSame(4, count($fields), 'four questions are parsed');
assertServiceSame('f1', $fields[0]['key'], 'keys are numbered');
assertServiceSame(true, $fields[0]['required'], 'required is read');
assertServiceSame(array('PC', 'Console'), $fields[2]['options'], 'select options are read');
assertServiceSame('text', $fields[3]['type'], 'a select with no options becomes text');
assertServiceSame(3, count(explode("\n", billing_service_fields_to_text(array_slice($fields, 0, 3)))), 'questions round-trip to text');

list($details, $errors) = billing_service_collect_details($fields, array('field_f1' => ' Rust ', 'field_f3' => 'PC'));
assertServiceSame(array('Game' => 'Rust', 'Platform' => 'PC'), $details, 'answers are collected and trimmed');
assertServiceSame(array(), $errors, 'no errors for good answers');
list(, $errors) = billing_service_collect_details($fields, array('field_f3' => 'Switch'));
assertServiceSame(2, count($errors), 'a missing required answer and a bad option are both reported');

// --- ticket text ---
assertServiceSame('[Server Setup] Rust modded', billing_service_subject('Server Setup', 'Rust modded'), 'subject carries the service name');
assertServiceSame(64, strlen(billing_service_subject('Custom Development', str_repeat('x', 80))), 'subject is cut to 64');
$text = billing_service_ticket_message('Server Setup', array('Game' => 'Rust'), 'Please hurry', 7);
assertServiceSame(true, strpos($text, 'Service request #7: Server Setup') === 0 && strpos($text, 'Game: Rust') !== false && strpos($text, 'Please hurry') !== false, 'ticket message lists the answers');
assertServiceSame('Waiting for our quote', billing_service_status_label('awaiting_quote'), 'status label');
assertServiceSame('$12.50', billing_service_money(12.5), 'money format');

echo "ServicesCatalogTest OK\n";
