# Task

## User Request

Create a generic Server Bot infrastructure as a first-class Server Content type. Inspect Panel, GSP-Agent-Windows, and GSP-Agent-Linux before editing. Preserve existing agent startup behavior. Add installed-content tracking, installed/enabled/disabled/update/uninstall state support, generate the existing also-run launch file from installed Server Bot entries, add database and UI support, validate Windows and Linux generation and install/uninstall regeneration, then commit and push `origin/unstable` if verification passes.

## Problem

Companion/bot processes such as BEC were still represented by game-specific scripts or manual compatibility files instead of Panel-owned Server Content state. The agents already had startup parser support for one `_alsoRun.cmd` workdir/executable/arguments triplet, but the Panel did not expose a generic Server Bot content type or regenerate that file from installed content records.

## Root Cause

Server Content had per-home manifest/history tables for normal content, but no generic bot template fields, no per-server bot enablement state, no UI actions for installed bot rows, and no generator for the current agent `_alsoRun.cmd` parser format.

## Repositories Inspected

- Panel
- GSP-Agent-Linux
- GSP-Agent-Windows

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `Panel/README.md`
- `Panel/docs/ARCHITECTURE.md`
- `Panel/docs/PANEL_ARCHITECTURE.md`
- `Panel/docs/AGENT_COMMUNICATION.md`
- `Panel/docs/REPOSITORY_STRUCTURE.md`
- `Panel/docs/COMMAND_FLOW.md`
- `Panel/docs/UPDATE_POLICY.md`
- `Panel/docs/modules/SERVER_CONTENT_MANAGER.md`
- `Panel/docs/features/COMPANION_PROGRAMS.md`
- `Panel/docs/features/WORKSHOP_SYSTEM.md`
- `Panel/docs/features/USER_API.md`
- `Panel/docs/DATABASE_SCHEMA_REFERENCE.md`
- `Panel/modules/addonsmanager/SERVER_CONTENT_ROADMAP.md`
- `Panel/modules/addonsmanager/SERVER_CONTENT_WORKSHOP_PHASE1.md`
- `Panel/modules/addonsmanager/module.php`
- `Panel/modules/addonsmanager/addons_manager.php`
- `Panel/modules/addonsmanager/user_addons.php`
- `Panel/modules/addonsmanager/addons_installer.php`
- `Panel/modules/addonsmanager/server_content_actions.php`
- `Panel/modules/addonsmanager/server_content_categories.php`
- `Panel/modules/addonsmanager/server_content_helpers.php`
- `Panel/modules/addonsmanager/monitor_buttons.php`
- `Panel/modules/addonsmanager/navigation.xml`
- `Panel/modules/addonsmanager/tests/workshop_helpers_test.php`
- `Panel/modules/config_games/server_configs/dayz_epoch_mod_win32.xml`
- `Panel/includes/lib_remote.php`
- `Panel/includes/api_functions.php`
- `GSP-Agent-Linux/README.md`
- `GSP-Agent-Linux/documentation/agent-guide.md`
- `GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `GSP-Agent-Linux/docs/AGENT_ACTIVITY_EVENTS.md`
- `GSP-Agent-Linux/gsp_agent.pl`
- `GSP-Agent-Windows/README.md`
- `GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `GSP-Agent-Windows/AgentScripts/gse_agent.pl`
- Prior Codex history records for database schema, production update path, Windows installer fixes, and agent lifecycle parity.

## Files Changed

- `Panel/docs/DATABASE_SCHEMA_REFERENCE.md`
- `Panel/docs/features/COMPANION_PROGRAMS.md`
- `Panel/docs/modules/SERVER_CONTENT_MANAGER.md`
- `Panel/js/modules/addonsmanager.js`
- `Panel/modules/addonsmanager/addons_installer.php`
- `Panel/modules/addonsmanager/addons_manager.php`
- `Panel/modules/addonsmanager/module.php`
- `Panel/modules/addonsmanager/server_content_actions.php`
- `Panel/modules/addonsmanager/server_content_categories.php`
- `Panel/modules/addonsmanager/server_content_helpers.php`
- `Panel/modules/addonsmanager/user_addons.php`
- `Panel/modules/addonsmanager/tests/server_bot_helpers_test.php`
- `Panel/docs/codex-history/2026/2026-07-15-1614-server-bot-content-infrastructure.md`

## Architecture Decisions

- Preserve existing Linux and Windows agent startup behavior; no agent files were changed.
- Use the actual parser file and keys currently implemented by both agents: `_alsoRun.cmd` with `workdir=`, `executable=`, and `arguments=`.
- Generate Linux `_alsoRun.cmd` at the server home root because the Linux parser reads there.
- Generate Windows/Cygwin `_alsoRun.cmd` in the game working directory because the Windows parser reads after `cd /d "$game_workdir"`.
- Refuse to overwrite a non-generated `_alsoRun.cmd` so existing manual compatibility behavior remains unchanged.
- Extend the existing `server_content_manifest` table instead of creating a separate installed-bot table, keeping installed content state in one per-home manifest.
- Add `server_bot` as a Server Content install method/category, but do not implement BEC, DZSA, or other game-specific packages.
- Document the Phase 1 limitation that the current agent parser supports one bot triplet; the generator activates the first enabled Server Bot row for a server.

## Validation Performed

- `php -l` on changed PHP files.
- `php modules/addonsmanager/tests/server_bot_helpers_test.php`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/config_games/tests/validate_server_configs.php`
- `git diff --check`
- Static inspection of Linux and Windows agent `_alsoRun.cmd` parser behavior.

## Git Commits

Repository

Panel

Commit

Pending final validation.

Hash

Pending final validation.

## Remaining Work

- Convert specific bot packages such as BEC and DZSA into Server Bot content templates in Phase 2.
- Add richer multi-bot runtime support only with a coordinated agent parser/runtime change; Phase 1 intentionally preserves the existing single-triplet parser.
- Perform live Panel/agent/browser validation against real Linux and Windows hosts.
