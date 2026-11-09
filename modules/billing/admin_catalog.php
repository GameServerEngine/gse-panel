<?php
/* Game Catalog admin page (module: billing). Rendered inside the panel layout. */
function cat_h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function cat_money(float $v): string
{
    return number_format($v, 2, '.', '');
}

function exec_module()
{
    global $table_prefix;
?>
    <style>
    .cat-game-list { list-style: none; padding: 0; margin: 0; max-width: 320px; }
    .cat-game-list li { border-bottom: 1px solid rgba(86,105,130,0.4); }
    .cat-game-list a { display: block; padding: 8px 10px; text-decoration: none; color: inherit; }
    .cat-game-list a:hover { background: rgba(86,105,130,0.15); }
    .cat-game-list a.cat-game-active { background: #26354a; color: #f0f0f0; }
    .cat-layout { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
    .cat-table { border-collapse: collapse; width: 100%; }
    .cat-table th, .cat-table td { border: 1px solid rgba(86,105,130,0.6); padding: 8px 10px; vertical-align: middle; }
    .cat-table thead th { background: #26354a; color: #f0f0f0; white-space: nowrap; }
    .cat-price-input { width: 80px; }
    .cat-slot-input { width: 60px; }
    .cat-mode-fields { display: none; }
    .cat-mode-fields.cat-mode-active { display: block; }
    .flash-ok  { background: #d4edda; border: 1px solid #c3e6cb; padding: 10px 12px; margin-bottom: 10px; border-radius: 6px; color: #155724; }
    .flash-err { background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px 12px; margin-bottom: 10px; border-radius: 6px; color: #721c24; }
    .muted { color: #999; font-size: 0.85em; }
    .btn-save-all {
      border: 1px solid #3e7ab8;
      border-radius: 6px;
      background: #2f6dac;
      color: #fff;
      font-weight: 600;
      padding: 9px 14px;
      font-size: 0.95rem;
      cursor: pointer;
    }
    .btn-save-all:hover { background: #25598d; }
    .tier-group { display: flex; gap: 8px; flex-wrap: wrap; }
    .tier-group label { display: block; font-size: 0.8em; color: #aaa; }
    </style>
    <?php
require_once(__DIR__ . '/bootstrap.php');
    require_once(__DIR__ . '/includes/catalog_pricing.php');
    
    
    $db = billing_get_db();
    if (!($db instanceof mysqli)) {
        die("Database connection failed.");
    }
    
    
    $flash = [];
    $flashType = 'ok';
    
    /* -----------------------------------------------------------------------
       Load the list of games from config_homes (same source adminserverlist.php
       syncs billing_services from).
    ----------------------------------------------------------------------- */
    $games = [];
    $res = $db->query("SELECT home_cfg_id, game_name, home_cfg_file FROM `{$table_prefix}config_homes` ORDER BY game_name");
    while ($res && ($row = $res->fetch_assoc())) {
        $games[] = $row;
    }
    
    $homeCfgId = (int)($_GET['home_cfg_id'] ?? $_POST['home_cfg_id'] ?? 0);
    if ($homeCfgId <= 0 && !empty($games)) {
        $homeCfgId = (int)$games[0]['home_cfg_id'];
    }
    
    /* -----------------------------------------------------------------------
       SAVE: catalog offers for the selected game submitted
    ----------------------------------------------------------------------- */
    if (isset($_POST['save_catalog']) && $homeCfgId > 0) {
        $offers = (array)($_POST['offer'] ?? []);
        $savedCount = 0;
    
        foreach ($offers as $remoteServerId => $data) {
            $remoteServerId = (int)$remoteServerId;
            if ($remoteServerId <= 0) {
                continue;
            }
    
            $enabled = isset($data['enabled']) ? 1 : 0;
            $mode = (string)($data['pricing_mode'] ?? 'per_slot');
            if (!in_array($mode, ['per_slot', 'tiers', 'base_plus_extra'], true)) {
                $mode = 'per_slot';
            }
            $periodDefault = (string)($data['period_default'] ?? 'monthly');
            if (!in_array($periodDefault, ['daily', 'monthly', 'yearly'], true)) {
                $periodDefault = 'monthly';
            }
            $slotMin = max(1, (int)($data['slot_min_qty'] ?? 1));
            $slotMax = max($slotMin, (int)($data['slot_max_qty'] ?? $slotMin));
    
            $perSlotMonthly = max(0.0, (float)($data['per_slot_price_monthly'] ?? 0));
            $baseSlots = max(0, (int)($data['base_slots'] ?? 0));
            $baseMonthly = max(0.0, (float)($data['base_price_monthly'] ?? 0));
            $extraMonthly = max(0.0, (float)($data['extra_price_per_slot_monthly'] ?? 0));
    
            $perSlotDaily = round($perSlotMonthly / 30, 4);
            $perSlotYear = round($perSlotMonthly * 12, 4);
            $baseDaily = round($baseMonthly / 30, 4);
            $baseYear = round($baseMonthly * 12, 4);
            $extraDaily = round($extraMonthly / 30, 4);
            $extraYear = round($extraMonthly * 12, 4);
    
            $stmt = $db->prepare(
                "INSERT INTO `{$table_prefix}catalog_offer`
                    (home_cfg_id, remote_server_id, enabled, pricing_mode, period_default, currency,
                     slot_min_qty, slot_max_qty,
                     per_slot_price_daily, per_slot_price_monthly, per_slot_price_year,
                     base_slots, base_price_daily, base_price_monthly, base_price_year,
                     extra_price_per_slot_daily, extra_price_per_slot_monthly, extra_price_per_slot_year,
                     updated_at)
                 VALUES (?, ?, ?, ?, ?, 'USD', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    pricing_mode = VALUES(pricing_mode),
                    period_default = VALUES(period_default),
                    slot_min_qty = VALUES(slot_min_qty),
                    slot_max_qty = VALUES(slot_max_qty),
                    per_slot_price_daily = VALUES(per_slot_price_daily),
                    per_slot_price_monthly = VALUES(per_slot_price_monthly),
                    per_slot_price_year = VALUES(per_slot_price_year),
                    base_slots = VALUES(base_slots),
                    base_price_daily = VALUES(base_price_daily),
                    base_price_monthly = VALUES(base_price_monthly),
                    base_price_year = VALUES(base_price_year),
                    extra_price_per_slot_daily = VALUES(extra_price_per_slot_daily),
                    extra_price_per_slot_monthly = VALUES(extra_price_per_slot_monthly),
                    extra_price_per_slot_year = VALUES(extra_price_per_slot_year),
                    updated_at = NOW()"
            );
            if ($stmt) {
                $stmt->bind_param(
                    'iiisssiiddddddddd',
                    $homeCfgId, $remoteServerId, $enabled, $mode, $periodDefault,
                    $slotMin, $slotMax,
                    $perSlotDaily, $perSlotMonthly, $perSlotYear,
                    $baseSlots, $baseDaily, $baseMonthly, $baseYear,
                    $extraDaily, $extraMonthly, $extraYear
                );
                $stmt->execute();
                $stmt->close();
                $savedCount++;
            }
    
            // Resolve the offer_id (needed for tier rows below) now that the upsert ran.
            $offerId = 0;
            $idRes = $db->query(
                "SELECT offer_id FROM `{$table_prefix}catalog_offer`
                 WHERE home_cfg_id = {$homeCfgId} AND remote_server_id = {$remoteServerId} LIMIT 1"
            );
            if ($idRes && ($idRow = $idRes->fetch_assoc())) {
                $offerId = (int)$idRow['offer_id'];
            }
    
            if ($offerId > 0 && $mode === 'tiers') {
                $tierDefaults = catalog_default_tier_slots($slotMax);
                foreach (['small', 'medium', 'large', 'full'] as $tierKey) {
                    $tierData = (array)($data['tier'][$tierKey] ?? []);
                    $slotCount = max(1, (int)($tierData['slot_count'] ?? $tierDefaults[$tierKey]));
                    $priceMonthly = max(0.0, (float)($tierData['price_monthly'] ?? 0));
                    $priceDaily = round($priceMonthly / 30, 4);
                    $priceYear = round($priceMonthly * 12, 4);
    
                    $tstmt = $db->prepare(
                        "INSERT INTO `{$table_prefix}catalog_tier`
                            (offer_id, tier_key, slot_count, price_daily, price_monthly, price_year)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            slot_count = VALUES(slot_count),
                            price_daily = VALUES(price_daily),
                            price_monthly = VALUES(price_monthly),
                            price_year = VALUES(price_year)"
                    );
                    if ($tstmt) {
                        $tstmt->bind_param('isiddd', $offerId, $tierKey, $slotCount, $priceDaily, $priceMonthly, $priceYear);
                        $tstmt->execute();
                        $tstmt->close();
                    }
                }
            }
        }

        $minCpuCores = max(0.0, (float)($_POST['min_cpu_cores'] ?? 0));
        $minRamMb    = max(0, (int)($_POST['min_ram_mb'] ?? 0));
        $minDiskGb   = max(0, (int)($_POST['min_disk_gb'] ?? 0));
        $reqStmt = $db->prepare(
            "INSERT INTO `{$table_prefix}billing_service_requirements`
                (home_cfg_id, min_cpu_cores, min_ram_mb, min_disk_gb)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                min_cpu_cores = VALUES(min_cpu_cores),
                min_ram_mb = VALUES(min_ram_mb),
                min_disk_gb = VALUES(min_disk_gb)"
        );
        if ($reqStmt) {
            $reqStmt->bind_param('idii', $homeCfgId, $minCpuCores, $minRamMb, $minDiskGb);
            $reqStmt->execute();
            $reqStmt->close();
        }

        $flash[] = "{$savedCount} location row(s) saved for this game.";
        $_SESSION['admin_catalog_flash'] = ['type' => 'ok', 'messages' => $flash];
        echo "<script>location.href='home.php?m=billing&p=game_catalog&home_cfg_id=" . (int)$homeCfgId . "';</script>";
        return;
    }
    
    if (!empty($_SESSION['admin_catalog_flash'])) {
        $flashData = $_SESSION['admin_catalog_flash'];
        unset($_SESSION['admin_catalog_flash']);
        $flashType = ($flashData['type'] ?? 'ok') === 'err' ? 'err' : 'ok';
        $flash = array_values(array_filter((array)($flashData['messages'] ?? []), 'is_string'));
    }
    
    /* -----------------------------------------------------------------------
       Load remote servers and existing catalog_offer/catalog_tier rows for the
       selected game.
    ----------------------------------------------------------------------- */
    $remoteServers = [];
    $rsRes = $db->query("SELECT remote_server_id, remote_server_name FROM `{$table_prefix}remote_servers` ORDER BY remote_server_name");
    while ($rsRes && ($row = $rsRes->fetch_assoc())) {
        $remoteServers[] = $row;
    }
    
    $offersByServer = [];
    if ($homeCfgId > 0) {
        $offRes = $db->query("SELECT * FROM `{$table_prefix}catalog_offer` WHERE home_cfg_id = {$homeCfgId}");
        while ($offRes && ($row = $offRes->fetch_assoc())) {
            $offersByServer[(int)$row['remote_server_id']] = $row;
        }
    }
    
    $tiersByOffer = [];
    if (!empty($offersByServer)) {
        $offerIds = array_map(static fn($o) => (int)$o['offer_id'], $offersByServer);
        $offerIds = array_filter($offerIds);
        if (!empty($offerIds)) {
            $inList = implode(',', $offerIds);
            $tierRes = $db->query("SELECT * FROM `{$table_prefix}catalog_tier` WHERE offer_id IN ({$inList})");
            while ($tierRes && ($row = $tierRes->fetch_assoc())) {
                $tiersByOffer[(int)$row['offer_id']][(string)$row['tier_key']] = $row;
            }
        }
    }
    
    $requirements = ['min_cpu_cores' => 0, 'min_ram_mb' => 0, 'min_disk_gb' => 0];
    if ($homeCfgId > 0) {
        $reqRes = $db->query(
            "SELECT min_cpu_cores, min_ram_mb, min_disk_gb
             FROM `{$table_prefix}billing_service_requirements`
             WHERE home_cfg_id = {$homeCfgId}"
        );
        if ($reqRes && ($reqRow = $reqRes->fetch_assoc())) {
            $requirements = [
                'min_cpu_cores' => (float)$reqRow['min_cpu_cores'],
                'min_ram_mb'    => (int)$reqRow['min_ram_mb'],
                'min_disk_gb'   => (int)$reqRow['min_disk_gb'],
            ];
        }
    }

    $currentGameName = '';
    foreach ($games as $g) {
        if ((int)$g['home_cfg_id'] === $homeCfgId) {
            $currentGameName = (string)$g['game_name'];
            break;
        }
    }
    ?>
    
    <?php foreach ($flash as $msg): ?>
      <div class="flash-<?php echo $flashType; ?>"><?php echo cat_h($msg); ?></div>
    <?php endforeach; ?>
    
    <h2>Game Catalog</h2>
    <p class="muted">
        The panel owns the catalog and all pricing. Pick a game on the left, then enable it per
        location (remote server) and choose a pricing mode. Only enabled offers appear on the
        storefront. Changing a price here never changes an existing order — orders lock their price
        at purchase time.
    </p>
    
    <div class="cat-layout">
      <div>
        <h3>Games</h3>
        <ul class="cat-game-list">
          <?php foreach ($games as $g): $gid = (int)$g['home_cfg_id']; ?>
            <li>
              <a class="<?php echo $gid === $homeCfgId ? 'cat-game-active' : ''; ?>"
                 href="<?php echo cat_h('home.php?m=billing&p=game_catalog&home_cfg_id=' . (int)$gid); ?>">
                <?php echo cat_h($g['game_name']); ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    
      <div style="flex:1 1 600px;">
        <?php if ($homeCfgId <= 0 || empty($games)): ?>
          <p>No game configs found. Ensure game configs are loaded in the panel (Home &rarr; Games configuration).</p>
        <?php elseif (empty($remoteServers)): ?>
          <p>No remote servers configured. Add a remote server before configuring the catalog.</p>
        <?php else: ?>
          <h3><?php echo cat_h($currentGameName); ?></h3>
          <form method="post" action="<?php echo cat_h('home.php?m=billing&p=game_catalog'); ?>">
            <input type="hidden" name="save_catalog" value="1">
            <input type="hidden" name="home_cfg_id" value="<?php echo $homeCfgId; ?>">
            <div style="overflow-x:auto;">
            <table class="cat-table">
              <thead>
                <tr>
                  <th>Location</th>
                  <th>Enabled</th>
                  <th>Pricing Mode</th>
                  <th>Period Default</th>
                  <th>Min Slots</th>
                  <th>Max Slots</th>
                  <th>Pricing (monthly)</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($remoteServers as $rs):
                $rid = (int)$rs['remote_server_id'];
                $offer = $offersByServer[$rid] ?? null;
                $offerId = $offer ? (int)$offer['offer_id'] : 0;
                $enabled = $offer ? (int)$offer['enabled'] : 0;
                $mode = $offer ? (string)$offer['pricing_mode'] : 'per_slot';
                $periodDefault = $offer ? (string)$offer['period_default'] : 'monthly';
                $slotMin = $offer ? (int)$offer['slot_min_qty'] : 1;
                $slotMax = $offer ? (int)$offer['slot_max_qty'] : 32;
                $perSlotMonthly = $offer ? (float)$offer['per_slot_price_monthly'] : 0;
                $baseSlots = $offer ? (int)$offer['base_slots'] : 0;
                $baseMonthly = $offer ? (float)$offer['base_price_monthly'] : 0;
                $extraMonthly = $offer ? (float)$offer['extra_price_per_slot_monthly'] : 0;
                $tierDefaults = catalog_default_tier_slots($slotMax);
                $tiers = $offerId > 0 ? ($tiersByOffer[$offerId] ?? []) : [];
                $rowId = 'row_' . $rid;
              ?>
                <tr>
                  <td><?php echo cat_h($rs['remote_server_name']); ?> <span class="muted">(#<?php echo $rid; ?>)</span></td>
                  <td style="text-align:center;">
                    <input type="hidden" name="offer[<?php echo $rid; ?>][enabled]" value="0">
                    <input type="checkbox" name="offer[<?php echo $rid; ?>][enabled]" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                  </td>
                  <td>
                    <select name="offer[<?php echo $rid; ?>][pricing_mode]" class="cat-mode-select" data-row="<?php echo cat_h($rowId); ?>">
                      <option value="per_slot" <?php echo $mode === 'per_slot' ? 'selected' : ''; ?>>Per slot</option>
                      <option value="tiers" <?php echo $mode === 'tiers' ? 'selected' : ''; ?>>Tiers</option>
                      <option value="base_plus_extra" <?php echo $mode === 'base_plus_extra' ? 'selected' : ''; ?>>Base + extra</option>
                    </select>
                  </td>
                  <td>
                    <select name="offer[<?php echo $rid; ?>][period_default]">
                      <option value="daily" <?php echo $periodDefault === 'daily' ? 'selected' : ''; ?>>Daily</option>
                      <option value="monthly" <?php echo $periodDefault === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                      <option value="yearly" <?php echo $periodDefault === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                    </select>
                  </td>
                  <td><input type="number" min="1" class="cat-slot-input" name="offer[<?php echo $rid; ?>][slot_min_qty]" value="<?php echo $slotMin; ?>"></td>
                  <td><input type="number" min="1" class="cat-slot-input" name="offer[<?php echo $rid; ?>][slot_max_qty]" value="<?php echo $slotMax; ?>"></td>
                  <td>
                    <div class="cat-pricing-fields" data-row="<?php echo cat_h($rowId); ?>">
                      <div class="cat-mode-fields cat-mode-per_slot" data-mode="per_slot">
                        $<input type="number" step="0.01" min="0" class="cat-price-input"
                               name="offer[<?php echo $rid; ?>][per_slot_price_monthly]"
                               value="<?php echo cat_money($perSlotMonthly); ?>"> / slot / month
                      </div>
                      <div class="cat-mode-fields cat-mode-tiers" data-mode="tiers">
                        <div class="tier-group">
                          <?php foreach (['small', 'medium', 'large', 'full'] as $tierKey):
                            $tierRow = $tiers[$tierKey] ?? null;
                            $tSlots = $tierRow ? (int)$tierRow['slot_count'] : $tierDefaults[$tierKey];
                            $tMonthly = $tierRow ? (float)$tierRow['price_monthly'] : 0;
                          ?>
                            <span>
                              <label><?php echo ucfirst($tierKey); ?> slots</label>
                              <input type="number" min="1" class="cat-slot-input"
                                     name="offer[<?php echo $rid; ?>][tier][<?php echo $tierKey; ?>][slot_count]"
                                     value="<?php echo $tSlots; ?>">
                              <label>$ / month</label>
                              <input type="number" step="0.01" min="0" class="cat-price-input"
                                     name="offer[<?php echo $rid; ?>][tier][<?php echo $tierKey; ?>][price_monthly]"
                                     value="<?php echo cat_money($tMonthly); ?>">
                            </span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="cat-mode-fields cat-mode-base_plus_extra" data-mode="base_plus_extra">
                        Base
                        <input type="number" min="0" class="cat-slot-input"
                               name="offer[<?php echo $rid; ?>][base_slots]" value="<?php echo $baseSlots; ?>"> slots for
                        $<input type="number" step="0.01" min="0" class="cat-price-input"
                               name="offer[<?php echo $rid; ?>][base_price_monthly]" value="<?php echo cat_money($baseMonthly); ?>"> / month,
                        then $<input type="number" step="0.01" min="0" class="cat-price-input"
                               name="offer[<?php echo $rid; ?>][extra_price_per_slot_monthly]" value="<?php echo cat_money($extraMonthly); ?>"> / extra slot / month
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <div style="margin-top:14px;">
              <h4>Minimum System Requirements</h4>
              <label>Min CPU cores
                <input type="number" step="0.1" min="0" class="cat-slot-input" name="min_cpu_cores" value="<?php echo cat_h($requirements['min_cpu_cores']); ?>">
              </label>
              <label>Min RAM (MB)
                <input type="number" step="1" min="0" class="cat-slot-input" name="min_ram_mb" value="<?php echo (int)$requirements['min_ram_mb']; ?>">
              </label>
              <label>Min Disk (GB)
                <input type="number" step="1" min="0" class="cat-slot-input" name="min_disk_gb" value="<?php echo (int)$requirements['min_disk_gb']; ?>">
              </label>
            </div>
            <div style="margin-top:14px;">
              <button type="submit" class="btn-save-all">Save Catalog</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
    
    <div style="margin-top:20px;" class="panel">
      <p><strong>Notes:</strong></p>
      <ul>
        <li>Only the monthly price is editable here; daily and yearly rates are derived automatically
            (monthly &divide; 30 and monthly &times; 12).</li>
        <li>An offer only appears on the storefront when <strong>Enabled</strong> is checked for that
            location.</li>
        <li>Tier slot counts default to 25% / 50% / 75% / 100% of Max Slots but can be edited per tier.</li>
        <li>Setting Base slots to 0 with a per-slot price under "Base + extra" is equivalent to pure
            per-slot pricing.</li>
      </ul>
    </div>
    
    <?php billing_maybe_close_db($db); ?>
    
    <script>
    function catalogApplyMode(row) {
        var select = row.querySelector('.cat-mode-select');
        var mode = select ? select.value : 'per_slot';
        row.querySelectorAll('.cat-mode-fields').forEach(function (el) {
            el.classList.toggle('cat-mode-active', el.dataset.mode === mode);
        });
    }
    document.querySelectorAll('.cat-table tbody tr').forEach(function (row) {
        catalogApplyMode(row);
        var select = row.querySelector('.cat-mode-select');
        if (select) {
            select.addEventListener('change', function () { catalogApplyMode(row); });
        }
    });
    </script>
    
    
}

<?php }
