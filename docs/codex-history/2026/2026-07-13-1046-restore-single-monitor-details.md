# Task

## User Request

Work only in `/home/dev/Projects/GSP/Panel`. Verify the nested Panel Git repository before editing, inspect status/diff/history/remotes, trace both single-server and all-servers Game Monitor render paths, compare against OGP reference files and recent monitor cleanup/control commits, determine the root cause, restore the original intended single-server OGP-style LGSL/game detail display and compact Start/Stop/Restart controls without showing all-server bulk controls, keep all-server bulk workflow unchanged, validate, commit, and push if verification passes.

## Problem

The single-server Game Monitor no longer showed the original refreshed LGSL/GameQ detail block with game/map/hostname/player information. Recent fixes restored direct compact controls and suppressed bulk radio controls on single-server pages, but the detail cell that hosts the refreshed OGP monitor output was still empty.

## Root Cause

Commit `b82867ba` removed the expanded row refreshed detail container from `server_monitor.php` and documented the old expanded query panel as intentionally not rendered. That removed the render target for `ref_servermonitor.php`, even though the refreshed endpoint still contains the OGP-style LGSL/GameQ map, hostname, player list, and notification markup. Later commits `7c4a7521` and `709c6931` restored single-server compact controls and bulk-control separation, but did not restore the refreshed detail container.

The single-server page was not currently rendering the all-server bulk radio inputs or Execute footer. Theme CSS/JS contains styling for the monitor detail and control classes, but the compact controls were not hidden by CSS; the missing LGSL/game detail was caused by the server-side render path.

## Repositories Inspected

- GSP Panel
- `/home/dev/Projects/GSP/references/OGP` read-only reference

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
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-12-1258-monitor-single-controls.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-12-1852-single-monitor-controls.md`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/ref_servermonitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/monitor_buttons.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/mini_start.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/js/modules/gamemanager.js`
- `/home/dev/Projects/GSP/Panel/includes/refreshed.php`
- `/home/dev/Projects/GSP/Panel/protocol/lgsl/LGSLMonitor.php`
- `/home/dev/Projects/GSP/Panel/protocol/GameQ/GameQMonitor.php`
- `/home/dev/Projects/GSP/Panel/themes/AdminLTE/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/AdminLTE/modules/gamemanager/game_monitor.js`
- `/home/dev/Projects/GSP/Panel/themes/AdminLTE-2/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/AdminLTE-2/modules/gamemanager/game_monitor.js`
- `/home/dev/Projects/GSP/Panel/themes/SimpleBootstrap/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/MasterControlProgram/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/MasterControlProgram/modules/gamemanager/game_monitor.js`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/ref_servermonitor.php`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/monitor_buttons.php`

## Files Changed

- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/ref_servermonitor.php`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-13-1046-restore-single-monitor-details.md`

## Architecture Decisions

- Keep Panel-side monitor rendering as the owner of UI action layout.
- Restore the refreshed OGP detail container only for single-server monitor views.
- Keep all-server and game-type list views on the existing bulk radio plus Execute workflow.
- Keep compact single-server controls single-sourced in `server_monitor.php`.
- Add a narrow `hide_controls=1` refreshed endpoint flag so `ref_servermonitor.php` can provide LGSL/GameQ detail markup without duplicating lifecycle controls on the single-server page.
- Preserve existing start, stop, restart, POST action, permissions, AJAX refresh, and localization paths.
- Do not restore removed documentation/legal/display-info content or unrelated public wording.

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
- Static source review confirmed `home_id` and `home_id-mod_id-ip-port` still define the single-server monitor path.
- Static source review confirmed bulk radio controls are still emitted only when `!$single_server_monitor`.
- Static source review confirmed the bottom Execute button is still emitted only when `$isAdmin and !$single_server_monitor`.
- Static source review confirmed the single-server expanded row now emits the refreshed detail container and compact direct controls.
- Static source review confirmed the refreshed endpoint emits LGSL/GameQ `monitor-1`, `map-icon`, `server-info`, hostname, current map, player list, and notification markup.
- Static source review confirmed the refreshed endpoint suppresses its legacy `srvctrl` table only when called with `hide_controls=1`, avoiding duplicate single-server lifecycle controls.
- Static source review confirmed the compact single-server controls still route to existing `p=restart`, `p=stop`, and `action-{home_id}` POST handling through `exec_operation()`.
- Static theme review confirmed relevant theme CSS/JS styles the monitor detail and control classes, and did not identify a rule hiding `.monitor-1`, `.monitor-3`, or `.gsp-monitor-compact-controls`.

## Git Commits

Repository

GSP Panel

Commit

Pending at record creation.

Hash

Pending at record creation; see final report.

## Remaining Work

Live browser validation against a configured Panel, database, and agent remains outside this workspace unless a running environment is provided.
