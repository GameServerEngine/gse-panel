<?php
// Admin bug report module. Sends a bug report to the bug report relay. The panel holds no token.
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
