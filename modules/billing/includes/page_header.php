<?php
/**
 * Uniform page header for every storefront page except home.
 *
 * Each page prints its own eyebrow, <h1> and intro paragraph inside the white content
 * card. menu.php buffers the page, and billing_page_header_filter() lifts those three
 * pieces out and prints them as one dark header band between the menu and the card, so
 * every page gets the same size and look without editing each page.
 *
 * Pages with no <h1> (My Account, My Servers, Order) use the titles below.
 */

if (!function_exists('billing_open_content')) {
    /** Opens the white content card. footer.php closes it. */
    function billing_open_content(): void
    {
        echo '<div class="gsw-page">' . "\n" . '<div class="gsw-content" id="gsw-content">' . "\n";
    }
}

if (!function_exists('billing_page_header_defaults')) {
    /** Header text for pages that print no <h1> of their own: script => [eyebrow, title, intro]. */
    function billing_page_header_defaults(): array
    {
        return array(
            'my_account.php' => array('Account', 'My Account', 'Your details, servers, and invoices in one place.'),
            'my_servers.php' => array('Servers', 'My Servers', 'Manage the game servers you rent.'),
            'order.php'      => array('Checkout', 'Order', 'Review your server and place your order.'),
        );
    }
}

if (!function_exists('billing_page_header_filter')) {
    /** Output-buffer callback: moves the page's eyebrow, <h1> and intro into the header band. */
    function billing_page_header_filter(string $html): string
    {
        $marker = '<div class="gsw-page">';
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return $html;
        }
        $head = substr($html, 0, $pos);
        $body = substr($html, $pos);

        $eyebrow = '';
        $title = '';
        $lead = '';

        if (preg_match('~<h1\b[^>]*>.*?</h1>~is', $body, $m, PREG_OFFSET_CAPTURE)) {
            $title = $m[0][0];
            $before = substr($body, 0, $m[0][1]);
            $after = substr($body, $m[0][1] + strlen($title));

            // The eyebrow is the paragraph right before the heading.
            if (preg_match('~<p class="gsw-section-eyebrow">(.*?)</p>\s*$~is', $before, $e)) {
                $eyebrow = $e[1];
                $before = substr($before, 0, -strlen($e[0]));
            }
            // The intro is the plain paragraph right after it.
            if (preg_match('~^\s*<p(?![^>]*class="[^"]*(?:gsw-btn|actions))[^>]*>(.*?)</p>~is', $after, $l)) {
                $lead = $l[1];
                $after = substr($after, strlen($l[0]));
            }
            $body = $before . $after;
        } else {
            $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $defaults = billing_page_header_defaults();
            if (!isset($defaults[$script])) {
                return $html;
            }
            list($eyebrow, $titleText, $lead) = $defaults[$script];
            $title = '<h1>' . htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') . '</h1>';
            $eyebrow = htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8');
            $lead = htmlspecialchars($lead, ENT_QUOTES, 'UTF-8');
        }

        $band = '<section class="gsw-page-header"><div class="gsw-page-header-inner">';
        if ($eyebrow !== '') {
            $band .= '<p class="gsw-section-eyebrow">' . $eyebrow . '</p>';
        }
        $band .= $title;
        if (trim($lead) !== '') {
            $band .= '<p class="gsw-page-lead">' . $lead . '</p>';
        }
        $band .= '</div></section>' . "\n";

        return $head . $band . $body;
    }
}
