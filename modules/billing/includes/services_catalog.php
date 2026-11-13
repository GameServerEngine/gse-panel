<?php
/*
 * Services the storefront sells besides game servers: server setup, custom development and so on.
 *
 * A service type is a row the admin edits (Administration > Billing > Services): a name, a short text,
 * a price (fixed, or a quote the admin sets after reading the request), the stages the price is paid in
 * (for example 50% to start and 50% on delivery) and the questions the customer answers when ordering.
 *
 * An order is a service request. It opens a support ticket (so it is tracked like any other ticket, with the
 * service name in the subject) and an invoice for the first stage. Paying the invoice moves the request on;
 * the admin bills the next stage when the work reaches it.
 *
 * The functions at the top need no database and are covered by tests/ServicesCatalogTest.php.
 */

if (!defined('BILLING_SERVICE_SUPPORT_SLUG')) {
    define('BILLING_SERVICE_SUPPORT_SLUG', 'support');
}

if (!function_exists('billing_service_slug')) {
    /** Lower case letters, digits and dashes. */
    function billing_service_slug(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        return substr($slug, 0, 40);
    }
}

if (!function_exists('billing_service_parse_stages')) {
    /** Stages from JSON: [{"label":"Deposit","percent":50},...]. One stage of 100% when empty or not valid. */
    function billing_service_parse_stages($json): array
    {
        $list = is_array($json) ? $json : json_decode((string)$json, true);
        $stages = array();
        if (is_array($list)) {
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = trim((string)($row['label'] ?? ''));
                $percent = (int)($row['percent'] ?? 0);
                if ($label === '' || $percent < 1 || $percent > 100) {
                    continue;
                }
                $stages[] = array('label' => substr($label, 0, 60), 'percent' => $percent);
            }
        }
        $sum = 0;
        foreach ($stages as $stage) {
            $sum += $stage['percent'];
        }
        if (!$stages || $sum !== 100 || count($stages) > 6) {
            return array(array('label' => 'Payment', 'percent' => 100));
        }
        return $stages;
    }
}

if (!function_exists('billing_service_stages_from_text')) {
    /** Admin text, one stage per line: "Deposit | 50". Returns array(stages, error). */
    function billing_service_stages_from_text(string $text): array
    {
        $stages = array();
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $percent = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
            if ($parts[0] === '' || $percent < 1 || $percent > 100) {
                return array(array(), 'Each stage needs a name and a percent from 1 to 100, like "Deposit | 50".');
            }
            $stages[] = array('label' => substr($parts[0], 0, 60), 'percent' => $percent);
        }
        if (!$stages) {
            return array(array(array('label' => 'Payment', 'percent' => 100)), '');
        }
        $sum = 0;
        foreach ($stages as $stage) {
            $sum += $stage['percent'];
        }
        if ($sum !== 100) {
            return array(array(), 'The stage percents must add up to 100 (they add up to ' . $sum . ').');
        }
        if (count($stages) > 6) {
            return array(array(), 'Use at most 6 stages.');
        }
        return array($stages, '');
    }
}

if (!function_exists('billing_service_stages_to_text')) {
    function billing_service_stages_to_text(array $stages): string
    {
        $lines = array();
        foreach ($stages as $stage) {
            $lines[] = $stage['label'] . ' | ' . $stage['percent'];
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('billing_service_stage_amounts')) {
    /** Dollar amount of each stage. Whole cents; the last stage takes the rounding so the stages add up. */
    function billing_service_stage_amounts(float $total, array $stages): array
    {
        $cents = (int)round($total * 100);
        $amounts = array();
        $given = 0;
        $last = count($stages) - 1;
        foreach (array_values($stages) as $i => $stage) {
            $part = $i === $last ? $cents - $given : (int)round($cents * $stage['percent'] / 100);
            $given += $part;
            $amounts[] = (float)($part / 100);
        }
        return $amounts;
    }
}

if (!function_exists('billing_service_parse_fields')) {
    /** Order form questions from JSON: [{"key","label","type":"text|textarea|select","required","options":[]}]. */
    function billing_service_parse_fields($json): array
    {
        $list = is_array($json) ? $json : json_decode((string)$json, true);
        $fields = array();
        if (!is_array($list)) {
            return $fields;
        }
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $type = (string)($row['type'] ?? 'text');
            if ($label === '' || !in_array($type, array('text', 'textarea', 'select'), true)) {
                continue;
            }
            $options = array();
            foreach ((array)($row['options'] ?? array()) as $option) {
                $option = trim((string)$option);
                if ($option !== '') {
                    $options[] = substr($option, 0, 80);
                }
            }
            if ($type === 'select' && !$options) {
                $type = 'text';
            }
            $fields[] = array(
                'key' => 'f' . (count($fields) + 1),
                'label' => substr($label, 0, 80),
                'type' => $type,
                'required' => !empty($row['required']),
                'options' => $options,
            );
        }
        return array_slice($fields, 0, 12);
    }
}

if (!function_exists('billing_service_fields_from_text')) {
    /** Admin text, one question per line: "Game | text | required" or "Platform | select | optional | PC; Console". */
    function billing_service_fields_from_text(string $text): array
    {
        $list = array();
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $list[] = array(
                'label' => $parts[0],
                'type' => strtolower($parts[1] ?? 'text') ?: 'text',
                'required' => strtolower($parts[2] ?? '') === 'required',
                'options' => isset($parts[3]) && $parts[3] !== '' ? array_map('trim', explode(';', $parts[3])) : array(),
            );
        }
        return billing_service_parse_fields($list);
    }
}

if (!function_exists('billing_service_fields_to_text')) {
    function billing_service_fields_to_text(array $fields): string
    {
        $lines = array();
        foreach ($fields as $field) {
            $line = $field['label'] . ' | ' . $field['type'] . ' | ' . ($field['required'] ? 'required' : 'optional');
            if ($field['options']) {
                $line .= ' | ' . implode('; ', $field['options']);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('billing_service_collect_details')) {
    /** Read the customer's answers from $post. Returns array(details label=>answer, errors). */
    function billing_service_collect_details(array $fields, array $post): array
    {
        $details = array();
        $errors = array();
        foreach ($fields as $field) {
            $raw = $post['field_' . $field['key']] ?? '';
            $value = trim(is_string($raw) ? $raw : '');
            $value = function_exists('mb_substr') ? mb_substr($value, 0, 2000) : substr($value, 0, 2000);
            if ($field['type'] === 'select' && $value !== '' && !in_array($value, $field['options'], true)) {
                $errors[] = $field['label'] . ': choose one of the listed options.';
                continue;
            }
            if ($field['required'] && $value === '') {
                $errors[] = $field['label'] . ' is required.';
                continue;
            }
            if ($value !== '') {
                $details[$field['label']] = $value;
            }
        }
        return array($details, $errors);
    }
}

if (!function_exists('billing_service_subject')) {
    /** Ticket subject: "[Service name] what the customer typed", at most 64 characters. */
    function billing_service_subject(string $typeName, string $subject): string
    {
        $text = '[' . trim($typeName) . '] ' . trim($subject);
        return function_exists('mb_substr') ? mb_substr(trim($text), 0, 64) : substr(trim($text), 0, 64);
    }
}

if (!function_exists('billing_service_ticket_message')) {
    /** First ticket message: what the customer asked for, with the answers listed. */
    function billing_service_ticket_message(string $typeName, array $details, string $message, int $requestId): string
    {
        $lines = array('Service request #' . $requestId . ': ' . $typeName);
        foreach ($details as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        if (trim($message) !== '') {
            $lines[] = '';
            $lines[] = trim($message);
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('billing_service_status_label')) {
    function billing_service_status_label(string $status): string
    {
        $labels = array(
            'awaiting_quote' => 'Waiting for our quote',
            'awaiting_payment' => 'Waiting for payment',
            'in_progress' => 'In progress',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        );
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('billing_service_money')) {
    function billing_service_money($amount): string
    {
        return '$' . number_format((float)$amount, 2);
    }
}

// ---------------------------------------------------------------------------
// Database functions
// ---------------------------------------------------------------------------

if (!function_exists('billing_service_types_ready')) {
    /** True when the service tables exist (the module update has run). */
    function billing_service_types_ready(mysqli $db, string $prefix): bool
    {
        $name = $db->real_escape_string($prefix . 'billing_service_types');
        $result = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$name}' LIMIT 1");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('billing_service_types_list')) {
    /** Service types, in display order. $kind: '' for all, 'paid' or 'support'. */
    function billing_service_types_list(mysqli $db, string $prefix, bool $enabledOnly = true, string $kind = ''): array
    {
        $where = array();
        if ($enabledOnly) {
            $where[] = 'enabled = 1';
        }
        if ($kind === 'paid' || $kind === 'support') {
            $where[] = "kind = '" . $kind . "'";
        }
        $sql = "SELECT * FROM `{$prefix}billing_service_types`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY sort_order, type_id';
        $rows = array();
        $result = $db->query($sql);
        while ($result && ($row = $result->fetch_assoc())) {
            $row['stages_list'] = billing_service_parse_stages($row['stages'] ?? '');
            $row['fields_list'] = billing_service_parse_fields($row['fields'] ?? '');
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('billing_service_type_get')) {
    function billing_service_type_get(mysqli $db, string $prefix, int $typeId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_service_types` WHERE type_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $typeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $row['stages_list'] = billing_service_parse_stages($row['stages'] ?? '');
        $row['fields_list'] = billing_service_parse_fields($row['fields'] ?? '');
        return $row;
    }
}

if (!function_exists('billing_service_type_get_by_slug')) {
    function billing_service_type_get_by_slug(mysqli $db, string $prefix, string $slug): ?array
    {
        $stmt = $db->prepare("SELECT type_id FROM `{$prefix}billing_service_types` WHERE slug = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? billing_service_type_get($db, $prefix, (int)$row['type_id']) : null;
    }
}

if (!function_exists('billing_service_ticket_note')) {
    /** A message from the team in a ticket, and the ticket shows as answered by staff. */
    function billing_service_ticket_note(mysqli $db, string $prefix, int $tid, string $message): void
    {
        if ($tid <= 0) {
            return;
        }
        $ip = function_exists('support_ticket_ip_bytes') ? support_ticket_ip_bytes('127.0.0.1') : "\x7f\x00\x00\x01";
        $stmt = $db->prepare("INSERT INTO `{$prefix}ticket_messages` (ticket_id, user_id, user_ip, message, is_admin) VALUES (?, 0, ?, ?, 1)");
        if ($stmt) {
            $stmt->bind_param('iss', $tid, $ip, $message);
            $stmt->execute();
            $stmt->close();
        }
        $db->query("UPDATE `{$prefix}tickets` SET status = 2, last_updated = NOW() WHERE tid = " . (int)$tid);
    }
}

if (!function_exists('billing_service_request_get')) {
    function billing_service_request_get(mysqli $db, string $prefix, int $requestId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM `{$prefix}billing_service_requests` WHERE request_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('billing_service_create_stage_invoice')) {
    /**
     * Invoice for one stage of a request (stage numbers start at 1). Returns the invoice id, or 0 when there
     * is nothing to bill: no price yet, a stage that does not exist, or one that already has an invoice.
     */
    function billing_service_create_stage_invoice(mysqli $db, string $prefix, array $request, array $type, int $stageNo): int
    {
        $stages = billing_service_parse_stages($type['stages'] ?? '');
        $total = (float)($request['total_price'] ?? 0);
        if ($total <= 0 || $stageNo < 1 || $stageNo > count($stages)) {
            return 0;
        }
        $requestId = (int)$request['request_id'];
        $check = $db->query("SELECT invoice_id FROM `{$prefix}billing_invoices` WHERE request_id = {$requestId} AND stage_no = {$stageNo}"
            . " AND payment_status <> 'cancelled' LIMIT 1");
        if ($check && $check->num_rows > 0) {
            return 0;
        }
        $amounts = billing_service_stage_amounts($total, $stages);
        $amount = $amounts[$stageNo - 1];
        if ($amount <= 0) {
            return 0;
        }
        $label = $stages[$stageNo - 1]['label'];
        $userId = (int)$request['user_id'];
        $homeId = (int)($request['home_id'] ?? 0);
        $name = '';
        $email = '';
        $u = $db->query("SELECT users_login, users_email, users_fname, users_lname FROM `{$prefix}users` WHERE user_id = {$userId} LIMIT 1");
        if ($u && ($row = $u->fetch_assoc())) {
            $name = trim(($row['users_fname'] ?? '') . ' ' . ($row['users_lname'] ?? ''));
            $name = $name !== '' ? $name : (string)($row['users_login'] ?? '');
            $email = (string)($row['users_email'] ?? '');
        }
        $title = substr($type['name'] . (count($stages) > 1 ? ' - ' . $label : ''), 0, 250);
        $description = substr('Service request #' . $requestId . ': ' . $type['name'] . ' (' . $label . ', stage ' . $stageNo . ' of ' . count($stages) . ')', 0, 490);
        $stmt = $db->prepare(
            "INSERT INTO `{$prefix}billing_invoices`
             (user_id, service_id, home_id, home_name, customer_name, customer_email, amount, subtotal, total_due, currency,
              payment_status, status, billing_status, description, invoice_kind, request_id, stage_no, stage_label)
             VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, 'USD', 'unpaid', 'due', 'due', ?, 'service', ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('iisssdddsiis', $userId, $homeId, $title, $name, $email, $amount, $amount, $amount, $description, $requestId, $stageNo, $label);
        $ok = $stmt->execute();
        $invoiceId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $invoiceId;
    }
}

if (!function_exists('billing_service_request_create')) {
    /**
     * Order a service. Opens the ticket, records the request and, for a fixed price, creates the invoice for stage 1.
     * Returns array('request_id', 'tid', 'invoice_id'). Throws when the ticket or the request cannot be written.
     */
    function billing_service_request_create(mysqli $db, string $prefix, int $userId, array $type, array $details, int $homeId, string $subject, string $message, string $ip): array
    {
        if ($userId <= 0 || ($type['kind'] ?? '') !== 'paid') {
            throw new InvalidArgumentException('A signed-in customer and a paid service are required.');
        }
        $typeId = (int)$type['type_id'];
        $fixed = ($type['price_mode'] ?? 'fixed') === 'fixed' && (float)($type['price'] ?? 0) > 0;
        $total = $fixed ? (float)$type['price'] : null;
        $status = $fixed ? 'awaiting_payment' : 'awaiting_quote';
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("INSERT INTO `{$prefix}billing_service_requests` (type_id, user_id, home_id, details, total_price, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiisds', $typeId, $userId, $homeId, $detailsJson, $total, $status);
        $stmt->execute();
        $requestId = (int)$db->insert_id;
        $stmt->close();

        try {
            $opened = support_ticket_open(
                $db, $prefix, $userId, $ip,
                billing_service_subject((string)$type['name'], $subject),
                billing_service_ticket_message((string)$type['name'], $details, $message, $requestId),
                $homeId
            );
        } catch (Throwable $e) {
            $db->query("DELETE FROM `{$prefix}billing_service_requests` WHERE request_id = " . $requestId);
            throw $e;
        }
        $tid = (int)$opened['tid'];
        $db->query("UPDATE `{$prefix}billing_service_requests` SET tid = {$tid} WHERE request_id = {$requestId}");

        $invoiceId = 0;
        if ($fixed) {
            $request = array('request_id' => $requestId, 'user_id' => $userId, 'home_id' => $homeId, 'total_price' => $total);
            $invoiceId = billing_service_create_stage_invoice($db, $prefix, $request, $type, 1);
            if ($invoiceId > 0) {
                billing_service_ticket_note($db, $prefix, $tid, 'Invoice #' . $invoiceId . ' for ' . billing_service_money(billing_service_stage_amounts($total, billing_service_parse_stages($type['stages'] ?? ''))[0])
                    . ' is in your cart. We start as soon as it is paid.');
            }
        } else {
            billing_service_ticket_note($db, $prefix, $tid, 'Thanks. We will read your request and reply here with a price. Nothing is charged until you have seen it.');
        }
        return array('request_id' => $requestId, 'tid' => $tid, 'invoice_id' => $invoiceId);
    }
}

if (!function_exists('billing_service_set_quote')) {
    /** Admin sets the total for a quoted request and bills stage 1. Returns the invoice id (0 when none was made). */
    function billing_service_set_quote(mysqli $db, string $prefix, int $requestId, float $total): int
    {
        $request = billing_service_request_get($db, $prefix, $requestId);
        if (!$request || $total <= 0 || in_array($request['status'], array('completed', 'cancelled'), true)) {
            return 0;
        }
        $type = billing_service_type_get($db, $prefix, (int)$request['type_id']);
        if (!$type) {
            return 0;
        }
        $db->query("UPDATE `{$prefix}billing_service_requests` SET total_price = " . number_format($total, 2, '.', '') . ", updated_at = NOW() WHERE request_id = " . $requestId);
        $request['total_price'] = $total;
        $invoiceId = billing_service_create_stage_invoice($db, $prefix, $request, $type, max(1, (int)$request['next_stage']));
        if ($invoiceId > 0) {
            $db->query("UPDATE `{$prefix}billing_service_requests` SET status = 'awaiting_payment' WHERE request_id = " . $requestId . " AND status = 'awaiting_quote'");
            billing_service_ticket_note($db, $prefix, (int)$request['tid'], 'Our price for this job is ' . billing_service_money($total) . '. Invoice #' . $invoiceId . ' is in your cart.');
        }
        return $invoiceId;
    }
}

if (!function_exists('billing_service_bill_next_stage')) {
    /** Admin bills the next stage (for example the payment due on delivery). Returns the invoice id, 0 when nothing to bill. */
    function billing_service_bill_next_stage(mysqli $db, string $prefix, int $requestId): int
    {
        $request = billing_service_request_get($db, $prefix, $requestId);
        if (!$request || in_array($request['status'], array('cancelled'), true)) {
            return 0;
        }
        $type = billing_service_type_get($db, $prefix, (int)$request['type_id']);
        if (!$type) {
            return 0;
        }
        $invoiceId = billing_service_create_stage_invoice($db, $prefix, $request, $type, (int)$request['next_stage']);
        if ($invoiceId > 0) {
            $stages = billing_service_parse_stages($type['stages'] ?? '');
            $amounts = billing_service_stage_amounts((float)$request['total_price'], $stages);
            $n = (int)$request['next_stage'];
            $db->query("UPDATE `{$prefix}billing_service_requests` SET updated_at = NOW() WHERE request_id = " . $requestId);
            billing_service_ticket_note($db, $prefix, (int)$request['tid'], 'Invoice #' . $invoiceId . ' (' . $stages[$n - 1]['label'] . ', ' . billing_service_money($amounts[$n - 1]) . ') is in your cart.');
        }
        return $invoiceId;
    }
}

if (!function_exists('billing_service_set_status')) {
    /** Admin changes the status. Cancelling also cancels any unpaid invoice of the request. */
    function billing_service_set_status(mysqli $db, string $prefix, int $requestId, string $status): bool
    {
        if (!in_array($status, array('in_progress', 'delivered', 'completed', 'cancelled'), true)) {
            return false;
        }
        $request = billing_service_request_get($db, $prefix, $requestId);
        if (!$request) {
            return false;
        }
        $db->query("UPDATE `{$prefix}billing_service_requests` SET status = '" . $status . "', updated_at = NOW() WHERE request_id = " . $requestId);
        if ($status === 'cancelled') {
            $db->query("UPDATE `{$prefix}billing_invoices` SET payment_status = 'cancelled', status = 'cancelled'"
                . " WHERE request_id = " . $requestId . " AND invoice_kind = 'service' AND payment_status = 'unpaid'");
        }
        billing_service_ticket_note($db, $prefix, (int)$request['tid'], 'Status of this request: ' . billing_service_status_label($status) . '.');
        return true;
    }
}

if (!function_exists('billing_service_on_invoice_paid')) {
    /** Called after a service invoice is paid. Moves the request on and tells the customer in the ticket. Never throws. */
    function billing_service_on_invoice_paid(mysqli $db, string $prefix, array $invoice): void
    {
        try {
            $requestId = (int)($invoice['request_id'] ?? 0);
            $request = $requestId > 0 ? billing_service_request_get($db, $prefix, $requestId) : null;
            if (!$request) {
                return;
            }
            $type = billing_service_type_get($db, $prefix, (int)$request['type_id']);
            $stageCount = $type ? count(billing_service_parse_stages($type['stages'] ?? '')) : 1;
            $stageNo = (int)($invoice['stage_no'] ?? 1);
            $next = max((int)$request['next_stage'], $stageNo + 1);
            $allPaid = $stageNo >= $stageCount;
            $status = (string)$request['status'];
            if ($status === 'delivered' && $allPaid) {
                $status = 'completed';
            } elseif (in_array($status, array('awaiting_payment', 'awaiting_quote'), true)) {
                $status = 'in_progress';
            }
            $db->query("UPDATE `{$prefix}billing_service_requests` SET next_stage = " . $next . ", status = '" . $db->real_escape_string($status)
                . "', updated_at = NOW() WHERE request_id = " . $requestId);
            $label = (string)($invoice['stage_label'] ?? 'Payment');
            $text = 'Payment received for invoice #' . (int)$invoice['invoice_id'] . ' (' . $label . ', ' . billing_service_money($invoice['total_due'] ?? $invoice['amount'] ?? 0) . '). Thank you.';
            if ($status === 'completed') {
                $text .= ' This request is complete.';
            } elseif (!$allPaid) {
                $text .= ' We will send the next invoice when that part of the job is reached.';
            } else {
                $text .= ' We are on it.';
            }
            billing_service_ticket_note($db, $prefix, (int)$request['tid'], $text);
        } catch (Throwable $e) {
            error_log('[billing services] on_invoice_paid failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('billing_service_requests_for_user')) {
    /** The customer's requests, newest first, with the service name and the open invoice (if any). */
    function billing_service_requests_for_user(mysqli $db, string $prefix, int $userId, int $limit = 25): array
    {
        $rows = array();
        $limit = max(1, min(100, $limit));
        $result = $db->query(
            "SELECT r.*, t.name AS type_name,
                    (SELECT invoice_id FROM `{$prefix}billing_invoices` i WHERE i.request_id = r.request_id AND i.payment_status = 'unpaid' ORDER BY invoice_id LIMIT 1) AS open_invoice_id
             FROM `{$prefix}billing_service_requests` r
             LEFT JOIN `{$prefix}billing_service_types` t ON t.type_id = r.type_id
             WHERE r.user_id = " . (int)$userId . " ORDER BY r.request_id DESC LIMIT " . $limit
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}
