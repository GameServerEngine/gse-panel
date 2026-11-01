<?php require_once(__DIR__ . '/billing_bootstrap.php'); require_once(__DIR__ . '/includes/session_bridge.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contact - <?php echo billing_brand_e('name'); ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow"><?php echo billing_brand_e('company'); ?></p>
  <h1>Contact</h1>
  <p>Contact <?php echo billing_brand_e('company'); ?> for <?php echo billing_brand_e('name'); ?> support, account questions, billing questions, or hosting inquiries.</p>

  <section class="panel">
    <h2>Contact Information</h2>
    <p>
      <?php echo billing_brand_e('company'); ?><br>
      3400 Laurel Rd.<br>
      Brunswick, OH 44212<br>
      Phone: <a href="tel:13307868223">330-786-8223</a><br>
      Email: <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a>
    </p>
    <p>For faster support, include your account email, server name, invoice or order number, game title, and the last change made before the issue started.</p>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
