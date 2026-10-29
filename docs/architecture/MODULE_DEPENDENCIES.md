# Module Dependencies

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This file maps how Panel modules depend on:

- agents
- shared libraries
- other modules
- database tables
- AJAX endpoints
- external services

It answers the practical question: what can call what?

## Core Dependency Layers

```text
User / admin UI
  -> home.php module routing
  -> module page PHP files
  -> shared include libraries
  -> database layer
  -> agent RPC wrapper (lib_remote.php)
  -> Linux / Windows agents

AJAX / external API
  -> module endpoint PHP files or gsp_api.php / status_api.php
  -> same shared libraries and DB layer
  -> agent RPC where needed
```

## Shared Internal Hubs

| Hub | Called By | Purpose |
|---|---|---|
| `includes/lib_remote.php` | `gamemanager`, `cron`, `server`, `user_games`, `litefm`, `ftp`, `mysql`, `addonsmanager`, `status`, `dashboard`, `util`, `fast_download`, `billing`, `editconfigfiles` | Agent XML-RPC transport |
| `modules/config_games/server_config_parser.php` | `gamemanager`, `user_games`, `addonsmanager`, `cron`, `dsi`, `config_games`, API | Game XML loading and interpretation |
| `includes/database_mysqli.php` | effectively all stateful modules | Database abstraction and business queries |
| `includes/functions.php` and `helpers.php` | global | auth, logging, formatting, small helpers |
| `includes/view.php` and `navig.php` | routing / page shell | module dispatch and page rendering |

## Module Dependency Table

| Module | Calls Agent | Calls Other Modules / Files | Shared Libraries | Major DB / State | AJAX / API Endpoints | External Integrations |
|---|---|---|---|---|---|---|
| `administration` | Indirectly, for update and logger views | `panel_update.php`, backup/log helpers | `functions.php`, DB layer, `lib_remote.php` | settings, remote server metadata | `watch_logger.php` | Git / Forgejo, filesystem |
| `addonsmanager` | Yes | `workshop_action.php`, `server_content_actions.php`, `server_content_helpers.php`, `config_games` | `lib_remote.php`, XML parser | `addons`, `server_content_manifest`, `server_content_workshop` | `workshop_action.php` | SteamCMD, Workshop, archive installers |
| `backup-restore` | Limited / indirect | local helper function file | DB layer, filesystem helpers | backup metadata if present | module pages | zip/tar filesystem work |
| `billing` | Yes for server provisioning/expiry actions | embeds storefront runtime; shares logic with `Website/` | DB layer, `lib_remote.php`, payment helpers | orders, invoices, coupons, server provisioning state | webhook and checkout entrypoints under module and website | PayPal, Stripe/manual gateways, email |
| `circular` | No | own helper file | DB layer | announcement/content records | `show_circular.php` | none |
| `config_games` | No direct agent use in main UI | `server_config_parser.php`, `cli-params.php`, `set_mods.php`, `set_params.php` | XML parser, DB layer | `config_homes`, `config_mods` | XML editor-like pages | GameQ, LGSL references |
| `cron` | Yes | `shared_cron_functions.php`, uses `gsp_api.php` URLs as scheduled payloads | `lib_remote.php`, XML parser, DB layer | Panel-side scheduler intent; agent-owned cron entries | `events.php`, `thetime.php`, `user_cron.php` | agent scheduler, `wget`, panel API |
| `dashboard` | Yes | `query_ref.php`, `updateWidgets.php` | `lib_remote.php`, DB layer | widget prefs, server overview | `updateWidgets.php` | query protocols |
| `dsi` | Yes | shared DSI includes, `gamemanager/home_handling_functions.php`, XML parser | `lib_remote.php`, XML parser, GameQ, LGSL | server and game metadata | `image.php`, list/admin pages | GameQ, LGSL, GeoIP |
| `editconfigfiles` | Yes | its own helpers/config lists | `lib_remote.php`, DB layer | file edit permissions by home | `modify.php` | remote file editing |
| `faq` | No | RSS/parser helper | DB/content layer | FAQ/news content | `faq.js` | RSS parsing |
| `fast_download` | Yes | admin/user pages, helper file | `lib_remote.php`, DB layer | fastdl config aliases | module pages | web server / FastDL service |
| `ftp` | Yes | bundled net2ftp app files, admin wrapper | `lib_remote.php`, DB layer | remote server FTP config | admin and embedded FTP UI | Pure-FTPd / FTP service, net2ftp |
| `gamemanager` | Yes | `home_handling_functions.php`, start/stop/restart/log pages | `lib_remote.php`, XML parser, helpers | server homes, last params, runtime status | `get_server_log.php`, `log.php`, `rcon.php` | screen, RCON, query protocols, SteamCMD |
| `lgsl_with_img_mod` | No agent by default | own LGSL pages | LGSL | server query presentation | `feed.php`, `image.php` | LGSL |
| `litefm` | Yes | file manager pages and helpers | `lib_remote.php`, session helpers | session download state | `fm_dir.php`, `fm_read.php`, `fm_write.php`, `get_file.php` | remote file management |
| `lostpwd` | No | own page | DB layer, mail helpers | users | form endpoints | email |
| `modulemanager` | No agent | `module_handling.php`, update modules | filesystem, DB layer | installed module metadata | add/delete/update pages | filesystem |
| `mysql` | Yes | own DB server management pages | `lib_remote.php`, DB layer | mysql server records, dumps | `get_dump.php` | MySQL server administration |
| `news` | No | admin upload/config pages | DB/content libs | news posts | upload/admin pages | file upload |
| `rcon` | Yes | standalone RCON page | `lib_remote.php`, XML parser | server home lookup | module page | RCON |
| `register` | No agent | registration helper pages | DB layer | users | `register-exec.php` | captcha/email if configured |
| `server` | Yes | add/edit/restart/reboot/firewall/view_log pages | `lib_remote.php`, DB layer | `remote_servers`, IP allocation | `mon_stats.php`, `view_log.php` | agent lifecycle, firewall, reboot |
| `settings` | No agent by default | `api_hosts.php`, theme/settings pages | DB layer, API helpers | `settings`, authorized-host files | settings pages | Discord webhook config, API host files |
| `status` | Yes | `modules/status/include/*` | `lib_remote.php`, config helpers | remote server list | embedded include pages | CPU/RAM/disk/uptime monitoring |
| `subusers` | No direct agent | own CRUD pages | DB layer | users, permissions | form endpoints | none |
| `support` | No agent | own page | DB layer, `functions.php` | support requests | support form page | Discord webhook |
| `teamspeak3` | No generic agent RPC | own TS3 admin lib set | TS3-specific classes, DB layer | TS3 homes/config | TS3 pages | TeamSpeak query/admin |
| `tickets` | No agent by default | attachment, rating, settings, count, submit, view pages | DB layer | tickets, attachments, ratings | `notificationCount.php`, `submitTicket.php`, `downloadAttachment.php` | email/notifications if configured |
| `TS3Admin` | No generic agent RPC | bundled TS3 admin web app | TS3 admin classes | TS3 runtime/admin data | JS-driven views | TeamSpeak admin interface |
| `tshock` | No generic agent RPC | shared TShock helpers | DB layer | Terraria/TShock tokens/config | create token and management pages | TShock REST/API |
| `update` | Indirectly through `administration/panel_update.php` and agent `component_update` | `updating.php`, patch manager | filesystem, DB/settings, `lib_remote.php` | update settings, backups, last update files | module pages | Git / Forgejo |
| `user_admin` | No direct agent | user/group CRUD pages | DB layer | users, groups, assignments | form endpoints | none |
| `user_games` | Yes | provisioning, clone, migrate, custom fields, browser | `lib_remote.php`, XML parser, DB layer | `server_homes`, `user_homes`, IP allocations, configs | `get_size.php`, browser pages | filesystem, provisioning, rsync |
| `util` | Yes | addadmin helpers, SteamID tools, network tools | `lib_remote.php`, DB layer | server lookup | helper pages | RCON, Steam ID conversion |

## Module-To-Module Relationships

| Source Module | Target Module / File | Why |
|---|---|---|
| `gamemanager` | `config_games/server_config_parser.php` | build startup commands, query definitions, control protocol metadata |
| `gamemanager` | `addonsmanager/monitor_buttons.php` and content helpers indirectly | content/update actions shown on monitor pages |
| `cron` | `addonsmanager/server_content_actions.php` via `gsp_api.php?server_content/run_scheduled_action` | scheduled content checks and installs |
| `cron` | `gamemanager` via `gsp_api.php?gamemanager/*` | start, stop, restart, Steam auto update |
| `user_games` | `gamemanager/home_handling_functions.php` | delete / teardown and lifecycle-related helpers |
| `user_games` | `billing/create_servers.php` | provisioning integration |
| `dsi` | `gamemanager/home_handling_functions.php` | shared game-home status logic |
| `update` | `administration/panel_update.php` | actual admin-facing updater implementation |
| `settings` | `api_functions.php` | authorized host list UI for external API |
| `billing` | `Website/` mirrored runtime | storefront and payment logic is shared between panel module and public site |

## AJAX And Async Endpoints

| Endpoint | Owning Module | Purpose |
|---|---|---|
| `modules/gamemanager/get_server_log.php` | `gamemanager` | live log refresh |
| `modules/dashboard/updateWidgets.php` | `dashboard` | dashboard widget refresh |
| `modules/tickets/notificationCount.php` | `tickets` | unread/support badge count |
| `modules/cron/events.php` | `cron` | scheduler log refresh |
| `modules/litefm/get_file.php` | `litefm` | streamed download chunks |
| `status_api.php` | architecture / public status | panel-local public node summary API |
| `gsp_api.php` | architecture / external API | token-authenticated external automation API |

## Major Database Ownership

This is not a full schema map. It identifies ownership boundaries.

| Table / Group | Main Owners |
|---|---|
| `settings` | `settings`, `update`, general bootstrap |
| `users`, `user_groups`, `user_homes` | `user_admin`, `subusers`, `register`, auth/session flows |
| `remote_servers`, `remote_server_ips` | `server`, provisioning, `status`, `dashboard` |
| `server_homes`, `home_ip_ports`, `game_mods` | `user_games`, `gamemanager`, `cron`, provisioning |
| `config_homes`, `config_mods` | `config_games`, `user_games`, `gamemanager` |
| `api_tokens` | `gsp_api.php`, user session helpers |
| `server_content_manifest`, `server_content_workshop`, `addons` | `addonsmanager` |
| billing/order/invoice/coupon tables | `billing`, `Website/` |
| ticket/support tables | `tickets`, `support` |
| widget / dashboard tables | `dashboard` |

## External Integration Map

| Integration | Primary Modules |
|---|---|
| SteamCMD | `gamemanager`, `addonsmanager`, Workshop flows, agents |
| Steam Workshop | `addonsmanager`, legacy `steam_workshop`, agents |
| GameQ / LGSL | `gamemanager`, `dashboard`, `dsi`, XML config |
| RCON | `gamemanager`, `rcon`, `util`, scheduler-triggered API calls |
| Git / Forgejo | `update`, `administration/panel_update.php`, agent `component_update` |
| FTP / Pure-FTPd / net2ftp | `ftp`, provisioning |
| Discord webhooks | `settings`, `support`, shared `functions.php::discordmsg` |
| PayPal / Stripe / manual payments | `billing`, `Website/api/*`, `Website/webhook.php` |
| TeamSpeak | `teamspeak3`, `TS3Admin` |
| TShock | `tshock` |

## Search Coverage Used For This Document

Key searches:

- `rg -n "new GSPRemoteLibrary|->remote_|->scheduler_|->component_update|->steam_workshop" modules includes`
- `rg -n "require|require_once|include|include_once" modules -g '*.php'`
- `find modules -maxdepth 2 -type f \\( -name '*.php' -o -name '*.js' \\)`
