<?php
/*
 * Adds an "AI Assistant" button to a server in Game Monitor. This is the only way a customer
 * reaches the assistant: it opens the chat for that one server, which they must own.
 */

$assistant_settings = $db->getSettings();
$assistant_customers_ok = !isset($assistant_settings['assistant_customer_enabled']) || $assistant_settings['assistant_customer_enabled'] === '1' || $db->isAdmin((int)$_SESSION['user_id']);
if (isset($assistant_settings['assistant_enabled']) && $assistant_settings['assistant_enabled'] === '1' && $assistant_customers_ok) {
	$module_buttons = array(
		"<a class='monitorbutton' href='?m=assistant&amp;p=chat&amp;home_id=" . (int)$server_home['home_id'] . "'><span>AI Assistant</span></a>"
	);
} else {
	$module_buttons = array();
}
unset($assistant_settings, $assistant_customers_ok);
?>
