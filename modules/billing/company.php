<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php require_once __DIR__ . '/includes/content.php'; ?>
<?php require_once(__DIR__ . '/billing_bootstrap.php'); require_once(__DIR__ . '/includes/session_bridge.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Company - <?php echo billing_brand_e('name'); ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<?php
  $contactLines = billing_text_list('contact.address_lines');
  $contactPhone = billing_text('contact.phone');
  $contactEmail = billing_brand('email');
  $phoneHref = preg_replace('/[^0-9+]/', '', $contactPhone);
?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow">Company</p>
  <h1>Company</h1>
  <p><?php echo billing_text_e('company.intro'); ?></p>

  <section class="panel">
    <h2>Contact</h2>
    <p>
<?php foreach ($contactLines as $line): ?>
      <?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?><br>
<?php endforeach; ?>
<?php if ($contactPhone !== ''): ?>
      Phone: <a href="tel:<?php echo htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8'); ?></a><br>
<?php endif; ?>
      Email: <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?></a>
    </p>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
