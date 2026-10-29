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

require_once(MODULES."/litefm/functions.php");
require_once(MODULES."/litefm/litefm.php");
function exec_gsp_module()
{
	$home_id = $_REQUEST['home_id'];

	if (empty($home_id))
	{
		print_failure( get_lang("home_id_missing") );
		return;
	}

	global $db, $view, $settings;

	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );
	if($isAdmin)
		$home_cfg = $db->getGameHome($home_id);
	else
		$home_cfg = $db->getUserGameHome($_SESSION['user_id'],$home_id);
	$path_error = '';
	$home_cfg = is_array($home_cfg) ? litefm_attach_game_root($home_cfg, $path_error) : false;
	if ($home_cfg === false) {
		print_failure("Invalid server file root: " . litefm_escape_html($path_error));
		return;
	}

	if ($home_cfg === FALSE)
	{
		print_failure( get_lang("no_access_to_home") );
		return;
	}

	if ( preg_match("/f/",$home_cfg['access_rights']) != 1 )
	{
		print_failure( get_lang("no_rights") );
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ". get_lang("back") ."</a></td></tr></table>";
		return;
	}

	litefm_check($home_id);

	$remote = new GSPRemoteLibrary($home_cfg['agent_ip'], $home_cfg['agent_port'], $home_cfg['encryption_key'], $home_cfg['timeout']);

	$os_string = $remote->what_os();
	$os = preg_match("/Linux/i", $os_string) ? "linux" : "windows";

	// We must always add the home directory to the fm_cwd so that user
	// can not go out of the homedir.
	$cwd_session_key = 'fm_cwd_' . $home_id;
	if (!isset($_SESSION[$cwd_session_key]) || !is_string($_SESSION[$cwd_session_key])) {
		$_SESSION[$cwd_session_key] = '';
	}

	// Validate cached path and recover from bad cached paths
	$path = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $_SESSION[$cwd_session_key]);
	if ($path === false)
	{
		// If we had a cached path and it's invalid, reset it and try again with root
		if ($_SESSION[$cwd_session_key] !== '')
		{
			print_success("Invalid saved path was reset to the server root.");
			$_SESSION[$cwd_session_key] = '';
			$path = litefm_safe_join_home_path(litefm_payload_root($home_cfg), '');
			// If even root is invalid, that's a real error
			if ($path === false)
			{
				print_failure("Could not access server home directory");
				echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ".get_lang('back')."</a></td></tr></table>";
				return;
			}
		}
		else
		{
			// Empty path but still invalid - real error
			print_failure(get_lang("unallowed_char"));
			echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ".get_lang('back')."</a></td></tr></table>";
			return;
		}
	}

	$home_root = clean_path(litefm_payload_root($home_cfg));
	if (!$remote->rfile_exists($home_root))
	{
		print_failure("Server files have not been installed yet.");
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ".get_lang('back')."</a></td></tr></table>";
		return;
	}

	if (!$remote->rfile_exists($path))
	{
		$requested_rel_path = isset($_SESSION[$cwd_session_key]) ? trim((string)$_SESSION[$cwd_session_key], '/') : '';
		$requested_rel_path = $requested_rel_path === '' ? '/' : $requested_rel_path;
		print_failure("Directory not found: " . litefm_escape_html($requested_rel_path));
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ".get_lang('back')."</a></td></tr></table>";
		return;
	}

	// Get File Operations Keys
	$fo_keys = get_file_operations_keys();
	// Get File Operations Settings
	$fo = get_fo_settings($settings,$fo_keys);

	$upload_folder_path = "modules/litefm/uploads/home_id_$home_id";
	// PHP post_max_size handling
	$PMS_bytes = return_bytes(ini_get('post_max_size'));
	if(isset($_SERVER['CONTENT_LENGTH']) AND $_SERVER['CONTENT_LENGTH'] > $PMS_bytes and $fo['upload'] == "1")
	{
		$error['post_max_size'] = "The uploaded file(s) size exceed the post_max_size directive in php.ini (".ini_get('post_max_size').").";
		echo json_encode( array( 'error' => $error ) );
	}
	// Get web to agent transfer progress
	elseif( isset( $_GET['pid'] ) and $_GET['pid'] != "" and $fo['upload'] == "1" )
	{
		$bytes = $_GET['size'];
		$totalsize = $bytes / 1024;
		$filename = litefm_decode_name_param($_GET['filename']);
		if (!litefm_is_valid_path_component($filename))
		{
			echo json_encode(array('pct' => 0, 'complete' => false));
			return;
		}
		$kbytes = $remote->rsync_progress( clean_path( $path."/".$filename ) );
		list($totalsize,$mbytes,$pct) = explode(";",do_progress($kbytes,$totalsize));
		$totalmbytes = round($totalsize / 1024, 2);
		$pct = $pct > 100 ? 100 : $pct;
		$complete = false;
		if ( $remote->is_file_download_in_progress( $_GET['pid'] ) == 0 )
		{
			$dest_file_path = clean_path( $upload_folder_path . "/" . $filename . ".txt" );
			unlink($dest_file_path);
			$directory = dir($upload_folder_path);
			$directory_empty = TRUE;
			while ((FALSE !== ($item = $directory->read())) && ( ! isset($directory_not_empty)))
			{
				if ($item != '.' && $item != '..')
				{
					$directory_empty = FALSE;
				}
			}
			$directory->close();
			if( $directory_empty )
				rmdir( $upload_folder_path );
			$db->logger(get_lang("upload_complete") . ": " . clean_path( $path . "/" . $filename ));
			$complete = true;
		}
		echo json_encode(array('pct' => $pct,
							   'complete' => $complete));
	}
	// Upload File(s)
	elseif( isset( $_POST['upload'] ) and $fo['upload'] == "1" )
	{
		$error = FALSE;
		foreach ( $_FILES['files']['error'] as $i => $error_code )
		{
			if($error_code > 0)
			{
				$error['error_message'][$i] = codeToMessage($error_code,$_FILES['files']['name'][$i]);
			}
		}

		if( is_array($error) )
		{
			echo json_encode( array( 'error' => $error ) );
		}
		// Save uploaded file to the website and start file download from the agent
		else
		{
			if( ! file_exists( $upload_folder_path ) )
			{
				if( ! mkdir($upload_folder_path, 0777, true) )
				{
					echo json_encode(array('error' => get_lang_f('can_not_create_upload_folder_path', "\n(".$upload_folder_path.")" )));
					return;
				}
			}
			$count = 0;
			$s = ( isset($_SERVER['HTTPS']) and  get_true_boolean($_SERVER['HTTPS']) ) ? "s" : "";
			$p = (isset($_SERVER['SERVER_PORT']) and $_SERVER['SERVER_PORT'] != "80") ? ":".$_SERVER['SERVER_PORT'] : "";
			$url = 'http'.$s.'://'.$_SERVER['SERVER_NAME'].$p.$_SERVER['SCRIPT_NAME'];
			// loop all files
			foreach ( $_FILES['files']['name'] as $i => $name )
			{
				// if file not uploaded then skip it
				if ( !is_uploaded_file($_FILES['files']['tmp_name'][$i]) )
					continue;
				$filename = basename((string)$_FILES['files']['name'][$i]);
				if (!litefm_is_valid_path_component($filename))
					continue;
				$dest_file_path = clean_path( $upload_folder_path . "/" . $filename . ".txt" );
				$file_url = str_replace( "home.php", $dest_file_path, $url );
				if( file_exists( $dest_file_path ) )
					unlink($dest_file_path);
				if( move_uploaded_file( $_FILES["files"]["tmp_name"][$i], $dest_file_path ) )
				{
					$remote_file_path = clean_path( $path . "/" . $filename );
					if( $remote->rfile_exists($remote_file_path) )
						$remote->shell_action('remove_file', $remote_file_path);
					$pid = $remote->start_file_download( $file_url, $path, $filename );
					$files[$count] = array('filename' => $filename,
										   'size' => $_FILES['files']['size'][$i],
										   'pid' => $pid);
					$count++;
				}
			}
			echo json_encode(array('count' => $count,
								   'files' => $files));
		}
	}
	// Create Folder
	elseif( isset( $_POST['create_folder'] ) and $fo['create_folder'] == "1" )
	{
		$folder_name = stripslashes($_POST['folder_name']);
		if (!litefm_is_valid_path_component($folder_name))
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		$folder_path = clean_path( $path . "/" . $folder_name );
		if (!litefm_path_within_home(litefm_payload_root($home_cfg), $folder_path))
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		$remote->shell_action('create_dir', $folder_path);
		$db->logger( get_lang("create_folder") . ": " . $folder_path );
	}
	// Delete File(s)
	elseif( isset( $_POST['remove'] ) and $fo['remove'] == "1" )
	{
		if( isset($_SESSION['fm_files_'.$home_id]) and !empty($_SESSION['fm_files_'.$home_id]) )
		{
			$files = "";
			foreach ((array)$_POST['items'] as $item)
			{
				if(isset($_SESSION['fm_files_'.$home_id][$item]))
				{
					$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
					$files .= $item_path.";";
				}
			}
			echo $files;
			if($files != "")
			{
				$remote->shell_action('remove_recursive', $files);
				$files = str_replace('" "','"<br>"',$files);
				$db->logger( get_lang("remove") . ": {$files}" );
			}
		}
	}
	// Rename File(s)/Folder(s)
	elseif( isset( $_POST['rename'] ) and $fo['rename'] == "1" )
	{
		if( isset($_SESSION['fm_files_'.$home_id]) and !empty($_SESSION['fm_files_'.$home_id]) )
		{
			foreach ((array)$_POST['items'] as $i => $item)
			{
				if(isset($_SESSION['fm_files_'.$home_id][$item]))
				{
					$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
					$new_item = stripslashes($_POST['values'][$i]);
					if (!litefm_is_valid_path_component($new_item))
					{
						print_failure(get_lang("unallowed_char"));
						continue;
					}
					$new_item_path = clean_path( $path . "/" . $new_item );
					if (!litefm_path_within_home(litefm_payload_root($home_cfg), $new_item_path))
					{
						print_failure(get_lang("unallowed_char"));
						continue;
					}
					if ($item_path != $new_item_path)
					{
						$remote->shell_action('rename', "$item_path;$new_item_path");
						$db->logger( get_lang("rename") . ": $item_path " . get_lang("to") . " $new_item_path" );
					}
				}
			}
		}
	}
	// Move Files/Folders
	elseif( isset( $_POST['move'] ) and $fo['move'] == "1" )
	{
		$selected_path = litefm_normalize_relative_path(stripslashes($_POST['selected_path']));
		if ($selected_path === false)
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		$destination = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $selected_path);
		if ($destination === false)
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		if($path != $destination)
		{
			if($remote->rfile_exists($destination))
			{
				foreach ((array)$_POST['items'] as $item)
				{
					if(isset($_SESSION['fm_files_'.$home_id][$item]))
					{
						$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
						$destination = clean_path($destination . "/.");
						$remote->shell_action('move', "$item_path;$destination");
						$db->logger( get_lang("move") . ": $item_path " . get_lang("to") . " $destination" );
					}
				}
			}
		}
	}
	// Copy Files/Folders
	elseif( isset( $_POST['copy'] ) and $fo['copy'] == "1" )
	{
		$selected_path = litefm_normalize_relative_path(stripslashes($_POST['selected_path']));
		if ($selected_path === false)
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		$destination = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $selected_path);
		if ($destination === false)
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		if($path != $destination)
		{
			if($remote->rfile_exists($destination))
			{
				foreach ((array)$_POST['items'] as $item)
				{
					if(isset($_SESSION['fm_files_'.$home_id][$item]))
					{
						$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
						$destination = clean_path($destination . "/.");
						$remote->shell_action('copy', "$item_path;$destination");
						$db->logger( get_lang("copy") . ": $item_path " . get_lang("to") . " $destination" );
					}
				}
			}
		}
	}
	// Compress Files/Folders
	elseif( isset( $_POST['compress'] ) and $fo['compress'] == "1" )
	{
		$files_w_path = '';
		$items = '';
		foreach ((array)$_POST['items'] as $item)
		{
			if(isset($_SESSION['fm_files_'.$home_id][$item]))
			{
				$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
				$files_w_path .= $item_path.'<br>';
				$items .= $_SESSION['fm_files_'.$home_id][$item].'\n';
			}
		}
		if($items != '')
		{
			$remote->compress_files($items,$path,$_POST['archive_name'],$_POST['archive_type']);
			$db->logger( get_lang("compress") . " " . $_POST['archive_type'] . ":<br>$files_w_path" );
		}
	}
	// uncompress
	elseif( isset( $_POST['uncompress'] ) and $fo['uncompress'] == "1" )
	{
		$selected_path = litefm_normalize_relative_path(stripslashes($_POST['selected_path']));
		if ($selected_path === false)
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		$destination = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $selected_path);
		if ($destination === false)
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		if($remote->rfile_exists($destination))
		{
			foreach ((array)$_POST['items'] as $item)
			{
				if(isset($_SESSION['fm_files_'.$home_id][$item]))
				{
					$file_location = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
					$remote->uncompress_file($file_location, $destination);
					$db->logger( get_lang("uncompress") . ": $file_location " . to . " $destination." );
				}
			}
		}
	}
	// Create file
	elseif( isset( $_POST['create_file'] ) and $fo['create_file'] == "1" )
	{
		$file_name = stripslashes($_POST['file_name']);
		if (!litefm_is_valid_path_component($file_name))
		{
			print_failure(get_lang("unallowed_char"));
			$file_name = "";
		}
		if ($file_name === "")
			return;
		$destination = clean_path( $path . "/" . $file_name);
		if (!litefm_path_within_home(litefm_payload_root($home_cfg), $destination))
		{
			print_failure(get_lang("unallowed_char"));
			return;
		}
		$remote->shell_action('touch', $destination);
		$db->logger( get_lang("create_file") . ": $destination" );
	}
	// Send by email
	elseif( isset( $_POST['send_by_email'] ) and $fo['send_by_email'] == "1" )
	{
		$archive_name = $_POST['archive_name'];
		$archive_type = $_POST['archive_type'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];
		$dest_email = $_POST['dest_email'];
		$items = '';
		foreach ((array)$_POST['items'] as $item)
		{
			if(isset($_SESSION['fm_files_'.$home_id][$item]))
			{
				$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
				$items .= $_SESSION['fm_files_'.$home_id][$item].'\n';
			}
		}
		if($items != '')
		{
			$retval = $remote->compress_files($items,$path,$archive_name,$archive_type);
			$archive = clean_path( "{$path}/{$archive_name}.{$archive_type}" );
			if( $retval == 0 )
			{
				do{
					$size1 = $remote->shell_action('size', $archive);
					sleep( 2 );
					$size2 = $remote->shell_action('size', $archive);
				}while($size1 != $size2);
			}
			if( $retval != -1 and $remote->rfile_exists($archive) )
			{
				$mail_retval = $remote->exec( "(echo '" . esc_squote($message) . "' | mutt -a '" . esc_squote($archive) . "' -s '" . esc_squote($subject) . "' -- '" . esc_squote($dest_email) . "');echo \$?" );
				if($mail_retval == 0)
				{
					echo get_lang("mail_sent_successfully");
					$db->logger( get_lang("send_by_email") . ": '$archive'<br>Subject: '$subject'<br>to: '$dest_email'" );
				}
				else
				{
					echo "The email could not be sent,\n".
						 "the package mutt or mutt-patched (a mail client)\n".
						 "must be installed, and postfix should be configured\n".
						 "in order to send large files.";
				}
			}
		}
	}
	// Secure File
	elseif( isset( $_POST['secure_file'] ) and $isAdmin )
	{
		if(isset($_SESSION['fm_files_'.$home_id][$_POST['item']]))
		{
			if($_POST['set_attr'] == '+i' or $_POST['set_attr'] == '-i')
			{
				$type = $_POST['set_attr'] == '+i' ? get_lang("chattr_locked") : get_lang("chattr_unlocked");
				$action = "chattr".$_POST['set_attr'];
				$file_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$_POST['item']] );
				$remote->secure_path($action, $file_path);
				$db->logger( "$type: $file_path" );
			}
		}
	}
	else
	{
		?>
		<script type="text/javascript" src="js/jquery/plugins/jquery.form.min.js"></script>
		<script type="text/javascript" src="js/zlib/zlib.min.js"></script>
		<script type="text/javascript" src="js/modules/litefm.js"></script>
		<?php
		echo "<h2>";
		echo empty($home_cfg['home_name']) ?  get_lang("not_available")  : htmlentities($home_cfg['home_name']);
		echo "</h2>";
		$_SESSION['fm_files_'.$home_id] = array();
		$show_path = litefm_display_home_path(litefm_payload_root($home_cfg), isset($_SESSION['fm_cwd_'.$home_id]) ? $_SESSION['fm_cwd_'.$home_id] : '');
		if ($show_path === false)
			$show_path = clean_path(litefm_payload_root($home_cfg));
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ". get_lang("back") ."</a></td></tr></table>";
		if ($remote->rfile_exists($path))
		{
			echo "<table class='center' style='width:100%;' ><tr>\n".
				 "<td colspan='3' ><h3>".
				 get_lang_f('currently_viewing',$show_path)."</h3></td>".
				 "</tr></table>\n";
			echo "<div class='file-operations' >\n";
			foreach ((array)$fo_keys as $key)
			{
				if($fo[$key] == "1")
					echo "<div class='operations-button' id='$key'><img src='" . check_theme_image("modules/litefm/action-images/$key.gif") . "' /><div>&nbsp;".get_lang($key)."</div></div>\n";
			}
			echo "</div>\n";
			$dirlist = $remote->remote_dirlistfm($path);

			if (!is_array($dirlist))
			{
				if ($remote->rfile_exists($path))
				{
					print_failure("Directory exists but cannot be opened. Check permissions.");
					return;
				}
				elseif(isset($_SESSION['fm_cwd_'.$home_id]))
				{
					unset($_SESSION['fm_cwd_'.$home_id]);
					$view->refresh("?m=litefm&amp;home_id=$home_id",0);
					return;
				}
				else
				{
					print_failure( get_lang("failed_list") );
					return;
				}
			}

			if ( empty($dirlist) )
			{

				echo "<table class='center' style='width:100%;' >\n".
					 show_back($home_id)."</table>";
				echo "<p>" . get_lang("empty_directory") . "</p>";
			}
			else
			{
				echo "<table class='center' style='width:100%;' >\n"
					 .show_back($home_id).
					 "<tr>\n<td style='width:10px;' >".
					 "<input type=checkbox name='switch' id='switch_check' />".
					 "</td>\n<td align=left>".
					  get_lang("filename") ."</td>\n";
				if( $os == "linux" )
					echo "<td>".  get_lang("filesecure")  ."</td>\n";
				echo "<td>". get_lang("filesize") ." [". get_lang("bytes") ."]</td>\n<td>".
					  get_lang("owner") ." ". get_lang("group") ."</td>\n</tr>\n";
				$i = 0;
				if(isset($dirlist['directorys']) and is_array($dirlist['directorys']))
				{
					$dirlist['directorys'] = array_orderby($dirlist['directorys'], 'filename', SORT_ASC);
					foreach ((array)$dirlist['directorys'] as $directory)
					{
						$directoryName = $directory['filename'];
						if (!litefm_is_valid_path_component($directoryName))
							continue;
						$encodedDirectoryName = rawurlencode($directoryName);
						$escapedDirectoryName = litefm_escape_html($directoryName);

						echo "<tr>\n".
							 "<td>".
								 "<input type=checkbox name='folder' data-item='$i' value=\"" . $escapedDirectoryName . "\" class='item' />\n".
							 "</td>".
							 "<td align=left>".
							 "<img class=\"viewitem\" src=\"" . check_theme_image("images/folder.png") . "\" alt=\"Directory\" /> ".
								 "<a href=\"?m=litefm&amp;home_id=$home_id&amp;item=$i&amp;name=" . $encodedDirectoryName . "&amp;type=directory\">".
								 $escapedDirectoryName . "</a></td>";
						if( $os == "linux" )
							echo "<td>-</td>";
						echo "<td>-</td> <td>" . $directory['user'] . " " . $directory['group']. "</td>\n".
							 "</tr>\n";
							$_SESSION['fm_files_'.$home_id][$i] = $directoryName;
						$i++;
					}
				}

				if(isset($dirlist['files']) and is_array($dirlist['files']))
				{
					$dirlist['files'] = array_orderby($dirlist['files'], 'filename', SORT_ASC);
					foreach ((array)$dirlist['files'] as $file)
					{
						$fileName = $file['filename'];
						if (!litefm_is_valid_path_component($fileName))
							continue;
						$encodedFileName = rawurlencode($fileName);
						$escapedFileName = litefm_escape_html($fileName);

						if( $os == "linux" )
						{
							if($isAdmin){
								$secureFile = "<td><div data-item='$i' data-file_name=\"" . litefm_escape_html($file['filename']) . "\" class='chattrButton ";
								if( preg_match( "/i/", $file['attr'] ) ){
									$secureFile .= "locked' data-set_attr='-i' ><i></i><span>". get_lang("chattr_no");
								}else{
									$secureFile .= "unlocked' data-set_attr='+i' ><i></i><span>". get_lang("chattr_yes");
								}
								$secureFile .= "</span></div></td>\n";
							}else{
								$secureFile = "<td><span class=";
								if( preg_match( "/i/", $file['attr'] ) ){
									$secureFile .= "'chattrLock'>". get_lang("chattr_locked");
								}else{
									$secureFile .= "'chattrUnlock'>". get_lang("chattr_unlocked");
								}
								$secureFile .= "</span></td>\n";
							}
						}
						else
							$secureFile = "";

						echo "<tr>\n".
							 "<td>".
							 "<input type=checkbox name='file' data-item='$i' value=\"" . $escapedFileName . "\" class='item' />\n".
							 "</td>".
							 "<td align=left id='fileid$i' >";
						echo "<img class=\"viewitem\" src=\"" . check_theme_image("images/txt.png") . "\" alt=\"Text file\" /> ".
							 "<a href=\"?m=litefm&amp;home_id=$home_id&amp;item=$i&amp;p=read_file&amp;name=" . $encodedFileName . "&amp;type=file\">". get_lang("button_edit") ."</a>".
							 "<a href=\"javascript:downloadFile($home_id, $i, {$file['size']}, '".str_replace("'", "\\'", $fileName)."');\" id=\"jsDwl$i\" >" .$escapedFileName . "</a>&nbsp;".
							 "</td>$secureFile<td>" . $file['size'] . "</td> <td>" . $file['user'] . " " . $file['group']. "</td>\n";
						echo "</tr>\n";
						$_SESSION['fm_files_'.$home_id][$i] = $fileName;
						$i++;
					}
				}

				if(isset($dirlist['binarys']) and is_array($dirlist['binarys']))
				{
					$dirlist['binarys'] = array_orderby($dirlist['binarys'], 'filename', SORT_ASC);
					foreach ((array)$dirlist['binarys'] as $binary)
					{
						$binaryName = $binary['filename'];
						if (!litefm_is_valid_path_component($binaryName))
							continue;
						$escapedBinaryName = litefm_escape_html($binaryName);

						if( $os == "linux" )
						{
							if($isAdmin){
								$secureFile = "<td><div data-item='$i' data-file_name=\"" . litefm_escape_html($binary['filename']) . "\" class='chattrButton ";
								if( preg_match( "/i/", $binary['attr'] ) ){
									$secureFile .= "locked' data-set_attr='-i' ><i></i><span>". get_lang("chattr_no");
								}else{
									$secureFile .= "unlocked' data-set_attr='+i' ><i></i><span>". get_lang("chattr_yes");
								}
								$secureFile .= "</span></div></td>\n";
							}else{
								$secureFile = "<td><span class=";
								if( preg_match( "/i/", $binary['attr'] ) ){
									$secureFile .= "'chattrLock'>". get_lang("chattr_locked");
								}else{
									$secureFile .= "'chattrUnlock'>". get_lang("chattr_unlocked");
								}
								$secureFile .= "</span></td>\n";
							}
						}
						else
							$secureFile = "";

						echo "<tr>\n".
							 "<td>".
							 "<input type=checkbox name='binary' data-item='$i' value=\"" . $escapedBinaryName . "\" class='item' />\n".
							 "</td>".
							 "<td align=left id='fileid$i' >";
						echo "<img class=\"viewitem\" src=\"" . check_theme_image("images/exec.png") . "\" alt=\"Binary file\" /> ".
							 "<a href=\"javascript:downloadFile($home_id, $i, {$binary['size']}, '".str_replace("'", "\\'", $binaryName)."');\" id=\"jsDwl$i\" >" .$escapedBinaryName . "</a>&nbsp;".
							 "</td>$secureFile<td>" . $binary['size'] . "</td><td>" . $binary['user'] . " " . $binary['group']. "</td>\n";
						echo "</tr>\n";
						$_SESSION['fm_files_'.$home_id][$i] = $binaryName;
						$i++;
					}
				}
				echo "</table>\n";
			}
		}
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ". get_lang("back") ."</a></td></tr></table>";
		// Dialog translation && info
		$user = $db->getUserById($_SESSION['user_id']);
		echo "<div id='dialog' ".
			 "data-folder=\"" . litefm_escape_html(clean_path("/".(@$_SESSION['fm_cwd_'.$home_id] ?? ''))) . "\" " .
			 "data-select_at_least_one_item='" . get_lang("select_at_least_one_item") . "' " .
			 "data-ask_delete='" . get_lang("delete_item") . "' " .
			 "data-ask_rename='" . get_lang("rename_item") . "' " .
			 "data-ask_move='" . get_lang("move_item") . "' " .
			 "data-ask_copy='" . get_lang("copy_item") . "' " .
			 "data-ask_compress='" . get_lang("compress_item") . "' " .
			 "data-ask_uncompress='" . get_lang("uncompress_item") . "' " .
			 "data-archive_name='" . get_lang("archive_name") . "' " .
			 "data-archive_type='" . get_lang("archive_type") . "' " .
			 "data-file_name='" . get_lang("file_name") . "' " .
			 "data-folder_name='" . get_lang("folder_name") . "' " .
			 "data-compresses_files_separately='" . get_lang("compresses_files_separately") . "' " .
			 "data-to='" . get_lang("to") . "' " .
			 "data-yes='" . get_lang("yes") . "' " .
			 "data-no='" . get_lang("no") . "' " .
			 "data-max_file_uploads='" . ini_get('max_file_uploads') . "' " .
			 "data-upload_to_web='" . get_lang("upload_to_web") . "' " .
			 "data-transfer_to_server='" . get_lang("transfer_to_server") . "' " .
			 "data-upload='" . get_lang("upload") . "' " .
			 "data-ask_send_by_email='" . get_lang("send_item_by_email") . "' " .
			 "data-subject='" . get_lang("subject") . "' " .
			 "data-message='" . get_lang("message") . "' " .
			 "data-dest_email='" . get_lang("dest_email") . "' " .
			 "data-user_email='" . $user['users_email'] . "' ";
				 if($isAdmin)
					 echo "data-ask_change_attr=\"" . litefm_escape_html(get_lang_f( 'secure_item', clean_path( $path . "/%file_name%" ) )) . "\" ";
			 echo "></div>";
	}
}
?>
