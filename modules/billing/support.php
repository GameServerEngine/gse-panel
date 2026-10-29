<?php require_once(__DIR__ . '/billing_bootstrap.php'); require_once(__DIR__ . '/includes/session_bridge.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Support - GameServers.World</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow">Hosting Support</p>
  <h1>Support</h1>
  <p>Use this page for help with GameServers.World orders, hosted servers, account access, billing, renewals, and service status. The service is managed by developers and gamers at Run Level Systems.</p>

  <div class="account-info-grid">
    <section class="account-section">
      <h2>Existing Customers</h2>
      <p>Sign in to review your account, orders, invoices, and servers.</p>
      <p><a class="gsw-btn" href="<?php echo htmlspecialchars(billing_url('my_account.php'), ENT_QUOTES, 'UTF-8'); ?>">Open My Account</a></p>
    </section>
    <section class="account-section">
      <h2>Server Status</h2>
      <p>Check public service status before opening a support request.</p>
      <p><a class="gsw-btn-secondary" href="<?php echo htmlspecialchars(billing_url('server_status.php'), ENT_QUOTES, 'UTF-8'); ?>">Server Status</a></p>
    </section>
  </div>

  <section class="panel">
    <h2>Contact Support</h2>
    <p>Email <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a> or call <a href="tel:13307868223">330-786-8223</a>.</p>
    <p>Include your account email, order or invoice number, game title, server name, screenshots, logs, and recent changes when available.</p>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
