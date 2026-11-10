# Work Tracking

| Type | Item | Status | Owner | GitLab |
| --- | --- | --- | --- | --- |
| todo | API page: per-machine dropdown, filled-in game server commands, admin machine commands. Test in a browser on the test panel (not run against any database). | done (code), browser test open | Claude | |
| need | gamemanager has no status command in api.php. Decide whether to add one so the API page can show Status. | done: gamemanager/status added (read-only, agent server_status RPC); browser/live test open | Claude | |
| todo | API page: admin-only server/list, user_games/list_servers, user_games/create restored. Test in a browser on the test panel. | done (code), browser test open | Claude | |
| todo | Navigation: single-click user menu, API page, Game Monitor "All", Administration menu setting. Test in a browser on the test panel. | done (code), browser test open | Claude | |
| need | Root `TODO.md` and this file overlap. Decide which one is the tracked list. | open | Leroy | |
| need | Check the Administration page and Game Monitor "All" link in the other themes (Silver, Obsidian, Revolution). | open | Claude | |
| todo | Start/restart: refuse port 0 or empty before sending to the agent (lib_remote.php); test in modules/gamemanager/tests/start_port_test.php. | done | Claude | |
| todo | Open one crash ticket per `Failed to Start` FAILED status (crash_tickets.php, marks in new table crash_ticket_marks, gamemanager db_version 3). Needs a test on the test panel with a real FAILED status; the module upgrade must be run there. | done (code), live test open | Claude | |
| need | install/gse_panel.sql was edited by hand for crash_ticket_marks. Regenerate with scripts/build-schema.sh when a scratch MySQL is available. | open | Claude | |
| issue | Existing tests fail before this work: game_path_helpers_test, lifecycle_contract_helpers_test, get_server_log_endpoint_test, port_allocator_test, check_schema_drift (71 tables). lib_remote_lifecycle_wrapper_test and agent_helpers_test need the PHP xmlrpc extension, which is not installed here. | open | Claude | |
| todo | Panel update is one unit: preflight (writes, disk, dump), stage + validate ZIP, backup, copy, migrate, verify; rollback restores files and database together (update_safety.php, 2.5.5). Run it on the test panel with a failing migration. | done (code), test-panel run open | Claude | |
| todo | Update and installer have no channels: one selected release (version + ZIP URL). Old update_channel/update_branch settings are still read. | done | Claude | |
| todo | install-panel.sh leaves no root-owned tree; repair_permissions is required. Needs a run as root on a scratch path. | done (code), root run open | Claude | |
| issue | Migration files named like `2026-10-28-bot-start-mode.sql` do not match the runner pattern `NNN_name.sql`, so they never run. Rename them or change migrations_discover. Staging logs a warning. | open | Claude | |
| need | Rename panel_update_log columns `channel`/`branch` to version/source_url in a migration. They hold version and ZIP URL now. | open | Claude | |
