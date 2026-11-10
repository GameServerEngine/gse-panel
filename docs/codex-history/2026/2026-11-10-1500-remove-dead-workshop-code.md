# Task

Version 2.7.2: delete dead Steam Workshop code from Server Content. The live modules/steam_workshop module, modules/config_games and the DayZ XML are not changed.

## Change

- scm_workshop_write_manifest_and_run(), scm_workshop_build_manifest_context() and scm_workshop_update_rows_state() are not defined anywhere. Their callers (server_content_actions.php, addons_installer.php, api.php) are removed.
- install_method `steam_workshop` can never be reached: scm_get_install_method_default() only returns keys of scm_get_install_methods(), which does not list it. All branches for it are removed (installer, api.php, addons_manager.php, helpers required-fields map and validator, JS).
- Deleted workshop_action.php, workshop_content.php, the steamcmd job script, workshop_helpers_test.php and all 61 tracked .bak files.
- Removed every scm_*workshop* helper with no remaining caller, the SCM_WORKSHOP_* constants, and the unused CSRF helpers.
- Removed the Workshop-only cron actions and their English labels.
