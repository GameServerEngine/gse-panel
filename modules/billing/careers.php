<?php require_once(__DIR__ . '/billing_bootstrap.php'); require_once(__DIR__ . '/includes/session_bridge.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Careers - GameServers.World</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow">Run Level Systems</p>
  <h1>Careers</h1>
  <p>There are no public GameServers.World openings at this time.</p>

  <section class="panel">
    <h2>Future Opportunities</h2>
    <p>Run Level Systems welcomes resumes for future software engineering, DevOps, AI engineering, infrastructure, web development, hosting operations, and game technology positions.</p>
    <p>Email a resume or professional introduction to <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a>.</p>
  </section>

  <section class="panel mt-20">
    <h2>Equal Opportunity</h2>
    <p>Run Level Systems is committed to equal opportunity. Employment decisions are based on qualifications, capability, business needs, and applicable law.</p>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
