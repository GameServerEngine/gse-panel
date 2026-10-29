# Library Reference

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This file documents the shared Panel libraries and helper entrypoints that multiple modules reuse.

Primary directories:

- `includes/`
- `protocol/`

No separate `Panel/libraries/` or `Panel/common/` tree is currently used as the main shared layer.

## Core Include Files

| File | Purpose | Used By |
|---|---|---|
| `includes/config.inc.php` | Main runtime configuration | global bootstrap, APIs, installers |
| `includes/database.php` | DB abstraction selector | bootstrap |
| `includes/database_mysqli.php` | Main database implementation and business-query layer | almost all stateful modules |
| `includes/functions.php` | general utility helpers, formatting, logging, Discord webhook helper | global |
| `includes/helpers.php` | session, auth, panel helper functions | global |
| `includes/html_functions.php` | HTML rendering helpers | module UIs |
| `includes/form_table_class.php` | form builder helper | admin and config forms |
| `includes/lang.php` | translation loading | global |
| `includes/lib_remote.php` | XML-RPC wrapper for agent commands | every agent-aware module |
| `includes/navig.php` | module/page routing | main panel request flow |
| `includes/view.php` | page shell and shared JS injection | main panel request flow |
| `includes/refreshed.php` | refresh helper | cron/events and refresh-style pages |
| `includes/debug.php` | debug support | limited / diagnostic use |
| `includes/ip_in_range.php` | CIDR / IP-range helper | API host authorization |
| `includes/api_functions.php` | `gsp_api.php` argument maps and API-side helper logic | external API, scheduler URLs |

## `lib_remote.php` Wrapper Surface

`includes/lib_remote.php` is the most important shared library in the repository.

Responsibilities:

- XML-RPC request creation
- encryption of parameters
- RPC request transport
- decoding return payloads
- compatibility fallbacks for older agents

Major consumers:

- `gamemanager`
- `cron`
- `server`
- `user_games`
- `addonsmanager`
- `litefm`
- `ftp`
- `mysql`
- `status`
- `dashboard`
- `util`

See:

- `docs/architecture/PANEL_AGENT_COMMANDS.md`

## Database Layer

`includes/database_mysqli.php` is more than a raw DB adapter. It acts as a domain service layer.

It owns:

- users and roles
- remote servers
- game homes
- config homes and mods
- API tokens
- widget settings
- many module-specific table helpers

Practical rule:

- before adding direct SQL inside a module, check whether `database_mysqli.php` already exposes the needed operation

## API Helper Layer

`includes/api_functions.php` supports `gsp_api.php`.

Responsibilities:

- endpoint argument definitions
- startup command generation
- RCON dispatch helper
- API host authorization checks

This file is also indirectly used by the scheduler because cron jobs call back into `gsp_api.php`.

## XML / Game Config Parsing

Shared files:

- `modules/config_games/server_config_parser.php`
- `modules/config_games/schema_server_config.xml`
- `modules/config_games/xml_tag_descriptions.php`

Responsibilities:

- validate game XML files
- load startup/query/install metadata
- expose game config values to `gamemanager`, `user_games`, `addonsmanager`, `dsi`, and APIs

## Protocol Libraries

| Path | Purpose | Used By |
|---|---|---|
| `protocol/lgsl/` | LGSL query implementation | `gamemanager`, `dsi`, XML helpers |
| `protocol/GameQ/` | GameQ query implementation | `gamemanager`, `dsi`, XML helpers |

Important note:

- query libraries provide optional metadata
- they are not the source of truth for runtime status

## Bundled Third-Party Libraries In Modules

These are module-local, not general-purpose shared libs, but they matter for architecture.

| Path | Purpose | Main Module |
|---|---|---|
| `modules/ftp/` bundled net2ftp code | browser-based FTP UI | `ftp` |
| `modules/TS3Admin/` | TeamSpeak admin webapp | `TS3Admin` |
| `modules/teamspeak3/ts3admin.class.php` and related files | TeamSpeak integration | `teamspeak3` |
| `modules/gamemanager/MinecraftRcon.class.php` | Minecraft-specific RCON client | `gamemanager` |
| `modules/news/include/library/HTMLPurifier/` | content sanitization | `news` |

## Filesystem And Update Helpers

Notable update-related shared logic currently lives in:

- `modules/administration/panel_update.php`
- `modules/update/updating.php`
- `modules/update/patch_manager.php`
- `modules/update/post_update.php`

These are not in `includes/`, but they function as shared update infrastructure used by the admin-facing update UI.

## Consumers By Concern

| Concern | Primary Shared Files |
|---|---|
| Agent RPC | `includes/lib_remote.php` |
| Database | `includes/database_mysqli.php` |
| API | `includes/api_functions.php`, `gsp_api.php` |
| Routing / page shell | `includes/navig.php`, `includes/view.php` |
| XML config | `modules/config_games/server_config_parser.php` |
| Query metadata | `protocol/lgsl`, `protocol/GameQ` |
| Auth/session helpers | `includes/helpers.php`, `includes/functions.php` |

## Search Coverage Used For This Document

- `find includes -maxdepth 1 -type f`
- `rg -n "require_once\\('includes/lib_remote.php'|require_once\\(\"modules/config_games/server_config_parser.php\"|require_once\\('protocol/" modules`
