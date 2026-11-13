<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php require_once __DIR__ . '/includes/content.php'; ?>
<?php require_once(__DIR__ . '/billing_bootstrap.php'); require_once(__DIR__ . '/includes/session_bridge.php'); ?>
<?php if (billing_text('careers.enabled') === '') { header('Location: ' . billing_url('company.php')); exit; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Careers - <?php echo billing_brand_e('name'); ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow"><?php echo billing_brand_e('company'); ?></p>
  <h1>Careers</h1>
  <p>There are no public <?php echo billing_brand_e('name'); ?> openings at this time.</p>

  <section class="panel">
    <h2>Future Opportunities</h2>
    <p><?php echo billing_brand_e('company'); ?> welcomes resumes for future software engineering, DevOps, AI engineering, infrastructure, web development, hosting operations, and game technology positions.</p>
    <p>Email a resume or professional introduction to <a href="mailto:<?php echo billing_brand_e('email'); ?>"><?php echo billing_brand_e('email'); ?></a>.</p>
  </section>

  <section class="panel mt-20">
    <h2>Equal Opportunity</h2>
    <p><?php echo billing_brand_e('company'); ?> is committed to equal opportunity. Employment decisions are based on qualifications, capability, business needs, and applicable law.</p>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
