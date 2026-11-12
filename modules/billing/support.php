<?php
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/billing_bootstrap.php';
require_once __DIR__ . '/includes/session_bridge.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/support_tickets.php';

/*
 * Support page: contact details, and a form that opens a ticket in the control
 * panel's tickets module (the same tickets and ticket_messages rows). Signed-in
 * customers may link the ticket to one of their servers, or to none.
 */

$userId = (int)($_SESSION['website_user_id'] ?? 0);
$csrfKey = 'billing_support_ticket_csrf';
$formErrors = array();
$subject = '';
$message = '';
$serviceId = 0;
$db = null;
$ticketsReady = false;
$services = array();
$tickets = array();

if ($userId > 0) {
    require_once __DIR__ . '/bootstrap.php';
    try {
        $db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
        if ($db) {
            $db->set_charset('utf8mb4');
            $ticketsReady = support_ticket_available($db, $table_prefix);
        }
    } catch (Throwable $e) {
        error_log('support page database: ' . $e->getMessage());
        $db = null;
    }
}

if ($ticketsReady) {
    $services = support_ticket_services($db, $table_prefix, $userId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_ticket') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $ownIds = array_column($services, 'home_id');

        if (!billing_action_validate_csrf($_POST['csrf'] ?? '', $csrfKey)) {
            $formErrors[] = 'Your session expired. Please try again.';
        } else {
            $formErrors = support_ticket_errors($subject, $message, $serviceId, $ownIds);
        }

        if (!$formErrors) {
            try {
                $opened = support_ticket_open(
                    $db,
                    $table_prefix,
                    $userId,
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    $subject,
                    $message,
                    $serviceId
                );
                $_SESSION['support_ticket_opened'] = $opened;
                header('Location: ' . billing_url('support.php') . '#tickets');
                exit();
            } catch (Throwable $e) {
                error_log('support ticket: ' . $e->getMessage());
                $formErrors[] = 'The ticket could not be saved. Please try again, or email us.';
            }
        }
    }

    $tickets = support_ticket_list($db, $table_prefix, $userId);
}

$openedTicket = $_SESSION['support_ticket_opened'] ?? null;
unset($_SESSION['support_ticket_opened']);
$csrf = $ticketsReady ? billing_action_csrf_token($csrfKey) : '';
$ticketBase = '/panel/home.php?m=tickets&p=viewticket';
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

  <div class="account-info-grid">
    <section class="account-section">
      <h2>Existing Customers</h2>
      <p>Sign in to review your account, orders, invoices, and servers.</p>
      <p><a class="gsw-btn" href="<?php echo support_h(billing_url('my_account.php')); ?>">Open My Account</a></p>
    </section>
    <section class="account-section">
      <h2>Server Status</h2>
      <p>Check public service status before opening a support request.</p>
      <p><a class="gsw-btn-secondary" href="<?php echo support_h(billing_url('server_status.php')); ?>">Server Status</a></p>
    </section>
  </div>

  <section class="panel" id="open-ticket">
    <h2>Open a Ticket</h2>
<?php if ($userId <= 0): ?>
    <p>Sign in to open a support ticket. Staff answer tickets in the same place you manage your servers.</p>
    <p><a class="gsw-btn" href="<?php echo support_h(billing_url('login.php') . '?return_to=' . urlencode(billing_url('support.php'))); ?>">Sign In</a></p>
<?php elseif (!$ticketsReady): ?>
    <p>Online tickets are not available right now. Email <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a> instead.</p>
<?php else: ?>
<?php if ($openedTicket): ?>
    <div class="gsw-alert gsw-alert-success" role="status">Your ticket was opened. Staff will reply in the control panel, where you can also read the conversation.</div>
<?php endif; ?>
<?php if ($formErrors): ?>
    <div class="gsw-alert gsw-alert-danger" role="alert">
      <?php foreach ($formErrors as $error): ?><div><?php echo support_h($error); ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>
    <form method="post" action="<?php echo support_h(billing_url('support.php')); ?>#open-ticket">
      <input type="hidden" name="action" value="open_ticket">
      <input type="hidden" name="csrf" value="<?php echo support_h($csrf); ?>">
      <div class="form-group">
        <label for="ticket-service">Server <span class="muted">(optional)</span></label>
        <select id="ticket-service" name="service_id">
          <option value="0">Not about a specific server</option>
<?php foreach ($services as $service): ?>
          <option value="<?php echo (int)$service['home_id']; ?>"<?php echo $serviceId === $service['home_id'] ? ' selected' : ''; ?>><?php echo support_h($service['home_name']); ?></option>
<?php endforeach; ?>
        </select>
      </div>
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

<?php if ($tickets): ?>
  <section class="panel" id="tickets">
    <h2>Your Tickets</h2>
    <table class="cart-table">
      <thead><tr><th>Subject</th><th>Server</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
<?php foreach ($tickets as $ticket): ?>
        <tr>
          <td><a href="<?php echo support_h($ticketBase . '&tid=' . (int)$ticket['tid'] . '&uid=' . urlencode((string)$ticket['uid'])); ?>"><?php echo support_h($ticket['subject']); ?></a></td>
          <td><?php echo support_h($ticket['home_name'] ?? ''); ?></td>
          <td><?php echo support_h(support_ticket_status_label((int)$ticket['status'])); ?></td>
          <td><?php echo support_h($ticket['last_updated'] ?? $ticket['created_at'] ?? ''); ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

  <section class="panel">
    <h2>Contact Support</h2>
    <p>Email <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a> or call <a href="tel:13307868223">330-786-8223</a>.</p>
    <p>Include your account email, order or invoice number, game title, server name, screenshots, logs, and recent changes when available.</p>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
