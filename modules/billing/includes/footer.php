<?php
// Simple footer include
require_once(dirname(__DIR__) . '/billing_bootstrap.php');
?>
<footer class="gsw-footer">
  <div class="container-wide gsw-footer-grid">
    <div class="gsw-footer-brand">
      <strong>GameServers.World</strong>
      <span>Game server hosting with human support from Run Level Systems.</span>
    </div>
    <div class="gsw-footer-group">
      <h2>Navigation</h2>
      <nav class="gsw-footer-links" aria-label="Footer navigation">
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('serverlist.php') : 'serverlist.php', ENT_QUOTES, 'UTF-8'); ?>">Game Servers</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('support.php') : 'support.php', ENT_QUOTES, 'UTF-8'); ?>">Support</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('docs.php') : 'docs.php', ENT_QUOTES, 'UTF-8'); ?>">Docs</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('server_status.php') : 'server_status.php', ENT_QUOTES, 'UTF-8'); ?>">Server Status</a>
      </nav>
    </div>
    <div class="gsw-footer-group">
      <h2>Contact</h2>
      <address class="gsw-footer-contact">
        Run Level Systems<br>
        3400 Laurel Rd.<br>
        Brunswick, OH 44212<br>
        <a href="tel:13307868223">330-786-8223</a><br>
        <a href="mailto:runlevelsystems@gmail.com">runlevelsystems@gmail.com</a>
      </address>
    </div>
    <div class="gsw-footer-legal">
      <nav class="gsw-footer-links" aria-label="Legal links">
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('company.php') : 'company.php', ENT_QUOTES, 'UTF-8'); ?>">Company</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('contact.php') : 'contact.php', ENT_QUOTES, 'UTF-8'); ?>">Contact</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('privacy.php') : 'privacy.php', ENT_QUOTES, 'UTF-8'); ?>">Privacy</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('tos.php') : 'tos.php', ENT_QUOTES, 'UTF-8'); ?>">Terms</a>
        <a href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('careers.php') : 'careers.php', ENT_QUOTES, 'UTF-8'); ?>">Careers</a>
      </nav>
      <p class="gsw-footer-copy">&copy; 2026 Run Level Systems. All rights reserved.</p>
      <p class="gsw-footer-powered">Powered by GameServer Engine.</p>
    </div>
  </div>
  <div class="last-updated">
      <?php
      // Runtime reads modules/billing timestamp; sync from modules/billing/timestamp.txt is handled by billing_bootstrap.php.
      $billing_ts = __DIR__ . '/../timestamp.txt';
      if (file_exists($billing_ts)) {
          echo trim(file_get_contents($billing_ts));
      }
      ?>
    </div>
</footer>

<!-- close site wrapper started in menu.php -->
</div>
