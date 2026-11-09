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

function exec_module()
{
	if ($_SESSION['users_role'] !== 'admin') {
		print_failure(get_lang('no_access'));
		return;
	}

	require_once(dirname(__FILE__) . '/../administration/panel_update.php');
	if (!function_exists('panel_update_section')) {
		print_failure('Update module is unavailable because the updater helper file did not load correctly.');
		return;
	}
	try {
		panel_update_section();
	} catch (Throwable $e) {
		if (function_exists('update_log')) {
			update_log('Unhandled update page error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		}
		print_failure('Update module failed: ' . htmlspecialchars($e->getMessage()));
	}
}
?>
