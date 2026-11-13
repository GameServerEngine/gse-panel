<?php
/**
 * Support tickets opened from the website.
 *
 * A ticket opened here is the same rows the control panel's tickets module writes
 * (tickets and ticket_messages), so staff see it and answer it in the panel
 * (Support > Tickets). The customer may link it to one of their servers, or to none.
 *
 * The first group of functions needs no database. The second group does.
 */

const SUPPORT_TICKET_SUBJECT_MIN = 4;
const SUPPORT_TICKET_SUBJECT_MAX = 64;
const SUPPORT_TICKET_MESSAGE_MIN = 4;
const SUPPORT_TICKET_MESSAGE_MAX = 5000;

/* ---------------------------------------------------------------------------
   No database
--------------------------------------------------------------------------- */

if (!function_exists('support_h')) {
    function support_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('support_ticket_errors')) {
    /**
     * What is wrong with a new ticket. An empty list means it can be opened.
     * $serviceId is 0 for "no server"; otherwise it must be one of the customer's own servers.
     */
    function support_ticket_errors(string $subject, string $message, int $serviceId, array $ownHomeIds): array
    {
        $errors = array();
        $subjectLength = mb_strlen($subject, 'UTF-8');
        if ($subjectLength < SUPPORT_TICKET_SUBJECT_MIN || $subjectLength > SUPPORT_TICKET_SUBJECT_MAX) {
            $errors[] = 'The subject must be ' . SUPPORT_TICKET_SUBJECT_MIN . ' to ' . SUPPORT_TICKET_SUBJECT_MAX . ' characters.';
        }
        $messageLength = mb_strlen($message, 'UTF-8');
        if ($messageLength < SUPPORT_TICKET_MESSAGE_MIN || $messageLength > SUPPORT_TICKET_MESSAGE_MAX) {
            $errors[] = 'The message must be ' . SUPPORT_TICKET_MESSAGE_MIN . ' to ' . SUPPORT_TICKET_MESSAGE_MAX . ' characters.';
        }
        if ($serviceId !== 0 && !in_array($serviceId, array_map('intval', $ownHomeIds), true)) {
            $errors[] = 'Choose one of your own servers, or no server.';
        }
        return $errors;
    }
}

if (!function_exists('support_ticket_status_label')) {
    /** The panel's ticket status codes: 0 closed, 1 open, 2 answered by staff, 3 waiting for staff. */
    function support_ticket_status_label(int $status): string
    {
        $labels = array(0 => 'Closed', 1 => 'Open', 2 => 'Answered', 3 => 'Waiting for staff');
        return $labels[$status] ?? 'Open';
    }
}

if (!function_exists('support_ticket_ip_bytes')) {
    /** The visitor's address as the bytes the tickets table stores. Falls back to 0.0.0.0. */
    function support_ticket_ip_bytes(string $ip): string
    {
        $bytes = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
        return $bytes === false ? inet_pton('0.0.0.0') : $bytes;
    }
}

/* ---------------------------------------------------------------------------
   Database
--------------------------------------------------------------------------- */

if (!function_exists('support_ticket_available')) {
    /** True when the panel's tickets tables exist (the tickets module has been installed). */
    function support_ticket_available(mysqli $db, string $prefix): bool
    {
        $found = 0;
        foreach (array('tickets', 'ticket_messages') as $table) {
            $name = $db->real_escape_string($prefix . $table);
            $result = $db->query(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$name}' LIMIT 1"
            );
            if ($result && $result->num_rows > 0) {
                $found++;
            }
        }
        return $found === 2;
    }
}

if (!function_exists('support_ticket_services')) {
    /** The servers the customer owns, as list of array('home_id' => int, 'home_name' => string). */
    function support_ticket_services(mysqli $db, string $prefix, int $userId): array
    {
        $rows = array();
        $result = $db->query(
            "SELECT home_id, home_name FROM `{$prefix}server_homes` WHERE user_id_main = " . (int)$userId . " ORDER BY home_name, home_id"
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = array('home_id' => (int)$row['home_id'], 'home_name' => (string)$row['home_name']);
        }
        return $rows;
    }
}

if (!function_exists('support_ticket_parent_id')) {
    /** A sub user's tickets belong to the main account, as in the panel. Everyone else owns their own. */
    function support_ticket_parent_id(mysqli $db, string $prefix, int $userId): int
    {
        $result = $db->query("SELECT users_role, users_parent FROM `{$prefix}users` WHERE user_id = " . (int)$userId . " LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        if ($row && $row['users_role'] === 'subuser' && (int)$row['users_parent'] > 0) {
            return (int)$row['users_parent'];
        }
        return $userId;
    }
}

if (!function_exists('support_ticket_open')) {
    /**
     * Opens a ticket the same way the panel does: a tickets row, then the first message from the
     * customer, with the ticket marked "waiting for staff". All or nothing.
     * Returns array('tid' => int, 'uid' => string). Throws when it could not be written.
     * $serviceId 0 means no server. $userId 0 opens a guest ticket (see support_guest_ticket_open).
     */
    function support_ticket_open(
        mysqli $db,
        string $prefix,
        int $userId,
        string $ip,
        string $subject,
        string $message,
        int $serviceId
    ): array {
        $parentId = $userId > 0 ? support_ticket_parent_id($db, $prefix, $userId) : 0; // guests (user 0) have no parent
        $ipBytes = support_ticket_ip_bytes($ip);
        $service = $serviceId === 0 ? null : $serviceId;

        $db->begin_transaction();
        try {
            $tid = 0;
            $uid = '';
            for ($attempt = 0; $attempt < 5 && $tid === 0; $attempt++) {
                $uid = bin2hex(random_bytes(4));
                $stmt = $db->prepare(
                    "INSERT INTO `{$prefix}tickets` (uid, user_id, parent_id, user_ip, subject, service_id, status, last_updated)
                     VALUES (?, ?, ?, ?, ?, ?, 3, NOW())"
                );
                $stmt->bind_param('siissi', $uid, $userId, $parentId, $ipBytes, $subject, $service);
                try {
                    $stmt->execute();
                    $tid = (int)$db->insert_id;
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() !== 1062) {
                        throw $e;
                    }
                }
                $stmt->close();
            }
            if ($tid === 0) {
                throw new RuntimeException('Could not make a unique ticket id.');
            }

            $stmt = $db->prepare(
                "INSERT INTO `{$prefix}ticket_messages` (ticket_id, user_id, user_ip, message, is_admin) VALUES (?, ?, ?, ?, 0)"
            );
            $stmt->bind_param('iiss', $tid, $userId, $ipBytes, $message);
            $stmt->execute();
            $stmt->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return array('tid' => $tid, 'uid' => $uid);
    }
}

if (!function_exists('support_ticket_list')) {
    /** The customer's newest tickets, each with the server name (blank for none). */
    function support_ticket_list(mysqli $db, string $prefix, int $userId, int $limit = 20): array
    {
        $rows = array();
        $result = $db->query(
            "SELECT t.tid, t.uid, t.subject, t.status, t.created_at, t.last_updated, h.home_name
             FROM `{$prefix}tickets` t
             LEFT JOIN `{$prefix}server_homes` h ON h.home_id = t.service_id
             WHERE t.user_id = " . (int)$userId . "
             ORDER BY t.tid DESC
             LIMIT " . max(1, (int)$limit)
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

/* ---------------------------------------------------------------------------
   Guest tickets, threads and replies
--------------------------------------------------------------------------- */

const SUPPORT_GUEST_COOKIE = 'gsw_ticket_key';
const SUPPORT_GUEST_PREFIX = '[Guest ticket - reply to: ';

if (!function_exists('support_guest_cookie_value')) {
    /** The visitor's ticket key from the cookie, or '' when there is none (or it is malformed). */
    function support_guest_cookie_value(): string
    {
        $value = (string)($_COOKIE[SUPPORT_GUEST_COOKIE] ?? '');
        return preg_match('/^[a-f0-9]{48}$/', $value) ? $value : '';
    }
}

if (!function_exists('support_guest_cookie_ensure')) {
    /**
     * The visitor's ticket key, made and stored in a cookie the first time. The cookie is how a
     * visitor who is not signed in finds their tickets and replies again. Call before any output.
     */
    function support_guest_cookie_ensure(): string
    {
        $value = support_guest_cookie_value();
        if ($value !== '') {
            return $value;
        }
        $value = bin2hex(random_bytes(24));
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (!headers_sent()) {
            setcookie(SUPPORT_GUEST_COOKIE, $value, array(
                'expires'  => time() + 180 * 86400,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
        $_COOKIE[SUPPORT_GUEST_COOKIE] = $value;
        return $value;
    }
}

if (!function_exists('support_guest_hash')) {
    /** Only a hash of the key is stored, so a copy of the database cannot be used to read tickets. */
    function support_guest_hash(string $token): string
    {
        return $token === '' ? '' : hash('sha256', $token);
    }
}

if (!function_exists('support_email_errors')) {
    function support_email_errors(string $email): array
    {
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array('Enter a valid email address so we can reach you.');
        }
        return array();
    }
}

if (!function_exists('support_message_errors')) {
    function support_message_errors(string $message): array
    {
        $length = mb_strlen($message, 'UTF-8');
        if ($length < SUPPORT_TICKET_MESSAGE_MIN || $length > SUPPORT_TICKET_MESSAGE_MAX) {
            return array('The message must be ' . SUPPORT_TICKET_MESSAGE_MIN . ' to ' . SUPPORT_TICKET_MESSAGE_MAX . ' characters.');
        }
        return array();
    }
}

if (!function_exists('support_message_display')) {
    /** A stored message for display: the guest reply-to line staff need is not repeated to the guest. */
    function support_message_display(string $message): string
    {
        return preg_replace('/^\[Guest ticket - reply to: [^\]]*\]\s*/', '', $message);
    }
}

if (!function_exists('support_guest_ensure_table')) {
    /** Which guest key owns which ticket, and the email to reach them. The panel's tickets table is untouched. */
    function support_guest_ensure_table(mysqli $db, string $prefix): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}billing_guest_tickets` (
                tid INT NOT NULL PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                email VARCHAR(190) NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY token_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('support_guest_ticket_open')) {
    /**
     * Opens a ticket for a visitor who is not signed in. It is the panel's own tickets row with
     * user 0; the email goes on the first message so staff can see it, and in the guest table.
     */
    function support_guest_ticket_open(mysqli $db, string $prefix, string $token, string $email, string $ip, string $subject, string $message): array
    {
        support_guest_ensure_table($db, $prefix);
        $opened = support_ticket_open($db, $prefix, 0, $ip, $subject, SUPPORT_GUEST_PREFIX . $email . "]\n\n" . $message, 0);
        $stmt = $db->prepare("INSERT INTO `{$prefix}billing_guest_tickets` (tid, token_hash, email, created_at) VALUES (?, ?, ?, ?)");
        $hash = support_guest_hash($token);
        $now = time();
        $tid = (int)$opened['tid'];
        $stmt->bind_param('issi', $tid, $hash, $email, $now);
        $stmt->execute();
        $stmt->close();
        return $opened;
    }
}

if (!function_exists('support_ticket_owner_sql')) {
    /** SQL (on alias t) that matches the tickets this visitor owns: theirs by account, or by guest key. '' when neither. */
    function support_ticket_owner_sql(mysqli $db, string $prefix, int $userId, string $guestHash): string
    {
        $parts = array();
        if ($userId > 0) {
            $parts[] = 't.user_id = ' . (int)$userId;
        }
        if ($guestHash !== '') {
            support_guest_ensure_table($db, $prefix);
            $parts[] = "t.tid IN (SELECT tid FROM `{$prefix}billing_guest_tickets` WHERE token_hash = '" . $db->real_escape_string($guestHash) . "')";
        }
        return $parts ? '(' . implode(' OR ', $parts) . ')' : '';
    }
}

if (!function_exists('support_ticket_list_for')) {
    /** The visitor's tickets, newest first, each with the server name (blank for none). */
    function support_ticket_list_for(mysqli $db, string $prefix, int $userId, string $guestHash, int $limit = 50): array
    {
        $owner = support_ticket_owner_sql($db, $prefix, $userId, $guestHash);
        if ($owner === '') {
            return array();
        }
        $rows = array();
        $result = $db->query(
            "SELECT t.tid, t.uid, t.subject, t.status, t.created_at, t.last_updated, h.home_name
             FROM `{$prefix}tickets` t
             LEFT JOIN `{$prefix}server_homes` h ON h.home_id = t.service_id
             WHERE {$owner}
             ORDER BY t.tid DESC
             LIMIT " . max(1, (int)$limit)
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('support_ticket_status_of')) {
    /** The status of a ticket the visitor owns, or null when it is not theirs. */
    function support_ticket_status_of(mysqli $db, string $prefix, int $tid, int $userId, string $guestHash): ?int
    {
        $owner = support_ticket_owner_sql($db, $prefix, $userId, $guestHash);
        if ($owner === '') {
            return null;
        }
        $result = $db->query("SELECT t.status FROM `{$prefix}tickets` t WHERE t.tid = " . (int)$tid . " AND {$owner} LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int)$row['status'] : null;
    }
}

if (!function_exists('support_ticket_messages')) {
    /** Posts for the given tickets, newest first, as [tid => [ ['message','date','is_admin'], ... ]]. */
    function support_ticket_messages(mysqli $db, string $prefix, array $tids): array
    {
        $tids = array_values(array_filter(array_map('intval', $tids)));
        if (!$tids) {
            return array();
        }
        $grouped = array();
        $result = $db->query(
            "SELECT ticket_id, message, `date`, is_admin FROM `{$prefix}ticket_messages`
             WHERE ticket_id IN (" . implode(',', $tids) . ") ORDER BY reply_id DESC"
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $grouped[(int)$row['ticket_id']][] = array(
                'message'  => (string)$row['message'],
                'date'     => (string)$row['date'],
                'is_admin' => (int)$row['is_admin'] === 1,
            );
        }
        return $grouped;
    }
}

if (!function_exists('support_ticket_reply')) {
    /** Adds the customer's reply and marks the ticket "waiting for staff", as the panel does. */
    function support_ticket_reply(mysqli $db, string $prefix, int $tid, int $userId, string $ip, string $message): void
    {
        $ipBytes = support_ticket_ip_bytes($ip);
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO `{$prefix}ticket_messages` (ticket_id, user_id, user_ip, message, is_admin) VALUES (?, ?, ?, ?, 0)");
            $stmt->bind_param('iiss', $tid, $userId, $ipBytes, $message);
            $stmt->execute();
            $stmt->close();
            $stmt = $db->prepare("UPDATE `{$prefix}tickets` SET status = 3, last_updated = NOW() WHERE tid = ?");
            $stmt->bind_param('i', $tid);
            $stmt->execute();
            $stmt->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
