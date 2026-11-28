<?php require_once __DIR__ . '/includes/brand.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Server - <?php echo billing_brand_e('name'); ?></title>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
    <style>
        /* Order-only rules. Page, button, form and panel styles come from css/header.css. */
        .order-layout { display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap; }
        .order-media { flex: 0 1 310px; max-width: 100%; }
        .order-media img { width: 100%; max-width: 280px; height: auto; border-radius: var(--radius); display: block; }
        .order-media-title { margin: 10px 0 6px; text-align: center; }
        .order-media-desc { color: var(--muted); max-width: 100%; word-break: break-word; }
        .order-form-card { flex: 1 1 500px; max-width: 100%; background: var(--page); }
        .order-form-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .order-form-table td { padding: 8px 6px; vertical-align: top; }
        .order-form-table td:first-child { width: 34%; font-weight: 650; }
        .order-form-table input[type="text"],
        .order-form-table input[type="number"],
        .order-form-table select,
        .order-form-table textarea { width: 100%; min-height: 40px; }
        .order-form-table input[type="radio"] { margin-right: 8px; }
        .location-option { margin-bottom: 8px; }
        .location-unavailable { color: var(--muted, #777); }
        .content-picker summary { cursor: pointer; font-weight: 600; }
        .content-list { margin: 8px 0 0; padding-left: 18px; }
        .content-list li { margin-bottom: 6px; }
        .slidecontainer { max-width: 100%; }
        .slidecontainer .slider { width: 100%; }
        .order-pricing { line-height: 1.5; word-break: break-word; }
        .order-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .order-back-form { margin: 0; }
        .tier-option { display: inline-block; margin: 0 10px 8px 0; }
        .offer-pricing-fields { display: none; }
        .offer-pricing-fields.offer-pricing-active { display: block; }
        @media (max-width: 768px) {
            .order-layout { gap: 12px; }
            .order-media { flex: 1 1 100%; display: flex; flex-direction: column; align-items: center; }
            .order-media img { max-width: min(100%, 260px); }
            .order-form-card { flex: 1 1 100%; padding: 10px; }
            .order-form-table,
            .order-form-table tbody,
            .order-form-table tr,
            .order-form-table td { display: block; width: 100%; }
            .order-form-table td:first-child { width: 100%; padding-bottom: 2px; }
            .order-form-table td { padding: 6px 4px; }
            .order-actions { flex-direction: column; }
            .order-actions .gsw-btn,
            .order-actions .gsw-btn-secondary { width: 100%; }
        }
    </style>
</head>
<body>
<?php

/*
This is the "order gameserver" page. It lists the enabled catalog locations for one game and has
the "Add to Cart" button. The game is selected from the serverlist page by a GET of home_cfg_id.
When the user clicks "Add to Cart", the next page is add_to_cart.php.

Every price shown here is informational only — add_to_cart.php recomputes the authoritative total
on the server from the live catalog offer before creating the invoice. The browser never sets a
price; it only sends offer_id, period, quantity, and (depending on pricing mode) slots or a tier key.
*/

require_once(__DIR__ . '/includes/login_required.php');
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/includes/configuration_quote.php');

/** @var string $table_prefix Table prefix for database tables */

$db = billing_get_db();
if (!($db instanceof mysqli)) {
    die("Connection failed.");
}

include(__DIR__ . '/includes/top.php');
include(__DIR__ . '/includes/menu.php');

function order_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function order_price_is_free($value): bool
{
    return ((int) round(((float)$value) * 100)) === 0;
}

$homeCfgId = (int)($_REQUEST['home_cfg_id'] ?? 0);
$orderErrorMessage = isset($_GET['error_message']) ? trim((string)$_GET['error_message']) : '';

if ($homeCfgId <= 0) {
    // Fall back to listing every game with at least one enabled offer.
    $games = catalog_load_enabled_games($db, $table_prefix);
    ?>
    <div class="order-shell">
      <div class="clearfix">
      <?php foreach ($games as $g): ?>
        <div class="float-left p-30-20 order-catalog-item">
          <?php echo order_e($g['display_name']); ?><br>
          <a href="order.php?home_cfg_id=<?php echo (int)$g['home_cfg_id']; ?>" class="gsw-btn">Order Now</a>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php
    billing_maybe_close_db($db);
    include(__DIR__ . '/includes/footer.php');
    echo '</body></html>';
    exit;
}

$offers = catalog_load_offers_for_game($db, $table_prefix, $homeCfgId);

$gameRes = $db->query("SELECT game_name, game_key, home_cfg_file FROM {$table_prefix}config_homes WHERE home_cfg_id = " . (int)$homeCfgId . " LIMIT 1");
$gameRow = $gameRes ? $gameRes->fetch_assoc() : null;

if (!$gameRow || empty($offers)) {
    error_log("billing order.php: home_cfg_id={$homeCfgId} not found or has no enabled catalog offers");
    echo "<p class='error'>The requested game could not be found or is no longer available.</p>";
    echo "<p><a href='serverlist.php'>Back to server list</a></p>";
    billing_maybe_close_db($db);
    include(__DIR__ . '/includes/footer.php');
    echo '</body></html>';
    exit;
}

$gameDetails = catalog_load_game_details($db, $table_prefix, $homeCfgId);
$displayName = $gameDetails['display_name'] !== '' ? $gameDetails['display_name'] : (string)$gameRow['game_name'];
$imgSrc = billing_image_url($gameDetails['img_url']);
if ($imgSrc === '') { $imgSrc = '/images/games/default_server.png'; }

$is_logged_in = (isset($_SESSION['website_user_id']) && !empty($_SESSION['website_user_id'])) || (isset($_SESSION['website_username']) && !empty($_SESSION['website_username']));

$cities = storefront_load_cities($db, $table_prefix);
$minimums = array_filter(array_map(fn($o) => storefront_min_slots($db, $table_prefix, $o), $offers));
$minSlots = $minimums ? min($minimums) : 1;
$maxSlots = max(array_column($offers, 'slot_max_qty'));
$quote = storefront_quote($db, $table_prefix, $offers, $minSlots, $cities);
?>
<div class="order-shell">
<div class="order-layout">
<div class="order-media decorative-bottom">
<img src="<?php echo order_e($imgSrc); ?>" alt="<?php echo order_e($displayName); ?>"
     onerror="this.src='/images/games/default_server.png'; this.onerror=null;">
<center class="order-media-title"><b><?php echo order_e($displayName); ?></b></center>
<p class="order-media-desc"><?php echo $gameDetails['description'] !== '' ? order_e($gameDetails['description']) : 'Configured through the ' . billing_brand_e('name') . ' billing catalog.'; ?></p>
</div>
<div class="order-form-card">
<?php if ($orderErrorMessage !== ''): ?>
<p class="error"><?php echo order_e($orderErrorMessage); ?></p>
<?php endif; ?>
<form id="configuration" method="post" action="add_to_cart.php">
<table class="order-form-table">
    <input type="hidden" name="home_cfg_id" value="<?php echo (int)$homeCfgId; ?>">
    <input type="hidden" name="remote_control_password" value="">
    <input type="hidden" name="ftp_password" value="">

    <input type="hidden" name="tier_key" id="submitTierKey" value="">
    <input type="hidden" name="period" id="submitPeriod" value="monthly">
<tr>
<td align="right"><b>Game Server Name</b></td>
<td align="left">
<input type="text" name="home_name" size="40" value="<?php echo order_e($displayName); ?>">
</td>
</tr>
<?php
require_once __DIR__ . '/includes/subdomain_bridge.php';
$sdCtx = billing_sd_load($db, $table_prefix);
if ($sdCtx):
    $sdDomains = sd_all_domains($sdCtx[1]);
    $sdSharedDomains = sd_domains($sdCtx[1]);
    $sdNodeDomains = sd_node_domains($sdCtx[1]);
    $sdName = isset($_GET['sd_name']) ? preg_replace('/[^a-z0-9-]/', '', strtolower((string)$_GET['sd_name'])) : '';
?>
<tr>
<td align="right"><b>Free Server Address</b></td>
<td align="left">
<input type="text" id="sd_name" name="sd_name" maxlength="32" value="<?php echo order_e($sdName); ?>" autocomplete="off" placeholder="my-server (optional)" style="width:55%;display:inline-block;">
<select id="sd_domain" name="sd_domain" style="width:40%;display:inline-block;">
<?php foreach ($sdDomains as $sdD): ?>
<option value="<?php echo order_e($sdD); ?>" data-node="<?php echo in_array($sdD, $sdSharedDomains, true) ? 0 : (int)array_search($sdD, $sdNodeDomains, true); ?>">.<?php echo order_e($sdD); ?></option>
<?php endforeach; ?>
</select>
<div id="sd_status" style="min-height:1.4em;margin-top:4px;"></div>
<?php echo sd_live_check_js('subdomain_check.php?home_cfg_id=' . (int)$homeCfgId, 'sd_name', 'sd_domain', 'sd_status'); ?>
</td>
</tr>
<?php endif; ?>
<tr>
  <td align="right"><b>Location</b></td>
  <td align="left">
<?php
$first = true;
foreach (storefront_build_location_groups($offers, $cities) as $group): ?>
  <div class="location-region"><b><?php echo order_e($group['region']); ?></b></div>
  <?php foreach ($group['cities'] as $city):
      $cid = (int)$city['location_id'];
      $id = $quote['locations'][$cid];
  ?>
  <label class="location-option<?php if (!$id) echo ' location-unavailable'; ?>" style="display:block">
    <input type="radio" name="offer_id" data-city="<?php echo $cid; ?>" value="<?php echo $id; ?>"
       required <?php if (!$id) echo 'disabled'; elseif ($first) { echo 'checked'; $first = false; } ?>>
    <?php echo order_e($city['name']); ?> <span <?php if ($id) echo 'hidden'; ?>>&mdash; Unavailable</span>
  </label>
  <?php endforeach; ?>
<?php endforeach; ?>
  </td>
</tr>
<?php $gameContent = catalog_load_game_content($db, $table_prefix, (int)$homeCfgId); ?>
<?php if (!empty($gameContent)): ?>
<tr>
  <td align="right"><b>Server content</b></td>
  <td align="left">
    <details class="content-picker">
      <summary><?php echo count($gameContent); ?> item(s) available for one-click install</summary>
      <ul class="content-list">
<?php foreach ($gameContent as $contentItem): ?>
        <li><b><?php echo order_e($contentItem['name']); ?></b> <span class="muted">(<?php echo order_e($contentItem['type_label']); ?>)</span>
<?php if ($contentItem['description'] !== ''): ?>
          <br><span class="muted"><?php echo order_e($contentItem['description']); ?></span>
<?php endif; ?>
        </li>
<?php endforeach; ?>
      </ul>
      <p class="muted">Once your server is ready, install any of these from the Server Content page of your control panel.</p>
    </details>
  </td>
</tr>
<?php endif; ?>
<tr>
  <td align="right"><b>Configure</b></td>
  <td align="left">
     <label>Player slots <input type="number" name="slots" min="<?php echo $minSlots; ?>" max="<?php echo $maxSlots; ?>" value="<?php echo $minSlots; ?>" required></label>
     <center><b>Months</b></center>
     <input type="range" name="qty" min="1" max="24" value="1" class="slider" id="invoiceRange">

<p class="order-pricing" id="orderPricingSummary">Duration: <span id="invoiceDuration">1 month</span><br>
<span id="totalPrice">Calculating price…</span></p>
 <input type="hidden" name="invoice_duration" value="month" />
  </td>
</tr>

<?php
require_once __DIR__ . '/includes/order_options.php';
$orderOptions = order_options_active($db, $table_prefix);
if ($orderOptions):
?>
<tr>
<td align="right"><b>Extras</b></td>
<td align="left">
<?php foreach ($orderOptions as $opt): ?>
<div class="order-option" style="margin-bottom:8px;">
<label><input type="checkbox" name="options[]" value="<?php echo (int)$opt['option_id']; ?>" class="order-option-input" data-price="<?php echo number_format($opt['price'], 2, '.', ''); ?>"> <b><?php echo order_e($opt['title']); ?></b> &mdash; $<?php echo number_format($opt['price'], 2); ?> one time</label>
<?php if ($opt['description'] !== ''): ?><div class="muted" style="margin-left:22px;"><?php echo order_e($opt['description']); ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</td>
</tr>
<?php endif; ?>
<tr>
<td align="left" colspan="2">
<?php if ($is_logged_in): ?>
<div class="order-actions">
<button type="submit" name="add_to_cart" disabled class="gsw-btn">Add to Cart</button>
</div>
<?php else: ?>
<div class="login-placeholder">Please <a href="login.php">login</a> to order</div>
<?php endif; ?>
</td>
</tr>
</table>
</form>
<div class="order-actions"><a class="gsw-btn-secondary" href="serverlist.php">Back to List</a></div>
</div>
</div>
</div>
<script src="configuration.js"></script>
<script>initConfiguration(document.getElementById('configuration'), <?php echo json_encode($quote, JSON_HEX_TAG); ?>);</script>
<?php
billing_maybe_close_db($db);
?>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
