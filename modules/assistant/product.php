<?php
/*
 * Questions about GameServer Engine itself (index: home.php?m=assistant&p=product), for any
 * signed-in user and the public demo. The chat has no tools: it answers from product_facts.txt
 * and cannot see a server, a file or an account.
 */

function exec_module()
{
	global $settings;
	require_once(__DIR__ . '/assistant_boot.php');
	require_once(__DIR__ . '/assistant_ui.php');
	require_once(__DIR__ . '/assistant_suggestions.php');
	require_once(__DIR__ . '/assistant_settings.php');

	if (!isset($settings['assistant_enabled']) || $settings['assistant_enabled'] !== '1') {
		echo '<p>The assistant is not switched on for this panel.</p>';
		return 0;
	}
	assistant_render_chat(
		'product',
		assistant_ask_label($settings) . ': GameServer Engine',
		'Ask about GameServer Engine: what it does, the games it runs, what it needs, how to install it and how to sell game servers with it.',
		'For example: what do I need to run it?',
		assistant_display_name($settings),
		array(
			'suggestions' => assistant_product_suggestions(),
			'ask' => isset($_GET['q']) ? (string)$_GET['q'] : '',
		)
	);
	echo '<p><a href="?m=gamemanager&amp;p=game_monitor">Back to Game Monitor</a></p>';
	return 1;
}
?>
