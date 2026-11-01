<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php
require_once(__DIR__ . '/bootstrap.php');

$publicLocations = [
    'Kansas City',
    'Los Angeles',
    'New York City',
    'Dallas',
    'Ireland',
];

function gsw_home_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Rent hosted game servers for communities, modders, clans, private groups, and public servers through <?php echo billing_brand_e('name'); ?>.">
    <title><?php echo billing_brand_e('name'); ?> - Game Server Hosting</title>
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo gsw_home_e(billing_url('images/brand/favicon-16x16.png')); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo gsw_home_e(billing_url('images/brand/favicon-32x32.png')); ?>">
    <link rel="apple-touch-icon" href="<?php echo gsw_home_e(billing_url('images/brand/apple-touch-icon.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); ?>
<?php include(__DIR__ . '/includes/menu.php'); ?>

<section class="gsw-supported-games-banner" aria-label="Supported game servers">
  <div class="gsw-supported-games-inner">
    <a class="gsw-supported-game-card" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">
      <img src="<?php echo gsw_home_e(panel_asset_url('images/games/day_z.jpg')); ?>" alt="" loading="eager">
      <span>DayZ</span>
    </a>
    <a class="gsw-supported-game-card" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">
      <img src="<?php echo gsw_home_e(panel_asset_url('images/games/rust_header.jpg')); ?>" alt="" loading="eager">
      <span>Rust</span>
    </a>
    <a class="gsw-supported-game-card" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">
      <img src="<?php echo gsw_home_e(panel_asset_url('images/games/minecraft.jpg')); ?>" alt="" loading="eager">
      <span>Minecraft</span>
    </a>
    <a class="gsw-supported-game-card" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">
      <img src="<?php echo gsw_home_e(panel_asset_url('images/games/cstrikesource.jpg')); ?>" alt="" loading="eager">
      <span>Counter-Strike Source</span>
    </a>
    <a class="gsw-supported-game-card" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">
      <img src="<?php echo gsw_home_e(panel_asset_url('images/games/arma2.jpg')); ?>" alt="" loading="eager">
      <span>Arma 2</span>
    </a>
  </div>
</section>

<main>
  <section class="gsw-hero-shell" aria-labelledby="home-title">
    <div class="gsw-hero gsw-hero-simple">
      <div>
        <p class="gsw-section-eyebrow">Game Server Hosting</p>
        <h1 id="home-title">Reliable game server hosting with real human help.</h1>
        <p class="gsw-hero-lede">Rent servers for communities, modded worlds, older titles, and unusual setups with practical support from the team that builds and operates the platform.</p>
        <div class="gsw-hero-actions" aria-label="Primary actions">
          <a class="gsw-btn" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">Browse Game Servers</a>
          <a class="gsw-btn-secondary" href="<?php echo gsw_home_e(billing_url('support.php')); ?>">Talk to the Team</a>
        </div>
      </div>
    </div>
  </section>

  <section class="gsw-trust-strip" aria-label="Hosting priorities">
    <div class="gsw-trust-item"><strong>Human Support</strong><span>Work with real people who run and build game-server systems.</span></div>
    <div class="gsw-trust-item"><strong>Competitive Pricing</strong><span>Straightforward hosting options priced to stay practical.</span></div>
    <div class="gsw-trust-item"><strong>Custom Help</strong><span>Ask for configuration or development help when a normal package is not enough.</span></div>
    <div class="gsw-trust-item"><strong>Community Ready</strong><span>Support for modded servers, older titles, private groups, and public communities.</span></div>
  </section>

  <section class="gsw-section" aria-labelledby="pillars-title">
    <p class="gsw-section-eyebrow">Why <?php echo billing_brand_e('name'); ?></p>
    <h2 class="gsw-section-title" id="pillars-title">Practical hosting without the runaround.</h2>
    <p class="gsw-section-copy">Pick a supported game, keep costs reasonable, and get help from people who understand real server operations.</p>
    <div class="gsw-grid mt-20">
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Support</span>
        <h3>People you can reach</h3>
        <p>Get direct help with ordering, setup questions, and server issues instead of being pushed through generic scripts.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Pricing</span>
        <h3>Competitive rates</h3>
        <p>Hosting should fit the size of the group and the game, with lower-cost options where they make sense.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Flexibility</span>
        <h3>Custom setup help</h3>
        <p>For mod packs, older games, unusual configs, or community-specific needs, talk to the team before you settle.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Experience</span>
        <h3>Built by server operators</h3>
        <p><?php echo billing_brand_e('name'); ?> is operated by the same team behind GameServer Engine, so hosting decisions stay grounded in real use.</p>
      </article>
    </div>
  </section>

  <section class="gsw-section" aria-labelledby="whatsincluded-title">
    <p class="gsw-section-eyebrow">What's Included</p>
    <h2 class="gsw-section-title" id="whatsincluded-title">Everything you need to run a game server, in one panel.</h2>
    <p class="gsw-section-copy">Every plan includes full control over your game server, not just a box it runs on.</p>
    <div class="gsw-grid mt-20">
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Ports</span>
        <h3>Game, query, and RCON ports</h3>
        <p>Your server's game, query, and admin (RCON) ports are set up for you and shown in the panel, ready to share with players.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Mods</span>
        <h3>Steam Workshop support</h3>
        <p>Install and manage Steam Workshop mods and collections for supported games directly from the panel.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Automation</span>
        <h3>Scheduled restarts and tasks</h3>
        <p>Schedule restarts, backups, and other routine tasks to run automatically on the times that work for your community.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Files</span>
        <h3>FTP file access</h3>
        <p>Connect over FTP to manage configs, maps, and mod files directly, whenever you need hands-on access.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">Safety Net</span>
        <h3>Backups</h3>
        <p>Back up your server so a bad config change or mod update is never more than a restore away.</p>
      </article>
      <article class="gsw-feature-card">
        <span class="gsw-card-kicker">New</span>
        <h3>AI assistant in the panel</h3>
        <p>The panel includes an AI assistant that answers configuration questions in plain language and helps you find and understand the settings for your game server. It's a guide alongside the panel's own tools, not a replacement for reviewing changes yourself before you save them.</p>
        <p><a href="<?php echo gsw_home_e(billing_url('ai.php')); ?>">Try the assistant</a></p>
      </article>
    </div>
  </section>

  <section class="gsw-section" aria-labelledby="locations-title">
    <p class="gsw-section-eyebrow">Current Hosting Locations</p>
    <h2 class="gsw-section-title" id="locations-title">Choose from active service regions.</h2>
    <p class="gsw-section-copy">Current locations are listed below. Exact game availability may vary by location.</p>
    <ul class="gsw-location-list" aria-label="Current hosting locations">
      <?php foreach ($publicLocations as $location): ?>
        <li><?php echo gsw_home_e($location); ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="gsw-section" aria-labelledby="powered-title">
    <div class="gsw-callout-panel gsw-powered">
      <p class="gsw-section-eyebrow">Built On Experience</p>
      <h2 class="gsw-section-title" id="powered-title">Powered by GameServer Engine.</h2>
      <p class="gsw-section-copy"><?php echo billing_brand_e('name'); ?> is operated by the team behind GameServer Engine, with the same practical focus on reliable game-server operations.</p>
      <p class="gsw-low-emphasis-link"><a href="<?php echo gsw_home_e(billing_url('docs.php')); ?>">Browse hosting documentation</a></p>
    </div>
  </section>

  <section class="gsw-section" aria-labelledby="final-title">
    <div class="gsw-callout-panel gsw-final-cta">
      <p class="gsw-section-eyebrow">Ready To Rent</p>
      <h2 id="final-title">Start with the catalog or talk through the setup.</h2>
      <p class="gsw-section-copy gsw-final-copy">Browse available game servers, or contact support if your community needs custom configuration or development help.</p>
      <div class="gsw-hero-actions gsw-actions-center">
        <a class="gsw-btn" href="<?php echo gsw_home_e(billing_url('serverlist.php')); ?>">Browse Game Servers</a>
        <a class="gsw-btn-secondary" href="<?php echo gsw_home_e(billing_url('support.php')); ?>">Talk to the Team</a>
      </div>
    </div>
  </section>
</main>

<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
