# Task

## User Request

Perform DEV+1 workflow Step 02 for GSE-Panel: implement the authoritative management-home and game-root contract. Add one validated helper that derives `game_root = management_home/game_key` from the XML `game_key`; reject unsafe game keys and paths escaping the management home; update install, update, executable, configuration, mission, mod, Workshop, and payload operations to use `game_root`; keep GSP management artifacts in `management_home`; do not add migration code; cover traversal, reserved-name, and boundary behavior with tests. Do not commit or push during this phase.

## Problem

The Panel still treated the server home as both the management directory and the game payload root in many lifecycle, install, file-management, log, Workshop, and Server Content paths. That conflicted with the simplified cross-platform lifecycle contract where the server-ID home is reserved for management artifacts and the installed game payload lives under a child directory named by the XML `game_key`.

## Root Cause

Path construction was duplicated across Panel modules and API endpoints. Most code concatenated `home_path`, XML paths, and user-selected content paths directly, with no centralized validation that the XML `game_key` was a single safe directory component and no common derivation of `management_home/<game_key>`.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`
- `/home/dev/Projects/GSE-Agent-Linux`
- `/home/dev/Projects/GSE-Agent-Windows`

## Files Read

- `/home/dev/Projects/AGENTS.md`
- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `.devplus/workspaces/panel-game-lifecycle-layout-20260727/WORKFLOW.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `docs/features/XML_SYSTEM.md`
- `includes/api_functions.php`
- `gsp_api.php`
- `modules/config_games/server_config_parser.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/cfg_text_replace.php`
- `modules/gamemanager/update_actions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/get_server_log.php`
- `modules/gamemanager/log.php`
- `modules/gamemanager/view_server_log.php`
- `modules/gamemanager/rcon.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/addons_installer.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/addonsmanager/tests/server_bot_helpers_test.php`
- `modules/addonsmanager/tests/workshop_helpers_test.php`
- `modules/steam_workshop/functions.php`
- `modules/steam_workshop/main.php`
- `modules/steam_workshop/uninstall.php`
- `modules/user_games/add_home.php`
- `modules/billing/create_servers.php`
- `modules/reseller/assign_server.php`
- `modules/litefm/litefm.php`
- `modules/litefm/fm_dir.php`
- `modules/litefm/fm_write.php`
- `modules/litefm/fm_read.php`
- `modules/litefm/get_file.php`
- `modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `/home/dev/Projects/GSE-Agent-Linux/README.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Windows/README.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PANEL_INTEGRATION.md`

## Files Changed

- `includes/game_path_helpers.php`
- `includes/tests/game_path_helpers_test.php`
- `includes/api_functions.php`
- `gsp_api.php`
- `modules/config_games/server_config_parser.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/cfg_text_replace.php`
- `modules/gamemanager/update_actions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/get_server_log.php`
- `modules/gamemanager/log.php`
- `modules/gamemanager/view_server_log.php`
- `modules/gamemanager/rcon.php`
- `modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/addons_installer.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/addonsmanager/tests/server_bot_helpers_test.php`
- `modules/addonsmanager/tests/workshop_helpers_test.php`
- `modules/steam_workshop/functions.php`
- `modules/steam_workshop/main.php`
- `modules/steam_workshop/uninstall.php`
- `modules/user_games/add_home.php`
- `modules/billing/create_servers.php`
- `modules/reseller/assign_server.php`
- `modules/litefm/litefm.php`
- `modules/litefm/fm_dir.php`
- `modules/litefm/fm_write.php`
- `modules/litefm/fm_read.php`
- `modules/litefm/get_file.php`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `docs/features/XML_SYSTEM.md`
- `docs/codex-history/2026/2026-07-27-1038-management-home-game-root-contract.md`

## Architecture Decisions

- Added `includes/game_path_helpers.php` as the single Panel helper for validating XML `game_key` and deriving `game_root` from `management_home`.
- Rejected empty keys, traversal, separators, drive prefixes, control characters, reserved Windows device names, unsafe characters, trailing dots, and resolved paths outside the management home.
- Kept lifecycle management values, Server Content scripts/manifests, generated `_alsoRun.cmd`, status/runtime coordination, and agent update/log coordination in `management_home`.
- Routed game executable checks, generated command path variables, game logs, config replacement, LiteFM payload browsing, new install targets, SteamCMD/master update targets, Workshop payloads, add-on downloads, and relative bot payload install folders to `game_root`.
- Passed lifecycle start/restart run directories as `game_key/exe_location` while continuing to pass `home_path` as the management home until later workflow steps expand the structured Start payload.
- Did not add migration, auto-move, compatibility-copy, or startup rearrangement code.

## Validation Performed

- `git diff --check`
- PHP syntax sweep over all touched PHP files
- `php includes/tests/game_path_helpers_test.php`
- `php modules/addonsmanager/tests/server_bot_helpers_test.php`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `php modules/config_games/tests/validate_server_configs.php`

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

None. Workflow phase explicitly defers commit and push until coordinated validation across Panel, Windows agent, and Linux agent.

Hash

None.

## Remaining Work

- Later workflow steps still need to complete the full current Start parameter payload and coordinated Linux/Windows agent compatibility validation.
- Existing homes are not migrated by design; operators must manually arrange existing game folders under `management_home/<game_key>`.
