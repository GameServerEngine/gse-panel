# Task

## User Request

Restore the original/correct single-server monitor compact control behavior only after verifying the current Panel repository, recent commits, upstream/downstream monitor control flow, and OGP/reference behavior. Single-server monitor must show compact Start/Stop/Restart controls near status without the multi-server bulk radio selector. Multi-server monitor must retain radio selection plus Execute. Removed default image and legal/display information must remain removed. Commit and push if validation passes.

## Problem

The single-server Game Monitor view still rendered the multi-server radio action selector and Execute workflow. The direct compact control restore was incomplete and only added expanded stop/restart controls for active servers.

## Root Cause

The original OGP/GSP monitor had two separate control paths: bulk radio controls in the table row, and direct single-server controls in the expanded status/detail area. The cleanup around `cced341c` removed the refreshed detail/control block while retaining bulk controls. The later `f68c10afa` restore added direct controls back for active servers but did not suppress bulk radio controls on single-server pages and did not provide a compact stopped-server Start control.

## Repositories Inspected

- GSP Panel
- GSP-Agent-Linux
- GSP-Agent-Windows
- references/OGP

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP-WORKSPACE.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/features/STATUS_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/GAMEMANAGER.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/ref_servermonitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/monitor_buttons.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/start_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/restart_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/mini_start.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/js/modules/gamemanager.js`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website/modules/gamemanager/ref_servermonitor.php`

## Files Changed

- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-12-1258-monitor-single-controls.md`

## Architecture Decisions

- Keep the Panel as the owner of UI action rendering.
- Keep existing start/stop/restart handlers and action payload shapes.
- Treat `home_id` and `home_id-mod_id-ip-port` monitor views as single-server views.
- Leave game-type filtered and all-server monitor views on the existing radio-selection plus Execute workflow.
- Do not restore removed map/default image, live query details, ports panel, or legal/display-info block.

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
- Static source review confirmed single-server detection is limited to `home_id` and `home_id-mod_id-ip-port`.
- Static source review confirmed bulk radio controls and the Execute footer are gated off for single-server monitor views and remain present for multi-server/list views.
- Static source review confirmed single-server controls use the existing stop/restart routes and the existing POST action payload shape for start.
- Static source review confirmed no default image, ports panel, Game Documentation/legal/display-info block, or refreshed live-query details were restored into the Game Monitor render path.

## Git Commits

Repository

GSP Panel

Commit

Restore single-server monitor controls

Hash

Created after this history record was written; see final report for the commit hash.

## Remaining Work

Live browser validation against a configured Panel and agent was not performed in this development workspace.
