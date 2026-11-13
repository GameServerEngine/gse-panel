<?php
// Stylesheet shared by every storefront page: the GameServer Engine look.
// billing_url() resolves the module path from any page; order.php includes this
// before bootstrap.php, so fall back to a path relative to the page.
$gswCss = function_exists('billing_url') ? billing_url('css/header.css') : 'css/header.css';
$gswManifest = function_exists('billing_url') ? billing_url('manifest.php') : 'manifest.php';
$gswSw = function_exists('billing_url') ? billing_url('sw.js') : 'sw.js';
$gswScope = function_exists('billing_url') ? billing_url('') : './';
?>
<meta name="theme-color" content="#07080c">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars(function_exists('billing_url') ? billing_url('images/brand/apple-touch-icon.png') : 'images/brand/apple-touch-icon.png', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="<?php echo htmlspecialchars($gswManifest, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(<?php echo json_encode($gswSw, JSON_UNESCAPED_SLASHES); ?>, { scope: <?php echo json_encode($gswScope, JSON_UNESCAPED_SLASHES); ?> }).catch(function () {});
  });
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;550;650;750;800;900&amp;display=swap">
<link rel="stylesheet" href="<?php echo htmlspecialchars($gswCss, ENT_QUOTES, 'UTF-8'); ?>">
