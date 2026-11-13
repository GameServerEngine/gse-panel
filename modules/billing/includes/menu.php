<?php
/**
 * Storefront header and navigation, in the GameServer Engine style.
 *
 * Opens the page shell: #gsw-site > .gsw-page > .gsw-content (the white content
 * section). footer.php closes the content section, prints the footer and closes
 * the shell, so every page's markup lands inside the white section.
 */

require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/session_bridge.php';
require_once dirname(__DIR__) . '/billing_bootstrap.php';

if (!function_exists('billing_nav_escape')) {
  function billing_nav_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('billing_nav_link')) {
  /** One primary navigation link; the link for the current script gets is-active. */
  function billing_nav_link(string $target, string $label): string {
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $class = 'gsw-nav-link' . ($current === $target ? ' is-active' : '');
    return '<a href="' . billing_nav_escape(billing_url($target)) . '" class="' . $class . '">' . $label . '</a>';
  }
}

// Signed in when the shared session carries a website user (the id is the panel user id).
$is_logged_in = !empty($_SESSION['website_user_id']) || !empty($_SESSION['website_username']);
$username = '';
if (!empty($_SESSION['website_username'])) {
  $username = (string)$_SESSION['website_username'];
} elseif (!empty($_SESSION['website_user_id'])) {
  $username = (string)$_SESSION['website_user_id'];
}

$cart_count = 0;
if ($is_logged_in && file_exists(__DIR__ . '/cart_helper.php')) {
  include_once __DIR__ . '/cart_helper.php';
  if (function_exists('get_cart_count')) {
    $cart_count = (int)get_cart_count();
  }
}

// The brand name is two words on the GameServer Engine mark: "Game Server" / "Engine".
$brandName = trim(billing_brand('name'));
$brandSplit = strrpos($brandName, ' ');
$brandFirst = $brandSplit === false ? $brandName : substr($brandName, 0, $brandSplit);
$brandLast = $brandSplit === false ? '' : substr($brandName, $brandSplit + 1);

$returnTo = (string)($_SERVER['REQUEST_URI'] ?? '/');
?>
<div id="gsw-site">

<header class="gsw-header">
  <div class="gsw-header-inner">
    <a href="<?php echo billing_nav_escape(billing_url('index.php')); ?>" class="gsw-brand" aria-label="<?php echo billing_brand_e('name'); ?> home">
      <img src="<?php echo billing_nav_escape(billing_url('images/brand/gse-icon-mark.png')); ?>" alt="" class="gsw-brand-mark" width="64" height="64">
      <span class="gsw-brand-text"><strong><?php echo billing_nav_escape($brandFirst); ?></strong><?php if ($brandLast !== ''): ?> <span><?php echo billing_nav_escape($brandLast); ?></span><?php endif; ?></span>
    </a>
    <button class="gsw-nav-toggle" type="button" aria-controls="gsw-primary-nav" aria-expanded="false" aria-label="Open primary navigation">
      <span class="gsw-nav-toggle-bars" aria-hidden="true"></span>
    </button>
    <nav class="gsw-header-nav" id="gsw-primary-nav" aria-label="Primary navigation">
      <?php echo billing_nav_link('index.php', 'Home'); ?>
      <?php echo billing_nav_link('serverlist.php', 'Game Servers'); ?>
      <?php echo billing_nav_link('services.php', 'Services'); ?>
      <?php echo billing_nav_link('docs.php', 'Documentation'); ?>
      <?php echo billing_nav_link('support.php', 'Support'); ?>
      <?php echo billing_nav_link('contact.php', 'Contact'); ?>
      <?php if ($is_logged_in): ?>
        <?php echo billing_nav_link('my_servers.php', 'My Servers'); ?>
        <?php echo billing_nav_link('my_account.php', 'My Account'); ?>
        <a href="<?php echo billing_nav_escape(billing_url('cart.php')); ?>" class="gsw-nav-link<?php echo basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'cart.php' ? ' is-active' : ''; ?>">Cart<?php if ($cart_count > 0): ?><span class="cart-badge"><?php echo $cart_count; ?></span><?php endif; ?></a>
      <?php endif; ?>
      <a href="<?php echo billing_nav_escape(panel_home_url()); ?>" class="gsw-nav-link">Control Panel</a>
      <?php if ($is_logged_in): ?>
        <a href="<?php echo billing_nav_escape(billing_url('logout.php') . '?return_to=' . urlencode($returnTo)); ?>" class="gsw-btn gsw-nav-cta" title="Signed in as <?php echo billing_nav_escape($username); ?>">Logout</a>
      <?php else: ?>
        <a href="<?php echo billing_nav_escape(billing_url('login.php') . '?return_to=' . urlencode($returnTo)); ?>" class="gsw-btn gsw-nav-cta">Login</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<div class="gsw-spacer" aria-hidden="true"></div>
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
    if (event.target && event.target.closest('a')) setOpen(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') setOpen(false);
  });
})();
</script>

<?php
require_once __DIR__ . '/page_header.php';
// Home sets $gsw_defer_content_open so it can print its full-width black band first,
// then call billing_open_content() itself. Every other page gets the uniform dark page
// header built from its own title (see page_header.php).
if (empty($GLOBALS['gsw_defer_content_open'])) {
  ob_start('billing_page_header_filter');
  billing_open_content();
}
?>
