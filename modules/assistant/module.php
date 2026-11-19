<?php
/*
 * GSE - GameServer Engine
 *
 * Assistant: a chat box for customers. It answers questions about their own game servers
 * (status, recent errors, who joined) and offers Start / Stop / Restart buttons.
 * It uses the Claude API from the panel server only. Agents never see the API key.
 */

// Module general information
$module_title = "Assistant";
$module_version = "0.1";
$db_version = 0;
$module_required = FALSE;
$module_menus = array( array( 'subpage' => 'chat', 'name'=>'Assistant', 'group'=>'user' ) );
?>
