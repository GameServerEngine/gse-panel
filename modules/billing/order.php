<?php require_once __DIR__ . '/includes/brand.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Server - <?php echo billing_brand_e('name'); ?></title>
    <link rel="stylesheet" href="css/header.css">
    <style>
        body { margin: 0; padding: 0; }
        .order-shell { width: min(1100px, 100% - 24px); margin: 20px auto 28px; }
        .order-layout { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
        .order-media { flex: 0 1 310px; max-width: 100%; }
        .order-media img { width: 100%; max-width: 280px; height: auto; border-radius: 8px; display: block; }
        .order-media-title { margin: 10px 0 6px; text-align: center; }
        .order-media-desc { color: #c6c6c6; max-width: 100%; word-break: break-word; }
        .order-form-card { flex: 1 1 500px; max-width: 100%; background: rgba(0,0,0,0.25); border-radius: 10px; padding: 14px; }
        .order-form-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .order-form-table td { padding: 8px 6px; vertical-align: top; }
        .order-form-table td:first-child { width: 34%; }
        .order-form-table input[type="text"],
        .order-form-table input[type="number"],
        .order-form-table select,
        .order-form-table textarea {
            width: 100%;
            min-height: 40px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            padding: 8px 10px;
        }
        .order-form-table input[type="radio"] { margin-right: 8px; }
        .location-option { margin-bottom: 8px; }
        .slidecontainer { max-width: 100%; }
        .slidecontainer .slider { width: 100%; }
        .order-pricing { line-height: 1.5; word-break: break-word; }
        .order-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .order-actions .gsw-btn,
        .order-actions .gsw-btn-secondary { width: auto; }
        .order-back-form { margin: 0; }
        .tier-option { display: inline-block; margin: 0 10px 8px 0; }
        .offer-pricing-fields { display: none; }
        .offer-pricing-fields.offer-pricing-active { display: block; }

        @media (max-width: 768px) {
            .order-shell { width: min(100%, calc(100% - 16px)); margin: 12px auto 20px; }
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
            .order-actions .gsw-btn-secondary { width: 100%; text-align: center; }
        }
    </style>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
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
require_once(__DIR__ . '/includes/catalog_storefront.php');

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

// Pre-load tiers for every tiers-mode offer so the page can render all options
// without a query per radio button.
$tiersByOffer = [];
foreach ($offers as $offer) {
    if ((string)$offer['pricing_mode'] === 'tiers') {
        $tiersByOffer[(int)$offer['offer_id']] = catalog_load_tiers_for_offer($db, $table_prefix, (int)$offer['offer_id']);
    }
}
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
<table class="order-form-table">
<form method="post" action="add_to_cart.php">
    <input type="hidden" name="home_cfg_id" value="<?php echo (int)$homeCfgId; ?>">
    <input type="hidden" name="remote_control_password" value="">
    <input type="hidden" name="ftp_password" value="">
    <input type="hidden" name="slots" id="submitSlots" value="">
    <input type="hidden" name="tier_key" id="submitTierKey" value="">
    <input type="hidden" name="period" id="submitPeriod" value="monthly">
<tr>
<td align="right"><b>Game Server Name</b></td>
<td align="left">
<input type="text" name="home_name" size="40" value="<?php echo order_e($displayName); ?>">
</td>
</tr>
<tr>
  <td align="right"><b>Location</b></td>
  <td align="left">
<?php
$locationGroups = storefront_build_location_groups($offers, storefront_load_enabled_cities($db, $table_prefix));
$firstOffer = true;
foreach ($locationGroups as $group):
?>
  <div class="location-region"><b><?php echo order_e($group['region']); ?></b></div>
<?php foreach ($group['cities'] as $cityEntry):
    $cityOffer = $cityEntry['offer'];
    if ($cityOffer === null):
?>
  <div class="location-option"><span class="muted"><?php echo order_e($cityEntry['name']); ?> &mdash; Unavailable</span></div>
<?php
        continue;
    endif;
    $offerId = (int)$cityOffer['offer_id'];
    $mode = (string)$cityOffer['pricing_mode'];
    $checked = $firstOffer ? ' checked' : '';
    $firstOffer = false;
?>
  <div class="location-option">
    <input type="radio" name="offer_id" id="offer_<?php echo $offerId; ?>" value="<?php echo $offerId; ?>"
           data-mode="<?php echo order_e($mode); ?>" required<?php echo $checked; ?>>
    <label for="offer_<?php echo $offerId; ?>"><?php echo order_e($cityEntry['name']); ?></label>
  </div>
<?php endforeach; ?>
<?php endforeach; ?>
  </td>
</tr>
<tr>
  <td align="right"><b>Configure</b></td>
  <td align="left">
<?php foreach ($offers as $offer):
    $offerId = (int)$offer['offer_id'];
    $mode = (string)$offer['pricing_mode'];
    $slotMin = (int)$offer['slot_min_qty'];
    $slotMax = (int)$offer['slot_max_qty'];
    $period = (string)$offer['period_default'];
?>
  <div class="offer-pricing-fields" data-offer="<?php echo $offerId; ?>">
    <?php if ($mode === 'per_slot' || $mode === 'base_plus_extra'): ?>
      <center><b>Player Slots</b></center>
      <input type="range" class="offer-slots-input" data-offer="<?php echo $offerId; ?>"
             min="<?php echo $slotMin; ?>" max="<?php echo $slotMax; ?>" value="<?php echo $slotMin; ?>" class="slider">
      <center><span class="offer-slots-readout" data-offer="<?php echo $offerId; ?>"><?php echo $slotMin; ?></span> slots</center>
      <?php if ($mode === 'per_slot'): ?>
        <span class="muted">$<?php echo number_format((float)$offer['per_slot_price_monthly'], 2); ?> / slot / month</span>
      <?php else: ?>
        <span class="muted">
          $<?php echo number_format((float)$offer['base_price_monthly'], 2); ?> for the first <?php echo (int)$offer['base_slots']; ?> slots,
          then $<?php echo number_format((float)$offer['extra_price_per_slot_monthly'], 2); ?> / extra slot / month
        </span>
      <?php endif; ?>
    <?php else: ?>
      <center><b>Server size</b></center>
      <?php foreach (($tiersByOffer[$offerId] ?? []) as $i => $tier): ?>
        <span class="tier-option">
          <input type="radio" class="offer-tier-input" name="tier_<?php echo $offerId; ?>" data-offer="<?php echo $offerId; ?>"
                 value="<?php echo order_e($tier['tier_key']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
          <?php echo order_e(ucfirst((string)$tier['tier_key'])); ?>
          (<?php echo (int)$tier['slot_count']; ?> slots, $<?php echo number_format((float)$tier['price_monthly'], 2); ?>/mo)
        </span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
     <center><b>Months</b></center>
     <input type="range" name="qty" min="1" max="24" value="1" class="slider" id="invoiceRange">

<p class="order-pricing" id="orderPricingSummary">Duration: <span id="invoiceDuration">1 month</span><br>
<span id="totalPrice">Select a location to see pricing.</span></p>
 <input type="hidden" name="invoice_duration" value="month" />
  </td>
</tr>

<tr>
<td align="left" colspan="2">
<?php if ($is_logged_in): ?>
<div class="order-actions">
<button type="submit" name="add_to_cart" class="gsw-btn">Add to Cart</button>
</div>
<?php else: ?>
<div class="login-placeholder">Please <a href="login.php">login</a> to order</div>
<?php endif; ?>
</form>
</td>
</tr>
<tr>
<td align="left" colspan="2">
<form action="serverlist.php" method="GET" class="order-back-form">
<div class="order-actions">
<button class="gsw-btn-secondary">Back to List</button>
</div>
</form>
</td>
</tr>
</table>
</div>
</div>
</div>
<script>
(function () {
    var offerData = <?php echo json_encode(array_map(static function (array $o) {
        return [
            'offer_id' => (int)$o['offer_id'],
            'pricing_mode' => (string)$o['pricing_mode'],
            'per_slot_price_monthly' => (float)$o['per_slot_price_monthly'],
            'base_slots' => (int)$o['base_slots'],
            'base_price_monthly' => (float)$o['base_price_monthly'],
            'extra_price_per_slot_monthly' => (float)$o['extra_price_per_slot_monthly'],
        ];
    }, $offers)); ?>;
    var tiersData = <?php echo json_encode(array_map(static function (array $tiers) {
        return array_map(static function (array $t) {
            return ['tier_key' => (string)$t['tier_key'], 'price_monthly' => (float)$t['price_monthly']];
        }, $tiers);
    }, $tiersByOffer)); ?>;

    var locationRadios = document.querySelectorAll('input[name="offer_id"]');
    var monthsSlider = document.getElementById('invoiceRange');
    var durationOut = document.getElementById('invoiceDuration');
    var totalOut = document.getElementById('totalPrice');
    var submitSlots = document.getElementById('submitSlots');
    var submitTierKey = document.getElementById('submitTierKey');
    var submitPeriod = document.getElementById('submitPeriod');

    function activeOfferId() {
        for (var i = 0; i < locationRadios.length; i++) {
            if (locationRadios[i].checked) return parseInt(locationRadios[i].value, 10);
        }
        return 0;
    }

    function findOffer(offerId) {
        for (var i = 0; i < offerData.length; i++) {
            if (offerData[i].offer_id === offerId) return offerData[i];
        }
        return null;
    }

    function recalc() {
        var offerId = activeOfferId();
        document.querySelectorAll('.offer-pricing-fields').forEach(function (el) {
            el.classList.toggle('offer-pricing-active', parseInt(el.dataset.offer, 10) === offerId);
        });

        var months = parseInt(monthsSlider.value, 10);
        durationOut.textContent = months + ' month' + (months !== 1 ? 's' : '');

        var offer = findOffer(offerId);
        submitPeriod.value = 'monthly';
        if (!offer) { totalOut.textContent = 'Select a location to see pricing.'; return; }

        var unit = 0;
        if (offer.pricing_mode === 'per_slot') {
            var slotsInput = document.querySelector('.offer-slots-input[data-offer="' + offerId + '"]');
            var slots = slotsInput ? parseInt(slotsInput.value, 10) : 0;
            unit = offer.per_slot_price_monthly * slots;
            submitSlots.value = slots;
            submitTierKey.value = '';
        } else if (offer.pricing_mode === 'base_plus_extra') {
            var slotsInput2 = document.querySelector('.offer-slots-input[data-offer="' + offerId + '"]');
            var slots2 = slotsInput2 ? parseInt(slotsInput2.value, 10) : 0;
            var extraSlots = Math.max(0, slots2 - offer.base_slots);
            unit = offer.base_price_monthly + (extraSlots * offer.extra_price_per_slot_monthly);
            submitSlots.value = slots2;
            submitTierKey.value = '';
        } else {
            var tierInput = document.querySelector('input[name="tier_' + offerId + '"]:checked');
            var tierKey = tierInput ? tierInput.value : null;
            var tiers = tiersData[offerId] || [];
            for (var t = 0; t < tiers.length; t++) {
                if (tiers[t].tier_key === tierKey) { unit = tiers[t].price_monthly; break; }
            }
            submitSlots.value = '';
            submitTierKey.value = tierKey || '';
        }

        totalOut.textContent = 'Estimated total: $' + (unit * months).toFixed(2) + ' (confirmed at checkout)';
    }

    document.querySelectorAll('.offer-slots-input').forEach(function (input) {
        input.addEventListener('input', function () {
            var readout = document.querySelector('.offer-slots-readout[data-offer="' + input.dataset.offer + '"]');
            if (readout) readout.textContent = input.value;
            recalc();
        });
    });
    document.querySelectorAll('.offer-tier-input').forEach(function (input) {
        input.addEventListener('change', recalc);
    });
    locationRadios.forEach(function (input) {
        input.addEventListener('change', recalc);
    });
    monthsSlider.addEventListener('input', recalc);

    recalc();
})();
</script>
<?php
billing_maybe_close_db($db);
?>
</body>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</html>
