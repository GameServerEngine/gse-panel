<?php require_once __DIR__ . '/brand.php'; ?>
<?php
/**
 * Navigation Menu for GameServers.World Website
 * This file provides a consistent navigation menu across all website pages
 */

require_once(__DIR__ . '/session_bridge.php');
require_once(dirname(__DIR__) . '/billing_bootstrap.php');

if (!function_exists('billing_nav_escape')) {
  function billing_nav_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('billing_nav_is_active')) {
  function billing_nav_is_active(string $target): bool {
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return $current === basename($target);
  }
}

if (!function_exists('billing_nav_link_class')) {
  function billing_nav_link_class(string $target): string {
    return 'gsw-nav-link' . (billing_nav_is_active($target) ? ' is-active' : '');
  }
}

$nav_prefix = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if (is_string($scriptName) && $scriptName !== '') {
  if (preg_match('#/modules/billing/(.*)$#', $scriptName, $match)) {
    // Panel-embedded or non-root deployment: depth relative to modules/billing/
    $subPath = $match[1];
    if ($subPath !== '') {
      $depth = substr_count($subPath, '/');
      if ($depth > 0) {
        $nav_prefix = str_repeat('../', $depth);
      }
    }
  } else {
    // Root deployment: compute prefix from the script's directory depth so that
    // links such as index.php correctly resolve to /index.php even when the
    // current page lives in a subdirectory like /docs/.
    $dir = dirname($scriptName);
    if ($dir !== '/' && $dir !== '' && $dir !== '.') {
      $segments = array_filter(explode('/', $dir), static function ($s) { return $s !== ''; });
      if (!empty($segments)) {
        $nav_prefix = str_repeat('../', count($segments));
      }
    }
  }
}
$nav_prefix = $nav_prefix ?: '';

// Check login status
// Primary check uses website_user_id, but some remote deployments may only set website_username.
// Treat presence of website_username as a fallback to consider the user logged in for UI purposes.
$is_logged_in = (isset($_SESSION['website_user_id']) && !empty($_SESSION['website_user_id'])) || (isset($_SESSION['website_username']) && !empty($_SESSION['website_username']));
$username = '';
if (isset($_SESSION['website_username']) && !empty($_SESSION['website_username'])) {
  $username = htmlspecialchars($_SESSION['website_username']);
} elseif (isset($_SESSION['website_user_id']) && !empty($_SESSION['website_user_id'])) {
  // fetch username lazily if only user_id is present
  $username = htmlspecialchars((string)($_SESSION['website_user_id']));
}

// Determine if the logged-in user is an admin by checking the panel DB
$is_admin = false;
if ($is_logged_in) {
  // load DB credentials
  require_once(__DIR__ . '/config_loader.php');
  
  // Variables from config.inc.php (helps IDEs understand scope)
  /** @var string $db_host Database host */
  /** @var string $db_user Database user */
  /** @var string $db_pass Database password */
  /** @var string $db_name Database name */
  /** @var string $table_prefix Table prefix for database tables */
  
  // Prefer reusing an existing $db if present, otherwise open a local connection
  $menu_db = null;
  $menu_db_opened = false;
  // Only reuse $db if it is still an open (non-closed) connection.
  // mysqli_thread_id() returns 0 on a closed handle; no @ needed since instanceof
  // already guarantees $db is a mysqli object.
  if (isset($db) && $db instanceof mysqli && mysqli_thread_id($db)) {
    $menu_db = $db;
  } else {
    $menu_db_port = isset($db_port) ? (int)$db_port : null;
    $menu_db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $menu_db_port);
    $menu_db_opened = true;
  }

  if ($menu_db) {
    $uid = null;
    if (isset($_SESSION['website_user_id']) && !empty($_SESSION['website_user_id'])) {
      $uid = intval($_SESSION['website_user_id']);
    }
    if (!empty($uid)) {
      $res = mysqli_query($menu_db, "SELECT users_role FROM {$table_prefix}users WHERE user_id = $uid LIMIT 1");
      if ($res && mysqli_num_rows($res) === 1) {
        $row = mysqli_fetch_assoc($res);
        if (strtolower((string)($row['users_role'] ?? '')) === 'admin') $is_admin = true;
      }
    }
    if ($menu_db_opened) {
      if (function_exists('billing_maybe_close_db')) {
        billing_maybe_close_db($menu_db);
      } else {
        @mysqli_close($menu_db);
      }
    }
  }
}
?>
<link rel="stylesheet" href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('css/header.css') : ($nav_prefix . 'css/header.css')); ?>">

<!-- site wrapper for scoping styles -->
<div id="gsw-site">

<div class="gsw-header">
  <div class="gsw-header-top">
    <button class="gsw-nav-toggle" type="button" aria-controls="gsw-primary-nav" aria-expanded="false" aria-label="Open primary navigation">
      <span class="gsw-nav-toggle-bars" aria-hidden="true"></span>
    </button>
    <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('index.php') : ($nav_prefix . 'index.php')); ?>" class="gsw-logo-link">
      <img src="<?php echo billing_nav_escape(function_exists('billing_url') ? panel_asset_url('images/logo.png') : ($nav_prefix . 'images/brand/gsw-text-logo.png')); ?>" alt="" class="gsw-logo-text" aria-hidden="true">
      <span class="gsw-site-name"><?php echo billing_brand_e('name'); ?></span>
    </a>
    <div class="gsw-header-actions">
      <!-- Always show the user-info area (may be empty for guests) and an auth button -->
      <?php
        // Build a safe absolute return_to under this site so auth redirects stay within this module
        $current = $_SERVER['REQUEST_URI'] ?? '/';
        $return_to_param = $current;
      ?>
      <?php if ($is_logged_in): ?>
        <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('my_account.php') : ($nav_prefix . 'my_account.php')); ?>" class="gsw-user-info">Welcome, <?php echo $username; ?></a>
        <a href="<?php echo billing_nav_escape((function_exists('billing_url') ? billing_url('logout.php') : ($nav_prefix . 'logout.php')) . '?return_to=' . urlencode($return_to_param)); ?>" class="gsw-header-btn"><span>Logout</span></a>
      <?php else: ?>
        <a href="<?php echo billing_nav_escape((function_exists('billing_url') ? billing_url('login.php') : ($nav_prefix . 'login.php')) . '?return_to=' . urlencode($return_to_param)); ?>" class="gsw-header-btn"><span>Login</span></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="gsw-header-bottom">
    <nav class="gsw-header-nav" id="gsw-primary-nav" aria-label="Primary navigation">
      <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('index.php') : ($nav_prefix . 'index.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('index.php')); ?>">Home</a>
      <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('serverlist.php') : ($nav_prefix . 'serverlist.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('serverlist.php')); ?>">Game Servers</a>
      <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('docs.php') : ($nav_prefix . 'docs.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('docs.php')); ?>">Documentation</a>
      <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('ai.php') : ($nav_prefix . 'ai.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('ai.php')); ?>">AI Assistant</a>
      <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('support.php') : ($nav_prefix . 'support.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('support.php')); ?>">Support</a>
      <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('contact.php') : ($nav_prefix . 'contact.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('contact.php')); ?>">Contact</a>
      <?php if ($is_logged_in): ?>
        <!-- My Account as a regular nav link, not a prominent button -->
        <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('my_account.php') : ($nav_prefix . 'my_account.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('my_account.php')); ?>">My Account</a>
        <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('cart.php') : ($nav_prefix . 'cart.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('cart.php')); ?>">Cart
          <?php
            $cart_count = 0;
            if (file_exists(__DIR__ . '/cart_helper.php')) {
              include_once __DIR__ . '/cart_helper.php';
              if (function_exists('get_cart_count')) $cart_count = (int) get_cart_count();
            }
            if ($cart_count > 0) echo ' <span class="cart-badge">' . intval($cart_count) . '</span>';
          ?>
        </a>
        <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('my_servers.php') : ($nav_prefix . 'my_servers.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('my_servers.php')); ?>">My Containers</a>
      <?php endif; ?>
      <?php if ($is_logged_in && $is_admin): ?>
        <a href="<?php echo billing_nav_escape(function_exists('billing_url') ? billing_url('admin.php') : ($nav_prefix . 'admin.php')); ?>" class="<?php echo billing_nav_escape(billing_nav_link_class('admin.php')); ?>">Admin</a>
      <?php endif; ?>
      <?php $panelRoot = function_exists('billing_runtime_panel_root') ? billing_runtime_panel_root() : null; ?>
      <a href="<?php echo billing_nav_escape($panelRoot ? '/panel/' : (function_exists('billing_url') ? billing_url('index.php') : ($nav_prefix . 'index.php'))); ?>" class="gsw-nav-link">Control Panel</a>
    </nav>
  </div>
</div>
<script>
(function () {
  var header = document.querySelector('.gsw-header');
  var toggle = document.querySelector('.gsw-nav-toggle');
  var nav = document.getElementById('gsw-primary-nav');
  if (!header || !toggle || !nav) return;
  function setOpen(open) {
    header.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close primary navigation' : 'Open primary navigation');
  }
  toggle.addEventListener('click', function () {
    setOpen(!header.classList.contains('is-open'));
  });
  nav.addEventListener('click', function (event) {
    if (event.target && event.target.closest('a')) {
      setOpen(false);
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      setOpen(false);
    }
  });
})();
</script>
