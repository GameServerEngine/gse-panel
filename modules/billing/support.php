<?php
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/billing_bootstrap.php';
require_once __DIR__ . '/includes/session_bridge.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/support_tickets.php';
require_once __DIR__ . '/includes/status_snapshot.php';

/*
 * Support page: contact details, account link, live service status, and tickets.
 *
 * Tickets are the control panel's own tickets and ticket_messages rows, so staff answer
 * them in the panel (Support > Tickets). Signed-in customers may link a ticket to one of
 * their servers. Visitors who are not signed in give an email address, and a cookie lets
 * them find the ticket and its replies again.
 */

$userId = (int)($_SESSION['website_user_id'] ?? 0);
$csrfKey = 'billing_support_ticket_csrf';
$formErrors = array();
$replyErrors = array();
$replyTid = 0;
$subject = '';
$message = '';
$email = '';
$serviceId = 0;
$db = null;
$ticketsReady = false;
$services = array();
$snapshot = null;
$prefix = '';

require_once __DIR__ . '/bootstrap.php';
try {
    $db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
    if ($db) {
        $db->set_charset('utf8mb4');
        $prefix = (string)$table_prefix;
        $ticketsReady = support_ticket_available($db, $prefix);
        $snapshot = billing_status_load($db, $prefix);
    }
} catch (Throwable $e) {
    error_log('support page database: ' . $e->getMessage());
    $db = null;
}

$guestHash = support_guest_hash(support_guest_cookie_value());
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if ($ticketsReady) {
    if ($userId > 0) {
        $services = support_ticket_services($db, $prefix, $userId);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $csrfOk = billing_action_validate_csrf($_POST['csrf'] ?? '', $csrfKey);

        if ($action === 'open_ticket') {
            $subject = trim((string)($_POST['subject'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $serviceId = $userId > 0 ? (int)($_POST['service_id'] ?? 0) : 0;
            $email = trim((string)($_POST['email'] ?? ''));

            if (!$csrfOk) {
                $formErrors[] = 'Your session expired. Please try again.';
            } else {
                $formErrors = support_ticket_errors($subject, $message, $serviceId, array_column($services, 'home_id'));
                if ($userId <= 0) {
                    $formErrors = array_merge($formErrors, support_email_errors($email));
                }
            }
            if (!$formErrors) {
                try {
                    if ($userId > 0) {
                        $opened = support_ticket_open($db, $prefix, $userId, $ip, $subject, $message, $serviceId);
                    } else {
                        $opened = support_guest_ticket_open($db, $prefix, support_guest_cookie_ensure(), $email, $ip, $subject, $message);
                    }
                    $_SESSION['support_ticket_opened'] = $opened;
                    header('Location: ' . billing_url('support.php') . '#tickets');
                    exit();
                } catch (Throwable $e) {
                    error_log('support ticket: ' . $e->getMessage());
                    $formErrors[] = 'The ticket could not be saved. Please try again, or email us.';
                }
            }
        } elseif ($action === 'reply_ticket') {
            $replyTid = (int)($_POST['tid'] ?? 0);
            $replyText = trim((string)($_POST['message'] ?? ''));
            $status = $csrfOk ? support_ticket_status_of($db, $prefix, $replyTid, $userId, $guestHash) : null;
            if (!$csrfOk) {
                $replyErrors[] = 'Your session expired. Please try again.';
            } elseif ($status === null) {
                $replyErrors[] = 'That ticket was not found.';
            } elseif ($status === 0) {
                $replyErrors[] = 'That ticket is closed. Open a new ticket to continue.';
            } else {
                $replyErrors = support_message_errors($replyText);
            }
            if (!$replyErrors) {
                try {
                    support_ticket_reply($db, $prefix, $replyTid, $userId, $ip, $replyText);
                    $_SESSION['support_ticket_replied'] = true;
                    header('Location: ' . billing_url('support.php') . '#ticket-' . $replyTid);
                    exit();
                } catch (Throwable $e) {
                    error_log('support reply: ' . $e->getMessage());
                    $replyErrors[] = 'Your reply could not be saved. Please try again, or email us.';
                }
            }
        }
    }
}

$tickets = $ticketsReady ? support_ticket_list_for($db, $prefix, $userId, $guestHash) : array();
$postsByTicket = $tickets ? support_ticket_messages($db, $prefix, array_column($tickets, 'tid')) : array();
$openTickets = array_values(array_filter($tickets, function ($t) { return (int)$t['status'] !== 0; }));
$closedTickets = array_values(array_filter($tickets, function ($t) { return (int)$t['status'] === 0; }));

$openedTicket = $_SESSION['support_ticket_opened'] ?? null;
$replied = !empty($_SESSION['support_ticket_replied']);
unset($_SESSION['support_ticket_opened'], $_SESSION['support_ticket_replied']);
$csrf = $ticketsReady ? billing_action_csrf_token($csrfKey) : '';

$contactEmail = billing_brand('email');
$discordUrl = billing_brand('discord_url');

/** One ticket as a collapsible block: reply box on top, then the posts, newest first. */
$renderTicket = function (array $ticket, bool $isOpen, bool $expand) use ($postsByTicket, $csrf, $replyErrors, $replyTid) {
    $tid = (int)$ticket['tid'];
    $meta = support_ticket_status_label((int)$ticket['status']);
    if (!empty($ticket['home_name'])) {
        $meta .= ' · ' . $ticket['home_name'];
    }
    $updated = $ticket['last_updated'] ?? $ticket['created_at'] ?? '';
    if ($updated !== '') {
        $meta .= ' · ' . $updated;
    }
    echo '<details class="gsw-ticket" id="ticket-' . $tid . '"' . ($expand ? ' open' : '') . '>';
    echo '<summary><span class="gsw-ticket-subject">' . support_h($ticket['subject']) . '</span>'
        . '<span class="gsw-ticket-meta">' . support_h($meta) . '</span></summary>';
    if ($isOpen) {
        echo '<form method="post" action="' . support_h(billing_url('support.php')) . '#ticket-' . $tid . '" class="gsw-ticket-reply">';
        if ($replyTid === $tid) {
            foreach ($replyErrors as $error) {
                echo '<div class="gsw-alert gsw-alert-danger" role="alert">' . support_h($error) . '</div>';
            }
        }
        echo '<input type="hidden" name="action" value="reply_ticket">'
            . '<input type="hidden" name="tid" value="' . $tid . '">'
            . '<input type="hidden" name="csrf" value="' . support_h($csrf) . '">'
            . '<label for="reply-' . $tid . '">Reply</label>'
            . '<textarea id="reply-' . $tid . '" name="message" rows="3" maxlength="' . SUPPORT_TICKET_MESSAGE_MAX . '" required></textarea>'
            . '<button type="submit" class="gsw-btn">Send Reply</button></form>';
    }
    echo '<div class="gsw-ticket-posts">';
    foreach ($postsByTicket[$tid] ?? array() as $post) {
        $who = $post['is_admin'] ? 'Staff' : 'You';
        echo '<div class="gsw-post ' . ($post['is_admin'] ? 'is-staff' : 'is-you') . '">'
            . '<div class="gsw-post-meta"><strong>' . $who . '</strong> · ' . support_h($post['date']) . '</div>'
            . '<div class="gsw-post-body">' . nl2br(support_h(support_message_display($post['message']))) . '</div></div>';
    }
    echo '</div></details>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Support - <?php echo billing_brand_e('name'); ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo support_h(billing_url('images/brand/favicon-32x32.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main>
  <p class="gsw-section-eyebrow">Hosting Support</p>
  <h1>Support</h1>
  <p>Use this page for help with <?php echo billing_brand_e('name'); ?> orders, hosted servers, account access, billing, renewals, and service status. The service is managed by developers and gamers at <?php echo billing_brand_e('company'); ?>.</p>

  <p class="gsw-support-contact">
    <strong>Contact us:</strong>
    <a href="mailto:<?php echo support_h($contactEmail); ?>"><?php echo support_h($contactEmail); ?></a>
<?php if ($discordUrl !== ''): ?>
    · <a href="<?php echo support_h($discordUrl); ?>" rel="noopener">Discord</a>
<?php endif; ?>
  </p>

  <div class="account-info-grid">
    <section class="account-section">
      <h2>Account Info</h2>
      <p>Sign in to review your account, orders, invoices, and servers.</p>
      <p><a class="gsw-btn" href="<?php echo support_h(billing_url('my_account.php')); ?>">Open My Account</a></p>
    </section>
    <section class="account-section">
      <h2>Server Status</h2>
      <div class="gsw-status-frame" id="gsw-status-frame" tabindex="0" role="region" aria-label="Live service status" data-feed="<?php echo support_h(billing_url('status_feed.php')); ?>">
<?php echo billing_status_render($snapshot); ?>
      </div>
    </section>
  </div>

<?php if ($openedTicket || $replied): ?>
  <div class="gsw-alert gsw-alert-success" role="status"><?php echo $openedTicket ? 'Your ticket was opened. Staff will reply here and in the control panel.' : 'Your reply was sent.'; ?></div>
<?php endif; ?>

<?php if ($openTickets): ?>
  <section class="panel" id="tickets">
    <h2>Open Tickets</h2>
<?php foreach ($openTickets as $i => $ticket) { $renderTicket($ticket, true, $i === 0 || (int)$ticket['tid'] === $replyTid); } ?>
  </section>
<?php endif; ?>

  <section class="panel" id="open-ticket">
    <h2>Open a Ticket</h2>
<?php if (!$ticketsReady): ?>
    <p>Online tickets are not available right now. Email <a href="mailto:<?php echo support_h($contactEmail); ?>"><?php echo support_h($contactEmail); ?></a> instead.</p>
<?php else: ?>
<?php if ($formErrors): ?>
    <div class="gsw-alert gsw-alert-danger" role="alert">
      <?php foreach ($formErrors as $error): ?><div><?php echo support_h($error); ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>
    <form method="post" action="<?php echo support_h(billing_url('support.php')); ?>#open-ticket">
      <input type="hidden" name="action" value="open_ticket">
      <input type="hidden" name="csrf" value="<?php echo support_h($csrf); ?>">
<?php if ($userId > 0): ?>
      <div class="form-group">
        <label for="ticket-service">Server <span class="muted">(optional)</span></label>
        <select id="ticket-service" name="service_id">
          <option value="0">Not about a specific server</option>
<?php foreach ($services as $service): ?>
          <option value="<?php echo (int)$service['home_id']; ?>"<?php echo $serviceId === $service['home_id'] ? ' selected' : ''; ?>><?php echo support_h($service['home_name']); ?></option>
<?php endforeach; ?>
        </select>
      </div>
<?php else: ?>
      <div class="form-group">
        <label for="ticket-email">Your email <span class="muted">(required, so we can reach you)</span></label>
        <input type="email" id="ticket-email" name="email" maxlength="190" value="<?php echo support_h($email); ?>" autocomplete="email" required>
        <p class="muted">You are not signed in. We keep a cookie on this browser so you can come back here and read the replies. <a href="<?php echo support_h(billing_url('login.php') . '?return_to=' . urlencode(billing_url('support.php'))); ?>">Sign in</a> to link a ticket to one of your servers.</p>
      </div>
<?php endif; ?>
      <div class="form-group">
        <label for="ticket-subject">Subject</label>
        <input type="text" id="ticket-subject" name="subject" maxlength="<?php echo SUPPORT_TICKET_SUBJECT_MAX; ?>" value="<?php echo support_h($subject); ?>" required>
      </div>
      <div class="form-group">
        <label for="ticket-message">How can we help?</label>
        <textarea id="ticket-message" name="message" rows="7" maxlength="<?php echo SUPPORT_TICKET_MESSAGE_MAX; ?>" required><?php echo support_h($message); ?></textarea>
        <p class="muted">Include the order or invoice number, game, server name, and recent changes when you have them.</p>
      </div>
      <button type="submit" class="gsw-btn">Open Ticket</button>
    </form>
<?php endif; ?>
  </section>

<?php if ($closedTickets): ?>
  <section class="panel" id="closed-tickets">
    <details class="gsw-ticket-group">
      <summary>Previous Tickets (<?php echo count($closedTickets); ?>)</summary>
<?php foreach ($closedTickets as $ticket) { $renderTicket($ticket, false, false); } ?>
    </details>
  </section>
<?php endif; ?>
</main>
<script>
(function () {
  var frame = document.getElementById('gsw-status-frame');
  if (!frame || !window.fetch) return;
  // The page reads a cached snapshot, so refreshing often is cheap.
  setInterval(function () {
    fetch(frame.getAttribute('data-feed'), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (!html) return;
        var top = frame.scrollTop;
        frame.innerHTML = html;
        frame.scrollTop = top;
      })
      .catch(function () {});
  }, 30000);
})();
</script>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
