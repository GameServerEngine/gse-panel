<?php
/*
 * Automatic crash tickets.
 *
 * When the agent reports a real crash for a game home (OS evidence only), open a support
 * ticket for the home's owner, or add to the ticket already open for that home in the
 * last 30 minutes, so repeated crashes in one incident stay in one ticket.
 * The owner gets the full crash details and the log tail, so they can see what happened.
 */

if (!defined('CRASH_TICKET_WINDOW_SECONDS')) {
    define('CRASH_TICKET_WINDOW_SECONDS', 1800);
}
if (!defined('CRASH_TICKET_LOG_BYTES')) {
    define('CRASH_TICKET_LOG_BYTES', 200000);
}

/** Owner (user_id) of a game home, or 0 if none is linked. */
function crash_owner_user_id($db, $home_id)
{
    $rows = $db->resultQuery('SELECT user_id FROM TABLE_PREFIX_PLACEHOLDERuser_homes WHERE home_id = ' . (int)$home_id . ' LIMIT 1');
    return (is_array($rows) && isset($rows[0]['user_id'])) ? (int)$rows[0]['user_id'] : 0;
}

/**
 * Open a crash ticket for the owner, or add to the open one for this home.
 *
 * $incident: array with keys home_id, server_label, detected_at (epoch), reason,
 *            exit_code, faulting_module, report_path.
 * $log_tail: text of the game's recent log, or '' if none.
 *
 * Returns array('action' => 'opened'|'updated'|'skipped', 'tid' => int) or array('action' => 'skipped').
 */
function open_crash_ticket($db, array $incident, $log_tail = '')
{
    $home_id = (int)($incident['home_id'] ?? 0);
    if ($home_id <= 0) {
        return array('action' => 'skipped');
    }
    $user_id = crash_owner_user_id($db, $home_id);
    if ($user_id <= 0) {
        return array('action' => 'skipped');
    }

    $ticket_path = dirname(__FILE__) . '/../tickets/include/ticket.php';
    if (is_file($ticket_path)) {
        require_once $ticket_path;
    }
    if (!class_exists('Ticket')) {
        return array('action' => 'skipped');
    }

    $label = trim((string)($incident['server_label'] ?? ('home ' . $home_id)));
    $subject = substr('Server crash detected: ' . $label, 0, 64);
    $when = date('Y-m-d H:i:s T', (int)($incident['detected_at'] ?? time()));

    $body  = "A crash was detected by the agent and confirmed by Windows crash reporting.\n\n";
    $body .= "Server: {$label}\n";
    $body .= "Detected: {$when}\n";
    $body .= "Reason: " . (string)($incident['reason'] ?? 'process crashed') . "\n";
    if (isset($incident['exit_code']) && $incident['exit_code'] !== '') {
        $body .= "Exit code: " . $incident['exit_code'] . "\n";
    }
    if (!empty($incident['faulting_module'])) {
        $body .= "Faulting module: " . $incident['faulting_module'] . "\n";
    }
    if (!empty($incident['report_path'])) {
        $body .= "Windows report: " . $incident['report_path'] . "\n";
    }
    if ($log_tail !== '') {
        if (strlen($log_tail) > CRASH_TICKET_LOG_BYTES) {
            $log_tail = substr($log_tail, -CRASH_TICKET_LOG_BYTES);
        }
        $body .= "\nRecent log (latest lines):\n" . $log_tail . "\n";
    }

    $since = date('Y-m-d H:i:s', time() - CRASH_TICKET_WINDOW_SECONDS);
    $existing = $db->resultQuery(
        "SELECT tid, uid FROM TABLE_PREFIX_PLACEHOLDERtickets WHERE user_id = " . (int)$user_id
        . " AND subject = '" . $db->realEscapeSingle($subject) . "'"
        . " AND status = 1 AND created_at >= '" . $db->realEscapeSingle($since) . "'"
        . " ORDER BY tid DESC LIMIT 1"
    );

    $ticket = new Ticket($db);
    if (is_array($existing) && isset($existing[0]['tid'])) {
        $tid = (int)$existing[0]['tid'];
        $uid = (string)$existing[0]['uid'];
        $ticket->message($tid, $user_id, '127.0.0.1', "Another crash was detected.\n\n" . $body, true, $uid);
        return array('action' => 'updated', 'tid' => $tid);
    }

    $result = $ticket->open($user_id, '127.0.0.1', $subject, $body, 0, true);
    if (!$result) {
        return array('action' => 'skipped');
    }
    return array('action' => 'opened', 'tid' => (int)$result['tid']);
}
