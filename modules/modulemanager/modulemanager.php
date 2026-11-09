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
    global $db;

    print "<h2>".get_lang('modules')."</h2>";
    print '<a href="?m=modulemanager&amp;p=update">'.get_lang('update_modules').'</a>';

    $modules = $db->getInstalledModules();

    if ( $modules === FALSE )
    {
        print_failure(get_lang('no_installed_modules'));
        return;
    }

    print "<p class='note'>".get_lang("not_complete")."</p>";

	$coreHTML = "<h3>" . get_lang("core_mods_installed") . "</h3>"; // Core Modules Installed:
	$nonCoreHTML = "<h3>" . get_lang("custom_mods_installed") . "</h3>"; // Custom Modules Installed:

    $coreHTML .= "<table class='center'><tr class='first_row'><td>".get_lang('module_id')."</td>
        <td>".get_lang('module_name')."</td><td>".get_lang('module_folder')."</td>
        <td>".get_lang('module_version')."</td><td>".get_lang('db_version')."</td>
        <td></td><td></td></tr>";

    $nonCoreHTML .= "<table class='center'><tr class='first_row'><td>".get_lang('module_id')."</td>
        <td>".get_lang('module_name')."</td><td>".get_lang('module_folder')."</td>
        <td>".get_lang('module_version')."</td><td>".get_lang('db_version')."</td>
        <td></td><td></td></tr>";

    require_once('modules/modulemanager/module_handling.php');

    $installed_modules = array();

    $i = 0;
    foreach ((array)$modules as $row)
    {
		$html = "";
		$coreModule = true;
		$enabled = !isset($row['enabled']) || (int)$row['enabled'] === 1;
        $html .= "<tr class='tr".($i++%2)."'><td>".$row['id']."</td>";
        $html .= "<td>".$row['title']."</td><td>".$row['folder']."</td><td>".$row['version']."</td><td>".$row['db_version']."</td>";
        $html .= "<td>";

        // Don't allow the deletion of core modules
        		$html .= "</td>";
		$html .= "<td>";

		// Enable/disable toggle. Modules that can never be disabled
		// (modulemanager, settings, user_admin) show a status note instead.
		if (module_cannot_be_disabled($row['folder'])) {
			$html .= "<span class='note'>".get_lang('module_cannot_be_disabled_short')."</span>";
		} elseif ($enabled) {
			$html .= "<a href='?m=modulemanager&amp;p=toggle&amp;id=".$row['id']."&amp;module=".$row['folder']."&amp;enable=0'>";
			$html .= get_lang('disable');
			$html .= "</a>";
		} else {
			$html .= "<a href='?m=modulemanager&amp;p=toggle&amp;id=".$row['id']."&amp;module=".$row['folder']."&amp;enable=1'>";
			$html .= get_lang('enable');
			$html .= "</a> <span class='note'>(".get_lang('module_disabled_status').")</span>";
		}
		$html .= "</td>";
		$html .= "</tr>\n";

		// Append HTML to correct table
		if($coreModule){
			$coreHTML .= $html;
		}else{
			$nonCoreHTML .= $html;
		}

        array_push( $installed_modules, $row['folder'] );
    }

    // End the table
    $coreHTML .= "</table>";
    $nonCoreHTML .= "</table>";

    // Print out installed modules tables
    echo $coreHTML . "\n";
    echo $nonCoreHTML . "\n";

	// Modules are built into the panel. They are only enabled or disabled, never installed or removed here.

}
?>
