# Task

## User Request

Perform DEV+1 workflow Step 04 for `/home/dev/Projects/GSE-Panel`: validate Panel behavior for the simplified cross-platform lifecycle and game-key payload layout, add focused automated tests, update durable agent handoff documentation, inspect the final diff for safety issues, and do not commit or push in this phase.

## Problem

Earlier workflow steps introduced the management-home/game-root contract and lifecycle payload helpers, but Step 04 still needed automated coverage that proves the behavior across path validation, Server Content placement, bot definitions, fake lifecycle RPCs, verified Stop/Restart handling, desired-versus-observed status, and compatibility boundaries. The Linux and Windows agent phases also needed a concise durable handoff describing exact RPC fields and evidence requirements.

## Root Cause

The remaining gap was validation and contract publication. During the test expansion, the generic Server Content path containment helper was also found to rely on prefix checks without rejecting parent-directory segments, which could let an absolute content path appear to be under `management_home` while resolving outside it on the agent host.

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
- `docs/development/CODEX_GUIDE.md`
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/features/XML_SYSTEM.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `includes/game_path_helpers.php`
- `includes/lifecycle_contract_helpers.php`
- `includes/tests/game_path_helpers_test.php`
- `includes/tests/lifecycle_contract_helpers_test.php`
- `includes/lib_remote.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/tests/server_bot_helpers_test.php`
- `modules/addonsmanager/tests/workshop_helpers_test.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `/home/dev/Projects/GSE-Agent-Linux/README.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/GAME_SERVER_LIFECYCLE_CONTRACT.md`
- `/home/dev/Projects/GSE-Agent-Linux/documentation/agent-guide.md`
- `/home/dev/Projects/GSE-Agent-Windows/README.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PANEL_INTEGRATION.md`

## Files Changed

- `includes/game_path_helpers.php`
- `modules/addonsmanager/server_content_helpers.php`
- `includes/tests/game_path_helpers_test.php`
- `includes/tests/lifecycle_contract_helpers_test.php`
- `modules/addonsmanager/tests/workshop_helpers_test.php`
- `modules/addonsmanager/tests/server_bot_helpers_test.php`
- `docs/architecture/LIFECYCLE_AGENT_HANDOFF.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/codex-history/2026/2026-07-27-1059-validate-lifecycle-agent-handoff.md`

## Architecture Decisions

- Reused the centralized game-path helper layer for parent-segment detection instead of adding another Server Content-only path parser.
- Kept `management_home` as the server-ID directory and `game_root` as `management_home/<validated game_key>`.
- Confirmed Server Content management files remain in `management_home/gsp_server_content`, while payload targets resolve under `game_root` unless an absolute path is validated inside `management_home`.
- Kept optional bot lifecycle state as one transparent `{working_directory, command/executable, arguments}` definition written to `management_home/bot.def`.
- Documented the `gse.lifecycle.v2` appended JSON payload as the cross-platform agent boundary while preserving narrow legacy scalar compatibility.

## Validation Performed

- `git status --short --branch`
- `git diff --stat`
- `git diff --name-status`
- Branch safety: `stable`, `unstable`, and `HEAD` all resolved to `e78152c04f9851d5795e9a0c72640acc900084c7`; `origin/unstable` and `unstable` remained ancestors of `stable`.
- `php includes/tests/game_path_helpers_test.php`
- `php includes/tests/lifecycle_contract_helpers_test.php`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/addonsmanager/tests/server_bot_helpers_test.php`
- `git diff --name-only -- '*.php' | xargs -r -n1 php -l`
- `php modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `php modules/config_games/tests/validate_server_configs.php`
- `php modules/server/tests/port_allocator_test.php`
- `php modules/server/tests/port_blocks_validation_test.php`
- `php modules/update/tests/update_config_smoke.php`
- `php modules/billing/tests/BillingLifecycleServiceTest.php`
- `php modules/billing/tests/BillingActionSecurityTest.php`
- `php modules/billing/tests/BillingRequestPathSourceTest.php`
- `php modules/billing/tests/BillingCouponHelperTest.php`
- `git diff --check`
- Safety scans for reference-folder modifications, migration-like code, secret-looking additions, runtime artifacts, and management-file paths under `game_root`.
- Cross-repository documentation inspection for Linux and Windows agent lifecycle contracts. The agent repositories were not modified.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

None. Workflow Step 04 explicitly defers commit and push until coordinated Panel, Windows agent, and Linux agent validation.

Hash

None.

## Remaining Work

- Linux agent phase: implement and validate the documented `gse.lifecycle.v2` evidence model on Linux.
- Windows agent phase: validate parity with the same payload fields and desired-versus-observed status behavior.
- Cross-repository validation: run real agent integration tests after Panel, Linux agent, and Windows agent changes are coordinated.
