# Codex Guide

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

This file is the first stop for future Codex sessions working in this repository.

## Repository Layout

```text
  /
  index.php
  home.php
  includes/
  modules/
  protocol/
  themes/
  docs/
```

## What To Read First

1. `docs/architecture/REPOSITORY_OVERVIEW.md`
2. `docs/architecture/PANEL_AGENT_FLOW.md`
3. `docs/architecture/API_REFERENCE.md`
4. `docs/architecture/PANEL_AGENT_COMMANDS.md`
5. `docs/architecture/MODULE_DEPENDENCIES.md`
6. `docs/architecture/LIBRARY_REFERENCE.md`
7. `docs/modules/MODULE_INDEX.md`
8. `docs/modules/GAMEMANAGER.md`
9. `docs/features/STATUS_SYSTEM.md`
10. `docs/features/XML_SYSTEM.md`
11. `docs/modules/SCHEDULER.md`
12. `docs/features/SCHEDULER_ACTIONS.md`
13. `docs/modules/SERVER_CONTENT_MANAGER.md`
14. `docs/features/USER_API.md`
15. `docs/decisions/`
16. `docs/games/`

## Important Files By Topic

### Startup Logic

- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/config_games/schema_server_config.xml`
- `modules/config_games/server_config_parser.php`
- `includes/lib_remote.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

### Status Logic

- `includes/lib_remote.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/server_monitor.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

### Scheduler Logic

- `modules/cron/module.php`
- `modules/cron/cron.php`
- `modules/cron/shared_cron_functions.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

### Workshop / Server Content Logic

- `modules/addonsmanager/module.php`
- `modules/addonsmanager/addons_manager.php`
- `modules/addonsmanager/user_addons.php`
- `modules/steam_workshop/module.php`
- `modules/steam_workshop/main.php`
- `modules/steam_workshop/monitor_buttons.php`

### XML Definitions

- `modules/config_games/schema_server_config.xml`
- `modules/config_games/xml_config_creator.php`
- `modules/config_games/set_params.php`
- `modules/config_games/cli-params.php`
- `modules/config_games/config_servers.php`

### Agent Communication

- `includes/lib_remote.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

### Decisions And Historical Reports

- `docs/architecture/AI_GSP_ARCHITECTURE.md`
- `docs/decisions/0001-screen-vs-tmux.md`
- `docs/decisions/0002-status-detection.md`
- `docs/decisions/0003-companion-programs.md`
- `docs/decisions/0004-workshop-system.md`
- `docs/decisions/0005-control-path-layout.md`
- `docs/decisions/0006-installers.md`
- `docs/decisions/COMPANION_PROGRAMS_DESIGN.md`
- `docs/decisions/SCHEDULER_ACTIONS_DESIGN.md`
- `docs/decisions/STEAM_WORKSHOP_DESIGN.md`
- `docs/development/GSP_PLATFORM_IMPROVEMENT_REPORT.md`

## Common Development Workflows

### Debug a start/stop/restart issue

1. Read `docs/modules/GAMEMANAGER.md`.
2. Check `includes/lib_remote.php`.
3. Check `modules/gamemanager/home_handling_functions.php`.
4. Check the matching `gsp_agent.pl`.
5. Compare session/process/port logic in both agents.

### Debug a status issue

1. Read `docs/features/STATUS_SYSTEM.md`.
2. Check `remote_server_status` in `includes/lib_remote.php`.
3. Check `server_status_without_decrypt` in both agents.
4. Check game XML query definitions in `config_games`.

### Debug scheduler behavior

1. Read `docs/modules/SCHEDULER.md`.
2. Check `modules/cron/cron.php`.
3. Check scheduler subroutines in both agents.
4. Verify whether the action is customer-safe or admin-only.

### Debug Workshop or add-on behavior

1. Read `docs/modules/SERVER_CONTENT_MANAGER.md`.
2. Check `modules/addonsmanager/module.php`.
3. Check the user/admin content pages.
4. Check whether the action should be treated as install, update, or uninstall.

### Debug Panel or Agent updates

1. Read `docs/modules/UPDATE.md`.
2. Check `modules/update/update.php`.
3. Check `modules/administration/panel_update.php`.
4. Check `includes/lib_remote.php` only if a shared helper is actually needed.
5. Remember that the active Panel update page updates the Panel only.
6. Website and agent updates are separate workflows and should not be reintroduced into the Panel update page.
7. Never let updater logic delete server homes, game install folders, user data, agent `Cfg/`, logs, uploads, backups, or runtime PID files.

## Things Already Investigated

The repository has already been mapped in these areas:

- module inventory
- panel-agent remote library
- Linux and Windows agent `screen` use
- status model direction
- game XML startup and query variables
- current Server Content Manager structure
- current scheduler structure
- module-level roles and dependency patterns
- preserved investigation reports have been moved into the docs tree

## Things Intentionally Not Yet Implemented

This documentation-only pass does not implement:

- lifecycle code changes
- status model code changes
- scheduler redesign
- Workshop/content redesign
- backup system replacement
- file manager or FTP rewrites
- billing/provisioning changes

## Practical Rule for Future Sessions

Before scanning code broadly, read the docs layer first. Only open source files when the documentation does not already answer the question.
