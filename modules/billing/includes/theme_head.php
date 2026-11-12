<?php
// Stylesheet shared by every storefront page: the GameServer Engine look.
// billing_url() resolves the module path from any page; order.php includes this
// before bootstrap.php, so fall back to a path relative to the page.
$gswCss = function_exists('billing_url') ? billing_url('css/header.css') : 'css/header.css';
?>
<meta name="theme-color" content="#07080c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;550;650;750;800;900&amp;display=swap">
<link rel="stylesheet" href="<?php echo htmlspecialchars($gswCss, ENT_QUOTES, 'UTF-8'); ?>">
