<?php
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/billing_bootstrap.php';
require_once __DIR__ . '/includes/session_bridge.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/support_tickets.php';
require_once __DIR__ . '/includes/services_catalog.php';
require_once __DIR__ . '/includes/content.php';

/*
 * Services page: help we sell besides game servers (setup, custom development, ...).
 *
 * The list is edited by the admin (Administration > Billing > Services). Ordering a service opens a support
 * ticket, so the work is tracked and answered there, and an invoice that is paid from the cart. A service
 * priced by quote opens the ticket first; the invoice follows once we have given a price.
 */

$userId = (int)($_SESSION['website_user_id'] ?? 0);
$csrfKey = 'billing_service_order_csrf';
$errors = array();
$db = null;
$prefix = '';
$ready = false;
$types = array();
$selected = null;
$homes = array();
$myRequests = array();
$subject = '';
$message = '';
$homeId = 0;
$answers = array();

require_once __DIR__ . '/bootstrap.php';
try {
    $db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
    if ($db) {
        $db->set_charset('utf8mb4');
        $prefix = (string)$table_prefix;
        $ready = billing_service_types_ready($db, $prefix) && support_ticket_available($db, $prefix);
    }
} catch (Throwable $e) {
    error_log('services page database: ' . $e->getMessage());
    $db = null;
}

if ($ready) {
    $types = billing_service_types_list($db, $prefix, true, 'paid');
    $slug = (string)($_GET['service'] ?? $_POST['service'] ?? '');
    foreach ($types as $type) {
        if ($slug !== '' && $type['slug'] === $slug) {
            $selected = $type;
        }
    }
    if ($userId > 0) {
        $homes = support_ticket_services($db, $prefix, $userId);
        $myRequests = billing_service_requests_for_user($db, $prefix, $userId);
        if ($selected && $_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['service_prefill'])) {
            $prefill = $_SESSION['service_prefill'];
            $subject = (string)($prefill['subject'] ?? '');
            $message = (string)($prefill['message'] ?? '');
            $homeId = (int)($prefill['home_id'] ?? 0);
        }
        unset($_SESSION['service_prefill']);
    }

    if ($selected && $userId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $homeId = (int)($_POST['home_id'] ?? 0);
        if (!billing_action_validate_csrf($_POST['csrf'] ?? '', $csrfKey)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            list($answers, $fieldErrors) = billing_service_collect_details($selected['fields_list'], $_POST);
            $errors = $fieldErrors;
            if ($homeId !== 0 && !in_array($homeId, array_map('intval', array_column($homes, 'home_id')), true)) {
                $errors[] = 'Choose one of your own servers, or leave it unselected.';
            }
            $len = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
            if ($len < 3 || $len > 40) {
                $errors[] = 'Give the request a short title of 3 to 40 characters.';
            }
        }
        if (!$errors) {
            try {
                $made = billing_service_request_create(
                    $db, $prefix, $userId, $selected, $answers, $homeId, $subject, $message, (string)($_SERVER['REMOTE_ADDR'] ?? '')
                );
                $_SESSION['service_ordered'] = $made;
                header('Location: ' . billing_url($made['invoice_id'] > 0 ? 'cart.php' : 'support.php') . ($made['invoice_id'] > 0 ? '' : '#ticket-' . $made['tid']));
                exit();
            } catch (Throwable $e) {
                error_log('service order: ' . $e->getMessage());
                $errors[] = 'Your request could not be saved. Please try again, or open a support ticket.';
            }
        }
    }
}
$csrf = $ready && $userId > 0 ? billing_action_csrf_token($csrfKey) : '';

$priceLine = function (array $type): string {
    $stages = $type['stages_list'];
    if (($type['price_mode'] ?? '') === 'fixed' && (float)$type['price'] > 0) {
        $text = 'From ' . billing_service_money($type['price']);
    } else {
        $text = 'Priced after we read your request';
    }
    if (count($stages) > 1) {
        $parts = array();
        foreach ($stages as $stage) {
            $parts[] = $stage['percent'] . '% ' . strtolower($stage['label']);
        }
        $text .= ' · paid in ' . count($stages) . ' steps: ' . implode(', ', $parts);
    }
    return $text;
};
$pageTitle = billing_text('services.title', 'Services');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo support_h($pageTitle); ?> - <?php echo billing_brand_e('name'); ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo support_h(billing_url('images/brand/favicon-32x32.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main>
  <p class="gsw-section-eyebrow"><?php echo billing_text_e('services.eyebrow', 'Services'); ?></p>
  <h1><?php echo billing_text_e('services.heading', 'Extra help for your game server'); ?></h1>
  <p><?php echo billing_text_e('services.intro'); ?></p>

<?php if ($selected): ?>
  <section class="panel" id="order">
    <h2><?php echo support_h($selected['name']); ?></h2>
    <p><?php echo support_h($selected['summary']); ?></p>
    <p class="muted"><?php echo support_h($priceLine($selected)); ?></p>
<?php if ($userId <= 0): ?>
    <p>Sign in to order this service, so we can keep your request, ticket and invoice together.</p>
    <p><a class="gsw-btn" href="<?php echo support_h(billing_url('login.php') . '?return_to=' . urlencode(billing_url('services.php') . '?service=' . $selected['slug'])); ?>">Sign in</a>
       <a class="gsw-btn" href="<?php echo support_h(billing_url('register.php')); ?>">Create an account</a></p>
<?php else: ?>
<?php if ($errors): ?>
    <div class="gsw-alert gsw-alert-danger" role="alert">
      <?php foreach ($errors as $error): ?><div><?php echo support_h($error); ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>
    <form method="post" action="<?php echo support_h(billing_url('services.php')); ?>#order">
      <input type="hidden" name="service" value="<?php echo support_h($selected['slug']); ?>">
      <input type="hidden" name="csrf" value="<?php echo support_h($csrf); ?>">
      <div class="form-group">
        <label for="svc-home">Server <span class="muted">(optional)</span></label>
        <select id="svc-home" name="home_id">
          <option value="0">Not for a server I have yet</option>
<?php foreach ($homes as $home): ?>
          <option value="<?php echo (int)$home['home_id']; ?>"<?php echo $homeId === (int)$home['home_id'] ? ' selected' : ''; ?>><?php echo support_h($home['home_name']); ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="svc-subject">Short title</label>
        <input type="text" id="svc-subject" name="subject" maxlength="40" value="<?php echo support_h($subject); ?>" required>
      </div>
<?php foreach ($selected['fields_list'] as $field): $name = 'field_' . $field['key']; $value = (string)($_POST[$name] ?? ''); ?>
      <div class="form-group">
        <label for="svc-<?php echo support_h($field['key']); ?>"><?php echo support_h($field['label']); ?><?php echo $field['required'] ? '' : ' <span class="muted">(optional)</span>'; ?></label>
<?php if ($field['type'] === 'textarea'): ?>
        <textarea id="svc-<?php echo support_h($field['key']); ?>" name="<?php echo support_h($name); ?>" rows="4" maxlength="2000"<?php echo $field['required'] ? ' required' : ''; ?>><?php echo support_h($value); ?></textarea>
<?php elseif ($field['type'] === 'select'): ?>
        <select id="svc-<?php echo support_h($field['key']); ?>" name="<?php echo support_h($name); ?>"<?php echo $field['required'] ? ' required' : ''; ?>>
          <option value=""></option>
<?php foreach ($field['options'] as $option): ?>
          <option value="<?php echo support_h($option); ?>"<?php echo $value === $option ? ' selected' : ''; ?>><?php echo support_h($option); ?></option>
<?php endforeach; ?>
        </select>
<?php else: ?>
        <input type="text" id="svc-<?php echo support_h($field['key']); ?>" name="<?php echo support_h($name); ?>" maxlength="2000" value="<?php echo support_h($value); ?>"<?php echo $field['required'] ? ' required' : ''; ?>>
<?php endif; ?>
      </div>
<?php endforeach; ?>
      <div class="form-group">
        <label for="svc-message">Anything else we should know? <span class="muted">(optional)</span></label>
        <textarea id="svc-message" name="message" rows="4" maxlength="<?php echo SUPPORT_TICKET_MESSAGE_MAX; ?>"><?php echo support_h($message); ?></textarea>
      </div>
      <button type="submit" class="gsw-btn"><?php echo ($selected['price_mode'] === 'fixed' && (float)$selected['price'] > 0) ? 'Order and go to cart' : 'Send request'; ?></button>
    </form>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if (!$ready || !$types): ?>
  <section class="panel"><p><?php echo billing_text_e('services.empty'); ?> <a href="<?php echo support_h(billing_url('support.php')); ?>">Open a support ticket</a>.</p></section>
<?php else: ?>
  <section class="panel">
    <div class="account-info-grid">
<?php foreach ($types as $type): ?>
      <article class="account-section">
        <h2><?php echo support_h($type['name']); ?></h2>
        <p><?php echo support_h($type['summary']); ?></p>
        <p class="muted"><?php echo support_h($priceLine($type)); ?></p>
        <p><a class="gsw-btn" href="<?php echo support_h(billing_url('services.php') . '?service=' . urlencode($type['slug']) . '#order'); ?>">Order</a></p>
      </article>
<?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php $steps = billing_text_list('services.how_steps'); if ($steps): ?>
  <section class="panel">
    <h2><?php echo billing_text_e('services.how_title', 'How it works'); ?></h2>
    <ol>
<?php foreach ($steps as $step): ?>
      <li><?php echo support_h($step); ?></li>
<?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>

<?php if ($myRequests): ?>
  <section class="panel" id="my-requests">
    <h2>Your requests</h2>
    <table class="gsw-table">
      <thead><tr><th>Request</th><th>Status</th><th>Price</th><th></th></tr></thead>
      <tbody>
<?php foreach ($myRequests as $row): ?>
        <tr>
          <td>#<?php echo (int)$row['request_id']; ?> <?php echo support_h($row['type_name'] ?? 'Service'); ?></td>
          <td><?php echo support_h(billing_service_status_label((string)$row['status'])); ?></td>
          <td><?php echo $row['total_price'] !== null ? support_h(billing_service_money($row['total_price'])) : '&mdash;'; ?></td>
          <td>
<?php if (!empty($row['open_invoice_id'])): ?><a href="<?php echo support_h(billing_url('cart.php')); ?>">Pay invoice</a> · <?php endif; ?>
<?php if ((int)$row['tid'] > 0): ?><a href="<?php echo support_h(billing_url('support.php') . '#ticket-' . (int)$row['tid']); ?>">Open ticket</a><?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
