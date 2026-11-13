<?php
/**
 * Storefront footer, in the GameServer Engine style.
 *
 * Closes the white content section (.gsw-content) and page (.gsw-page) that
 * menu.php opened, prints the footer, then closes the #gsw-site shell.
 */
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/content.php';
require_once dirname(__DIR__) . '/billing_bootstrap.php';

if (!function_exists('billing_footer_link')) {
    /** One footer link to a storefront page. */
    function billing_footer_link(string $target, string $label): string
    {
        return '<a href="' . htmlspecialchars(billing_url($target), ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
    }
}
?>
</div><!-- /.gsw-content -->
</div><!-- /.gsw-page -->

<footer class="gsw-footer">
  <div class="gsw-footer-inner">
    <div class="gsw-footer-brand">
      <img src="<?php echo htmlspecialchars(billing_url('images/brand/gse-logo-footer.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="gsw-footer-logo" width="56" height="56">
      <p><strong><?php echo billing_brand_e('name'); ?></strong><br><?php echo billing_text_e('site.tagline'); ?></p>
    </div>
    <nav class="gsw-footer-links" aria-label="Footer navigation">
      <?php echo billing_footer_link('serverlist.php', 'Game Servers'); ?>
      <?php echo billing_footer_link('support.php', 'Support'); ?>
      <?php if (is_file(dirname(__DIR__) . '/services.php')) { echo billing_footer_link('services.php', 'Services'); } ?>
      <?php echo billing_footer_link('docs.php', 'Docs'); ?>
      <?php echo billing_footer_link('server_status.php', 'Server Status'); ?>
      <?php echo billing_footer_link('company.php', 'Company'); ?>
      <?php echo billing_footer_link('contact.php', 'Contact'); ?>
      <?php echo billing_footer_link('privacy.php', 'Privacy'); ?>
      <?php echo billing_footer_link('tos.php', 'Terms'); ?>
      <?php if (billing_text('careers.enabled') !== '') { echo billing_footer_link('careers.php', 'Careers'); } ?>
    </nav>
    <address class="gsw-footer-contact">
      <?php echo billing_brand_e('company'); ?><br>
<?php foreach (billing_text_list('contact.address_lines') as $billing_line): ?>
      <?php echo htmlspecialchars($billing_line, ENT_QUOTES, 'UTF-8'); ?><br>
<?php endforeach; ?>
<?php if (billing_text('contact.phone') !== ''): ?>
      <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', billing_text('contact.phone')), ENT_QUOTES, 'UTF-8'); ?>"><?php echo billing_text_e('contact.phone'); ?></a><br>
<?php endif; ?>
      <a href="mailto:<?php echo billing_brand_e('email'); ?>"><?php echo billing_brand_e('email'); ?></a>
    </address>
    <p class="gsw-footer-copy">&copy; 2026 <?php echo billing_brand_e('company'); ?>. All rights reserved. Powered by GameServer Engine.<?php
      // Runtime reads modules/billing timestamp; sync from modules/billing/timestamp.txt is handled by billing_bootstrap.php.
      $billing_ts = dirname(__DIR__) . '/timestamp.txt';
      if (is_file($billing_ts)) {
          echo ' <span class="gsw-last-updated">' . htmlspecialchars(trim((string)file_get_contents($billing_ts)), ENT_QUOTES, 'UTF-8') . '</span>';
      }
    ?></p>
  </div>
</footer>

</div><!-- /#gsw-site -->
