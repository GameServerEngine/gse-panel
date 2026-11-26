<?php
// One-time extras a customer can tick on the game server order page (Server Setup and similar).
// The options live in billing_order_options. Ticked options are copied to billing_invoice_options
// when the invoice is created, so the invoice keeps its own record if an option changes later.

function order_options_table_ready(mysqli $db, string $prefix): bool
{
    $res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($prefix . 'billing_order_options') . "'");
    return (bool)($res && $res->num_rows > 0);
}

// Enabled options, in the order the admin sorted them.
function order_options_active(mysqli $db, string $prefix): array
{
    if (!order_options_table_ready($db, $prefix)) {
        return array();
    }
    $rows = array();
    $res = $db->query("SELECT option_id, title, description, price FROM `{$prefix}billing_order_options` WHERE enabled = 1 ORDER BY sort_order, option_id");
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = array(
            'option_id' => (int)$r['option_id'],
            'title' => (string)$r['title'],
            'description' => (string)($r['description'] ?? ''),
            'price' => round((float)$r['price'], 2),
        );
    }
    return $rows;
}

// The options the customer ticked, matched against the active list. Prices always come from the database.
function order_options_selected(mysqli $db, string $prefix, $posted): array
{
    $ids = array();
    foreach ((array)$posted as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    if (!$ids) {
        return array();
    }
    $chosen = array();
    foreach (order_options_active($db, $prefix) as $opt) {
        if (isset($ids[$opt['option_id']])) {
            $chosen[] = $opt;
        }
    }
    return $chosen;
}

function order_options_total(array $chosen): float
{
    $total = 0.0;
    foreach ($chosen as $opt) {
        $total += (float)$opt['price'];
    }
    return round($total, 2);
}

function order_options_save_invoice(mysqli $db, string $prefix, int $invoiceId, array $chosen): void
{
    if ($invoiceId < 1 || !$chosen) {
        return;
    }
    $stmt = $db->prepare("INSERT INTO `{$prefix}billing_invoice_options` (invoice_id, option_id, title, price) VALUES (?,?,?,?)");
    if (!$stmt) {
        return;
    }
    foreach ($chosen as $opt) {
        $optId = (int)$opt['option_id'];
        $title = (string)$opt['title'];
        $price = (float)$opt['price'];
        $stmt->bind_param('iisd', $invoiceId, $optId, $title, $price);
        $stmt->execute();
    }
    $stmt->close();
}
