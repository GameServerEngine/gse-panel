# Repository Overview

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

## Purpose

This repository contains the GameServer Engine (GSE) Panel: the management console and control-plane code for hosted game servers.

The surrounding workspace is a multi-repository container, not a single Git repository:

- Workspace root: `/home/dev/Projects/GSP`
- Panel/control-plane repository: `/home/dev/Projects/GSP/Panel`
- Gameservers.World storefront: `/home/dev/Projects/GSP/Panel/modules/billing`
- Linux agent repository: `/home/dev/Projects/GSP/GSP-Agent-Linux`
- Windows agent repository: `/home/dev/Projects/GSP/GSP-Agent-Windows`

`GSP` remains in local paths and repository names as a compatibility identifier.

## Top-Level Layout

```text
/
  includes/
  modules/
  protocol/
  sql/
  themes/
  docs/
```

## Major Components

### Panel

The Panel is the orchestration layer. It:

- loads module pages from `modules/*`
- talks to agents through `includes/lib_remote.php`
- stores panel-side state in the database
- renders server lifecycle, file, backup, scheduler, Workshop, support, and billing pages

Important Panel files:

- `includes/lib_remote.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/server_monitor.php`
- `modules/config_games/schema_server_config.xml`
- `modules/addonsmanager/module.php`
- `modules/cron/module.php`
- `modules/user_games/module.php`

### Agents

The agents are the execution layer. They:

- launch game servers inside `screen`
- stop and restart servers
- read logs
- run updates and install jobs
- execute scheduler jobs
- report status back to the Panel

Important agent files:

- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`
- `Agent_Linux/startups/`
- `Agent-Windows/ServerFiles/`
- `Agent_Linux/php-query/`
- `Agent-Windows/php-query/`

### Gameservers.World Storefront

The canonical customer-facing hosting storefront is `modules/billing`. It is separate from the server runtime path and is used for:

- public hosting pages
- documentation links and account entry
- customer onboarding and billing
- billing and commerce surfaces

Gameservers.World is not a separate top-level repository and is not the whole Panel.

## Panel <-> Agent Communication

The Panel uses XML-RPC over HTTP to call methods exposed by `gsp_agent.pl`.

The remote wrapper lives in:

- `includes/lib_remote.php`

The most important calls are:

- `universal_start`
- `remote_stop_server`
- `remote_restart_server`
- `remote_server_status`
- `is_screen_running`
- `get_log`
- `remote_query`
- scheduler methods

The agents decode the request, execute the action locally, and return a status code or payload.

## Database Usage

The Panel database stores:

- server homes and assigned ports
- game definitions and mod mappings
- user permissions and subusers
- scheduler definitions and related data
- content and Workshop metadata
- tickets/support records
- billing and provisioning records

Key tables discovered in current code:

- `server_homes`
- `home_ip_ports`
- `game_mods`
- `status_cache`
- `config_homes`
- `config_mods`
- `addons`
- `server_content_workshop`
- `server_content_manifest`
- `server_content_install_history`
- `tickets`
- `ticket_messages`
- `ticket_attachments`
- `ticket_settings`
- billing-related tables in `modules/billing`

The agents also keep runtime files such as screen logs, update logs, scheduler state, and helper manifests, but those should be treated as runtime state rather than the source of truth.

## Startup Flow

1. User clicks start in the Panel.
2. Panel builds the startup command from the game XML and stored server parameters.
3. Panel sends `universal_start` to the agent.
4. Agent creates a `screen` session and launches the server command.
5. Panel polls status and logs.
6. If the managed session exists and the required port listens, the server is considered online.

## Status Reporting Flow

The desired status flow is:

```text
Panel asks agent for status
  -> agent checks managed session/process
  -> agent checks required listening port
  -> agent optionally performs query metadata lookup
  -> agent returns structured status
  -> Panel renders ONLINE / STARTING / STOPPING / UNRESPONSIVE / OFFLINE / UNKNOWN
```

Query results are metadata only. They are not the source of truth for online/offline state.

## What To Read First

For future investigations, start with:

1. `docs/development/CODEX_GUIDE.md`
2. `docs/architecture/PANEL_AGENT_FLOW.md`
3. `docs/modules/GAMEMANAGER.md`
4. `docs/features/STATUS_SYSTEM.md`
5. `docs/features/XML_SYSTEM.md`
