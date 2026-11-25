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

    require_once('modules/modulemanager/module_handling.php');
    panel_install_new_modules($db);

    print "<h2>".get_lang('modules')."</h2>";
    print '<a href="?m=modulemanager&amp;p=update">'.get_lang('update_modules').'</a>';

    $modules = $db->getInstalledModules();

    if ( $modules === FALSE )
    {
        print_failure(get_lang('no_installed_modules'));
        return;
    }

    print "<p class='note'>".get_lang("not_complete")."</p>";

	// Three groups in this order: Required, Enabled, Disabled. Each group is sorted by module name.
	$groups = array('required' => array(), 'enabled' => array(), 'disabled' => array());
	foreach ((array)$modules as $row)
	{
		$enabled = !isset($row['enabled']) || (int)$row['enabled'] === 1;
		if (module_cannot_be_disabled($row['folder']))
			$groups['required'][] = $row;
		elseif ($enabled)
			$groups['enabled'][] = $row;
		else
			$groups['disabled'][] = $row;
	}

	$headings = array(
		'required' => get_lang('group_required'),
		'enabled' => get_lang('group_enabled'),
		'disabled' => get_lang('group_disabled'),
	);
	$i = 0;
	foreach ($headings as $key => $heading)
	{
		$rows = $groups[$key];
		usort($rows, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });

		echo "<h3>" . $heading . "</h3>";
		if (count($rows) === 0)
			continue;

		echo "<table class='center'><tr class='first_row'><td>".get_lang('module_id')."</td>"
			. "<td>".get_lang('module_name')."</td><td>".get_lang('module_folder')."</td>"
			. "<td>".get_lang('module_version')."</td><td>".get_lang('db_version')."</td>"
			. "<td></td><td></td></tr>\n";

		foreach ($rows as $row)
		{
			$enabled = !isset($row['enabled']) || (int)$row['enabled'] === 1;
			$html = "<tr class='tr".($i++%2)."'><td>".$row['id']."</td>";
			$html .= "<td>".$row['title']."</td><td>".$row['folder']."</td><td>".$row['version']."</td><td>".$row['db_version']."</td>";
			$html .= "<td></td><td>";

			// Enable/disable toggle. Modules that can never be disabled show a status note instead.
			if (module_cannot_be_disabled($row['folder'])) {
				$html .= "<span class='note'>".get_lang('module_cannot_be_disabled_short')."</span>";
			} elseif ($enabled) {
				$html .= "<a href='?m=modulemanager&amp;p=toggle&amp;id=".$row['id']."&amp;module=".$row['folder']."&amp;enable=0'>".get_lang('disable')."</a>";
			} else {
				$html .= "<a href='?m=modulemanager&amp;p=toggle&amp;id=".$row['id']."&amp;module=".$row['folder']."&amp;enable=1'>".get_lang('enable')."</a> <span class='note'>(".get_lang('module_disabled_status').")</span>";
			}
			$html .= "</td></tr>\n";
			echo $html;
		}
		echo "</table>\n";
	}

	// Modules are built into the panel. They are only enabled or disabled, never installed or removed here.

}
?>
