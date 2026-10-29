# Task

## User Request

Perform DEV+1 workflow Step 03 for GSE-Panel: update Start, Stop, Restart, Force Stop, and optional bot integration so the Panel sends current lifecycle path/runtime values to the agents, keeps management artifacts in management_home, uses a simple one-bot definition, verifies stop outcomes, distinguishes desired state from observed runtime, and does not commit or push during this phase.

## Problem

The Panel lifecycle calls still used the legacy positional agent contract as the only source of Start/Restart data. Stop handling also treated legacy return codes as success before requiring follow-up runtime evidence in every path. Optional bot state could be generated as `_alsoRun.cmd`, but Start did not send or write the current simple `bot.def` definition expected by the simplified agent watchdog contract.

## Root Cause

Lifecycle request construction was scattered across UI, API, scheduled Server Content restart, and batch helper paths. `includes/lib_remote.php` normalized XML-RPC results to legacy scalars, which hid structured agent failures. Status display paths could still rely on status labels without explicitly separating desired-state markers from observed screen/PID/port evidence.

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
- `docs/COMMAND_FLOW.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/UPDATE_POLICY.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `includes/lib_remote.php`
- `includes/game_path_helpers.php`
- `includes/api_functions.php`
- `gsp_api.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/cfg_text_replace.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/server/agent_interface.php`
- `modules/reseller/cron-shop.php`
- `modules/reseller/account_details.php`
- `/home/dev/Projects/GSE-Agent-Linux/README.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/GAME_SERVER_LIFECYCLE_CONTRACT.md`
- `/home/dev/Projects/GSE-Agent-Windows/README.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/GAME_SERVER_LIFECYCLE_CONTRACT.md`
- `/home/dev/Projects/GSE-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSE-Agent-Windows/AgentScripts/gse_agent.pl`

## Files Changed

- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `gsp_api.php`
- `includes/api_functions.php`
- `includes/lib_remote.php`
- `includes/lifecycle_contract_helpers.php`
- `includes/tests/lifecycle_contract_helpers_test.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/gamemanager/cfg_text_replace.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/reseller/account_details.php`
- `modules/reseller/cron-shop.php`
- `modules/server/agent_interface.php`

Other files were already dirty from prior workflow steps and were preserved.

## Architecture Decisions

- Appended a JSON `gse.lifecycle.v2` payload after legacy positional lifecycle RPC arguments so current agents can consume rich data while older deployed agents ignore the extra argument.
- Kept legacy scalar wrapper methods for older callers, but added structured-result wrapper variants for maintained lifecycle paths.
- Built Start payloads from current resolved `management_home`, validated `game_key`, `game_root`, run directories, command arguments, environment, pre-start commands, assigned port/protocol context, selected options, replace-text write results, and optional bot definition.
- Generated active `bot.def` in `management_home` from the first enabled Server Content bot row, refused to overwrite non-generated `bot.def`, and did not place bot management files under `game_root`.
- Treated Stop results as provisional until `server_status` plus narrow legacy session/port fallback shows no managed process/session/port evidence survived.
- Made status display show desired state as intent only and require runtime evidence when desired-state fields are present.

## Validation Performed

- `git status --short --branch`
- `git diff --stat`
- `git diff --check`
- PHP syntax checks on lifecycle, API, gamemanager, Server Content, server-agent interface, and reseller files changed in this step.
- `php includes/tests/lifecycle_contract_helpers_test.php`
- `php includes/tests/game_path_helpers_test.php`
- `php modules/addonsmanager/tests/server_bot_helpers_test.php`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/config_games/tests/validate_server_configs.php`

## Git Commits

Repository

GSE-Panel

Commit

None. This workflow phase explicitly defers commit and push until coordinated validation across Panel, Windows agent, and Linux agent.

Hash

None.

## Remaining Work

- Later workflow validation should exercise live/fake agent Start, Stop, Restart, protected-port, surviving-process, crash-loop, and incompatible-agent responses end to end.
- Coordinated Panel/Linux/Windows validation and delivery remain deferred to later workflow steps.
