# Task

## User Request

Implement Phase 2 directly in `/home/dev/Projects/GSP/Panel`: verify Phase 1 Server Bot infrastructure and current architecture, then finish the database-backed installed Server Content lifecycle, preserve the existing agent-compatible `_alsoRun.cmd` format, add installed UI actions, add BEC and DZSA Server Bot packages, validate, commit with `feat: add installed server content lifecycle and bot packages`, and push `unstable` to `origin`.

## Problem

Phase 1 added generic Server Bot infrastructure, but installed Server Content still needed a fuller Panel database source of truth, package metadata for practical bot entries, safe lifecycle ordering, install/update/uninstall behavior, UI status clarity, and first real BEC/DZSA catalog definitions.

## Root Cause

The existing implementation stored basic installed state in `server_content_manifest` and generated `_alsoRun.cmd`, but Server Bot package metadata was limited to working directory, executable, and arguments. Lifecycle actions updated DB state before all file-generation outcomes were known, uninstall left rows marked instead of removing installed state, and no practical BEC or DZSA catalog rows were seeded.

## Repositories Inspected

- Panel
- GSP-Agent-Linux
- GSP-Agent-Windows

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `docs/features/COMPANION_PROGRAMS.md`
- `docs/decisions/COMPANION_PROGRAMS_DESIGN.md`
- `docs/codex-history/2026/2026-07-15-1614-server-bot-content-infrastructure.md`
- `modules/addonsmanager/module.php`
- `modules/addonsmanager/addons_manager.php`
- `modules/addonsmanager/addons_installer.php`
- `modules/addonsmanager/user_addons.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/server_content_categories.php`
- `modules/addonsmanager/tests/server_bot_helpers_test.php`
- `modules/addonsmanager/tests/workshop_helpers_test.php`
- `modules/config_games/server_configs/dayz_win64.xml`
- `modules/config_games/server_configs/dayz_epoch_mod_win32.xml`
- `modules/config_games/server_configs/dayz_arma2co_win32.xml`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/AgentScripts/gse_agent.pl`

## Files Changed

- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/features/COMPANION_PROGRAMS.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `docs/codex-history/2026/2026-07-15-1649-installed-server-content-lifecycle.md`
- `js/modules/addonsmanager.js`
- `modules/addonsmanager/addons_installer.php`
- `modules/addonsmanager/addons_manager.php`
- `modules/addonsmanager/module.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/tests/server_bot_helpers_test.php`
- `modules/addonsmanager/user_addons.php`

## Architecture Decisions

- Reuse `server_content_manifest` as the Panel-owned installed-content table instead of creating a second source of truth.
- Treat `server_content_manifest.id` as the installed content identifier and display it as Installed Content ID.
- Preserve the exact current agent parser file format: `_alsoRun.cmd` with `workdir=`, `executable=`, and `arguments=`.
- Continue refusing to overwrite unmanaged `_alsoRun.cmd` files without the Panel generated marker.
- Resolve Server Bot placeholders into per-server manifest launch fields at install time.
- Generate from prospective enabled rows before changing installed state so manual-file conflicts do not create false DB state.
- Delete the installed manifest row only after successful Server Bot uninstall handling and launch-file regeneration.
- Seed BEC as an existing/admin-provided Windows bot template.
- Seed DZSA as an optional sidecar using `node-mod-server.exe`; do not change DayZ back to `DZSALModServer.exe`.
- Leave multi-bot runtime execution as Phase 3 because the current agent parser still supports one command triplet.

## Validation Performed

- `php -l modules/addonsmanager/server_content_helpers.php`
- `php -l modules/addonsmanager/addons_installer.php`
- `php -l modules/addonsmanager/addons_manager.php`
- `php -l modules/addonsmanager/user_addons.php`
- `php -l modules/addonsmanager/module.php`
- `php -l modules/addonsmanager/tests/server_bot_helpers_test.php`
- `php modules/addonsmanager/tests/server_bot_helpers_test.php`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/config_games/tests/validate_server_configs.php`

## Git Commits

Repository

Panel

Commit

feat: add installed server content lifecycle and bot packages

Hash

Final commit hash is reported by Git after this self-referential history record is committed.

## Remaining Work

- Live browser/agent validation against real Linux and Windows hosts.
- Add coordinated agent parser/runtime support for multiple simultaneous Server Bot command triplets.
- Add trusted upstream checksums for seeded downloadable bot packages when available.
- Expand uninstall ownership metadata before allowing generic package file removal beyond explicit admin uninstall scripts.
