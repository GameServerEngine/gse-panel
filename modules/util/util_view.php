<?php
/*
 * Page building blocks for the Utilities page: the tool list and the form of each diagnostic tool.
 * Everything printed from data goes through util_esc().
 */

function util_esc($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// Categories and their tools, in display order. 'agent' tools run on a GSE Agent.
function util_categories()
{
	return array(
		array('id' => 'network', 'label' => 'network_tools', 'tools' => array('ping', 'port_check', 'tcp_test', 'traceroute', 'speed_test')),
		array('id' => 'game', 'label' => 'game_tools', 'tools' => array('server_query')),
		array('id' => 'steam', 'label' => 'steam_admins', 'tools' => array('sourcemod', 'amx', 'steamid')),
	);
}

function util_tool_labels()
{
	return array('ping' => 'ping', 'port_check' => 'port_checker', 'tcp_test' => 'tcp_test', 'traceroute' => 'traceroute', 'speed_test' => 'speed_test',
		'server_query' => 'server_query', 'sourcemod' => 'sourcemod_admins', 'amx' => 'amx_mod_admins', 'steamid' => 'steam_converter');
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

function util_agent_field($tool)
{
	util_field($tool . '-agent', get_lang('select_agent'), '<select id="' . $tool . '-agent" name="agent" class="util-agent" required><option value="">' . util_esc(get_lang('loading_agents')) . '</option></select>');
}

function util_host_field($tool, $label)
{
	util_field($tool . '-host', $label, '<input type="text" id="' . $tool . '-host" name="host" required maxlength="253" autocomplete="off" spellcheck="false" autocapitalize="off">',
		'A host name or IP address. You can test your own game servers and the test targets the administrator listed.');
}

function util_number_field($tool, $name, $label, $value, $min, $max, $hint = '')
{
	util_field($tool . '-' . $name, $label, '<input type="number" id="' . $tool . '-' . $name . '" name="' . $name . '" value="' . $value . '" min="' . $min . '" max="' . $max . '" required>', $hint);
}

function util_protocol_select($tool)
{
	$adapters = '';
	foreach (diag_query_adapters() as $adapter) {
		$adapters .= '<option value="' . util_esc($adapter->id()) . '">' . util_esc($adapter->label()) . '</option>';
	}
	return '<select id="' . $tool . '-adapter" name="adapter">' . $adapters . '</select>';
}

function util_preset_select($tool)
{
	$options = '<option value="">' . util_esc(get_lang('custom_endpoint')) . '</option>';
	foreach (diag_game_presets() as $preset) {
		$options .= '<option value="' . util_esc($preset['key']) . '" data-port="' . (int)$preset['port'] . '" data-adapter="' . util_esc($preset['adapter']) . '">' . util_esc($preset['label']) . '</option>';
	}
	return '<select id="' . $tool . '-preset" class="util-preset">' . $options . '</select>';
}

// "My servers" shortcut: filled by JavaScript from the config endpoint.
function util_servers_field($tool)
{
	util_field($tool . '-server', get_lang('my_servers'), '<select id="' . $tool . '-server" class="util-servers"><option value="">-</option></select>');
}

// One diagnostic tool: form + result area.
function util_render_diag_tool($tool, $title, $intro, $fields_callback)
{
	echo '<h3>' . util_esc($title) . '</h3><p class="util-intro">' . util_esc($intro) . '</p>';
	echo '<form class="util-diag-form" method="post" action="" data-tool="' . $tool . '" novalidate>';
	echo '<input type="hidden" name="tool" value="' . $tool . '">';
	util_agent_field($tool);
	call_user_func($fields_callback, $tool);
	echo '<button type="submit" class="util-run">' . util_esc(get_lang('run_test')) . '</button>';
	echo '</form>';
	echo '<div class="util-result" id="' . $tool . '-result" role="status" aria-live="polite" tabindex="-1"></div>';
}

function util_fields_ping($tool)
{
	util_servers_field($tool);
	util_host_field($tool, get_lang('remote_target'));
}

function util_fields_port_check($tool)
{
	util_field($tool . '-preset', get_lang('game_preset'), util_preset_select($tool), 'Presets fill in the usual port. You can still enter any port for an authorized endpoint.');
	util_servers_field($tool);
	util_host_field($tool, get_lang('remote_target'));
	util_number_field($tool, 'port', get_lang('port'), '27015', 1, 65535);
	util_field($tool . '-protocol', get_lang('protocol'), '<select id="' . $tool . '-protocol" name="protocol"><option value="tcp">TCP (connection test)</option><option value="udp">UDP (game query)</option></select>',
		'UDP sends a real game query and checks the answer. A UDP port that does not answer is not proof that it is closed.');
	util_field($tool . '-adapter', get_lang('query_protocol'), util_protocol_select($tool), 'Used for UDP only.');
	util_number_field($tool, 'timeout_ms', get_lang('timeout_ms'), '3000', 500, 10000);
}

function util_fields_tcp_test($tool)
{
	util_servers_field($tool);
	util_host_field($tool, get_lang('remote_target'));
	util_number_field($tool, 'port', get_lang('port'), '27015', 1, 65535, 'TCP only. A successful TCP connection does not verify the game protocol, and many games use UDP.');
	util_number_field($tool, 'timeout_ms', get_lang('timeout_ms'), '3000', 500, 10000);
}

function util_fields_traceroute($tool)
{
	util_servers_field($tool);
	util_host_field($tool, get_lang('remote_target'));
	util_number_field($tool, 'max_hops', get_lang('max_hops'), '20', 1, 30);
}

function util_fields_server_query($tool)
{
	util_field($tool . '-preset', get_lang('game_preset'), util_preset_select($tool));
	util_servers_field($tool);
	util_host_field($tool, get_lang('remote_target'));
	util_number_field($tool, 'port', get_lang('query_port'), '27015', 1, 65535, 'The query port is not always the game port. Check your server settings if there is no answer.');
	util_field($tool . '-adapter', get_lang('query_protocol'), util_protocol_select($tool));
	util_number_field($tool, 'timeout_ms', get_lang('timeout_ms'), '3000', 500, 10000);
	util_field($tool . '-players', get_lang('show_players'), '<input type="checkbox" id="' . $tool . '-players" name="players" value="1">');
}

function util_fields_speed_test($tool)
{
	util_field($tool . '-endpoint', get_lang('speed_endpoint'), '<select id="' . $tool . '-endpoint" name="endpoint" class="util-endpoints"></select>',
		'Throughput from the selected agent to a configured test endpoint. It is not the speed between players and the game server.');
	util_field($tool . '-size', get_lang('test_size'), '<select id="' . $tool . '-size" name="size"></select>');
}

function util_render_tool($tool, $role)
{
	$titles = util_tool_labels();
	$intros = array(
		'ping' => 'Sends 4 ping packets from the agent to the target.',
		'port_check' => 'Checks whether a port answers, from the selected agent.',
		'tcp_test' => 'Tries one TCP connection from the selected agent and measures how long it takes.',
		'traceroute' => 'Shows the network path from the selected agent to the target.',
		'speed_test' => 'Downloads a test file on the agent and measures the average speed.',
		'server_query' => 'Asks a game server for its name, map and players.',
	);
	if (in_array($tool, array('sourcemod', 'amx', 'steamid'), true)) {
		include 'modules/util/tools/' . $tool . '.php';
		return;
	}
	util_render_diag_tool($tool, get_lang($titles[$tool]), $intros[$tool], 'util_fields_' . $tool);
}
