<?php
// One-time extras a customer can tick on the game server order page (Server Setup and similar).
// The options live in billing_order_options. Ticked options are copied to billing_invoice_options
// when the invoice is created, so the invoice keeps its own record if an option changes later.

// The SQL that creates the order option tables and the first option. The Billing database step
// (module.php, db_version 18) and the Order Options page both use this one list, so a panel that
// never ran the step still gets the tables. Every statement is safe to run again.
function order_options_install_sql(string $prefix): array
{
    $options = '`' . $prefix . 'billing_order_options`';
    return array(
        "CREATE TABLE IF NOT EXISTS {$options} (
            `option_id` INT(11) NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(120) NOT NULL,
            `description` TEXT NULL,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`option_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `{$prefix}billing_invoice_options` (
            `invoice_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `title` VARCHAR(120) NOT NULL,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`invoice_id`, `option_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO {$options} (title, description, price, enabled, sort_order)
            SELECT 'Server Setup', 'Server will be installed immediately but allow up to 24 hours for the configuration to be performed.', 5.00, 1, 10
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM {$options} WHERE title = 'Server Setup')",
        // The option replaces the old $25 Server Setup service.
        "UPDATE `{$prefix}billing_service_types` SET enabled = 0 WHERE slug = 'server-setup'",
    );
}

// Creates the order option tables when they are missing. Returns '' on success, otherwise the error text.
function order_options_install(mysqli $db, string $prefix): string
{
    $firstError = '';
    foreach (order_options_install_sql($prefix) as $sql) {
        try {
            $db->query($sql);
        } catch (Throwable $e) {
            if ($firstError === '') {
                $firstError = $e->getMessage();
            }
        }
    }
    return order_options_table_ready($db, $prefix) ? '' : ($firstError !== '' ? $firstError : 'the table was not created');
}

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
