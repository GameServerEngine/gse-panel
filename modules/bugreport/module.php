<?php
// Admin bug report module. Creates a GitHub issue from a form.
// The GitHub token is stored in the settings table (setting name: bugreport_github_token).
$module_title = "Bug report";
$module_version = "1.0";
$db_version = 0;
$module_required = FALSE;
$module_menus = array(
    array( 'subpage' => '', 'name'=>'Bug report', 'group'=>'admin' )
);

$install_queries = array();
$install_queries[0] = array();
?>
