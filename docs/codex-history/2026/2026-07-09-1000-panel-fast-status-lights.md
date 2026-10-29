# Task

## User Request

Revert/remove live LGSL/GameQ/TeamSpeak protocol query display from the main Game Monitor page because it can hang page rendering. Keep Game Monitor responsive and simple with green Online, yellow Starting/Stopping, and red Stopped status lights/words. Remove the default computer/server image from the status area. Keep static port display only if non-blocking. Do not modify XML files or agent repositories. Commit and push Panel changes after validation.

## Problem

Game Monitor still had render paths that loaded live protocol monitor code for expanded server details. That could block the monitor experience when LGSL, GameQ, or TeamSpeak queries stalled.

## Root Cause

`server_monitor.php` scheduled `ref_servermonitor.php` for active rows, and `ref_servermonitor.php` included LGSL/GameQ/TeamSpeak monitor files that can execute live network queries. Stopped rows also embedded the heavier mini-start content instead of a simple non-query status summary.

## Repositories Inspected

- GSP Panel
- GSP-Agent-Linux documentation only
- GSP-Agent-Windows documentation only

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `README.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/COMMAND_FLOW.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/modules/GAMEMANAGER.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `Panel/modules/gamemanager/server_monitor.php`
- `Panel/modules/gamemanager/home_handling_functions.php`
- `Panel/modules/gamemanager/monitor_buttons.php`
- `Panel/modules/gamemanager/ref_servermonitor.php`
- `Panel/modules/gamemanager/mini_start.php`
- `Panel/includes/refreshed.php`
- `Panel/js/modules/gamemanager.js`

## Files Changed

- `Panel/modules/gamemanager/home_handling_functions.php`
- `Panel/modules/gamemanager/server_monitor.php`
- `Panel/modules/gamemanager/ref_servermonitor.php`
- `Panel/includes/refreshed.php`
- `docs/codex-history/2026/2026-07-09-1000-panel-fast-status-lights.md`

## Architecture Decisions

- Keep agent lifecycle status as the source for Game Monitor status display.
- Do not run LGSL/GameQ/TeamSpeak live query monitors from the Game Monitor render or refresh path.
- Preserve static port display because it is derived from XML/config values and local port-conversion logic, not live network queries.
- Keep LGSL/DSI/TeamSpeak modules and simple external links intact.
- Map monitor status to only `Online`, `Starting`, `Stopping`, or `Stopped` with green/yellow/red indicators.

## Validation Performed

- `php -l Panel/modules/gamemanager/home_handling_functions.php`
- `php -l Panel/modules/gamemanager/server_monitor.php`
- `php -l Panel/modules/gamemanager/monitor_buttons.php`
- `php -l Panel/modules/gamemanager/ref_servermonitor.php`
- `php -l Panel/includes/refreshed.php`
- `git diff --check`
- Static scan confirmed no Game Monitor render/refresh path invokes LGSL/GameQ/TeamSpeak live query monitor functions.
- Static scan confirmed lifecycle status words and green/yellow/red light classes are used.
- Static scan confirmed default computer/server status images are not rendered by the Game Monitor status area.
- `git status --short`

## Git Commits

Repository

GSP Panel

Commit

Pending commit after validation.

Hash

Pending commit after validation.

## Remaining Work

None.
