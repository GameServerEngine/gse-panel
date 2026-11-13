<?php
require_once __DIR__ . '/../includes/page_header.php';

function ph_check($cond, $msg) { if (!$cond) { throw new RuntimeException($msg); } }

$_SERVER['SCRIPT_NAME'] = '/support.php';
$page = "<header>menu</header>\n<div class=\"gsw-page\">\n<div class=\"gsw-content\" id=\"gsw-content\">\n"
    . "<main>\n  <p class=\"gsw-section-eyebrow\">Hosting Support</p>\n  <h1>Support</h1>\n  <p>Help with <b>orders</b>.</p>\n  <p class=\"x\">Second</p>\n</main>\n</div></div>";
$out = billing_page_header_filter($page);
ph_check(strpos($out, '<section class="gsw-page-header">') < strpos($out, '<div class="gsw-page">'), 'header band is before the content card');
ph_check(substr_count($out, '<h1') === 1, 'one h1');
ph_check(strpos($out, 'Help with <b>orders</b>.') < strpos($out, '<div class="gsw-page">'), 'intro moved into header');
ph_check(strpos($out, 'Second') > strpos($out, '<div class="gsw-page">'), 'only the first paragraph moves');
ph_check(strpos($out, 'Hosting Support') < strpos($out, '<div class="gsw-page">'), 'eyebrow moved into header');

// h1 keeps its attributes (docs.php anchors on id="top").
$out = billing_page_header_filter('<div class="gsw-page"><main><h1 id="top" class="gsw-section-title">Docs</h1><div>body</div></main></div>');
ph_check(strpos($out, '<h1 id="top" class="gsw-section-title">Docs</h1>') !== false, 'h1 attributes kept');
ph_check(strpos($out, 'gsw-page-lead') === false, 'no lead when the page has none');

// A button row after the heading is not an intro paragraph.
$out = billing_page_header_filter('<div class="gsw-page"><h1>T</h1><p class="gsw-btn-row">go</p></div>');
ph_check(strpos($out, 'gsw-page-lead') === false, 'button paragraph not moved');

// No h1: pages with defaults get a header, others are left alone.
$_SERVER['SCRIPT_NAME'] = '/my_account.php';
$out = billing_page_header_filter('<div class="gsw-page"><div class="account-section">x</div></div>');
ph_check(strpos($out, '<h1>My Account</h1>') !== false, 'default title for my_account');
$_SERVER['SCRIPT_NAME'] = '/unknown.php';
$plain = '<div class="gsw-page"><p>x</p></div>';
ph_check(billing_page_header_filter($plain) === $plain, 'unknown page without h1 untouched');
ph_check(billing_page_header_filter('no marker here') === 'no marker here', 'no marker, untouched');

echo "PageHeaderTest passed\n";
