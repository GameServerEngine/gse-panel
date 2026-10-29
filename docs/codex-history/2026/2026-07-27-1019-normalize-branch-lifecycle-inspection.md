# Task

## User Request

Read `/home/dev/Projects/AGENTS.md` first and perform only DEV+1 workflow Step 01 for `GSE-Panel`: normalize the local development branch, preserve existing uncommitted work, inspect the complete Panel lifecycle and path construction, compare Linux and Windows agent contracts, and produce an implementation map without committing or pushing.

## Problem

The Panel must be updated in later workflow steps for the simplified cross-platform lifecycle contract, but Step 01 was limited to branch safety normalization and inspection. The repository also had pre-existing untracked work that needed to be preserved.

## Root Cause

The current Panel code treats `server_homes.home_path` as both the management home and the game payload root. Start, stop, restart, update, Server Content, Workshop, bot, config, file-manager, FTP, and replace-text paths are built from that single value, while the new agent contract separates `management_home` from `game_root = management_home/<validated game_key>`.

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
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/features/XML_SYSTEM.md`
- `docs/features/WORKSHOP_SYSTEM.md`
- `docs/agents/WINDOWS_AGENT.md`
- `docs/agents/LINUX_AGENT.md`
- `includes/lib_remote.php`
- `includes/api_functions.php`
- `includes/database_mysqli.php`
- `gsp_api.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/update_actions.php`
- `modules/gamemanager/update_server.php`
- `modules/gamemanager/cfg_text_replace.php`
- `modules/config_games/server_config_parser.php`
- `modules/config_games/schema_server_config.xml`
- `modules/addonsmanager/addons_installer.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/steam_workshop/main.php`
- `modules/steam_workshop/functions.php`
- `modules/litefm/litefm.php`
- `modules/litefm/fm_dir.php`
- `modules/litefm/fm_write.php`
- `modules/editconfigfiles/modify.php`
- `modules/server/agent_interface.php`
- `modules/billing/create_servers.php`
- `modules/user_games/add_home.php`
- `modules/user_games/clone_home.php`
- `/home/dev/Projects/GSE-Agent-Linux/AGENTS.md`
- `/home/dev/Projects/GSE-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSE-Agent-Windows/AGENTS.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/GAME_SERVER_LIFECYCLE_CONTRACT.md`
- `/home/dev/Projects/GSE-Agent-Windows/AgentScripts/gse_agent.pl`

## Files Changed

- `docs/codex-history/2026/2026-07-27-1019-normalize-branch-lifecycle-inspection.md`

## Architecture Decisions

- No lifecycle implementation changes were made in Step 01.
- The local `unstable` branch was safely fast-forwarded to current `stable` and checked out after verifying ancestry and untracked path safety.
- Existing uncommitted work was preserved and left unstaged.
- Future implementation should add explicit Panel helpers for validated `game_key`, `management_home`, and `game_root` and then route lifecycle RPCs and path construction through those helpers.

## Validation Performed

- Verified initial dirty state and empty tracked diff.
- Verified local `unstable` was an ancestor of `stable`.
- Verified no conflicting tracked paths for the three pre-existing untracked entries.
- Fast-forwarded local `unstable` to `stable`, switched to `unstable`, and rechecked status, diff, and revisions.
- Inspected Panel lifecycle and path call sites and compared Linux and Windows agent contracts.

## Git Commits

Repository: `/home/dev/Projects/GSE-Panel`

Commit: Not committed by workflow instruction.

Hash: N/A

## Remaining Work

- Implement validated `game_key`, `management_home`, and `game_root` helpers.
- Update Panel lifecycle RPC payloads and agent compatibility handling.
- Move install/update/config/content path construction to the new game payload boundary.
- Keep GSP management artifacts, Server Content management state, and bot definitions in `management_home`.
- Improve stop/restart status propagation so user-facing flows do not report success while agent evidence remains active.
