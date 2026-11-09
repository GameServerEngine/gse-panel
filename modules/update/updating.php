<?php
/*
 *
 * GSP - GameServer Panel
 * Legacy update route compatibility page.
 *
 * File-copy updates now run through the CSRF-protected Panel update page
 * backed by modules/administration/panel_update.php.
 *
 */

function exec_module()
{
    if ($_SESSION['users_role'] !== 'admin') {
        print_failure(get_lang('no_access'));
        return;
    }

    echo "<h4>Panel Release ZIP Update</h4>\n";
    echo "<p>The legacy direct update route is disabled. Use the main Panel Updates page to select <strong>stable</strong> or <strong>unstable</strong>, review the release ZIP URL, create a backup, and run the CSRF-protected update workflow.</p>\n";
    echo "<p>Release updates download a ZIP archive, extract it into temporary staging, validate the Panel payload root, and copy application files into the Panel folder while preserving config and runtime directories.</p>\n";
    echo "<p><a href='home.php?m=update'>Open Panel Updates</a></p>\n";
}
?>
