<?php
/*
 * Try AI: shows what AI Assist does, with questions to pick. A pick signs the visitor in to the
 * panel's demo account (demo_login.php) and asks it there, on the real AI Assist. Product
 * questions open the product chat; the others open AI Assist on the demo game server.
 * The panel's address comes from panel_base in site_config.php. The questions are the panel's
 * own (modules/assistant/assistant_suggestions.php), so the page and the panel never disagree.
 */
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/billing_bootstrap.php';
require_once dirname(__DIR__) . '/assistant/assistant_suggestions.php';

function ai_e($v)
{
	return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Link that signs in to the demo and asks one question (to = product or server).
function ai_demo_link($to, $question = '')
{
	$query = array('to' => $to);
	if ($question !== '') {
		$query['q'] = $question;
	}
	return panel_home_url() . 'demo_login.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Try AI Assist - <?php echo billing_brand_e('name'); ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo ai_e(billing_url('images/brand/favicon-32x32.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php
include(__DIR__ . '/includes/top.php');
include(__DIR__ . '/includes/menu.php');
?>
<main>
  <section class="gsw-section">
    <span class="gsw-section-eyebrow">AI Assist</span>
    <h1 class="gsw-section-title">Try AI Assist</h1>
    <p class="gsw-section-copy">Pick a question and AI Assist answers it in the demo panel, on a real game server. It reads the server's logs and files, and it proposes changes for you to apply. It never changes anything by itself.</p>
  </section>

  <section class="gsw-section">
    <div class="gsw-grid">
      <div class="gsw-feature-card">
        <h3>Ask about GameServer Engine</h3>
        <p>What it does, the games it runs, what it needs and how to sell game servers with it.</p>
        <div class="gsw-cta-row">
<?php foreach (assistant_product_suggestions() as $question): ?>
          <a class="gsw-btn-secondary" href="<?php echo ai_e(ai_demo_link('product', $question)); ?>"><?php echo ai_e($question); ?></a>
<?php endforeach; ?>
        </div>
      </div>
      <div class="gsw-feature-card">
        <h3>Try it on the demo server</h3>
        <p>An Urban Terror server in the demo panel. Ask what a customer would ask about their own server.</p>
        <div class="gsw-cta-row">
<?php foreach (assistant_server_suggestions() as $question): ?>
          <a class="gsw-btn-secondary" href="<?php echo ai_e(ai_demo_link('server', $question)); ?>"><?php echo ai_e($question); ?></a>
<?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="gsw-section">
    <h2 class="gsw-section-title">Or look around the demo panel</h2>
    <p class="gsw-section-copy">One click signs you in to a shared demo account. Start, stop and restart its game server, read its log and ask AI Assist your own questions. Everyone shares the account, so it cannot change settings, passwords or files.</p>
    <div class="gsw-cta-row">
      <a class="gsw-btn" href="<?php echo ai_e(ai_demo_link('monitor')); ?>">Open the demo panel</a>
      <a class="gsw-btn-secondary" href="<?php echo ai_e(ai_demo_link('product')); ?>">Ask your own question</a>
    </div>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
