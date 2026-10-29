<?php require_once(__DIR__ . '/billing_bootstrap.php'); require_once(__DIR__ . '/includes/session_bridge.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Terms of Service - GameServers.World</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow">GameServers.World</p>
  <h1>Terms of Service</h1>
  <p class="muted">Last updated: July 2026</p>

  <p>These terms apply to the GameServers.World storefront and game-server hosting services operated by Run Level Systems.</p>

  <h2>Who We Are</h2>
  <p>GameServers.World provides game server hosting, related account tools, billing workflows, documentation, and best-effort support.</p>

  <h2>What You Are Buying</h2>
  <p>You are renting server resources and related service access for supported games, mods, and server configurations. Specific plan details, duration, price, and renewal terms are shown during ordering or in your account.</p>

  <h2>Billing and Renewals</h2>
  <p>Invoices, service status, renewals, and account records are handled through the storefront and account pages. Keep your account email current so service notices can reach you.</p>

  <h2>Acceptable Use</h2>
  <p>Services may not be used for illegal activity, DDoS activity, malware, unauthorized access, harassment, fraud, crypto mining, abuse, or activity that harms the network, other customers, or upstream providers.</p>

  <h2>Game and Mod Licenses</h2>
  <p>You are responsible for complying with applicable game, mod, plugin, and content licenses or EULAs for anything you upload, configure, or run.</p>

  <h2>Support</h2>
  <p>Support is provided on a best-effort basis. Include logs, order information, server name, game title, and recent changes when asking for help.</p>

  <h2>Backups and Data</h2>
  <p>Backups, where available, are a best-effort safeguard and not a guaranteed recovery system. Keep your own copies of important files and configurations.</p>

  <h2>Suspension and Termination</h2>
  <p>Services may be suspended or terminated for nonpayment, abuse, legal risk, security risk, or violation of these terms. We may act immediately when urgent network, legal, or safety issues are involved.</p>

  <h2>Limitation of Liability</h2>
  <p>Technology services can fail. To the maximum extent allowed by law, Run Level Systems is not responsible for indirect, incidental, or consequential losses.</p>

  <h2>Contact</h2>
  <p>
    Run Level Systems<br>
    3400 Laurel Rd., Brunswick, OH 44212<br>
    Phone: <a href="tel:13307868223">330-786-8223</a><br>
    Email: <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a>
  </p>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
