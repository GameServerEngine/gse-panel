<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/content.php');

function gsw_home_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** One card of the home page card grids. */
function gsw_home_card(array $card): void
{
    echo '      <article class="gsw-feature-card">' . "\n";
    if (!empty($card['kicker'])) {
        echo '        <span class="gsw-card-kicker">' . gsw_home_e($card['kicker']) . '</span>' . "\n";
    }
    echo '        <h3>' . gsw_home_e($card['title'] ?? '') . '</h3>' . "\n";
    echo '        <p>' . gsw_home_e($card['text'] ?? '') . '</p>' . "\n";
    if (!empty($card['link'])) {
        echo '        <p><a href="' . gsw_home_e(billing_url($card['link'])) . '">' . gsw_home_e($card['link_label'] ?? 'Learn more') . '</a></p>' . "\n";
    }
    echo '      </article>' . "\n";
}

$games = billing_text_list('home.games');
$trust = billing_text_list('home.trust');
$whyCards = billing_text_list('home.why.cards');
$includedCards = billing_text_list('home.included.cards');
$locations = billing_text_list('home.locations.list');
$showServices = billing_text('home.services.enabled') === '1' && is_file(__DIR__ . '/services.php');
$secondaryTarget = $showServices && billing_text('home.hero.secondary') === 'See Services' ? 'services.php' : 'support.php';
$ctaSecondaryTarget = $showServices && billing_text('home.cta.secondary') === 'See Services' ? 'services.php' : 'support.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo billing_text_e('home.meta_description'); ?>">
    <title><?php echo billing_brand_e('name'); ?> - <?php echo billing_text_e('home.title_suffix'); ?></title>
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo gsw_home_e(billing_url('images/brand/favicon-16x16.png')); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo gsw_home_e(billing_url('images/brand/favicon-32x32.png')); ?>">
    <link rel="apple-touch-icon" href="<?php echo gsw_home_e(billing_url('images/brand/apple-touch-icon.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); ?>
<?php $GLOBALS['gsw_defer_content_open'] = true; include(__DIR__ . '/includes/menu.php'); ?>

<div class="gsw-home-band">
<div class="gsw-home-band-inner">
<?php if ($games): ?>
<section class="gsw-supported-games-banner" aria-label="Supported game servers">
  <div class="gsw-supported-games-inner">
<?php foreach ($games as $game): ?>
    <a class="gsw-supported-game-card" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">
      <img src="<?php echo gsw_home_e(billing_url($game['image'] ?? '')); ?>" alt="<?php echo gsw_home_e($game['name'] ?? ''); ?>" loading="eager">
    </a>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

  <section class="gsw-hero-shell" aria-labelledby="home-title">
    <div class="gsw-hero gsw-hero-simple">
      <div>
        <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.hero.eyebrow'); ?></p>
        <h1 id="home-title"><?php echo billing_text_e('home.hero.title'); ?></h1>
        <p class="gsw-hero-lede"><?php echo billing_text_e('home.hero.lede'); ?></p>
        <div class="gsw-hero-actions" aria-label="Primary actions">
          <a class="gsw-btn" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>"><?php echo billing_text_e('home.hero.primary'); ?></a>
          <a class="gsw-btn-secondary" href="<?php echo gsw_home_e(billing_url($secondaryTarget)); ?>"><?php echo billing_text_e('home.hero.secondary'); ?></a>
        </div>
      </div>
    </div>
  </section>
</div>
</div>

<?php billing_open_content(); ?>
<main>

<?php if ($trust): ?>
  <section class="gsw-trust-strip" aria-label="Why choose us">
<?php foreach ($trust as $item): ?>
    <div class="gsw-trust-item"><strong><?php echo gsw_home_e($item['title'] ?? ''); ?></strong><span><?php echo gsw_home_e($item['text'] ?? ''); ?></span></div>
<?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($whyCards): ?>
  <section class="gsw-section" aria-labelledby="pillars-title">
    <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.why.eyebrow'); ?></p>
    <h2 class="gsw-section-title" id="pillars-title"><?php echo billing_text_e('home.why.title'); ?></h2>
    <p class="gsw-section-copy"><?php echo billing_text_e('home.why.copy'); ?></p>
    <div class="gsw-grid mt-20">
<?php foreach ($whyCards as $card) { gsw_home_card($card); } ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($includedCards): ?>
  <section class="gsw-section" aria-labelledby="whatsincluded-title">
    <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.included.eyebrow'); ?></p>
    <h2 class="gsw-section-title" id="whatsincluded-title"><?php echo billing_text_e('home.included.title'); ?></h2>
    <p class="gsw-section-copy"><?php echo billing_text_e('home.included.copy'); ?></p>
    <div class="gsw-grid mt-20">
<?php foreach ($includedCards as $card) { gsw_home_card($card); } ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($showServices): ?>
  <section class="gsw-section" aria-labelledby="services-title">
    <div class="gsw-callout-panel">
      <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.services.eyebrow'); ?></p>
      <h2 class="gsw-section-title" id="services-title"><?php echo billing_text_e('home.services.title'); ?></h2>
      <p class="gsw-section-copy"><?php echo billing_text_e('home.services.copy'); ?></p>
      <p><a class="gsw-btn-secondary" href="<?php echo gsw_home_e(billing_url('services.php')); ?>"><?php echo billing_text_e('home.services.link_label'); ?></a></p>
    </div>
  </section>
<?php endif; ?>

<?php if ($locations): ?>
  <section class="gsw-section" aria-labelledby="locations-title">
    <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.locations.eyebrow'); ?></p>
    <h2 class="gsw-section-title" id="locations-title"><?php echo billing_text_e('home.locations.title'); ?></h2>
    <p class="gsw-section-copy"><?php echo billing_text_e('home.locations.copy'); ?></p>
    <ul class="gsw-location-list" aria-label="Hosting locations">
      <?php foreach ($locations as $location): ?>
        <li><?php echo gsw_home_e($location); ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if (billing_text('home.about.title') !== ''): ?>
  <section class="gsw-section" aria-labelledby="about-title">
    <div class="gsw-callout-panel gsw-powered">
      <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.about.eyebrow'); ?></p>
      <h2 class="gsw-section-title" id="about-title"><?php echo billing_text_e('home.about.title'); ?></h2>
      <p class="gsw-section-copy"><?php echo billing_text_e('home.about.copy'); ?></p>
<?php if (billing_text('home.about.link_label') !== ''): ?>
      <p class="gsw-low-emphasis-link"><a href="<?php echo gsw_home_e(billing_url('docs.php')); ?>"><?php echo billing_text_e('home.about.link_label'); ?></a></p>
<?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <section class="gsw-section" aria-labelledby="final-title">
    <div class="gsw-callout-panel gsw-final-cta">
      <p class="gsw-section-eyebrow"><?php echo billing_text_e('home.cta.eyebrow'); ?></p>
      <h2 id="final-title"><?php echo billing_text_e('home.cta.title'); ?></h2>
      <p class="gsw-section-copy gsw-final-copy"><?php echo billing_text_e('home.cta.copy'); ?></p>
      <div class="gsw-hero-actions gsw-actions-center">
        <a class="gsw-btn" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>"><?php echo billing_text_e('home.cta.primary'); ?></a>
        <a class="gsw-btn-secondary" href="<?php echo gsw_home_e(billing_url($ctaSecondaryTarget)); ?>"><?php echo billing_text_e('home.cta.secondary'); ?></a>
      </div>
    </div>
  </section>
</main>

<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
