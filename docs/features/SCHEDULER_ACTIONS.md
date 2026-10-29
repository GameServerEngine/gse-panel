# Scheduler Actions

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This file is the command reference for the current scheduler system.

Primary files:

- `modules/cron/cron.php`
- `modules/cron/user_cron.php`
- `modules/cron/shared_cron_functions.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

## Current Model

The Panel scheduler builds a cron expression and a command string.

In the common case, that command string is a `wget` call back into:

- `gsp_api.php`

The agent stores the cron entry and executes it locally.

## Action Catalog

| Action Key | Built By | Effective Runtime Target | Modules Affected | Agent Calls Eventually Performed |
|---|---|---|---|---|
| `start` | `cron/shared_cron_functions.php` | `gsp_api.php?gamemanager/start` | `cron`, `gamemanager` | `universal_start` |
| `stop` | same | `gsp_api.php?gamemanager/stop` | `cron`, `gamemanager` | `stop_server` |
| `restart` | same | `gsp_api.php?gamemanager/restart` | `cron`, `gamemanager` | `restart_server` |
| `steam_auto_update` | same | `gsp_api.php?gamemanager/update&type=steam` | `cron`, `gamemanager` | `steam_cmd` / auto-update path |
| `server_content_check_updates` | same | `gsp_api.php?server_content/run_scheduled_action` | `cron`, `addonsmanager` | server-content manifest flow, remote `exec` / helper scripts |
| `server_content_check_workshop_updates` | same | same | `cron`, `addonsmanager` | Workshop/content check flow |
| `server_content_install_updates_if_stopped` | same | same | `cron`, `addonsmanager`, `gamemanager` | conditional install |
| `server_content_install_updates_next_restart` | same | same | `cron`, `addonsmanager`, `gamemanager` | deferred install marker |
| `server_content_install_updates_now` | same | same | `cron`, `addonsmanager` | immediate content install |
| `server_content_install_updates_and_restart` | same | same | `cron`, `addonsmanager`, `gamemanager` | content update plus restart |
| `server_content_notify_updates_only` | same | same | `cron`, `addonsmanager` | check-and-notify |
| `server_content_update_all` | same | same | `cron`, `addonsmanager` | aggregate update flow |
| `server_content_validate_files` | same | same | `cron`, `addonsmanager` | validation flow |
| `server_content_backup_before_update` | same | same | `cron`, `addonsmanager`, backup-related helpers | backup hook before content update |
| `workshop_update` | same | same | `cron`, `addonsmanager` | Server Content Manager Workshop update flow |
| `workshop_update_and_restart` | same | same | `cron`, `addonsmanager`, `gamemanager` | Workshop update plus safe restart request |
| `workshop_download_only` | same | same | `cron`, `addonsmanager` | SteamCMD download/cache without copying into live mod folders |
| `workshop_install_pending_on_restart` | same | same | `cron`, `addonsmanager` | Install Workshop items marked `pending_action=install_on_restart` |

## Agent Scheduler RPCs

| RPC | Purpose |
|---|---|
| `scheduler_add_task` | add cron line |
| `scheduler_edit_task` | update cron line |
| `scheduler_del_task` | delete cron line |
| `scheduler_list_tasks` | list cron lines |

## Internal Agent Scheduler Subroutines

| Subroutine | Purpose |
|---|---|
| `scheduler_dispatcher` | top-level cron callback |
| `scheduler_server_action` | execute parsed action |
| `scheduler_log_events` | append `scheduler.log` |
| `scheduler_read_tasks` | reload current cron entries |
| `scheduler_stop` | stop and rebuild cron object |

## Runtime Flow

```text
User/admin saves scheduler job in Panel
  -> Panel builds cron line
  -> Panel sends cron line to agent with scheduler_add_task/edit_task
  -> Agent stores job
  -> Agent executes cron job later
  -> cron job usually calls gsp_api.php
  -> gsp_api.php dispatches to gamemanager or server_content action
  -> those paths may call the agent again for actual server/content work
```

This means the current scheduler is two-hop:

1. agent cron executes a Panel API URL
2. the Panel API route often calls back to the same or another agent

## Logging

Current observable logs:

- agent-side `scheduler.log`
- panel-side UI through `modules/cron/events.php`
- module-specific logs from `gamemanager` or `addonsmanager`

## Limitations

| Limitation | Effect |
|---|---|
| string-based cron commands | weaker typing and validation |
| action permissions are implicit | customer-safe vs admin-only is not strongly modeled |
| result storage is agent-log-centric | poor user-facing job history |
| jobs depend on Panel URL/token validity | token rotations require cron rewrite |
| many actions are API callbacks, not local structured tasks | more moving parts and harder debugging |

## Search Coverage Used For This Document

- `sed -n '1,260p' modules/cron/shared_cron_functions.php`
- `rg -n "scheduler_" Agent_Linux/gsp_agent.pl Agent-Windows/GSP/gsp_agent.pl`
- `rg -n "gamemanager/(start|stop|restart)|server_content/run_scheduled_action" modules/cron`
