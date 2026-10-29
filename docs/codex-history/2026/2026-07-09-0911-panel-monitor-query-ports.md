# Task

## User Request

In `/home/dev/Projects/GSP/Panel`, make a focused Panel-only change for Game Monitor query/ports display and the pending slow-start flow fix. Do not modify agent repositories and do not mass-edit XML files.

## Problem

Game Monitor did not expose useful derived port metadata from existing XML tags, DSI was offered for games without query support, unsupported query profiles could render an empty live details block, and the inline start flow could show a red `Failed to start the remote server. Error code: -1` for slow-starting servers instead of returning to Game Monitor with a transient STARTING state.

## Root Cause

Port/query metadata was scattered across startup construction, LGSL/GameQ monitor includes, and module buttons instead of being derived through shared Panel helpers. The monitor refresh helper only polled expanded rows marked online, and the start path treated nonpositive start return values as hard failures before considering transient agent status or the known slow-start `-1` case.

## Repositories Inspected

- `Panel`

## Files Read

- `AGENTS.md`
- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/COMMAND_FLOW.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/features/XML_SYSTEM.md`
- `docs/modules/GAMEMANAGER.md`
- `docs/modules/dsi.md`
- `docs/modules/lgsl_with_img_mod.md`
- `docs/modules/config_games.md`
- `Panel/modules/gamemanager/server_monitor.php`
- `Panel/modules/gamemanager/start_server.php`
- `Panel/modules/gamemanager/home_handling_functions.php`
- `Panel/modules/gamemanager/monitor_buttons.php`
- `Panel/modules/gamemanager/ref_servermonitor.php`
- `Panel/modules/gamemanager/mini_start.php`
- `Panel/modules/dsi/monitor_buttons.php`
- `Panel/modules/dsi/dsi_user.php`
- `Panel/modules/dsi/includes/functions_ui.php`
- `Panel/modules/lgsl_with_img_mod/lgsl.php`
- `Panel/protocol/lgsl/LGSLMonitor.php`
- `Panel/protocol/lgsl/lgsl_protocol.php`
- `Panel/protocol/GameQ/GameQMonitor.php`
- `Panel/protocol/TeamSpeak3/TS3Monitor.php`
- `Panel/modules/config_games/server_config_parser.php`
- `Panel/modules/config_games/schema_server_config.xml`
- `Panel/includes/refreshed.php`

## Files Changed

- `Panel/includes/refreshed.php`
- `Panel/modules/dsi/monitor_buttons.php`
- `Panel/modules/gamemanager/home_handling_functions.php`
- `Panel/modules/gamemanager/mini_start.php`
- `Panel/modules/gamemanager/monitor_buttons.php`
- `Panel/modules/gamemanager/ref_servermonitor.php`
- `Panel/modules/gamemanager/server_monitor.php`
- `Panel/modules/gamemanager/start_server.php`
- `docs/codex-history/2026/2026-07-09-0911-panel-monitor-query-ports.md`

## Architecture Decisions

- Keep lifecycle status agent-driven; LGSL/GameQ/TeamSpeak query data remains optional metadata.
- Derive Game Monitor port rows from existing XML metadata instead of adding fields or mass-editing game XML files.
- Gate DSI and LGSL buttons on existing query support metadata.
- Treat `RUNNING`, `ONLINE`, `STARTING`, and the known slow-start `-1` start return as transient or active start outcomes, then return users to Game Monitor for status polling.
- Poll expanded STARTING rows at about 60 seconds instead of only polling ONLINE rows.

## Validation Performed

- `php -l Panel/modules/gamemanager/home_handling_functions.php`
- `php -l Panel/modules/gamemanager/server_monitor.php`
- `php -l Panel/modules/gamemanager/start_server.php`
- `php -l Panel/modules/gamemanager/monitor_buttons.php`
- `php -l Panel/modules/gamemanager/ref_servermonitor.php`
- `php -l Panel/modules/gamemanager/mini_start.php`
- `php -l Panel/modules/dsi/monitor_buttons.php`
- `php -l Panel/includes/refreshed.php`
- `git diff --check`
- Static check: no literal `source:` text in touched Game Monitor output files.
- Static check: `failed_start_server_code` remains only behind the transient start-success guard.
- Static check: DSI and LGSL monitor buttons are gated by query support helpers.

## Git Commits

Repository

Panel

Commit

Pending

Hash

Pending

## Remaining Work

- No agent changes were made in this workflow.
- Query detail links still use the legacy LGSL module landing page because the legacy LGSL detail page is keyed by its own LGSL server table entry, not directly by a Game Monitor home ID.
