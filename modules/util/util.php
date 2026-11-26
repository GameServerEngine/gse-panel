<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */
require_once 'modules/util/diag_env.php';
require_once 'modules/util/util_view.php';

echo '<script src="js/modules/util.js"></script>';

// Utilities page: a category list on the left (on top on small screens) and the selected tool on the right.
// Old links keep working: ?m=util still opens the page, and #tabs-1 .. #tabs-4 are mapped to tools in util.js.
function exec_module()
{
	global $db;
	$userInfo = $db->getUserById($_SESSION['user_id']);
	$role = $userInfo['users_role'];
	$allowed = util_allowed_tools($role);
	$current = util_pick_tool(isset($_GET['tool']) ? (string)$_GET['tool'] : 'ping', $allowed);
?>
<h2><?php echo get_lang('module_name'); ?></h2>
<?php if ($role === 'admin') { ?>
<p class="util-admin-link"><a href="?m=util&amp;p=diag_settings"><?php echo get_lang('diag_policy'); ?></a></p>
<?php } ?>
<div class="util-layout">
	<?php util_render_nav($allowed, $current); ?>
	<div class="util-main">
		<div id="loading_agents" class="show util-note"><?php echo get_lang('loading_agents'); ?></div>
		<div id="loading_failed" class="hide util-note"><?php echo get_lang('loading_failed'); ?></div>
		<div id="agents_offline" class="hide util-note"><?php echo get_lang('agents_offline'); ?></div>
		<div id="your-ip-line" class="util-note"><?php echo get_lang('your_ip'); ?> <span id="your-address"><?php echo util_esc(getClientIPAddress()); ?></span></div>
<?php foreach ($allowed as $tool) { ?>
		<section class="util-tool" id="tool-<?php echo $tool; ?>" data-tool="<?php echo $tool; ?>"<?php echo $tool === $current ? '' : ' hidden'; ?>>
<?php util_render_tool($tool, $role); ?>
		</section>
<?php } ?>
	</div>
</div>
<div id="translation"
data-command_empty="<?=get_lang('command_empty')?>"
data-agent_invalid="<?=get_lang('agent_invalid')?>"
data-post_failed="<?=get_lang('post_failed')?>"
data-select_server="<?=get_lang('select_server')?>"
data-select_server_option="<?=get_lang('select_server_option')?>"
data-select_agent="<?=get_lang('select_agent')?>" ></div>
<?php
}
?>
