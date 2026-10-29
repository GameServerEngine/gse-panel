<?php
/**
 * Steam Workshop was removed from Server Content.
 * Use modules/steam_workshop instead.
 */

if (!function_exists('print_failure')) {
	function print_failure($msg) {
		echo '<div class="failure">'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</div>';
	}
}

echo '<h2>Steam Workshop</h2>';
print_failure('Steam Workshop has been removed from Server Content. Use the Steam Workshop module instead.');
echo '<p>The working legacy Steam Workshop module handles Workshop installs. Server Content will continue to manage normal downloadable content/addons only.</p>';
?>
