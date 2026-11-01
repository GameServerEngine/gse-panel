<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/catalog_storefront.php');

function billing_service_price_is_free($value): bool
{
    return ((int) round(((float)$value) * 100)) === 0;
}

function billing_detect_variant_label(array $row): string
{
    $haystack = strtolower(trim((string)($row['home_cfg_file'] ?? $row['game_key'] ?? '')));
    if ($haystack === '') {
        return '';
    }
    if (preg_match('/(?:^|[_\-])(win|windows)(?:[_\-]|$)/i', $haystack)) {
        return 'Windows';
    }
    if (preg_match('/(?:^|[_\-])linux(?:[_\-]|$)/i', $haystack)) {
        return 'Linux';
    }
    return '';
}

function billing_serverlist_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function billing_serverlist_excerpt(string $value): string
{
    $value = trim(strip_tags(str_replace('<br>', ' ', $value)));
    $value = preg_replace('/\s+/', ' ', $value);
    if ($value === '') {
        return 'Configured through the ' . billing_brand('name') . ' billing catalog.';
    }
    return strlen($value) > 170 ? rtrim(substr($value, 0, 167)) . '...' : $value;
}

$gameRows = [];
$catalogError = '';
$db = billing_get_db();

if ($db instanceof mysqli) {
    try {
        $gameRows = catalog_load_enabled_games($db, $table_prefix);
    } catch (Throwable $e) {
        $catalogError = 'The catalog could not be loaded from the current billing schema.';
    }
} else {
    $catalogError = 'The game-server catalog is temporarily unavailable because the storefront database is not configured in this environment.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Server Catalog - <?php echo billing_brand_e('name'); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo billing_serverlist_e(billing_url('images/brand/favicon-32x32.png')); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); ?>
<?php include(__DIR__ . '/includes/menu.php'); ?>

<main class="gsw-section" aria-labelledby="catalog-title">
  <p class="gsw-section-eyebrow">Game Server Catalog</p>
  <h1 class="gsw-section-title" id="catalog-title">Browse available game servers.</h1>
  <p class="gsw-section-copy">Choose a supported title, compare slot and price details, and configure the server that fits your community.</p>

  <?php if ($catalogError !== ''): ?>
    <section class="gsw-callout-panel mt-20">
      <h2>Catalog Unavailable</h2>
      <p><?php echo billing_serverlist_e($catalogError); ?></p>
      <div class="gsw-cta-row">
        <a class="gsw-btn-secondary" href="<?php echo billing_serverlist_e(billing_url('support.php')); ?>">Contact Support</a>
        <a class="gsw-btn-secondary" href="<?php echo billing_serverlist_e(billing_url('docs.php')); ?>">Browse Documentation</a>
      </div>
    </section>
  <?php elseif (empty($gameRows)): ?>
    <section class="gsw-callout-panel mt-20">
      <h2>No Published Services</h2>
      <p>No enabled game-server products are currently published in the billing catalog.</p>
    </section>
  <?php else: ?>
    <section class="gsw-catalog-grid" aria-label="Available game servers">
      <?php foreach ($gameRows as $row): ?>
        <?php
          $serviceDisplayName = (string)($row['game_name'] ?? 'Game Server');
          $variantLabel = billing_detect_variant_label($row);
          if ($variantLabel !== '' && stripos($serviceDisplayName, $variantLabel) === false) {
              $serviceDisplayName .= ' - ' . $variantLabel;
          }
          $startingPrice = $row['starting_price_monthly'] ?? null;
          $priceLabel = ($startingPrice === null)
              ? 'See pricing options'
              : (billing_service_price_is_free($startingPrice) ? 'Free setup option' : 'From $' . number_format((float)$startingPrice, 2) . ' monthly');
          $slots = '';
          if (!empty($row['slot_min_qty']) || !empty($row['slot_max_qty'])) {
              $slots = (int)($row['slot_min_qty'] ?? 0) . '-' . (int)($row['slot_max_qty'] ?? 0) . ' slots';
          }
        ?>
        <article class="gsw-catalog-card">
          <h2><?php echo billing_serverlist_e($serviceDisplayName); ?></h2>
          <p><?php echo billing_serverlist_e(billing_serverlist_excerpt('')); ?></p>
          <div class="gsw-catalog-meta" aria-label="Server details">
            <?php if ($slots !== ''): ?><span><?php echo billing_serverlist_e($slots); ?></span><?php endif; ?>
            <span><?php echo billing_serverlist_e($priceLabel); ?></span>
          </div>
          <div class="gsw-card-actions">
            <a href="<?php echo billing_serverlist_e(billing_url('order.php?home_cfg_id=' . urlencode((string)$row['home_cfg_id']))); ?>" class="gsw-catalog-action">Configure Server</a>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>

<?php billing_maybe_close_db($db); ?>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
