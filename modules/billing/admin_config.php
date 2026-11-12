<?php
/**
 * Admin Config Editor
 *
 * Provides billing configuration controls for PayPal and related settings.
 *
 * Database settings remain internal to the panel and are never rendered here.
 */

require_once(__DIR__ . '/includes/admin_auth.php');
require_once(__DIR__ . '/includes/config_loader.php');
require_once(__DIR__ . '/includes/action_security.php');
require_once(__DIR__ . '/includes/paypal_credentials.php');
if (!billing_is_panel_context()) {
    include(__DIR__ . '/includes/top.php');
    include(__DIR__ . '/includes/menu.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('gameserverpanel_web');
    session_start();
}
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['admin_csrf'];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Read current values from config (already loaded by config_loader above).
// ---------------------------------------------------------------------------
$cfgVals = [
    'SITE_BASE_URL'                  => $SITE_BASE_URL          ?? '',
    'SITE_BACKGROUND'                => $SITE_BACKGROUND         ?? 'images/dark.jpg',
    'SITE_DATA_DIR'                  => $SITE_DATA_DIR           ?? '',
    'paypal_mode'                    => $paypal_mode             ?? 'sandbox',
    'paypal_sandbox_client_id'       => $paypal_sandbox_client_id       ?? '',
    'paypal_sandbox_client_secret'   => '',
    'paypal_sandbox_webhook_id'      => $paypal_sandbox_webhook_id      ?? '',
    'paypal_live_client_id'          => $paypal_live_client_id          ?? '',
    'paypal_live_client_secret'      => '',
    'paypal_live_webhook_id'         => $paypal_live_webhook_id         ?? '',
    'paypal_webhook_path'            => $paypal_webhook_path            ?? '/paypal/webhook.php',
];

// Computed full webhook URL for display
$computedWebhookUrl = function_exists('paypal_get_full_webhook_url')
    ? paypal_get_full_webhook_url()
    : rtrim($cfgVals['SITE_BASE_URL'], '/') . $cfgVals['paypal_webhook_path'];

// Detect panel-mode (DB settings are managed by the panel)
$panelMode     = defined('BILLING_PANEL_CONFIG_PATH');
$panelCfgPath  = $panelMode ? BILLING_PANEL_CONFIG_PATH : null;

$status = '';
$statusType = 'info';   // 'success' | 'error' | 'info'
$paypalDb = billing_get_paypal_db_config();
$paypalCsrf = billing_action_csrf_token('billing_paypal_config_csrf');
$cfgVals['paypal_mode'] = ($paypalDb['paypal_mode'] ?? $cfgVals['paypal_mode']) === 'live' ? 'live' : 'sandbox';
if (($paypalDb['paypal_client_id'] ?? '') !== '') {
    if ($cfgVals['paypal_mode'] === 'live') {
        $cfgVals['paypal_live_client_id'] = (string)$paypalDb['paypal_client_id'];
        $cfgVals['paypal_live_client_secret'] = (string)($paypalDb['paypal_client_secret'] ?? '');
        $cfgVals['paypal_live_webhook_id'] = (string)($paypalDb['paypal_webhook_id'] ?? '');
    } else {
        $cfgVals['paypal_sandbox_client_id'] = (string)$paypalDb['paypal_client_id'];
        $cfgVals['paypal_sandbox_client_secret'] = (string)($paypalDb['paypal_client_secret'] ?? '');
        $cfgVals['paypal_sandbox_webhook_id'] = (string)($paypalDb['paypal_webhook_id'] ?? '');
    }
}

// ---------------------------------------------------------------------------
// POST: Save interactive form
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_form') {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, (string)$token)) {
        $status     = 'Invalid CSRF token.';
        $statusType = 'error';
    } else {
        // Collect and validate form values
        $formVals = [
            'SITE_BACKGROUND'             => trim($_POST['SITE_BACKGROUND']             ?? 'images/dark.jpg'),
            'SITE_DATA_DIR'               => trim($_POST['SITE_DATA_DIR']               ?? ''),
            'SITE_BASE_URL'               => trim($_POST['SITE_BASE_URL']               ?? ''),
            'paypal_mode'                 => (strtolower(trim($_POST['paypal_mode'] ?? 'sandbox')) === 'live') ? 'live' : 'sandbox',
            'paypal_sandbox_client_id'    => trim($_POST['paypal_sandbox_client_id']    ?? ''),
            'paypal_live_client_id'       => trim($_POST['paypal_live_client_id']       ?? ''),
            'paypal_sandbox_webhook_id'   => trim($_POST['paypal_sandbox_webhook_id']   ?? ''),
            'paypal_live_webhook_id'      => trim($_POST['paypal_live_webhook_id']      ?? ''),
            'paypal_webhook_path'         => trim($_POST['paypal_webhook_path']         ?? '/paypal/webhook.php'),
        ];

        // Client secrets: only update if a non-blank value was submitted (never erase existing).
        $sbSecPost = trim($_POST['paypal_sandbox_client_secret'] ?? '');
        $formVals['paypal_sandbox_client_secret'] = '';

        $lvSecPost = trim($_POST['paypal_live_client_secret'] ?? '');
        $formVals['paypal_live_client_secret'] = '';

        // Validate
        $validationError = '';
        if ($validationError) {
            $status     = $validationError;
            $statusType = 'error';
        } else {
            // The visible form submits mode-specific names; persist the active mode
            // credentials in the panel database as part of this same save request.
            $paypalMode = $formVals['paypal_mode'];
            $paypalId = $paypalMode === 'live' ? $formVals['paypal_live_client_id'] : $formVals['paypal_sandbox_client_id'];
            $paypalWebhook = $paypalMode === 'live' ? $formVals['paypal_live_webhook_id'] : $formVals['paypal_sandbox_webhook_id'];
            $paypalSecret = $paypalMode === 'live' ? $lvSecPost : $sbSecPost;
            $paypalKey = billing_paypal_read_key();
            if ($paypalSecret !== '' && $paypalKey === '') {
                $status = 'Save failed: encryption key not available.';
                $statusType = 'error';
            } else {
                global $db_host, $db_user, $db_pass, $db_name, $db_port, $table_prefix;
                $paypalDbLink = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : 0);
                if (!$paypalDbLink) {
                    $status = 'Save failed: database connection unavailable.';
                    $statusType = 'error';
                } else {
                    try {
                        $encryptedSecret = $paypalSecret !== ''
                            ? billing_paypal_encrypt_secret($paypalSecret, $paypalKey)
                            : (string)($paypalDb['paypal_client_secret_enc'] ?? '');
                        $esc = static fn(string $value): string => mysqli_real_escape_string($paypalDbLink, $value);
                        $globalResult = mysqli_query($paypalDbLink, "SELECT config_id FROM `{$table_prefix}billing_config` WHERE game_key='__global__' ORDER BY config_id ASC LIMIT 1");
                        $globalRow = $globalResult ? mysqli_fetch_assoc($globalResult) : null;
                        if ($globalRow) {
                            $sql = "UPDATE `{$table_prefix}billing_config` SET paypal_mode='" . $esc($paypalMode) . "', paypal_client_id='" . $esc($paypalId) . "', paypal_client_secret_enc='" . $esc($encryptedSecret) . "', paypal_webhook_id='" . $esc($paypalWebhook) . "' WHERE config_id=" . (int)$globalRow['config_id'];
                        } else {
                            $sql = "INSERT INTO `{$table_prefix}billing_config` (game_key, paypal_mode, paypal_client_id, paypal_client_secret_enc, paypal_webhook_id) VALUES ('__global__','" . $esc($paypalMode) . "','" . $esc($paypalId) . "','" . $esc($encryptedSecret) . "','" . $esc($paypalWebhook) . "')";
                        }
                        if (!mysqli_query($paypalDbLink, $sql)) {
                            throw new RuntimeException('database write failed.');
                        }
                        $paypalDb = billing_paypal_config($paypalDbLink, (string)$table_prefix);
                        $cfgVals['paypal_mode'] = $paypalMode;
                        if ($paypalMode === 'live') {
                            $cfgVals['paypal_live_client_id'] = $paypalDb['paypal_client_id'] ?? '';
                            $cfgVals['paypal_live_client_secret'] = $paypalDb['paypal_client_secret'] ?? '';
                            $cfgVals['paypal_live_webhook_id'] = $paypalDb['paypal_webhook_id'] ?? '';
                        } else {
                            $cfgVals['paypal_sandbox_client_id'] = $paypalDb['paypal_client_id'] ?? '';
                            $cfgVals['paypal_sandbox_client_secret'] = $paypalDb['paypal_client_secret'] ?? '';
                            $cfgVals['paypal_sandbox_webhook_id'] = $paypalDb['paypal_webhook_id'] ?? '';
                        }
                        $status = 'PayPal settings saved.';
                        $statusType = 'success';
                    } catch (Throwable $e) {
                        $status = 'Save failed: ' . $e->getMessage();
                        $statusType = 'error';
                    }
                    mysqli_close($paypalDbLink);
                }
            }
        }
    }
}

?>
<?php if (!billing_is_panel_context()): ?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin — Edit Config</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/header.css">
  <style>
    .cfg-section { background:transparent; border:1px solid rgba(128,128,128,0.4); border-radius:6px; padding:20px 24px; margin-bottom:28px; }
    .cfg-section h2 { margin-top:0; color:inherit; border-bottom:2px solid rgba(128,128,128,0.4); padding-bottom:8px; }
    .field-group { margin-bottom:18px; }
    .field-group label { display:block; font-weight:600; color:inherit; margin-bottom:4px; }
    .field-help { font-size:0.9em; color:inherit; opacity:0.8; margin-bottom:6px; }
    .field-group input[type=text],
    .field-group input[type=password],
    .field-group input[type=number],
    .field-group select { width:100%; max-width:520px; padding:8px 10px; border:1px solid #ccc;
                           border-radius:4px; font-size:1em; box-sizing:border-box; }
    .field-group .pw-wrap { display:flex; gap:6px; align-items:center; max-width:520px; }
    .field-group .pw-wrap input { flex:1; }
    .btn-show { padding:8px 14px; font-size:0.9em; border:1px solid #aaa; border-radius:4px;
                background:#f5f5f5; color:#222; cursor:pointer; white-space:nowrap; }
    .status-box { padding:12px 16px; border-radius:4px; margin-bottom:18px; font-weight:600; }
    .status-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .status-error   { background:#f8d7da; color:#8b1e2d; border:1px solid #b21f35; }
    .status-info    { background:#d1ecf1; color:#0c5460; border:1px solid #bee5eb; }
    .panel-badge { background:#e8f4fd; border:1px solid #9ec8f0; color:#1a5276; padding:10px 14px;
                   border-radius:4px; margin-bottom:18px; font-size:0.9em; }
    .readonly-field { background:rgba(128,128,128,0.15); color:inherit; cursor:not-allowed; }
    .warn-box { background:#fff3cd; border:1px solid #ffc107; color:#856404; padding:10px 14px;
                border-radius:4px; margin-bottom:14px; font-size:0.9em; }
    .save-row { margin:14px 0; }
    .save-row button { padding:10px 24px; font-size:1em; font-weight:600; }
  </style>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php endif; ?>
<div class="container-wide panel">
  <h1>Edit Site Config</h1>

  <?php if ($status): ?>
  <div class="status-box status-<?php echo h($statusType); ?>"><?php echo h($status); ?></div>
  <?php endif; ?>

  <?php if (!empty($billing_config_warning)): ?>
  <div class="warn-box">⚠️ <?php echo h($billing_config_warning); ?></div>
  <?php endif; ?>

  <!-- ===================================================================
       SECTION A: Interactive form
  ==================================================================== -->
  <div class="cfg-section">
    <form method="post" action="home.php?m=billing&amp;p=billing&amp;s=config">
      <input type="hidden" name="csrf"   value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_form">



      <!-- Site Background -->
      <div class="field-group">
        <label for="cfg_bg">Site Background Image</label>
        <div class="field-help">
          Path to background image relative to the billing site root (e.g. <code>images/dark.jpg</code>).
        </div>
        <input type="text" id="cfg_bg" name="SITE_BACKGROUND"
               value="<?php echo h((string)$cfgVals['SITE_BACKGROUND']); ?>"
               placeholder="images/dark.jpg">
      </div>

      <!-- Data Directory -->
      <div class="field-group">
        <label for="cfg_datadir">Site Data Directory</label>
        <div class="field-help">
          Absolute path where payment webhook JSON files are stored.
          Leave empty to use the default: <code>modules/billing/data/</code>.
        </div>
        <input type="text" id="cfg_datadir" name="SITE_DATA_DIR"
               value="<?php echo h((string)$cfgVals['SITE_DATA_DIR']); ?>"
               placeholder="(default: billing/data/)">
      </div>

      <hr style="border:none;border-top:1px solid rgba(128,128,128,0.4);margin:24px 0;">
      <h3 style="margin-top:0;color:inherit;">PayPal Configuration</h3>

      <?php
      $isSandboxMode = ($cfgVals['paypal_mode'] ?? 'sandbox') !== 'live';
      $modeLabel     = $isSandboxMode ? '🟡 Sandbox (test mode)' : '🟢 Live (real payments)';
      $modeBadgeClass = $isSandboxMode ? 'status-info' : 'status-success';
      ?>
      <div class="status-box <?php echo h($modeBadgeClass); ?>" style="margin-bottom:14px;font-size:0.95em;">
        Currently active PayPal mode: <strong><?php echo h($modeLabel); ?></strong>
      </div>

      <!-- PayPal Mode -->
      <div class="field-group">
        <label for="cfg_mode">PayPal Mode</label>
        <div class="field-help">
          <strong>Sandbox</strong> uses test credentials and the PayPal sandbox API — safe for development.
          <strong>Live</strong> processes real payments. Switch only after configuring live credentials.
        </div>
        <select id="cfg_mode" name="paypal_mode">
          <option value="sandbox" <?php echo $isSandboxMode ? 'selected' : ''; ?>>Sandbox (test mode)</option>
          <option value="live"    <?php echo !$isSandboxMode ? 'selected' : ''; ?>>Live (real payments)</option>
        </select>
      </div>

      <!-- Sandbox credentials -->
      <h4 style="color:inherit;margin:20px 0 8px;">Sandbox Credentials</h4>
      <div class="field-group">
        <label for="cfg_sb_id">Sandbox Client ID</label>
        <div class="field-help">Found in PayPal Developer Dashboard → sandbox app. Safe to expose in browser JS.</div>
        <input type="text" id="cfg_sb_id" name="paypal_sandbox_client_id"
               value="<?php echo h((string)$cfgVals['paypal_sandbox_client_id']); ?>"
               placeholder="AfvY_... or sandbox client ID">
      </div>
      <div class="field-group">
        <label for="cfg_sb_sec">Sandbox Client Secret</label>
        <div class="field-help"><strong>Server-side only</strong> — never sent to the browser. Leave blank to keep existing value.</div>
        <input type="password" id="cfg_sb_sec" name="paypal_sandbox_client_secret"
                 placeholder="<?php echo $cfgVals['paypal_sandbox_client_secret'] !== '' ? '(set — leave blank to keep)' : '(not set)'; ?>"
                 autocomplete="new-password">
      </div>
      <div class="field-group">
        <label for="cfg_sb_wh">Sandbox Webhook ID</label>
        <div class="field-help">
          Webhook ID from your PayPal sandbox app (for signature verification).
          Leave empty to skip verification in sandbox mode (OK for initial setup).
        </div>
        <input type="text" id="cfg_sb_wh" name="paypal_sandbox_webhook_id"
               value="<?php echo h((string)$cfgVals['paypal_sandbox_webhook_id']); ?>"
               placeholder="Sandbox Webhook ID">
      </div>

      <!-- Live credentials -->
      <h4 style="color:inherit;margin:20px 0 8px;">Live Credentials</h4>
      <div class="field-group">
        <label for="cfg_lv_id">Live Client ID</label>
        <div class="field-help">From your PayPal live app. Leave blank until ready for production.</div>
        <input type="text" id="cfg_lv_id" name="paypal_live_client_id"
               value="<?php echo h((string)$cfgVals['paypal_live_client_id']); ?>"
               placeholder="Live Client ID">
      </div>
      <div class="field-group">
        <label for="cfg_lv_sec">Live Client Secret</label>
        <div class="field-help"><strong>Server-side only.</strong> Leave blank to keep existing value.</div>
        <input type="password" id="cfg_lv_sec" name="paypal_live_client_secret"
                 placeholder="<?php echo $cfgVals['paypal_live_client_secret'] !== '' ? '(set — leave blank to keep)' : '(not set)'; ?>"
                 autocomplete="new-password">
      </div>
      <div class="field-group">
        <label for="cfg_lv_wh">Live Webhook ID</label>
        <div class="field-help">Webhook ID from your PayPal live app (for signature verification).</div>
        <input type="text" id="cfg_lv_wh" name="paypal_live_webhook_id"
               value="<?php echo h((string)$cfgVals['paypal_live_webhook_id']); ?>"
               placeholder="Live Webhook ID">
      </div>

      <!-- Webhook path + computed URL -->
      <h4 style="color:inherit;margin:20px 0 8px;">Webhook Endpoint</h4>
      <div class="field-help" style="margin-bottom:10px;">
        PayPal requires a <strong>full public HTTPS URL</strong> to deliver webhook events.
        Copy the computed URL below into your PayPal app's webhook configuration.
      </div>
      <div class="field-group">
        <label for="cfg_wh_path">Webhook Path</label>
        <div class="field-help">Path relative to the billing site root (must start with <code>/</code>). Default: <code>/paypal/webhook.php</code></div>
        <input type="text" id="cfg_wh_path" name="paypal_webhook_path"
               value="<?php echo h((string)$cfgVals['paypal_webhook_path']); ?>"
               placeholder="/paypal/webhook.php"
               oninput="updateWebhookUrl()">
      </div>
      <div class="field-group">
        <label>Computed Full Webhook URL <small style="font-weight:normal;opacity:0.8;">(read-only — paste this into PayPal)</small></label>
        <div class="field-help">
          This is the URL PayPal will POST webhook events to.
          It must be publicly accessible over HTTPS before enabling live mode.
        </div>
        <input type="text" id="computed_webhook_url"
               class="readonly-field"
               value="<?php echo h($computedWebhookUrl); ?>"
               readonly
               style="font-family:monospace;color:inherit;background:rgba(128,128,128,0.15);">
        <button type="button" id="copy_webhook_url_btn" class="btn-show" style="margin-top:4px;"
                onclick="var u=document.getElementById('computed_webhook_url');if(u){navigator.clipboard.writeText(u.value).then(function(){var b=document.getElementById('copy_webhook_url_btn');b.textContent='Copied!';setTimeout(function(){b.textContent='Copy';},2000);});}">Copy</button>
      </div>
      <script>
        function updateWebhookUrl() {
          var path = document.getElementById('cfg_wh_path');
          var out  = document.getElementById('computed_webhook_url');
          if (!path || !out) return;
          var b = <?php echo json_encode(rtrim((string)$cfgVals['SITE_BASE_URL'], '/')); ?>;
          var p = path.value.replace(/^([^\/])/, '/$1');
          out.value = b + p;
        }
        document.addEventListener('DOMContentLoaded', function() {
          updateWebhookUrl();
        });
      </script>

      <div class="save-row">
        <button type="submit">💾 Save Settings</button>
      </div>
    </form>
  </div>

  <!-- ===================================================================
       SECTION B: PayPal Diagnostics
  ==================================================================== -->
  <?php
  // Gather diagnostics data
  $diag_mode         = $cfgVals['paypal_mode'] ?? 'sandbox';
  $diag_is_sandbox   = $diag_mode !== 'live';
  $diag_sb_id_set    = ($cfgVals['paypal_sandbox_client_id']     ?? '') !== '';
  $diag_sb_sec_set   = ($cfgVals['paypal_sandbox_client_secret'] ?? '') !== '';
  $diag_sb_wh_set    = ($cfgVals['paypal_sandbox_webhook_id']    ?? '') !== '';
  $diag_lv_id_set    = ($cfgVals['paypal_live_client_id']        ?? '') !== '';
  $diag_lv_sec_set   = ($cfgVals['paypal_live_client_secret']    ?? '') !== '';
  $diag_lv_wh_set    = ($cfgVals['paypal_live_webhook_id']       ?? '') !== '';
  $diag_wh_path      = '/' . ltrim((string)($cfgVals['paypal_webhook_path'] ?? '/paypal/webhook.php'), '/');
  $diag_wh_full_url  = $computedWebhookUrl;
  $diag_wh_file      = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($diag_wh_path, '/');
  $diag_wh_exists    = file_exists($diag_wh_file);

  // Active mode credential check
  $diag_active_id_set  = $diag_is_sandbox ? $diag_sb_id_set  : $diag_lv_id_set;
  $diag_active_sec_set = $diag_is_sandbox ? $diag_sb_sec_set : $diag_lv_sec_set;
  $diag_active_wh_set  = $diag_is_sandbox ? $diag_sb_wh_set  : $diag_lv_wh_set;

  function diag_badge(bool $ok, string $yes = 'Yes', string $no = 'No'): string {
      $cls   = $ok ? 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;' : 'background:#f8d7da;color:#8b1e2d;border:1px solid #b21f35;';
      $label = $ok ? $yes : $no;
      return '<span style="' . $cls . 'padding:2px 8px;border-radius:3px;font-size:0.85em;font-weight:600;display:inline-block;word-break:break-word;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
  }

  // Last webhook events + recent PayPal errors
  $diag_recent_events = [];
  $diag_recent_errors = [];
  $diag_errors_warning = '';
  try {
      $port_int = intval($db_port ?? 3306) ?: 3306;
      $diag_db  = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', $port_int);
      if ($diag_db) {
          $pfx_diag = $table_prefix ?? '';
          mysqli_set_charset($diag_db, 'utf8mb4');

          $res = @mysqli_query($diag_db, "SELECT paypal_event_id, event_type, processing_status, created_at FROM `{$pfx_diag}billing_paypal_webhook_events` ORDER BY id DESC LIMIT 5");
          if ($res) {
              while ($row = mysqli_fetch_assoc($res)) {
                  $diag_recent_events[] = $row;
              }
          }

          // Recent PayPal errors — use BillingRepository for safe table creation
          require_once __DIR__ . '/classes/BillingRepository.php';
          $diag_repo = new BillingRepository($diag_db, $pfx_diag);
          if ($diag_repo->ensureBillingPaypalErrorsTable()) {
              $diag_recent_errors = $diag_repo->getRecentPaypalErrors(10);
          } else {
              $diag_errors_warning = 'Could not create billing_paypal_errors table. Check DB permissions.';
          }

          mysqli_close($diag_db);
      }
  } catch (Throwable $e) {
      $diag_errors_warning = 'Diagnostics DB query failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  }
  ?>
  <style>
    .diag-grid { display:grid; grid-template-columns:1fr; gap:8px; font-size:1em; }
    @media (min-width:600px) { .diag-grid { grid-template-columns:280px 1fr; } }
    .diag-row { display:contents; }
    .diag-label { color:inherit; opacity:0.85; font-weight:600; padding:6px 0; border-bottom:1px solid rgba(128,128,128,0.3); word-break:break-word; }
    .diag-value { padding:6px 0; border-bottom:1px solid rgba(128,128,128,0.3); word-break:break-all; }
    .diag-sub { font-size:0.9em; color:inherit; opacity:0.75; margin-top:4px; }
    .diag-sep { grid-column:1/-1; border-top:2px solid rgba(128,128,128,0.4); margin:6px 0 2px; }
    .recent-errors-table { width:100%; border-collapse:collapse; font-size:0.95em; overflow-x:auto; display:block; }
    .recent-errors-table th { background:rgba(128,128,128,0.2); padding:6px 8px; text-align:left; border-bottom:2px solid rgba(128,128,128,0.4); white-space:nowrap; }
    .recent-errors-table td { padding:5px 8px; border-bottom:1px solid rgba(128,128,128,0.3); word-break:break-word; }
  </style>
  <div class="cfg-section">
    <h2>PayPal Diagnostics</h2>

    <!-- Self-check button -->
    <form method="post" style="margin-bottom:16px;">
      <input type="hidden" name="csrf"   value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="self_check">
      <button type="submit" class="btn-show" style="padding:9px 18px;font-size:0.95em;">🔍 Run Billing Self-Check</button>
    </form>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'self_check') {
        $token = $_POST['csrf'] ?? '';
        if (hash_equals($csrf, (string)$token)):
    ?>
    <div class="status-box status-info" style="font-size:0.9em;">
      <strong>Self-Check Results:</strong><br>
      &bull; Mode: <strong><?php echo h($diag_mode ?: '(unknown)'); ?></strong><br>
      &bull; Active Client ID: <?php echo $diag_active_id_set  ? '✅ configured' : '❌ missing'; ?><br>
      &bull; Active Client Secret: <?php echo $diag_active_sec_set ? '✅ configured' : '❌ missing'; ?><br>
      &bull; Active Webhook ID: <?php echo $diag_active_wh_set  ? '✅ configured' : '⚠️ missing (signature verification skipped)'; ?><br>
      &bull; Webhook file: <?php echo $diag_wh_exists ? '✅ exists' : '❌ not found'; ?> — <code style="word-break:break-all"><?php echo h($diag_wh_file); ?></code><br>
      &bull; Logs directory: <?php $logDir = __DIR__ . '/logs'; echo (is_dir($logDir) && is_writable($logDir)) ? '✅ writable' : '⚠️ ' . (is_dir($logDir) ? 'not writable' : 'missing'); ?><br>
      &bull; Data directory: <?php echo (is_dir($SITE_DATA_DIR ?? '') && is_writable($SITE_DATA_DIR ?? '')) ? '✅ writable' : '⚠️ check path'; ?><br>
    </div>
    <?php endif; } ?>

    <div class="diag-grid">
      <div class="diag-row">
        <div class="diag-label">Current mode</div>
        <div class="diag-value">
          <strong><?php echo h($diag_mode !== '' ? $diag_mode : '(not set)'); ?></strong>
          <?php if ($diag_mode === 'sandbox'): ?>
            <span style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:1px 7px;border-radius:3px;font-size:0.8em;margin-left:6px;">test</span>
          <?php elseif ($diag_mode === 'live'): ?>
            <span style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:1px 7px;border-radius:3px;font-size:0.8em;margin-left:6px;">live</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="diag-sep"></div>

      <div class="diag-row">
        <div class="diag-label">Active Client ID</div>
        <div class="diag-value"><?php echo diag_badge($diag_active_id_set); ?></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Active Client Secret</div>
        <div class="diag-value"><?php echo diag_badge($diag_active_sec_set); ?></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Active Webhook ID</div>
        <div class="diag-value"><?php echo diag_badge($diag_active_wh_set, 'Yes', 'No — signature verification skipped'); ?></div>
      </div>

      <div class="diag-sep"></div>

      <div class="diag-row">
        <div class="diag-label">Sandbox Client ID</div>
        <div class="diag-value"><?php echo diag_badge($diag_sb_id_set); ?></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Sandbox Client Secret</div>
        <div class="diag-value"><?php echo diag_badge($diag_sb_sec_set); ?></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Sandbox Webhook ID</div>
        <div class="diag-value"><?php echo diag_badge($diag_sb_wh_set); ?></div>
      </div>

      <div class="diag-sep"></div>

      <div class="diag-row">
        <div class="diag-label">Live Client ID</div>
        <div class="diag-value"><?php echo diag_badge($diag_lv_id_set); ?></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Live Client Secret</div>
        <div class="diag-value"><?php echo diag_badge($diag_lv_sec_set); ?></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Live Webhook ID</div>
        <div class="diag-value"><?php echo diag_badge($diag_lv_wh_set); ?></div>
      </div>

      <div class="diag-sep"></div>

      <div class="diag-row">
        <div class="diag-label">Webhook path</div>
        <div class="diag-value"><code><?php echo h($diag_wh_path); ?></code></div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Full public webhook URL</div>
        <div class="diag-value">
          <code><?php echo h($diag_wh_full_url !== '' ? $diag_wh_full_url : '(Site Base URL not configured)'); ?></code>
        </div>
      </div>
      <div class="diag-row">
        <div class="diag-label">Webhook file on disk</div>
        <div class="diag-value">
          <?php echo diag_badge($diag_wh_exists, 'Found', 'Not found'); ?>
          <div class="diag-sub"><code><?php echo h($diag_wh_file); ?></code></div>
        </div>
      </div>
    </div>

    <?php if (!empty($diag_recent_events)): ?>
    <h4 style="margin-top:22px;color:inherit;">Recent Webhook Events</h4>
    <div style="overflow-x:auto;">
    <table class="recent-errors-table">
      <thead><tr>
        <th>PayPal Event ID</th>
        <th>Type</th>
        <th>Status</th>
        <th>Received</th>
      </tr></thead>
      <tbody>
      <?php foreach ($diag_recent_events as $ev): ?>
        <tr>
          <td><code><?php echo h($ev['paypal_event_id'] ?: '—'); ?></code></td>
          <td><?php echo h($ev['event_type']); ?></td>
          <td><?php echo diag_badge($ev['processing_status'] === 'processed', $ev['processing_status'], $ev['processing_status']); ?></td>
          <td><?php echo h($ev['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
    <p style="opacity:0.8;font-size:0.95em;margin-top:12px;">No webhook events recorded yet. Events will appear here after PayPal delivers the first webhook to <code><?php echo h($diag_wh_full_url ?: $diag_wh_path); ?></code>.</p>
    <?php endif; ?>

    <h4 style="margin-top:22px;color:inherit;">Recent PayPal Errors</h4>
    <?php if ($diag_errors_warning): ?>
    <div class="warn-box"><?php echo h($diag_errors_warning); ?></div>
    <?php elseif (empty($diag_recent_errors)): ?>
    <p style="opacity:0.8;font-size:0.95em;">No PayPal errors logged yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="recent-errors-table">
      <thead><tr>
        <th>Time</th><th>Context</th><th>Error Code</th><th>Message</th>
        <th>Debug ID</th><th>Order ID</th><th>User</th>
      </tr></thead>
      <tbody>
      <?php foreach ($diag_recent_errors as $er): ?>
        <tr>
          <td style="white-space:nowrap"><?php echo h($er['created_at']); ?></td>
          <td><?php echo h($er['context']); ?></td>
          <td><code><?php echo h($er['error_code']); ?></code></td>
          <td><?php echo h($er['message']); ?></td>
          <td><code><?php echo h($er['paypal_debug_id'] ?? '—'); ?></code></td>
          <td><code><?php echo h($er['order_id'] ?? '—'); ?></code></td>
          <td><?php echo h($er['user_id'] ?? '—'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php if (!billing_is_panel_context()): ?><?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
<?php endif; ?>
