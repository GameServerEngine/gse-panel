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

function exec_gsp_module()
{
    global $db;
    global $view;

    require_once('modules/modulemanager/module_handling.php');

    $enable = isset($_REQUEST['enable']) && $_REQUEST['enable'] == '1';

    if ( isset($_REQUEST['id']) && isset($_REQUEST['module']) &&
        toggle_module_enabled($db, $_REQUEST['id'], $_REQUEST['module'], $enable) === TRUE )
    {
        print_success($enable ? get_lang('module_enabled') : get_lang('module_disabled'));
    }

    $view->refresh("?m=modulemanager");
}
?>
