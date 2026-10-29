# Task

## User Request

Work in `/home/dev/Projects/GSP/Panel`, verify the active nested repository before edits, inspect recent monitor cleanup/control history and the full single-server and multi-server render paths, compare against older/original OGP behavior, identify the exact root cause, restore correct single-server compact Start/Stop/Restart controls without restoring the bulk selector, keep multi-server bulk radio plus Execute behavior unchanged, keep removed default image and legal/display information removed, validate, then commit and push if verification passes.

## Problem

The current single-server Game Monitor suppresses the multi-server bulk radio selector, but its compact direct controls were generated only after the main monitor row was already assembled. That meant the row-level status/control column could not render those controls.

## Root Cause

The regression came from the monitor cleanup that removed the original detail/control container that hosted direct single-server controls. A later restore rebuilt compact single-server controls, but generated them after `$first` was built and placed them in the expanded child row. The bulk selector condition was already corrected for single-server views; the remaining issue was render order and placement, not CSS/JS hiding, route handling, or incorrect downstream lifecycle handlers.

## Repositories Inspected

- GSP Panel
- references/OGP

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/development/CODEX_GUIDE.md`
- `/home/dev/Projects/GSP/Panel/docs/features/STATUS_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/GAMEMANAGER.md`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/ref_servermonitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/monitor_buttons.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/start_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/restart_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/module.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/js/modules/gamemanager.js`
- `/home/dev/Projects/GSP/Panel/themes/Evolution/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/AdminLTE-2/modules/gamemanager/game_monitor.js`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/ref_servermonitor.php`

## Files Changed

- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-12-1852-single-monitor-controls.md`

## Architecture Decisions

- Keep Panel-side monitor rendering as the owner of lifecycle controls.
- Reuse the existing compact single-server control markup and existing start/stop/restart action routes.
- Keep single-server detection limited to `home_id` and `home_id-mod_id-ip-port`.
- Keep multi-server list views on the existing radio-selection plus Execute workflow.
- Do not restore the removed default image, query/detail panel, port panel, legal text, or display-info block.

## Validation Performed

- `php -l modules/gamemanager/server_monitor.php`
- `php -l modules/gamemanager/ref_servermonitor.php`
- `php -l modules/gamemanager/monitor_buttons.php`
- `php -l modules/gamemanager/home_handling_functions.php`
- `php -l modules/gamemanager/start_server.php`
- `php -l modules/gamemanager/stop_server.php`
- `php -l modules/gamemanager/restart_server.php`
- `php modules/config_games/tests/validate_server_configs.php`
- `git diff --check`
- Static source review confirmed single-server detection remains limited to `home_id` and `home_id-mod_id-ip-port`.
- Static source review confirmed bulk radio inputs are still gated by `!$single_server_monitor`.
- Static source review confirmed the Execute footer remains gated by `$isAdmin and !$single_server_monitor`.
- Static source review confirmed compact single-server controls now render into the main row control column.
- Static source review confirmed stop/restart still route to existing `p=stop` and `p=restart` handlers, and start still uses the existing `action-{home_id}` POST payload handled by `exec_operation()`.
- Static source review confirmed the removed default image, port panel, legal/display-info block, and refreshed query/detail panel were not restored.

## Git Commits

Repository

GSP Panel

Commit

Pending at record creation.

Hash

Pending at record creation.

## Remaining Work

Live browser validation against a running configured Panel and agent remains outside this workspace unless a running environment is provided.
