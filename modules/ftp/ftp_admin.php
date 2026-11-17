<script type="text/javascript" src="js/modules/ftp.js"></script>
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

// FTP Users page.
//
// The list shows the game servers that have an FTP login, under the agent that hosts them, plus any other
// account you look up or add here (for example an admin login). Open a row with the + to change that account:
//   - New password: leave empty to keep the current one. A game server's saved FTP password is updated too.
//   - Home directory: the folder the account sees. Windows paths such as C:\GSE\... are converted.
// "Change account details" applies both. The Pure-FTPd agent can change nothing else on an account.

function ftp_admin_h($text)
{
	return htmlspecialchars((string)$text, ENT_QUOTES);
}

// Turns the agent's "show account" text ("Login : x", "Directory : /home/./", ...) into an array.
function ftp_admin_account_details($details)
{
	$out = array();
	if (!is_string($details))
		return $out;
	foreach (explode("\n", $details) as $line)
	{
		$parts = explode(":", $line, 2);
		if (count($parts) == 2)
			$out[trim($parts[0])] = trim($parts[1]);
	}
	return $out;
}

// A folder the way people read it: without the trailing "/./" Pure-FTPd adds.
function ftp_admin_clean_dir($dir)
{
	$dir = preg_replace('#/\./?$#', '', (string)$dir);
	return ($dir === '') ? '/' : $dir;
}

function ftp_admin_valid_login($login)
{
	return (bool)preg_match('/^[A-Za-z0-9_.-]{1,20}$/', (string)$login);
}

function ftp_admin_valid_password($password)
{
	return strlen($password) >= 1 and strlen($password) <= 20 and !preg_match('/[\r\n\0]/', $password);
}

// Returns array(server_row, RemoteLibrary), or array(false, false) for an unknown agent.
function ftp_admin_remote($remote_server_id)
{
	global $db;
	$server_row = $db->getRemoteServer($remote_server_id);
	if (!$server_row)
		return array(false, false);
	return array($server_row, new RemoteLibrary($server_row['agent_ip'], $server_row['agent_port'], $server_row['encryption_key'], $server_row['timeout']));
}

function ftp_admin_failure($text, $remote)
{
	$why = (is_object($remote) and $remote->ftp_error !== '') ? ' The FTP server said: ' . $remote->ftp_error : '';
	print_failure(ftp_admin_h($text . $why));
}

// Add FTP account. Returns array(remote_server_id, login) to show afterwards, or null.
function ftp_admin_add_account()
{
	global $db;
	list($server_row, $remote) = ftp_admin_remote(isset($_POST['remote_server_id']) ? $_POST['remote_server_id'] : 0);
	if ($server_row === false)
	{
		print_failure('Unknown remote agent.');
		return null;
	}
	$login = isset($_POST['ftp_login']) ? trim($_POST['ftp_login']) : '';
	$password = isset($_POST['ftp_password']) ? (string)$_POST['ftp_password'] : '';
	$path = RemoteLibrary::ftp_agent_path(isset($_POST['full_path']) ? $_POST['full_path'] : '');

	if (!ftp_admin_valid_login($login))
	{
		print_failure('The username must be 1 to 20 letters, digits, dots, dashes or underscores.');
		return null;
	}
	if (!ftp_admin_valid_password($password))
	{
		print_failure(get_lang("ftp_account_password_too_long"));
		return null;
	}
	if ($path === '' or $path[0] !== '/')
	{
		print_failure('Enter the home directory as a full path, for example /home/gameserver/951/gamefiles or C:\GSE\Agent\home\gameserver\951\gamefiles.');
		return null;
	}

	// Already used by a game server's FTP login, or already on this agent's FTP server?
	$known = array();
	foreach (explode("\n", $db->getFtpAccountsText()) as $account_line)
	{
		$parts = explode("\t", $account_line);
		$known[] = trim($parts[0]);
	}
	if (in_array($login, $known, true) or $remote->ftp_login_exists($login))
	{
		print_failure(get_lang("ftp_account_already_exists"));
		return null;
	}

	if ($remote->ftp_mgr("useradd", $login, $password, $path) === 0)
	{
		ftp_admin_failure("The FTP server on " . $server_row['remote_server_name'] . " did not create the account.", $remote);
		return null;
	}
	$details = ftp_admin_account_details($remote->ftp_mgr("show", $login));
	if (!isset($details['Login']))
	{
		print_failure("The agent accepted the request, but the account '" . ftp_admin_h($login) . "' is not on the FTP server afterwards.");
		return null;
	}
	$dir = isset($details['Directory']) ? ftp_admin_clean_dir($details['Directory']) : $path;
	print_success("FTP account '" . ftp_admin_h($login) . "' created. Home directory on the server: " . ftp_admin_h($dir));
	return array($server_row['remote_server_id'], $login);
}

function ftp_admin_delete_account()
{
	global $db;
	list($server_row, $remote) = ftp_admin_remote(isset($_POST['remote_server_id']) ? $_POST['remote_server_id'] : 0);
	$login = isset($_POST['ftp_login']) ? (string)$_POST['ftp_login'] : '';
	if ($server_row === false or $login === '')
	{
		print_failure('Unknown remote agent or account.');
		return;
	}
	if ($remote->ftp_mgr("userdel", $login) === 0)
	{
		ftp_admin_failure("Could not remove the account '" . $login . "'.", $remote);
		return;
	}
	$home_info = $db->getHomeByFtpLogin($server_row['remote_server_id'], $login);
	if ($home_info)
		$db->changeFtpStatus('disabled', $home_info['home_id']);
	print_success("FTP account '" . ftp_admin_h($login) . "' removed.");
}

// Change account details: apply the new password and/or home directory. Returns array(remote_server_id, login).
function ftp_admin_edit_account()
{
	global $db;
	list($server_row, $remote) = ftp_admin_remote(isset($_POST['remote_server_id']) ? $_POST['remote_server_id'] : 0);
	$login = isset($_POST['ftp_login']) ? (string)$_POST['ftp_login'] : '';
	if ($server_row === false or $login === '')
	{
		print_failure('Unknown remote agent or account.');
		return null;
	}
	$shown = array($server_row['remote_server_id'], $login);

	$current = ftp_admin_account_details($remote->ftp_mgr("show", $login));
	if (!isset($current['Login']))
	{
		print_failure("The account '" . ftp_admin_h($login) . "' was not found on the FTP server.");
		return $shown;
	}

	$changed = array();

	$new_password = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
	if ($new_password !== '')
	{
		if (!ftp_admin_valid_password($new_password))
		{
			print_failure(get_lang("ftp_account_password_too_long"));
			return $shown;
		}
		if ($remote->ftp_mgr("passwd", $login, $new_password) === 0)
		{
			ftp_admin_failure("The password could not be changed.", $remote);
			return $shown;
		}
		$home_info = $db->getHomeByFtpLogin($server_row['remote_server_id'], $login);
		if ($home_info)
		{
			$db->changeFtpPassword($home_info['home_id'], $new_password);
			$changed[] = 'password (also saved in the game server settings)';
		}
		else
			$changed[] = 'password';
	}

	$new_dir = RemoteLibrary::ftp_agent_path(isset($_POST['home_directory']) ? $_POST['home_directory'] : '');
	$old_dir = isset($current['Directory']) ? ftp_admin_clean_dir($current['Directory']) : '';
	if ($new_dir !== '' and $new_dir !== $old_dir)
	{
		if ($new_dir[0] !== '/')
		{
			print_failure('Enter the home directory as a full path.');
			return $shown;
		}
		if ($remote->ftp_mgr("usermod", $login, "", $new_dir) === 0)
		{
			ftp_admin_failure("The home directory could not be changed.", $remote);
			return $shown;
		}
		$changed[] = 'home directory';
	}

	if (count($changed) == 0)
		print_success("Nothing to change for '" . ftp_admin_h($login) . "'.");
	else
		print_success("Updated " . implode(' and ', $changed) . " for '" . ftp_admin_h($login) . "'.");
	return $shown;
}

// One account: the table row plus the editor that opens under it with the +.
function ftp_admin_print_account($server_row, $display_ip, $remote, $login, $server_name, $expand)
{
	$details = ftp_admin_account_details($remote->ftp_mgr("show", $login));
	$found = isset($details['Login']);
	$dir = ($found and isset($details['Directory'])) ? ftp_admin_clean_dir($details['Directory']) : '';
	$dir_text = $found ? ftp_admin_h($dir) : '<i>not on this FTP server</i>';
	$expand_class = $expand ? 'expandme' : '';

	echo "<tr class='maintr $expand_class'><td class='collapsible'></td><td>" . ftp_admin_h($server_row['remote_server_name']) . " (" . ftp_admin_h($display_ip) . ")</td>"
		. "<td><b class='failure'>" . ftp_admin_h($login) . "</b></td><td>" . ftp_admin_h($server_name) . "</td><td>$dir_text</td></tr>\n";
	echo "<tr class='expand-child'><td colspan='5'>\n";
	if (!$found)
	{
		echo "There is no FTP account named '" . ftp_admin_h($login) . "' on this agent's FTP server. Use Add FTP account to create it.\n</td></tr>\n";
		return;
	}
	echo "<form method=POST>\n<table>\n"
		. "<tr><td>" . get_lang("login") . "</td><td><input type=text value='" . ftp_admin_h($login) . "' readonly /></td><td></td></tr>\n"
		. "<tr><td>New password</td><td><input type=text name='new_password' value='' maxlength='20' size='40' autocomplete='off' placeholder='leave empty to keep the current password' /></td><td></td></tr>\n"
		. "<tr><td>Home directory</td><td><input type=text name='home_directory' value='" . ftp_admin_h($dir) . "' size='60' /></td>"
		. "<td>The folder this account sees. Windows paths like C:\\GSE\\... are converted.</td></tr>\n"
		. "<tr><td colspan='2'><center>\n"
		. "<input type=hidden name='remote_server_id' value='" . ftp_admin_h($server_row['remote_server_id']) . "'/>\n"
		. "<input type=hidden name='ftp_login' value='" . ftp_admin_h($login) . "'/>\n"
		. "<input type=submit name='edit_ftp_user' value='" . get_lang("change_account_details") . "' />\n"
		. "</center></td><td>\n"
		. "<input type='image' name='del_ftp_user' src='modules/administration/images/remove.gif' onclick=\"return confirm('Remove this FTP account?');\">" . get_lang("remove_account") . "</input>\n"
		. "</td></tr>\n</table>\n</form>\n</td></tr>\n";
}

function exec_module()
{
	include('includes/lib_remote.php');

	global $db;
	global $view;

	echo "<h2>" . get_lang("ftp_admin") . "</h2>";

	// An account to show even when no game server uses it (just added, looked up, or just changed).
	$extra = null;
	if (isset($_POST['add_ftp_user']))
		$extra = ftp_admin_add_account();
	elseif (isset($_POST['del_ftp_user_y']))
		ftp_admin_delete_account();
	elseif (isset($_POST['edit_ftp_user']))
		$extra = ftp_admin_edit_account();
	elseif (isset($_POST['lookup_ftp_user']) and isset($_POST['remote_server_id']))
	{
		$lookup = isset($_POST['lookup_ftp_login']) ? trim($_POST['lookup_ftp_login']) : '';
		if (ftp_admin_valid_login($lookup))
			$extra = array($_POST['remote_server_id'], $lookup);
		else
			print_failure('Enter the username to look up.');
	}

	$servers = $db->getRemoteServers();
	if ($servers === false)
	{
		echo get_lang('no_remote_servers');
		return;
	}

	$options = '';
	foreach ((array)$servers as $server_row)
	{
		$display_ip = checkDisplayPublicIP($server_row['display_public_ip'], $server_row['agent_ip']);
		$options .= "<option value='" . ftp_admin_h($server_row['remote_server_id']) . "'>" . ftp_admin_h($server_row['remote_server_name']) . " (" . ftp_admin_h($display_ip) . ":" . ftp_admin_h($server_row['agent_port']) . ")</option>";
	}

	echo "<form method=POST>\n<table class='center' style='width:100%'><tr>\n"
		. "<td>" . get_lang("remote_server") . "<br><select name='remote_server_id'>$options</select></td>\n"
		. "<td>" . get_lang("login") . "<br><input type=text name='ftp_login' maxlength='20' /></td>\n"
		. "<td>" . get_lang("password") . "<br><input type=text name='ftp_password' maxlength='20' autocomplete='off' /></td>\n"
		. "<td>" . get_lang("full_path") . "<br><input type=text name='full_path' size='50' placeholder='/home/gameserver/951/gamefiles' /></td>\n"
		. "</tr><tr><td colspan='4'><input style='width:100%;' type=submit name='add_ftp_user' value='" . get_lang("add_ftp_account") . "' /></td></tr>\n</table>\n</form>\n";

	echo "<form method=POST>\n<table class='center' style='width:100%'><tr>\n"
		. "<td>Manage an account that no game server uses (for example an admin login):</td>\n"
		. "<td><select name='remote_server_id'>$options</select></td>\n"
		. "<td><input type=text name='lookup_ftp_login' maxlength='20' placeholder='username' /></td>\n"
		. "<td><input type=submit name='lookup_ftp_user' value='Show account' /></td>\n"
		. "</tr></table>\n</form>\n";
	?>
	<table id="servermonitor" class="tablesorter" data-sortlist='[[2,0]]'>
		<thead>
		<tr>
			<th class="header sorter-false"></th><th><?php print_lang('remote_server'); ?></th><th><?php print_lang('login'); ?></th><th><?php print_lang('server_name'); ?></th><th><?php print_lang('full_path'); ?></th>
		</tr>
		</thead>
		<tbody>
	<?php
	foreach ((array)$servers as $server_row)
	{
		$display_ip = checkDisplayPublicIP($server_row['display_public_ip'], $server_row['agent_ip']);
		$remote = new RemoteLibrary($server_row['agent_ip'], $server_row['agent_port'], $server_row['encryption_key'], $server_row['timeout']);

		if ($remote->status_chk() !== 1)
		{
			echo "<tr><td></td><td>" . ftp_admin_h($server_row['remote_server_name']) . " (" . ftp_admin_h($display_ip) . ")</td><td colspan='3'><span class='failure'>" . get_lang("offline") . "</span></td></tr>\n";
			continue;
		}

		$shown_logins = array();
		$homes = $db->getFtpHomesForRemoteServer($server_row['remote_server_id']);
		foreach (is_array($homes) ? $homes : array() as $home)
		{
			$shown_logins[] = $home['ftp_login'];
			$expand = ($extra !== null and $extra[0] == $server_row['remote_server_id'] and $extra[1] === $home['ftp_login']);
			ftp_admin_print_account($server_row, $display_ip, $remote, $home['ftp_login'], $home['home_name'], $expand);
		}

		if ($extra !== null and $extra[0] == $server_row['remote_server_id'] and !in_array($extra[1], $shown_logins, true))
			ftp_admin_print_account($server_row, $display_ip, $remote, $extra[1], '(not tied to a game server)', true);
	}
	echo "</tbody>";
	echo "</table>\n";
}
