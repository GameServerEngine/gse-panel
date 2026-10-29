# Task

## User Request

Restore the original OGP single-server and multi-server Game Monitor structure in the nested Panel repository only, preserving current GSE billing, lifecycle, permissions, action handlers, monitor button customizations, and current GSP naming/API conventions. Validate after both implementation phases, then commit and push if validation passes.

## Problem

The single-server Game Monitor path had been partially restored through local replacement markup in `server_monitor.php` instead of using the original refreshed monitor detail implementation in `ref_servermonitor.php`.

## Root Cause

Recent commits `7c4a7521`, `709c6931`, and `e818303b` added `singleServerControls`, `hide_controls`, and single-server-only detail/control relocation. That suppressed the original `ref_servermonitor.php` `monitor-1`, `monitor-2`, and `monitor-3` output and replaced it with a hybrid server-monitor layout.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`

## Files Read

- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/COMMAND_FLOW.md`
- `docs/modules/GAMEMANAGER.md`
- `docs/features/STATUS_SYSTEM.md`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/ref_servermonitor.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/monitor_buttons.php`
- `modules/gamemanager/mini_start.php`
- `modules/ftp/monitor_buttons.php`
- Theme-specific `modules/gamemanager/game_monitor.css` and `game_monitor.js` files
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/ref_servermonitor.php`

## Files Changed

- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/ref_servermonitor.php`
- `docs/codex-history/2026/2026-07-13-1157-restore-monitor-structure.md`

## Architecture Decisions

- Kept `exec_gsp_module()` as the module entry point.
- Kept current GSP remote naming: `GSPRemoteLibrary` and `GSP_SCREEN_TYPE_*`.
- Kept current agent status/lifecycle logic through `get_agent_server_status()` and `is_agent_status_active()`.
- Kept billing display through `get_server_billing_expiration_html((int)$server_home['home_id'])`.
- Restored `ref_servermonitor.php` as the owner of the original OGP `monitor-1`, `monitor-2`, and `monitor-3` detail/control output.
- Kept bulk radio controls and the bottom Execute workflow guarded out of single-server views.
- Did not change FTP or other module `monitor_buttons.php` files.
- Did not change theme files because no verified CSS/JS rule hides `monitor-1`, `monitor-2`, `monitor-3`, or `.srvctrl`; the `display:none` rules hide replacement images and line breaks only.

## Validation Performed

- `php -l modules/gamemanager/server_monitor.php`
- `php -l modules/gamemanager/ref_servermonitor.php`
- `php -l modules/gamemanager/home_handling_functions.php`
- `php -l modules/gamemanager/monitor_buttons.php`
- `php -l modules/ftp/monitor_buttons.php`
- `git diff --check`
- Source inspection confirmed `hide_controls`, `singleServerControls`, and single-server detail relocation markup were removed.
- Source inspection confirmed single-server views exclude bulk radio/Execute controls.
- Source inspection confirmed multi-server views retain bulk radio controls and Execute.
- Source inspection confirmed `ref_servermonitor.php` emits `monitor-1`, `monitor-2`, and `monitor-3`.
- Source inspection confirmed Start/Stop/Restart still target existing gamemanager handlers.
- Theme CSS/JS scan confirmed monitor detail containers are not hidden.

## Git Commits

Repository: Panel

Commit: pending

Hash: pending

## Remaining Work

- Live Panel/Agent/database/browser rendering was not validated in this environment.
