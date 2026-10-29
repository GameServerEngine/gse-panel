# API Reference

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This is the master reference for:

- Panel -> agent XML-RPC
- external Panel API routes
- public status API
- Website payment/webhook endpoints

Detailed command catalog:

- `docs/architecture/PANEL_AGENT_COMMANDS.md`

Scheduler action catalog:

- `docs/features/SCHEDULER_ACTIONS.md`

## Architecture Overview

```text
home.php module request
  -> module PHP page
  -> shared include libraries
  -> database layer
  -> optional agent RPC through lib_remote.php

external automation
  -> gsp_api.php
  -> api_* handler
  -> same module/library logic
  -> optional agent RPC

public node status
  -> status_api.php
  -> direct remote host probe + optional agent stats

website checkout/webhook
  -> Website/api/* or Website/webhook.php
  -> billing runtime
  -> DB and payment gateway APIs
```

## Panel -> Agent XML-RPC

Transport:

- XML-RPC over HTTP
- endpoint path `/RPC2`
- wrapper `includes/lib_remote.php`
- server implementations in `Agent_Linux/gsp_agent.pl` and `Agent-Windows/GSP/gsp_agent.pl`

Primary categories:

| Category | Examples |
|---|---|
| lifecycle | `server_status`, `universal_start`, `stop_server`, `restart_server` |
| files | `readfile`, `writefile`, `dirlist`, `get_file_part` |
| updates | `steam_cmd`, `component_update`, `stop_update` |
| system | `exec`, `sudo_exec`, `rebootnow`, `what_os`, `discover_ips`, `mon_stats` |
| scheduler | `scheduler_add_task`, `scheduler_edit_task`, `scheduler_del_task`, `scheduler_list_tasks` |
| content | `writefile` + `exec` manifest/script orchestration; legacy `steam_workshop`, `get_workshop_mods_info` |

See the full command table in:

- `docs/architecture/PANEL_AGENT_COMMANDS.md`

## Panel Wrapper Methods

Most Panel modules do not build XML-RPC directly. They call `GSPRemoteLibrary`.

| Wrapper Method | Agent Command | Common Modules |
|---|---|---|
| `status_chk()` | `quick_chk` | `cron`, `server`, `status`, `dashboard`, update pages |
| `remote_server_status()` | `server_status` | `gamemanager` |
| `universal_start()` | `universal_start` | `gamemanager`, API start |
| `remote_stop_server()` | `stop_server` | `gamemanager`, billing, API stop |
| `remote_restart_server()` | `restart_server` | `gamemanager`, API restart |
| `remote_send_rcon_command()` | `send_rcon_command` | `gamemanager`, `rcon`, util helpers |
| `remote_readfile()` / `remote_writefile()` | `readfile` / `writefile` | `litefm`, `editconfigfiles`, `addonsmanager`, `mysql`, `server` |
| `remote_query()` | `remote_query` | `gamemanager`, dashboards |
| `component_update()` | `component_update` | update/admin pages |
| `scheduler_*()` | `scheduler_*` | `cron` |
| `steam_workshop()` | `steam_workshop` | legacy workshop path only |

## External Panel API

Primary endpoint:

- `gsp_api.php`

Auth model:

- token-based
- token issued by `token/create`
- requests can be GET, POST, or JSON body
- host allowlist can be enforced

Top-level API handlers implemented in `gsp_api.php`:

| Handler Function | Route Prefix | Purpose |
|---|---|---|
| `api_token()` | `token/*` | create/test tokens |
| `api_server()` | `server/*` | remote agent CRUD/status |
| `api_user_games()` | `user_games/*` | home provisioning and listing |
| `api_user_admin()` | `user_admin/*` | user CRUD and assignments |
| `api_gamemanager_admin()` | `gamemanager_admin/*` | admin game manager actions |
| `api_gamemanager()` | `gamemanager/*` | start/stop/restart/update/RCON |
| `api_litefm()` | `litefm/*` | file listing/read/write/delete |
| `api_addonsmanager()` | `addonsmanager/*` | content template listing/install |
| `api_server_content()` | `server_content/*` | scheduled content actions |
| `api_steam_workshop()` | `steam_workshop/*` | legacy workshop installs |
| `api_setting()` | `setting/*` | setting read helper |

Detailed route list:

| Route | Purpose |
|---|---|
| `token/create`, `token/test` | auth bootstrap |
| `server/list`, `server/status`, `server/restart`, `server/create`, `server/remove`, `server/add_ip`, `server/remove_ip`, `server/list_ips`, `server/edit_ip` | remote host management |
| `user_games/list_games`, `user_games/list_servers`, `user_games/create`, `user_games/clone`, `user_games/set_expiration` | server-home management |
| `user_admin/list`, `user_admin/get`, `user_admin/create`, `user_admin/remove`, `user_admin/set_expiration`, `user_admin/list_assigned`, `user_admin/assign`, `user_admin/remove_assign` | user management |
| `gamemanager/start`, `gamemanager/stop`, `gamemanager/restart`, `gamemanager/rcon`, `gamemanager/update` | lifecycle automation |
| `gamemanager_admin/reorder` | admin ordering helper |
| `litefm/list`, `litefm/get`, `litefm/save`, `litefm/remove` | remote file operations |
| `addonsmanager/list`, `addonsmanager/install` | add-on templates |
| `steam_workshop/install` | legacy Workshop install |
| `server_content/run_scheduled_action` | typed content action trigger |
| `setting/get` | panel setting read |

See:

- `docs/features/USER_API.md`

## Public Status API

Endpoint:

- `status_api.php`

Purpose:

- public or semi-public read-only summary of remote nodes

Auth:

- shared query token

Behavior:

- probes remote agents with TCP
- optionally calls agent stats method names if available
- caches JSON locally for 30 seconds

Return shape:

- `generated_at`
- `nodes[]`
  - `name`
  - `host`
  - `agent_port`
  - `online`
  - `cpu_percent`
  - `mem_percent`
  - `disk_percent`

## Website API And Webhooks

| Endpoint | Purpose | Auth / Verification |
|---|---|---|
| `Website/api/create_order.php` | create PayPal order | storefront checkout/session context |
| `Website/api/capture_order.php` | capture PayPal order | storefront checkout/session context |
| `Website/api/log_error.php` | client-side error logging | open endpoint, writes log |
| `Website/webhook.php` | verified PayPal webhook processing | OAuth + webhook signature verification |
| `Website/paypal/webhook.php` | compatibility wrapper to active webhook runtime | same |

## Internal Module Endpoints

These are not public APIs in the same sense as `gsp_api.php`, but they matter architecturally.

| Endpoint | Module | Purpose |
|---|---|---|
| `modules/gamemanager/get_server_log.php` | `gamemanager` | AJAX log refresh |
| `modules/dashboard/updateWidgets.php` | `dashboard` | dashboard async refresh |
| `modules/tickets/notificationCount.php` | `tickets` | unread count |
| `modules/cron/events.php` | `cron` | scheduler log refresh |
| `modules/litefm/get_file.php` | `litefm` | chunked file downloads |

## Workshop Manifest Contract

Current preferred Workshop/content flow:

1. Panel validates Workshop IDs
2. Panel writes manifest JSON to server home
3. Panel writes a generated per-job shell script to server home via `writefile`
4. The job script writes a temporary SteamCMD runscript
5. Panel runs the generated job script with `exec`
6. Agent logs/install output is returned to Panel

Common manifest fields:

- `manifest_version`
- `home_id`
- `home_cfg_id`
- `content_type`
- `action`
- `items`
- `options`

For Workshop-specific manifests, the broader fields documented in `WORKSHOP_SYSTEM.md` also apply, including app IDs, install strategy, update policy, pending action, and target paths.

Current Workshop actions:

- `install`
- `update`
- `check_updates`
- `download_only`
- `validate_files`
- `remove`

Current Scheduler/API action aliases:

- `workshop_update`
- `workshop_update_and_restart`
- `workshop_download_only`
- `workshop_install_pending_on_restart`

The legacy `steam_workshop/install` API route and agent `steam_workshop` RPC remain compatibility-only. New user-facing Workshop work should use `addonsmanager` / Server Content Manager.

## Status Contract

`server_status` is the preferred runtime contract.

Expected fields:

- `status`
- `ready`
- `process_running`
- `session_running`
- `game_port_listening`
- `query_port_listening`
- `rcon_port_listening`
- `pid`
- `session_name`
- `ip`
- `port`
- `query_port`
- `rcon_port`
- `last_error`
- `query_info`

State meanings:

- `ONLINE`
- `STARTING`
- `STOPPING`
- `OFFLINE`
- `UNRESPONSIVE`
- `UNKNOWN`

Rules:

- query failure alone must not imply offline
- agent process/session or game-port evidence is sufficient for online
- `UNKNOWN` is reserved for unreachable or inconclusive agent state

Panel fallback behavior:

- `modules/gamemanager/home_handling_functions.php` calls `remote_server_status()` first.
- If that structured RPC is unavailable or ambiguous, the Panel calls `is_screen_running()`.
- The Panel then calls generic agent `exec()` with an `ss`/`netstat` port probe for the configured game port.
- The fallback port grep must match normal listener output such as `:2302` or `.2302`; do not shell-quote the port inside the regex.

## Scheduler-As-API Contract

Agent cron jobs often execute URLs like:

- `gsp_api.php?gamemanager/start`
- `gsp_api.php?gamemanager/stop`
- `gsp_api.php?gamemanager/restart`
- `gsp_api.php?gamemanager/update&type=steam`
- `gsp_api.php?server_content/run_scheduled_action`

This means `gsp_api.php` is part of the scheduler runtime and must stay backward compatible with those generated URLs.

## Search Coverage Used For This Document

- `sed -n '1,240p' gsp_api.php`
- `rg -n "^function api_" gsp_api.php`
- `sed -n '1,240p' status_api.php`
- `find Website/api -maxdepth 1 -type f`
- `sed -n '1,220p' Website/webhook.php`
