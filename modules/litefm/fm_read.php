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
	require_once(MODULES."/litefm/litefm.php");

	$home_id = $_REQUEST['home_id'];

	if (empty($home_id))
	{
		print_failure(get_lang('home_id_missing'));
		return;
	}

	global $db;
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
		print_failure(get_lang('no_access_to_home'));
		return;
	}

	if ( preg_match("/f/",$home_cfg['access_rights']) != 1 )
	{
		print_failure( get_lang("no_rights") );
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ". get_lang("back") ."</a></td></tr></table>";
		return;
	}

	$home_id = $home_cfg['home_id'];

	if (litefm_check($home_id) === FALSE)
		return;

	// Validate and recover from bad cached paths
	$cwd_session_key = 'fm_cwd_' . $home_id;
	if (isset($_SESSION[$cwd_session_key]) && $_SESSION[$cwd_session_key] !== '')
	{
	$testPath = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $_SESSION[$cwd_session_key]);
		if ($testPath === false)
		{
			print_success("Invalid saved path was reset to the server root.");
			$_SESSION[$cwd_session_key] = '';
		}
	}

	$show_path = litefm_display_home_path(litefm_payload_root($home_cfg), isset($_SESSION[$cwd_session_key]) ? $_SESSION[$cwd_session_key] : '');
	if ($show_path === false)
		$show_path = clean_path(litefm_payload_root($home_cfg));
	echo "<table class='center' style='width:100%;'>".show_back($home_id)."</table>";
	echo "<table class='center' style='width:100%;' ><tr>\n".
		 "<td colspan='3' ><h3>$show_path</h3></td>".
		 "</tr></table>\n";
	//Logic to open the file we're editing
	$remote = new GSPRemoteLibrary($home_cfg['agent_ip'], $home_cfg['agent_port'], $home_cfg['encryption_key'], $home_cfg['timeout']);
	$data = "";
	$rel_path = isset($_SESSION[$cwd_session_key]) ? $_SESSION[$cwd_session_key]:'';
	$filepath = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $rel_path);
	if ($filepath === false)
	{
		print_failure(get_lang('unallowed_char'));
		return;
	}
	$file_info =  $remote->remote_readfile($filepath ,$data);
	if ( $file_info === 0 )
	{
		print_failure(get_lang("not_found"));
		return;
	}
	else if ( $file_info === -1 )
	{
		print_failure(get_lang("agent_offline"));
		return;
	}
	else if ( $file_info === -2 )
	{
		print_failure(get_lang("failed_read"));
		return;
	}
	?>
<div id="editor_wrapper" >
	<xmp id="editor"><?php echo $data;?></xmp>
</div>
<button onclick="saveToFile()"><?php echo get_lang('save'); ?></button>
<link rel="stylesheet" type="text/css" href="modules/litefm/fm_read.css">
<script src="modules/litefm/ace/ace.js" type="text/javascript" charset="utf-8"></script>
<script src="modules/litefm/ace/ext-modelist.js" type="text/javascript" charset="utf-8"></script>
<script>
    var editor = ace.edit("editor");
    editor.setTheme("ace/theme/tomorrow");
    (function () {
        var modelist = ace.require("ace/ext/modelist");
		var filePath = "<?php echo htmlspecialchars($rel_path, ENT_QUOTES, 'UTF-8'); ?>";
        var mode = modelist.getModeForPath(filePath).mode;
        console.log(mode);
        editor.session.setMode(mode);
    }());

	function saveToFile()
	{
		var form = document.createElement("form");
		form.setAttribute("method", "POST");
		form.setAttribute("action", "?m=litefm&p=write_file");

		var textArea = document.createElement("textarea");
		textArea.setAttribute("name", "file_content");
		textArea.setAttribute("style", "display:none;");
		textArea.value = editor.getValue();
		form.appendChild(textArea);

		var hiddenField = document.createElement("input");
		hiddenField.setAttribute("type", "hidden");
		hiddenField.setAttribute("name", "home_id");
		hiddenField.setAttribute("value", "<?php echo $home_id; ?>");
		form.appendChild(hiddenField);

		var submitButton = document.createElement("input");
		submitButton.setAttribute("type", "hidden");
		submitButton.setAttribute("name", "save_file");
		submitButton.setAttribute("value", "save_file");
		form.appendChild(submitButton);

		document.body.appendChild(form);
		form.submit();
	}
</script>
<?php
	show_back($home_id);
}
?>
