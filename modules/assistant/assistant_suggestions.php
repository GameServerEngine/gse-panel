<?php
/*
 * The questions offered as buttons under the assistant's chat boxes. The storefront's Try AI
 * page (modules/billing/ai.php) shows the same ones, so a visitor sees what they will get.
 */

// Questions shown as buttons under a server's chat.
function assistant_server_suggestions()
{
	return array(
		'Is my server running?',
		'Are there any errors in the log?',
		'Who joined the server recently?',
		'Show me the server config.',
		'Write a welcome message for my players.',
	);
}

// Questions shown as buttons under the product chat.
function assistant_product_suggestions()
{
	return array(
		'What is GameServer Engine?',
		'Is it really free?',
		'Which games can it run?',
		'What do I need to install it?',
		'How does AI Assist work?',
		'Can I sell game servers with it?',
	);
}
?>
