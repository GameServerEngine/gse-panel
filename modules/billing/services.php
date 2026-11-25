<?php
// The Services page is now Development (development.php). Keep old links working.
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: development.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
