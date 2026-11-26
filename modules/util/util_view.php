<?php
/*
 * Page building blocks for the Utilities page: the tool list and the form of each diagnostic tool.
 * Everything printed from data goes through util_esc().
 */

function util_esc($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// Categories and their tools, in display order.
function util_categories()
{
	return array(
		array('id' => 'network', 'label' => 'network_tools', 'tools' => array('ping', 'port_check')),
		array('id' => 'steam', 'label' => 'steam_admins', 'tools' => array('sourcemod', 'amx', 'steamid')),
	);
}

function util_tool_labels()
{
	return array('ping' => 'ping', 'port_check' => 'port_checker', 'sourcemod' => 'sourcemod_admins', 'amx' => 'amx_mod_admins', 'steamid' => 'steam_converter');
}

// Tools this role may see. The Steam and admin tools are open to everyone, as before.
function util_allowed_tools($role)
{
	$allowed = array('sourcemod', 'amx', 'steamid');
	foreach (diag_default_tool_access() as $tool => $roles) {
		if (in_array($role, $roles, true)) {
			$allowed[] = $tool;
		}
	}
	return $allowed;
}

function util_pick_tool($wanted, $allowed)
{
	return in_array($wanted, $allowed, true) ? $wanted : 'ping';
}

// The navigation: one block per category, a link per tool. Works without JavaScript (plain links).
function util_render_nav($allowed, $current)
{
	$labels = util_tool_labels();
	echo '<nav class="util-nav" aria-label="' . util_esc(get_lang('module_name')) . '">';
	foreach (util_categories() as $category) {
		$items = array_values(array_intersect($category['tools'], $allowed));
		if (!$items) {
			continue;
		}
		echo '<div class="util-category"><h3 id="cat-' . $category['id'] . '">' . util_esc(get_lang($category['label'])) . '</h3><ul aria-labelledby="cat-' . $category['id'] . '">';
		foreach ($items as $tool) {
			$active = ($tool === $current);
			echo '<li><a href="?m=util&amp;tool=' . $tool . '" data-tool="' . $tool . '"' . ($active ? ' aria-current="page" class="active"' : '') . '>' . util_esc(get_lang($labels[$tool])) . '</a></li>';
		}
		echo '</ul></div>';
	}
	echo '</nav>';
}

function util_field($id, $label, $control, $hint = '')
{
	$describe = $hint === '' ? '' : ' aria-describedby="' . $id . '-hint"';
	$control = str_replace(array('<input ', '<select '), array('<input' . $describe . ' ', '<select' . $describe . ' '), $control);
	echo '<div class="util-field"><label for="' . $id . '">' . util_esc($label) . '</label>' . $control;
	echo $hint === '' ? '' : '<small id="' . $id . '-hint" class="util-hint">' . util_esc($hint) . '</small>';
	echo '</div>';
}

// The agent machine a test runs on (ping).
function util_agent_field($tool)
{
	util_field($tool . '-agent', get_lang('select_agent'), '<select id="' . $tool . '-agent" name="agent" class="util-agent" required><option value="">' . util_esc(get_lang('loading_agents')) . '</option></select>');
}

// "My servers": filled by JavaScript from the config endpoint.
function util_servers_field($tool, $hint = '', $required = false)
{
	util_field($tool . '-server', get_lang('my_servers'), '<select id="' . $tool . '-server" class="util-servers"' . ($required ? ' required' : '') . '><option value="">-</option></select>', $hint);
}

// One diagnostic tool: form + result area. $fields_callback prints the fields of the tool.
function util_render_diag_tool($tool, $title, $intro, $fields_callback, $role)
{
	echo '<h3>' . util_esc($title) . '</h3><p class="util-intro">' . util_esc($intro) . '</p>';
	echo '<form class="util-diag-form" method="post" action="" data-tool="' . $tool . '" novalidate>';
	echo '<input type="hidden" name="tool" value="' . $tool . '">';
	call_user_func($fields_callback, $tool, $role);
	echo '<button type="submit" class="util-run">' . util_esc(get_lang('run_test')) . '</button>';
	echo '</form>';
	echo '<div class="util-result" id="' . $tool . '-result" role="status" aria-live="polite" tabindex="-1"></div>';
}

// Ping: pick the agent to ping from. The target is the visitor's own IP unless something else is typed.
function util_fields_ping($tool, $role)
{
	util_agent_field($tool);
	util_servers_field($tool, 'Choosing a game server only selects the agent that runs its machine.');
	util_field($tool . '-host', get_lang('remote_target'), '<input type="text" id="' . $tool . '-host" name="host" maxlength="253" autocomplete="off" spellcheck="false" autocapitalize="off" value="' . util_esc(getClientIPAddress()) . '">',
		'Filled in with your own IP address. Type any other public IP address or host name to ping that instead.');
}

// Port check: pick one of your game servers. The agent, address and port come from that server.
function util_fields_port_check($tool, $role)
{
	util_servers_field($tool, 'Checks which of this game server\'s ports are really listening on its machine.', true);
	echo '<input type="hidden" name="agent" class="util-agent-value"><input type="hidden" name="server_ip" class="util-server-ip"><input type="hidden" name="server_port" class="util-server-port">';
	if ($role === 'admin') {
		util_field($tool . '-extra', 'Extra ports', '<input type="text" id="' . $tool . '-extra" name="extra_ports" maxlength="100" autocomplete="off" spellcheck="false">',
			'Optional, administrators only. Other ports to check on the same machine, separated by commas.');
	}
}

function util_render_tool($tool, $role)
{
	$titles = util_tool_labels();
	$intros = array(
		'ping' => 'Sends 4 ping packets from the agent machine to the target. It shows how well that machine can reach you.',
		'port_check' => 'Asks the game server machine which ports it is listening on and compares them with the ports your game server uses.',
	);
	if (in_array($tool, array('sourcemod', 'amx', 'steamid'), true)) {
		include 'modules/util/tools/' . $tool . '.php';
		return;
	}
	util_render_diag_tool($tool, get_lang($titles[$tool]), $intros[$tool], 'util_fields_' . $tool, $role);
}
