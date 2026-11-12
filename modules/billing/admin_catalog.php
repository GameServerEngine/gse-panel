<?php
/**
 * Game Catalog admin page (Administration > Billing > Game Catalog).
 *
 * The list shows every game config. Open a game to change what the storefront shows
 * (name, description, picture), its price, and which agents it is sold on.
 * A game is sold on an agent when it is ticked here and the agent has installs turned on.
 *
 * panel_admin.php includes this file and calls billing_admin_catalog_render().
 * The older page key game_catalog still works through exec_module() at the bottom.
 * The placement headroom form (how full an agent may get before it is skipped) is on the list view.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/catalog_admin.php';

const CAT_CSRF_KEY = 'billing_admin_catalog_csrf';

function cat_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cat_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

/** Link to this page inside the panel. Returns text that is safe to put in an href. */
function cat_url(array $params = array()): string
{
    $params = array_merge(array('m' => 'billing', 'p' => 'billing', 's' => 'game_catalog'), $params);
    return cat_h('home.php?' . http_build_query($params));
}

/** The folder with game pictures, inside the billing module. */
function cat_image_dir(): string
{
    return __DIR__ . '/images/games';
}

function cat_set_flash(string $type, array $messages): void
{
    $_SESSION['admin_catalog_flash'] = array('type' => $type, 'messages' => $messages);
}

function cat_render_flash(): void
{
    if (empty($_SESSION['admin_catalog_flash'])) {
        return;
    }
    $flash = $_SESSION['admin_catalog_flash'];
    unset($_SESSION['admin_catalog_flash']);
    $class = ($flash['type'] ?? 'ok') === 'err' ? 'cat-flash-err' : 'cat-flash-ok';
    foreach ((array)$flash['messages'] as $message) {
        echo '<div class="' . $class . '">' . cat_h($message) . '</div>';
    }
}

/** The panel has already sent its page header, so a redirect has to be done by the browser. */
function cat_redirect(string $url): void
{
    echo '<script>location.href = ' . json_encode(html_entity_decode($url, ENT_QUOTES, 'UTF-8')) . ';</script>';
}

/* ---------------------------------------------------------------------------
   Saving
--------------------------------------------------------------------------- */

/** Warnings to show after a save. The game is still saved. */
function cat_save_warnings(array $settings, array $tickedAgents): array
{
    $warnings = array();
    if (!empty($tickedAgents) && catalog_settings_are_free($settings)) {
        $warnings[] = 'A price is 0.00, so customers can order this game for free.';
    }
    foreach ($tickedAgents as $agent) {
        if ((int)$agent['install_enabled'] !== 1) {
            $warnings[] = $agent['remote_server_name'] . ' has installs turned off, so customers cannot order there yet.';
        }
    }
    return $warnings;
}

/** Saves the game form. Returns the address to go to next. */
function cat_save_game(mysqli $db, string $prefix): string
{
    $homeCfgId = (int)($_POST['home_cfg_id'] ?? 0);
    $game = catalog_admin_game($db, $prefix, $homeCfgId);
    if ($game === null) {
        cat_set_flash('err', array('That game was not found.'));
        return cat_url();
    }

    $details = array(
        'display_name' => catalog_clean_text($_POST['display_name'] ?? '', 255),
        'description' => catalog_clean_text($_POST['description'] ?? '', 1000),
        'img_url' => catalog_pick_image($_POST['img_url'] ?? '', catalog_list_images(cat_image_dir())),
    );
    $settings = catalog_read_settings($_POST);
    $agents = catalog_admin_agents($db, $prefix);
    $postedIds = array_map('intval', (array)($_POST['agent_ids'] ?? array()));

    $allIds = array();
    $tickedAgents = array();
    foreach ($agents as $agent) {
        $allIds[] = (int)$agent['remote_server_id'];
        if (in_array((int)$agent['remote_server_id'], $postedIds, true)) {
            $tickedAgents[] = $agent;
        }
    }
    $tickedIds = array_map(static function ($agent) {
        return (int)$agent['remote_server_id'];
    }, $tickedAgents);

    $error = catalog_admin_save_game(
        $db, $prefix, $homeCfgId, $details, $settings, $tickedIds, $allIds, catalog_admin_read_requirements($_POST)
    );
    if ($error !== '') {
        cat_set_flash('err', array($error));
    } else {
        $saved = 'Saved ' . $game['game_name'] . '. It is published on ' . count($tickedIds) . ' agent(s).';
        cat_set_flash('ok', array_merge(array($saved), cat_save_warnings($settings, $tickedAgents)));
    }
    return cat_url(array('home_cfg_id' => $homeCfgId));
}

/**
 * Handles the import form. Preview returns the plan to show on this page.
 * Draft and publish write it and return an address to go to.
 * Returns array('redirect' => address or null, 'preview' => plan or null).
 */
function cat_import(mysqli $db, string $prefix): array
{
    $validIds = array_map('intval', array_column(catalog_admin_agents($db, $prefix), 'remote_server_id'));
    $agentIds = array_values(array_intersect($validIds, array_map('intval', (array)($_POST['agent_ids'] ?? array()))));
    $useSaved = !empty($_POST['use_saved_agents']);
    if (empty($agentIds) && !$useSaved) {
        cat_set_flash('err', array('Tick at least one agent, or choose to use the agents saved on the old services.'));
        return array('redirect' => null, 'preview' => null);
    }

    $plan = catalog_admin_import_plan($db, $prefix, $agentIds, catalog_list_images(cat_image_dir()), $useSaved);
    $plan['agent_ids'] = $agentIds;
    $plan['use_saved_agents'] = $useSaved;
    $mode = (string)($_POST['mode'] ?? 'preview');
    if ($mode === 'preview') {
        return array('redirect' => null, 'preview' => $plan);
    }

    $error = catalog_admin_apply_import($db, $prefix, $plan, $mode === 'publish');
    if ($error !== '') {
        cat_set_flash('err', array($error));
    } else {
        $what = $mode === 'publish' ? 'published' : 'saved as drafts';
        cat_set_flash('ok', array('Imported ' . $plan['counts']['games'] . ' game(s), ' . $plan['counts']['offers'] . ' offer(s) ' . $what . '.'));
    }
    return array('redirect' => cat_url(), 'preview' => null);
}

/** Saves the placement headroom form. Returns the address to go to, or null to show the page again. */
function cat_save_headroom(mysqli $db, string $prefix): ?string
{
    $values = catalog_read_headroom($_POST);
    if ($values === null) {
        cat_set_flash('err', array('Placement headroom percentages must be whole numbers between 1 and 100.'));
        return null;
    }
    $error = catalog_admin_save_headroom($db, $prefix, $values['cpu'], $values['ram'], $values['disk']);
    if ($error !== '') {
        cat_set_flash('err', array($error));
        return null;
    }
    cat_set_flash('ok', array('Placement headroom saved.'));
    return cat_url();
}

/** Handles any form post. Same return value as cat_import(). */
function cat_handle_post(mysqli $db, string $prefix): array
{
    $none = array('redirect' => null, 'preview' => null);
    if (!billing_action_validate_csrf($_POST['csrf'] ?? '', CAT_CSRF_KEY)) {
        cat_set_flash('err', array('Invalid security token. Reload the page and try again.'));
        return $none;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_game') {
        return array('redirect' => cat_save_game($db, $prefix), 'preview' => null);
    }
    if ($action === 'import') {
        return cat_import($db, $prefix);
    }
    if ($action === 'save_headroom') {
        return array('redirect' => cat_save_headroom($db, $prefix), 'preview' => null);
    }
    return $none;
}

/* ---------------------------------------------------------------------------
   Page parts
--------------------------------------------------------------------------- */

function cat_render_styles(): void
{
    ?>
    <style>
    .cat-table { border-collapse: collapse; width: 100%; }
    .cat-table th, .cat-table td { border: 1px solid rgba(128,128,128,0.4); padding: 8px 10px; text-align: left; vertical-align: middle; }
    .cat-table th { background: rgba(128,128,128,0.2); white-space: nowrap; }
    .cat-form th { width: 190px; vertical-align: top; }
    .cat-muted { opacity: 0.75; font-size: 0.9em; }
    .cat-wide { width: 100%; max-width: 520px; box-sizing: border-box; }
    .cat-number { width: 90px; }
    .cat-input { width: 70px; margin: 0 14px 0 6px; }
    .cat-hidden { display: none; }
    .cat-picture { display: block; max-width: 180px; margin-top: 8px; }
    .cat-badge-on { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 1px 8px; border-radius: 3px; font-size: 0.9em; }
    .cat-badge-off { background: rgba(128,128,128,0.25); border: 1px solid rgba(128,128,128,0.5); padding: 1px 8px; border-radius: 3px; font-size: 0.9em; }
    .cat-flash-ok { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px 12px; margin-bottom: 10px; border-radius: 6px; }
    .cat-flash-err { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px 12px; margin-bottom: 10px; border-radius: 6px; }
    .cat-box { border: 1px solid rgba(128,128,128,0.4); border-radius: 6px; padding: 12px 16px; margin: 16px 0; }
    .cat-button { border: 1px solid #3e7ab8; border-radius: 6px; background: #2f6dac; color: #fff; font-weight: 600; padding: 8px 14px; cursor: pointer; }
    .cat-button:hover { background: #25598d; }
    .cat-tiers span { display: inline-block; margin: 0 14px 8px 0; }
    </style>
    <?php
}

/** The checkboxes for choosing agents. $ticked is a list of agent ids that start ticked. */
function cat_render_agent_checkboxes(array $agents, array $ticked): void
{
    foreach ($agents as $agent) {
        $id = (int)$agent['remote_server_id'];
        $checked = in_array($id, $ticked, true) ? ' checked' : '';
        echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="agent_ids[]" value="' . $id . '"' . $checked . '> '
            . cat_h($agent['remote_server_name']) . ' <span class="cat-muted">(' . cat_h($agent['server_os'] ?: 'OS not set') . ')</span></label>';
    }
}

function cat_render_import_preview(array $plan): void
{
    $counts = $plan['counts'];
    echo '<div class="cat-box"><strong>Preview</strong><ul>';
    echo '<li>' . (int)$counts['games'] . ' game(s) would get ' . (int)$counts['offers'] . ' new offer(s).</li>';
    echo '<li>' . (int)$counts['already_set_up'] . ' offer(s) already exist and stay as they are.</li>';
    echo '<li>' . (int)$counts['no_price'] . ' old service(s) have no price and are skipped.</li>';
    echo '<li>' . (int)$counts['no_config'] . ' old service(s) are no longer in the game configs and are skipped.</li>';
    echo '</ul>';
    $names = array_slice(array_column($plan['games'], 'name'), 0, 12);
    if (!empty($names)) {
        echo '<p class="cat-muted">Includes: ' . cat_h(implode(', ', $names)) . (count($plan['games']) > 12 ? ', and more.' : '.') . '</p>';
    }
    echo '</div>';
}

/** The placement headroom form. */
function cat_render_headroom(mysqli $db, string $prefix, string $csrf): void
{
    $headroom = catalog_admin_headroom($db, $prefix);
    ?>
    <details class="cat-box">
      <summary><strong>Placement headroom</strong></summary>
      <p>When a customer orders, an agent is only picked if its use stays under these percentages.</p>
      <form method="post" action="<?php echo cat_url(); ?>">
        <input type="hidden" name="csrf" value="<?php echo cat_h($csrf); ?>">
        <input type="hidden" name="action" value="save_headroom">
        <?php foreach (array('cpu' => 'CPU %', 'ram' => 'RAM %', 'disk' => 'Disk %') as $key => $label) : ?>
          <label><?php echo cat_h($label); ?>
            <input type="number" min="1" max="100" class="cat-input" name="headroom_<?php echo $key; ?>_pct"
                   value="<?php echo (int)$headroom[$key]; ?>"></label>
        <?php endforeach; ?>
        <p><button type="submit" class="cat-button">Save headroom</button></p>
      </form>
    </details>
    <?php
}

/** The import tool: copies the old services list into the catalog on the agents you tick. */
function cat_render_import(mysqli $db, string $prefix, string $csrf, array $agents, ?array $preview): void
{
    $oldCount = count(catalog_admin_legacy_rows($db, $prefix));
    if ($oldCount === 0) {
        return;
    }
    $open = $preview !== null ? ' open' : '';
    $ticked = $preview !== null ? $preview['agent_ids'] : array();
    ?>
    <details class="cat-box"<?php echo $open; ?>>
      <summary><strong>Import from the old services list</strong> (<?php echo $oldCount; ?> enabled)</summary>
      <p>Copies each old service's price, slot range and picture into the catalog, on the agents it was saved with and/or the agents you tick below.
         Games that are already set up are not changed, and old services with no price are skipped.</p>
      <?php if ($preview !== null) { cat_render_import_preview($preview); } ?>
      <form method="post" action="<?php echo cat_url(); ?>">
        <input type="hidden" name="csrf" value="<?php echo cat_h($csrf); ?>">
        <input type="hidden" name="action" value="import">
        <p><label><input type="checkbox" name="use_saved_agents" value="1"<?php echo !empty($preview['use_saved_agents']) ? ' checked' : ''; ?>>
           Also use the agents already saved on each old service</label></p>
        <p>And put them on these agents:</p>
        <?php cat_render_agent_checkboxes($agents, $ticked); ?>
        <p>
          <button type="submit" name="mode" value="preview" class="cat-button">Preview</button>
          <button type="submit" name="mode" value="draft" class="cat-button">Import as drafts</button>
          <button type="submit" name="mode" value="publish" class="cat-button"
                  onclick="return confirm('This puts the games on the storefront now. Continue?');">Import and publish</button>
        </p>
      </form>
    </details>
    <?php
}

function cat_render_filter(string $search, string $status): void
{
    $options = array('' => 'All games', 'published' => 'Published', 'draft' => 'Not published');
    ?>
    <form method="get" action="home.php" style="margin:12px 0;">
      <input type="hidden" name="m" value="billing">
      <input type="hidden" name="p" value="billing">
      <input type="hidden" name="s" value="game_catalog">
      <input type="text" name="q" value="<?php echo cat_h($search); ?>" placeholder="Search games">
      <select name="status">
        <?php foreach ($options as $value => $label): ?>
          <option value="<?php echo cat_h($value); ?>"<?php echo $value === $status ? ' selected' : ''; ?>><?php echo cat_h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="cat-button">Filter</button>
    </form>
    <?php
}

function cat_render_game_rows(array $games): void
{
    foreach ($games as $game) {
        $shown = $game['display_name'] !== null && $game['display_name'] !== '' ? $game['display_name'] : $game['game_name'];
        $published = (int)$game['published_agents'];
        $link = cat_url(array('home_cfg_id' => (int)$game['home_cfg_id']));
        echo '<tr><td><a href="' . $link . '">' . cat_h($shown) . '</a></td>';
        echo '<td class="cat-muted">' . cat_h($game['home_cfg_file']) . '</td>';
        echo '<td>' . ($published > 0
            ? '<span class="cat-badge-on">Published on ' . $published . ' agent(s)</span>'
            : '<span class="cat-badge-off">Not published</span>') . '</td>';
        echo '<td>' . ($game['from_price'] !== null ? '$' . cat_money($game['from_price']) : '&mdash;') . '</td>';
        echo '<td><a href="' . $link . '">Edit</a></td></tr>';
    }
}

function cat_render_list(mysqli $db, string $prefix, string $csrf, ?array $preview): void
{
    $search = trim((string)($_GET['q'] ?? ''));
    $status = in_array($_GET['status'] ?? '', array('published', 'draft'), true) ? (string)$_GET['status'] : '';
    $games = catalog_admin_game_list($db, $prefix, $search, $status);
    ?>
    <h2>Game Catalog</h2>
    <p>Choose which games customers can rent, what they cost, and which agents they run on.
       Open a game to edit it. Only published games appear on the storefront.</p>
    <?php
    cat_render_import($db, $prefix, $csrf, catalog_admin_agents($db, $prefix), $preview);
    cat_render_headroom($db, $prefix, $csrf);
    cat_render_filter($search, $status);
    ?>
    <p class="cat-muted"><?php echo count($games); ?> game(s) shown.</p>
    <table class="cat-table">
      <thead><tr><th>Game</th><th>Game config</th><th>Storefront</th><th>Lowest price / month</th><th></th></tr></thead>
      <tbody><?php cat_render_game_rows($games); ?></tbody>
    </table>
    <?php
}

function cat_render_details_fields(array $game, array $images): void
{
    ?>
    <h3>What customers see</h3>
    <table class="cat-form">
      <tr><th><label for="cat-name">Display name</label></th>
        <td><input type="text" id="cat-name" name="display_name" maxlength="255" class="cat-wide"
                   value="<?php echo cat_h($game['display_name']); ?>" placeholder="<?php echo cat_h($game['game_name']); ?>">
            <div class="cat-muted">Leave blank to use the game config name.</div></td></tr>
      <tr><th><label for="cat-desc">Description</label></th>
        <td><textarea id="cat-desc" name="description" rows="3" maxlength="1000" class="cat-wide"><?php echo cat_h($game['description']); ?></textarea></td></tr>
      <tr><th><label for="cat-img">Picture</label></th>
        <td><select id="cat-img" name="img_url">
              <option value="">(no picture)</option>
              <?php foreach ($images as $image): ?>
                <option value="<?php echo cat_h($image); ?>"<?php echo $image === $game['img_url'] ? ' selected' : ''; ?>><?php echo cat_h($image); ?></option>
              <?php endforeach; ?>
            </select>
            <img id="cat-picture" class="cat-picture<?php echo $game['img_url'] === '' ? ' cat-hidden' : ''; ?>" alt=""
                 src="<?php echo $game['img_url'] === '' ? '' : cat_h('images/games/' . rawurlencode($game['img_url'])); ?>"></td></tr>
    </table>
    <?php
}

function cat_period_options(string $selected): void
{
    foreach (array('daily' => 'Daily', 'monthly' => 'Monthly', 'yearly' => 'Yearly') as $value => $label) {
        echo '<option value="' . $value . '"' . ($value === $selected ? ' selected' : '') . '>' . $label . '</option>';
    }
}

function cat_render_tier_fields(array $s): void
{
    echo '<div class="cat-mode-fields cat-tiers" data-mode="tiers">';
    foreach ($s['tiers'] as $key => $tier) {
        echo '<span>' . ucfirst($key) . ': <input type="number" min="1" class="cat-number" name="tier[' . $key . '][slot_count]" value="' . (int)$tier['slot_count'] . '"> slots at $'
            . '<input type="number" step="0.01" min="0" class="cat-number" name="tier[' . $key . '][price_monthly]" value="' . cat_money($tier['monthly']) . '"> / month</span>';
    }
    echo '</div>';
}

function cat_render_price_fields(array $s): void
{
    $modes = array('per_slot' => 'Per slot', 'tiers' => 'Tiers (small, medium, large, full)', 'base_plus_extra' => 'Base price + extra slots');
    ?>
    <h3>Price</h3>
    <p class="cat-muted">One price for the game. It is used on every agent ticked below. Only monthly prices are typed in;
       daily is the monthly price divided by 30 and yearly is the monthly price times 12.</p>
    <table class="cat-form">
      <tr><th><label for="cat-mode">Pricing mode</label></th>
        <td><select id="cat-mode" name="pricing_mode">
          <?php foreach ($modes as $value => $label): ?>
            <option value="<?php echo $value; ?>"<?php echo $value === $s['mode'] ? ' selected' : ''; ?>><?php echo cat_h($label); ?></option>
          <?php endforeach; ?>
        </select></td></tr>
      <tr><th><label for="cat-period">Default billing period</label></th>
        <td><select id="cat-period" name="period_default"><?php cat_period_options($s['period']); ?></select></td></tr>
      <tr><th>Slots customers can pick</th>
        <td>From <input type="number" min="1" class="cat-number" name="slot_min_qty" value="<?php echo (int)$s['slot_min']; ?>">
            to <input type="number" min="1" class="cat-number" name="slot_max_qty" value="<?php echo (int)$s['slot_max']; ?>"></td></tr>
      <tr><th>Monthly price</th>
        <td>
          <div class="cat-mode-fields" data-mode="per_slot">$<input type="number" step="0.01" min="0" class="cat-number" name="per_slot_price_monthly"
              value="<?php echo cat_money($s['per_slot_monthly']); ?>"> per slot per month</div>
          <?php cat_render_tier_fields($s); ?>
          <div class="cat-mode-fields" data-mode="base_plus_extra">
            The first <input type="number" min="0" class="cat-number" name="base_slots" value="<?php echo (int)$s['base_slots']; ?>"> slots cost
            $<input type="number" step="0.01" min="0" class="cat-number" name="base_price_monthly" value="<?php echo cat_money($s['base_monthly']); ?>"> per month,
            then $<input type="number" step="0.01" min="0" class="cat-number" name="extra_price_per_slot_monthly" value="<?php echo cat_money($s['extra_monthly']); ?>"> per extra slot per month
          </div>
        </td></tr>
    </table>
    <?php
}

function cat_agent_note(array $agent): string
{
    if ((int)$agent['install_enabled'] !== 1) {
        return 'Installs are turned off on this agent, so customers cannot order here yet.';
    }
    return '';
}

function cat_render_agent_table(array $agents, array $offers): void
{
    ?>
    <h3>Available on</h3>
    <?php if (empty($agents)): ?>
      <p>There are no agents yet. Add an agent to the panel first, then come back to publish this game on it.</p>
      <?php return; ?>
    <?php endif; ?>
    <table class="cat-table">
      <thead><tr><th>Sell on this agent</th><th>Agent</th><th>OS</th><th>City</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($agents as $agent):
          $id = (int)$agent['remote_server_id'];
          $on = isset($offers[$id]) && (int)$offers[$id]['enabled'] === 1; ?>
        <tr>
          <td style="text-align:center;"><input type="checkbox" name="agent_ids[]" value="<?php echo $id; ?>"<?php echo $on ? ' checked' : ''; ?>></td>
          <td><?php echo cat_h($agent['remote_server_name']); ?> <span class="cat-muted">(#<?php echo $id; ?>)</span></td>
          <td><?php echo cat_h($agent['server_os'] ?: 'not set'); ?></td>
          <td><?php echo cat_h($agent['city_name'] ?: 'not set'); ?></td>
          <td class="cat-muted"><?php echo cat_h(cat_agent_note($agent)); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

function cat_render_requirement_fields(array $need): void
{
    ?>
    <h3>Minimum machine size</h3>
    <p class="cat-muted">An agent must have this much free before the game is placed on it. Leave at 0 for no limit.</p>
    <table class="cat-form">
      <tr><th>CPU cores</th><td><input type="number" step="0.1" min="0" class="cat-number" name="min_cpu_cores" value="<?php echo cat_h($need['cpu']); ?>"></td></tr>
      <tr><th>RAM (MB)</th><td><input type="number" step="1" min="0" class="cat-number" name="min_ram_mb" value="<?php echo (int)$need['ram']; ?>"></td></tr>
      <tr><th>Disk (GB)</th><td><input type="number" step="1" min="0" class="cat-number" name="min_disk_gb" value="<?php echo (int)$need['disk']; ?>"></td></tr>
    </table>
    <?php
}

/** Shows only the price fields for the chosen mode, and previews the chosen picture. */
function cat_render_script(): void
{
    ?>
    <script>
    (function () {
        var mode = document.getElementById('cat-mode');
        var picture = document.getElementById('cat-picture');
        var image = document.getElementById('cat-img');
        if (!mode) { return; }
        function showMode() {
            document.querySelectorAll('.cat-mode-fields').forEach(function (box) {
                box.classList.toggle('cat-hidden', box.getAttribute('data-mode') !== mode.value);
            });
        }
        function showPicture() {
            picture.classList.toggle('cat-hidden', image.value === '');
            picture.src = image.value === '' ? '' : 'modules/billing/images/games/' + encodeURIComponent(image.value);
        }
        mode.addEventListener('change', showMode);
        image.addEventListener('change', showPicture);
        showMode();
    })();
    </script>
    <?php
}

function cat_render_edit(mysqli $db, string $prefix, string $csrf, array $game): void
{
    $homeCfgId = (int)$game['home_cfg_id'];
    $offers = catalog_admin_offers($db, $prefix, $homeCfgId);
    $tiers = catalog_admin_tiers($db, $prefix, array_column($offers, 'offer_id'));
    ?>
    <p><a href="<?php echo cat_url(); ?>">&larr; All games</a></p>
    <h2><?php echo cat_h($game['display_name'] !== '' ? $game['display_name'] : $game['game_name']); ?></h2>
    <p class="cat-muted">Game config: <?php echo cat_h($game['home_cfg_file']); ?></p>
    <form method="post" action="<?php echo cat_url(array('home_cfg_id' => $homeCfgId)); ?>">
      <input type="hidden" name="csrf" value="<?php echo cat_h($csrf); ?>">
      <input type="hidden" name="action" value="save_game">
      <input type="hidden" name="home_cfg_id" value="<?php echo $homeCfgId; ?>">
      <?php
      cat_render_details_fields($game, catalog_list_images(cat_image_dir()));
      cat_render_price_fields(catalog_settings_from_offers($offers, $tiers));
      cat_render_agent_table(catalog_admin_agents($db, $prefix), $offers);
      cat_render_requirement_fields(catalog_admin_requirements($db, $prefix, $homeCfgId));
      ?>
      <p style="margin-top:16px;"><button type="submit" class="cat-button">Save game</button></p>
    </form>
    <?php
    cat_render_script();
}

/* ---------------------------------------------------------------------------
   Entry point
--------------------------------------------------------------------------- */

function billing_admin_catalog_render(): void
{
    global $table_prefix;
    $prefix = isset($table_prefix) ? (string)$table_prefix : '';

    if (strtolower((string)($_SESSION['users_role'] ?? '')) !== 'admin') {
        echo '<div class="failure">The Game Catalog needs a panel administrator account.</div>';
        return;
    }
    $db = billing_get_db();
    if (!($db instanceof mysqli)) {
        echo '<div class="failure">Game Catalog: the database connection failed.</div>';
        return;
    }
    $missing = catalog_admin_missing_tables($db, $prefix);
    if (!empty($missing)) {
        echo '<div class="failure">These tables are missing: ' . cat_h(implode(', ', $missing))
            . '. Run the panel update so the database migrations are applied.</div>';
        return;
    }

    $preview = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = cat_handle_post($db, $prefix);
        if ($result['redirect'] !== null) {
            cat_redirect($result['redirect']);
            return;
        }
        $preview = $result['preview'];
    }

    $csrf = billing_action_csrf_token(CAT_CSRF_KEY);
    cat_render_styles();
    cat_render_flash();
    $game = catalog_admin_game($db, $prefix, (int)($_GET['home_cfg_id'] ?? 0));
    if ($game !== null) {
        cat_render_edit($db, $prefix, $csrf, $game);
    } else {
        cat_render_list($db, $prefix, $csrf, $preview);
    }
    billing_maybe_close_db($db);
}

/* Older route (page key game_catalog in navigation.xml). */
if (!function_exists('exec_module')) {
    function exec_module(): void
    {
        billing_admin_catalog_render();
    }
}
