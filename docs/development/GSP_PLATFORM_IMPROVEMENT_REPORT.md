# GSP Platform Improvement Report

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

Investigation date: 2026-06-05

Repository reviewed:

- `Agent-Windows`
- `Agent_Linux`
- `Panel`
- `Website`

Scope: investigation and roadmap only. No source code changes are included in this report.

## 1. Executive Summary

GSP already has the pieces of a serious game server hosting platform: a panel, Linux and Windows/Cygwin agents, XML-based game definitions, file management, FTP, server lifecycle controls, RCON, scheduler hooks, add-on/content modules, support/ticket modules, billing/provisioning work, and utilities for popular server ecosystems.

The current risk is not lack of features. The risk is uneven reliability and inconsistent integration. Some modules are mature enough to keep, some are partially modernized, and some are clearly legacy/testing code that should be hidden until replaced. A professional customer-facing platform should first make the core server experience accurate and trustworthy:

- Start, stop, restart, and status must reflect actual agent truth.
- Logs must update live and help users diagnose startup failures.
- File, FTP, and config editing must feel like the customer owns the server while staying inside safe boundaries.
- Backups must be real, visible, restorable, and scheduled.
- Scheduler actions must be safe predefined operations, not arbitrary commands for customers.
- Add-ons, Steam Workshop, mods, and companion programs should be first-class managed systems rather than customer-edited helper scripts.
- Documentation should be tied directly to the selected game, common config files, ports, startup parameters, mods, and troubleshooting.

The platform philosophy should be:

> GSP should not try to hide game server administration behind shallow buttons. It should give customers secure control over their server files, startup parameters, logs, mods, schedules, backups, and documentation, while keeping host files, agent controls, credentials, and unsafe commands protected.

The roadmap should prioritize the foundation first:

1. Reliable lifecycle and status reporting.
2. Live logs and startup diagnostics.
3. Safe file/FTP/config editing.
4. Working backups and restore.
5. Scheduler cleanup with typed actions and logs.
6. Server content and Steam Workshop as managed jobs.
7. Support/docs/billing polish.
8. Optional advanced modules such as companion programs, utility tools, notifications, and game-specific integrations.

## 2. Current System Findings

### Repository Layout

| Path | Purpose | Notes |
|---|---|---|
| `Agent-Windows` | Windows/Cygwin game server agent | Uses Perl, screen, Windows/Cygwin-compatible commands, Steam/update helpers, log access, start/stop/restart operations. |
| `Agent_Linux` | Linux game server agent | Uses Perl, screen, sudo execution, Steam/update helpers, log access, start/stop/restart operations. |
| `Panel` | Main web control panel | PHP modules, XML game configs, database logic, agent XML-RPC communication, UI. |
| `Website` | Public website / customer-facing site | Separate from Panel; should link cleanly into billing/support/dashboard flows. |

Note: the user-facing prompt refers to `/Agent-Linux`, but the actual local directory is `Agent_Linux`.

### Panel-Agent Communication

The Panel communicates with agents through `includes/lib_remote.php`, which wraps XML-RPC calls to agent methods. Important calls include:

- `universal_start`
- `remote_stop_server`
- `remote_restart_server`
- `remote_server_status`
- `is_screen_running`
- `get_log`
- `remote_query`
- file read/write/list operations
- scheduler operations
- update/install operations

The agents expose corresponding Perl subroutines through `gsp_agent.pl`. Both agents use `screen` as the shared backend for game server sessions and update/install jobs.

### Lifecycle Status Direction

The dirty worktree already contains lifecycle/status changes that appear to move toward an agent-owned structured status model:

- `server_status_without_decrypt` exists in both agents.
- `includes/lib_remote.php` contains `remote_server_status`.
- `modules/gamemanager/home_handling_functions.php` contains helper logic for agent server status.
- `modules/gamemanager/server_monitor.php` consumes agent status.

The intended direction is correct: marker files and LGSL/GameQ query success should not be the source of truth. The agent should report process/session and port readiness, while query data is optional metadata.

### Screen Backend

Both agents use screen sessions named in the `GSP_<TYPE>_<HOME_ID>` pattern. This makes Linux and Windows/Cygwin behavior similar, which is useful for portability.

Current screen-related behavior includes:

- Home server screen sessions.
- Update/install screen sessions.
- Screen log files under agent run screen log paths.
- Start wrapper scripts generated around commands.
- Stop logic that attempts graceful stop, screen quit, and process cleanup.

Screen should remain the shared backend for now, but GSP should improve the status model around it.

### Marker Files

The agents still contain `SERVER_STOPPED` marker-file references. These marker files can be useful as loop/autorestart hints, but they must not be trusted as the actual server state. Crash, failed start, power loss, and agent restart can leave marker files wrong.

### Query Systems

The Panel and agents still include GameQ/LGSL-style query support. This should remain useful for:

- hostname
- map
- player count
- server rules
- extra metadata

It should not determine whether a server is online. A server can be healthy while query is unsupported, slow, blocked, or temporarily unavailable.

### Current Design Tension

The current system mixes:

- GSP legacy modules.
- GSP custom business/billing modules.
- Newer partial systems such as Server Content Manager.
- Older modules that are present but not production-ready.

The improvement plan should avoid one giant rewrite. The better path is progressive consolidation around a few strong interfaces:

- Agent status API.
- Agent job API.
- Safe file roots.
- XML capability declarations.
- Database-backed task/content/backup state.
- Panel pages that show state, logs, and next actions clearly.

## 3. Current Module Inventory

| Module | Current State | Keep/Remove/Improve | Why | Priority |
|---|---|---|---|---|
| `gamemanager` | Core server monitor, start, stop, restart, logs, RCON presets, updates. | Improve | This is the center of the customer experience. Lifecycle accuracy and logs must be excellent. | Critical |
| `config_games` | XML game definitions, CLI parameter tooling, mod config mapping. | Improve | This should become the source of game capabilities, startup templates, docs hooks, workshop support, scheduler support. | Critical |
| `user_games` | Server assignment, creation, install commands, expiration, custom fields. | Improve | Provisioning correctness and billing tie-ins depend on this. | Critical |
| `server` | Remote server/node management. | Keep/Improve | Needed for agent/node administration and capacity management. | Critical |
| `litefm` | In-panel file manager/editor, appears to include Ace editor assets. | Improve | Should become the preferred safe in-browser file editor. | High |
| `ftp` | FTP/net2ftp-style module and FTP permissions. | Improve/Audit | FTP is important for advanced users, but embedded legacy tools need security review. | High |
| `editconfigfiles` | Game-specific editable config file shortcuts. | Improve | Useful for customers who want common files surfaced without browsing folders. | High |
| `addonsmanager` | Renamed/commented as Server Content Manager with newer tables for Workshop/content manifests/history. | Improve | Best current home for mods, add-ons, scripts, config packs, and Workshop. | High |
| `steam_workshop` | Marked deprecated; navigation removed; DB helpers retained for compatibility. | Hide/Migrate | Should not compete with Server Content Manager. Preserve data migration path only. | Medium |
| `cron` | Scheduler/CRON module, stores jobs on agents, supports user/admin actions. | Improve | Needs safe typed actions, DB logs, conflict handling, and better UX. | High |
| `backup-restore` | Explicitly says not working/testing; hard-coded paths and credentials style. | Hide/Replace | Unsafe and not production-ready. Replace with managed backup system. | Critical |
| `tickets` | Richer support ticket module with tables, messages, attachments, settings. | Keep/Improve | Better foundation than simple support page. Add server context/log attachments. | High |
| `support` | Basic support entry module. | Keep as landing | Should route to tickets, docs, and server-specific support requests. | Medium |
| `news` | Legacy optional NewsLister-style module. | Hide or Modernize | Useful only if converted into polished announcements. | Low |
| `circular` | Notification/circular module. | Improve | Better candidate for admin notices and maintenance announcements than old news. | Medium |
| `billing` | Large custom billing/provisioning/customer shop area with docs and payment gateways. | Improve | Important for commercial hosting, but should be tested as part of provisioning lifecycle. | High |
| `mysql` | MySQL admin/database module. | Keep/Future | Useful future customer database hosting tie-in. | Medium |
| `fast_download` | FastDL support and access rules. | Keep | Useful for Source/GoldSrc communities. | Medium |
| `rcon` | Admin RCON module. | Keep/Integrate | RCON should support scheduler warnings, console commands, and diagnostics. | High |
| `tshock` | Terraria/TShock-specific controls. | Keep Conditional | Expose only for Terraria/TShock servers and document setup. | Medium |
| `util` | AMX, SourceMod, Steam ID, agent/network tools. | Improve/Hide legacy | Useful tools should remain, outdated helpers should be hidden or labeled legacy. | Medium |
| `faq` | FAQ module. | Improve | Could become part of a game documentation/help system. | Medium |
| `update` | Panel update module with patch/backup concepts. | Keep Admin | Useful for admin maintenance, not customer server management. | Medium |
| `status` | Alpha/admin status module. | Hide/Improve | Could become node health dashboard later. | Low |
| `subusers` | Subuser management. | Keep/Improve | Important commercial feature for teams and communities. | High |
| `dashboard` | Panel landing/dashboard. | Improve | Should surface server health, billing status, support, docs, and alerts. | High |
| `TS3Admin`, `teamspeak3` | Voice server related modules. | Keep if sold | Hide if not part of current product line. | Low |
| `dsi`, `lgsl_with_img_mod`, `mods` | Legacy/niche modules. | Audit | Keep only if actively used. | Low |

## 4. Broken / Risky / Outdated Features

| Feature/Module | Risk | Recommendation |
|---|---|---|
| `backup-restore` | Contains explicit "not working/testing" messaging, hard-coded paths, hard-coded panel URL, hard-coded SSH port, and shell command construction. | Hide from customer UI immediately. Replace with agent-managed backup jobs. |
| Marker-file state checks | `SERVER_RUNNING` / `SERVER_STOPPED` can become stale. | Treat marker files only as loop hints. Agent process/session/port status is source of truth. |
| LGSL/GameQ as online proof | Some games do not query reliably or start slowly. | Query must be optional metadata only. |
| Agent restart shortcuts | Restart logic is historically fragile when separate from stop/start truth. | Restart should mean stop, wait, start, with status polling and logs. |
| Scheduler raw commands | Admin scheduler can run raw commands; API token is embedded in generated wget command; output is discarded. | Customer scheduler must use predefined safe actions. Raw command stays admin-only with audit logs. |
| `--no-check-certificate` in scheduler command | Weakens transport trust. | Use verified HTTPS by default. Only allow override with admin warning. |
| Scheduler state on agent flat file only | Panel lacks durable task run history and structured result state. | Store schedules and task runs in Panel DB; agent executes jobs and reports status. |
| Legacy FTP/net2ftp tooling | Old embedded third-party file tooling may include outdated libraries. | Security audit or migrate to LiteFM as primary UI while retaining FTP service support. |
| Duplicate support systems | `support` and `tickets` both exist, with different depth. | Make `support` a landing page and `tickets` the actual workflow. |
| Deprecated `steam_workshop` module | Separate from newer Server Content Manager. | Hide and migrate data into Server Content Manager. |
| Server Content Manager partial Workshop support | Current structure is promising but lacks professional mod state, load order, async jobs, and per-game install strategies. | Continue as the home for Workshop/content, but build a complete job/state model. |
| Post scripts/custom scripts | Can become arbitrary command execution if exposed too freely. | Restrict customer input; commands should come from trusted XML/admin definitions. |
| Scattered docs | Billing docs, module docs, XML docs, FAQ, and external docs are not unified in server pages. | Build a game-aware documentation surface linked from monitor/config/content pages. |
| Large legacy utility set | AMX/SourceMod/Steam ID tools may be useful but can look outdated. | Hide until verified, then expose per-game where relevant. |

## 5. Modules to Keep

These modules are core or strategically useful:

- `gamemanager`
- `config_games`
- `user_games`
- `server`
- `litefm`
- `ftp`
- `editconfigfiles`
- `addonsmanager`
- `cron`
- `tickets`
- `support`
- `billing`
- `subusers`
- `rcon`
- `fast_download`
- `mysql`
- `faq`
- `update`

Keep does not mean "leave as-is." Most of these need cleanup and integration, but they belong in the future product.

## 6. Modules to Remove or Hide

Remove should usually mean "hide from customer navigation until replaced," not immediate deletion.

| Module | Recommendation | Reason |
|---|---|---|
| `backup-restore` | Hide immediately | Explicitly not working and uses hard-coded backup infrastructure. |
| `steam_workshop` | Hide from UI, keep migration helpers | Deprecated by Server Content Manager. |
| `news` | Hide unless modernized | Old NewsLister-style approach is not a polished support/notification system. |
| `status` | Hide or admin-only | Alpha module should not be customer-facing. |
| `dsi` | Audit/hide if unused | Unknown current business purpose. |
| `mods` | Audit/hide if replaced by Server Content Manager | Avoid duplicate mod/content concepts. |
| `lgsl_with_img_mod` | Admin/legacy only | Query images are secondary; do not confuse status reporting. |
| `TS3Admin`, `teamspeak3` | Hide unless voice hosting is sold | Only expose if part of product offering. |

## 7. Modules to Improve

| Module | Improvement Needed |
|---|---|
| `gamemanager` | Agent-truth status, clear state badges, startup diagnostics, current log excerpts, restart as stop-wait-start, force stop UX, query metadata note. |
| `config_games` | XML capability schema for startup timeout, query availability, RCON, scheduler actions, Workshop, companion programs, docs, editable config files. |
| `user_games` | Provisioning validation, safer defaults, billing lifecycle clarity, post-create checks, install logs, port collision handling. |
| `litefm` | Safe root enforcement, editor backups, syntax highlighting by extension, read-only protected dirs, large file handling. |
| `ftp` | Security audit, FTPS/SFTP guidance, credential rotation, clear user docs, avoid exposing control paths. |
| `editconfigfiles` | Better game-specific config shortcuts, docs next to fields, backup-before-save. |
| `addonsmanager` | Full Server Content system: install jobs, progress logs, Workshop metadata, load order, enable/disable, update/uninstall. |
| `cron` | Typed action registry, task logs, status, no customer raw shell, overlap prevention, missed-task handling. |
| `tickets` | Server context, recent logs, screenshots/attachments, priority, staff assignment, notifications. |
| `billing` | Provisioning audit, server expiration behavior, invoices, service suspension/recovery, payment event logs. |
| `rcon` | Safe presets, scheduler integration, warning messages, output capture. |
| `util` | Modernize useful tools, hide outdated helpers, scope by game. |
| `tshock` | Capability-gated Terraria/TShock UI, setup docs, credential handling. |

## 8. Commercial Hosting Feature Comparison

Public references reviewed:

- Nitrado automated tasks: https://server.nitrado.net/en-GB/guides/automated-tasks-en/
- Nitrado panel navigation/backups: https://server.nitrado.net/en-GB/guides/navigating-nitrado-server-panel
- Nitrado backup management: https://server.nitrado.net/en-US/guides/how-to-manage-and-restore-nitrado-server-backups/
- Nodecraft file access: https://nodecraft.com/features/full-file-access
- Nodecraft control panel knowledgebase: https://nodecraft.com/support/knowledgebase/nodepanel
- TCAdmin features: https://www.tcadmin.com/features/
- TCAdmin game tools docs: https://docs.tcadmin.com/using-tcadmin/game-tools/
- CubeCoders AMP product pages: https://cubecoders.com/AMP/7d2d and https://www.cubecoders.com/AMP/TQ
- Shockbyte scheduled tasks: https://shockbyte.com/help/knowledgebase/articles/creating-scheduled-tasks-with-shockbyte-panel
- Shockbyte backups: https://shockbyte.com/help/knowledgebase/articles/how-to-backup-your-server-files
- Host Havoc scheduled backups: https://hosthavoc.com/billing/knowledgebase/479/Creating-Scheduled-Backups.html
- Host Havoc backup restore: https://hosthavoc.com/billing/knowledgebase/428/CreatingorRestoring-backups.html
- PingPerfect gamepanel overview: https://test.pingperfecthost.us/index.php/knowledgebase/405/Gamepanel-Explained.html
- PingPerfect scheduled server messages: https://pingperfect.com/knowledgebase/706/7-Days-to-Die--Scheduled-Server-Messages.html

| Feature | GSP Current | Commercial Pattern | Recommended GSP Improvement |
|---|---|---|---|
| Start/stop/restart | Exists, but historically mixed marker/query/screen assumptions. | One-click power controls with status feedback. | Agent-truth lifecycle with OFFLINE, STARTING, ONLINE, STOPPING, UNRESPONSIVE, UNKNOWN. |
| File manager | LiteFM and FTP/net2ftp exist. | Built-in file manager, editor, uploads, archives, search, FTP/SFTP. | Make LiteFM primary and FTP secondary; add safe roots, backups, syntax highlighting. |
| FTP access | Existing FTP module and credentials. | Full file access with FTP/SFTP/FTPS. | Keep FTP, document it clearly, support credential rotation, avoid control-file exposure. |
| Config editing | `editconfigfiles` and custom fields exist. | Common config files surfaced in friendly editors. | Build per-game config shortcuts from XML docs and editable file declarations. |
| Startup parameters | Last params/custom fields exist. | Simple settings plus advanced startup args. | Use template + advanced mode, validate paths/flags, show docs. |
| Live console/logs | Existing log viewer; recent work improved AJAX refresh. | Real-time console/log view with commands where supported. | Keep AJAX-only log updates, add startup log, dated log detection, highlight common errors. |
| Backups | Current backup module is not production-ready. | Manual and scheduled backups, restore/download, retention. | Replace with agent-managed backup jobs and Panel DB history. |
| Scheduler | Exists, but command-oriented and agent-file driven. | Scheduled restarts, backups, commands, updates, warnings. | Typed action registry, task run logs, notifications, conflict handling. |
| Mods/addons | Server Content Manager is partially built. | One-click mods/plugins/Workshop where game supports it. | First-class Server Content jobs with per-game strategies and DayZ/Arma/Rust paths. |
| Workshop | Deprecated module plus partial Server Content scripts. | TCAdmin advertises Workshop browser and one-click installs. | URL/ID first version, metadata later, load order, startup integration, update/uninstall. |
| RCON/console commands | RCON module and presets exist. | Console/RCON commands and scheduled warnings. | Capability-gate by game, validate presets, capture command output. |
| Support | Basic support plus richer tickets module. | Tickets/live chat/docs links. | Use tickets as primary, attach server context and recent logs. |
| Docs | Scattered docs and FAQ. | Knowledgebase per game and task. | Game-aware docs panel generated from XML and curated links. |
| Subusers | Exists. | Role/permission sharing. | Improve permissions around files, power actions, billing, content, schedules. |
| Notifications | Circular/news/support pieces exist. | Panel/email/Discord notices. | Unified notifications for tasks, outages, restarts, billing, support. |

What GSP can do better than many hosts:

- Keep advanced file/startup control rather than hiding everything.
- Make status honest even when game query is unsupported.
- Show exactly why a server is STARTING, ONLINE, STOPPING, or UNRESPONSIVE.
- Tie docs directly to the user's selected game, config file, and current action.
- Provide safe structured automation without blocking advanced users from manual config.
- Keep agent behavior portable across Linux and Windows/Cygwin.

## 9. Recommended GSP Feature Set

### Customer-Facing Core

- Server dashboard with clear status and current action.
- Start, stop, restart, force stop.
- Live logs and downloadable logs.
- File manager and FTP credentials.
- Common config file shortcuts.
- Startup parameter editor with simple and advanced modes.
- Server Content / Mods / Workshop page.
- Backups page with create, restore, download, and retention.
- Scheduler page with safe predefined actions.
- Support ticket button with server context.
- Game documentation page.
- Subuser permissions.

### Advanced Customer Features

- RCON/console command presets.
- Scheduled warnings before restart.
- Scheduled Workshop/mod updates.
- Clone mod list or config set to another server.
- Repair/validate server files.
- Restore edited config files.
- Optional companion programs if allowed by game/admin config.

### Admin Features

- Node health and capacity dashboard.
- Game XML capability editor.
- Server provisioning audit.
- Port allocation and collision report.
- Backup storage policy.
- Content install strategy definitions.
- Scheduler action registry.
- Support ticket triage.
- Billing/service lifecycle reporting.
- Secure role and permission management.

## 10. Server Lifecycle Improvement Plan

### Target Status Model

| Status | Meaning |
|---|---|
| `OFFLINE` | No managed process/session and no required listening game port. |
| `STARTING` | Managed session/process exists but required game port is not listening yet. |
| `ONLINE` | Managed session/process exists and required game port is listening. |
| `STOPPING` | Stop was requested and the managed session/process still exists. |
| `UNRESPONSIVE` | Session/process exists but port did not become ready after timeout, or stop failed. |
| `UNKNOWN` | Panel cannot reach the agent or agent cannot determine state. |

### Source of Truth

The agent must be the source of truth. Readiness should be based on:

1. Managed screen/session existence.
2. Process/PID when known.
3. Required game port listening.
4. Optional query metadata only after readiness.

Do not mark a server failed only because LGSL/GameQ does not answer.

### Start

Recommended behavior:

1. Panel sends start request.
2. Agent launches managed screen/session.
3. If session launches, Panel displays STARTING immediately.
4. Panel polls agent status.
5. If process/session exists and game port listens, Panel displays ONLINE.
6. If startup timeout expires without port readiness, display UNRESPONSIVE with log excerpt and troubleshooting links.

Per-game XML should declare:

- default startup timeout
- game port variable
- query port variable
- whether query is expected/reliable
- startup log path patterns
- common startup errors

Slow-start games like Arma/DayZ should use longer defaults such as 180 seconds or more.

### Stop

Recommended behavior:

1. Panel sends stop request.
2. Agent sends graceful stop command if configured.
3. Agent marks STOPPING hint.
4. Agent polls process/session and port.
5. If still running after timeout, escalate to screen quit / process kill.
6. Report stopped only when session/process is gone and port is not listening.
7. If still running, return UNRESPONSIVE and show kill/force-stop option if permitted.

### Restart

Restart should be simple:

1. Stop server.
2. Wait 60 seconds.
3. Start server.

Do not use a separate shortcut that bypasses stop/start status checks. Display restart as a sequence: stopping, waiting, starting, online/unresponsive.

### Force Stop

Force stop should be available with confirmation. It should:

- kill only the managed session/process tree for that server.
- avoid executable-name-based killing.
- log the action.
- record user/admin who clicked it.

## 11. File/FTP/Config Editing Plan

### Philosophy

Customers should feel like they own the server. They should be able to edit config files, upload mods, download worlds, use FTP, and inspect logs. GSP's job is to keep them inside the game server boundary and protect agent/control files.

### Safe File Boundaries

Customer file access should be limited to:

- game install/home path
- declared save/config/log paths
- optional allowed mod/content paths

Customer file access should not include:

- agent run directory
- screen logs outside exposed copies
- Panel secrets
- control manifests that can cause command execution
- global cache directories unless read-only and explicitly allowed
- other users' homes

### File Manager

Improve LiteFM as the primary file manager:

- browse, upload, download, rename, move, copy, delete.
- zip/unzip where safe.
- text editor with syntax highlighting.
- large file warnings.
- read-only handling for protected files.
- backup-before-save option.
- restore last edited version.
- path validation on every request.

### FTP

Keep FTP for advanced users:

- show host, port, username, and reset password.
- support TLS/FTPS/SFTP where available.
- clear docs for FileZilla/WinSCP.
- rotate credentials.
- disable FTP per server if needed.
- never expose agent control directories.

### Config Shortcuts

Use `editconfigfiles` and XML declarations to surface common files:

- `serverDZ.cfg`
- `server.cfg`
- `GameUserSettings.ini`
- `Engine.ini`
- `server.properties`
- `oxide/config/*.json`
- `tshock/config.json`

Each shortcut should show:

- what the file controls.
- whether restart is required.
- link to official docs.
- backup/restore button.

## 12. Documentation System Plan

### Current State

Docs exist in scattered places:

- `docs`
- `docs/deployment`
- `modules/billing/docs`
- FAQ module
- module-specific docs
- XML guide/editor pages

The docs are valuable but not integrated strongly enough into the server workflow.

### Recommended Docs Model

Create a game-aware documentation surface:

- Server Overview: quick start, status meaning, where logs are.
- Files: common config files and save paths.
- Startup Parameters: supported flags and examples.
- Ports: game/query/RCON/Steam ports.
- Mods/Workshop: game-specific install behavior.
- Scheduler: safe recommended tasks.
- Backups: what is included/excluded.
- Troubleshooting: common startup and query failures.

### XML Integration

Game XML files should be extended to declare docs:

```xml
<documentation>
  <page key="getting_started" title="Getting Started" source="local">docs/dayz/getting-started.md</page>
  <external key="official_server_docs" title="Official Server Docs" url="https://example.com" />
</documentation>
```

Docs should open in the Panel and external links should open in a new tab.

### Trusted Internet Resources

Use official docs first:

- game publisher/developer docs.
- Steam dedicated server docs.
- Bohemia Interactive docs for DayZ/Arma.
- Facepunch docs for Rust.
- Valve docs for Source/SteamCMD.

Third-party guides should be labeled as community resources.

## 13. Scheduler/Automation Plan

### Current Scheduler Findings

The current `cron` module:

- provides admin and user scheduler pages.
- can create cron-like jobs on agents.
- builds commands that call Panel API endpoints through `wget`.
- stores tasks primarily on the agent side.
- supports start, stop, restart, Steam update, and newer Server Content actions.
- still allows raw command scheduling in admin context.

Limitations:

- task output is commonly discarded.
- Panel lacks durable structured task run history.
- API token is embedded in generated command text.
- no clear overlap/conflict control.
- no missed-task handling after downtime.
- no polished customer task log.
- no typed action argument schema.

### Recommended Action Groups

Customer-safe:

- restart server.
- stop server.
- start server.
- create backup.
- update installed mods.
- send restart warning.
- run allowed RCON preset.
- rotate logs.
- check server status.

Advanced customer:

- validate/repair server files.
- update SteamCMD game files.
- scheduled wipe/reset if game XML supports it.
- restore latest backup.
- clone backup/config to another server.

Admin-only:

- arbitrary shell command.
- raw script execution.
- permission repair.
- force kill.
- node cleanup.
- storage cleanup.
- global cache cleanup.

### Proposed Action Registry

Each action should have:

- action key.
- display name.
- description.
- allowed roles.
- required permissions.
- supported OS.
- required game XML capability.
- arguments schema.
- validation rules.
- timeout.
- retry behavior.
- conflict rules.
- log policy.

Example:

```yaml
action_key: restart_server
display_name: Restart Server
role: customer
agent_action: stop_wait_start
args:
  warning_minutes: integer
  warning_message: string
timeout_seconds: 600
conflicts_with:
  - update_server
  - restore_backup
  - install_content
```

### Task Logs

Each scheduled run should have:

- pending/running/success/failed/skipped/canceled.
- start and end time.
- server ID.
- node/agent.
- action key.
- user/admin that created the task.
- output log.
- error summary.
- next run time.

## 14. Addons/Workshop/Mods Plan

### Current State

`addonsmanager` has already been renamed conceptually to Server Content Manager. Its schema includes:

- `addons`
- `server_content_workshop`
- `server_content_manifest`
- `server_content_install_history`
- install method fields
- workshop item/app fields
- target path templates
- launch parameter additions
- policy fields for required/blocked Workshop IDs

This is the correct module to improve.

The old `steam_workshop` module is deprecated and should stay hidden except for migration/backward compatibility.

### Target System

Server Content should support:

- scripts.
- mods.
- plugins.
- config packs.
- Steam Workshop items.
- maps.
- server extensions.
- companion support packages if needed.

### Workshop First Version

First version should allow:

1. user enters Workshop URL or numeric ID.
2. Panel extracts numeric ID.
3. Panel validates against game XML Workshop capability.
4. user adds item to queue.
5. agent runs SteamCMD download.
6. agent installs content using trusted strategy.
7. Panel tracks install state and logs.
8. user can enable/disable/reorder.
9. startup parameters update from structured mod list.

### DayZ / Arma Requirements

DayZ and Arma-style games need:

- download Workshop item.
- normalize install folder name.
- copy/install to `@ModName` style folder.
- copy `.bikey` files into `keys`.
- update startup line, for example `-mod=@CF;@ModTwo`.
- preserve load order.
- allow disable without deleting.
- allow update/uninstall.
- prompt restart when server is running.

### XML Capability Example

```xml
<workshop_support>
  <enabled>1</enabled>
  <provider>steam</provider>
  <steam_app_id>221100</steam_app_id>
  <workshop_app_id>221100</workshop_app_id>
  <download_method>steamcmd</download_method>
  <install_strategy>dayz_mod_folder</install_strategy>
  <install_path>{GAME_PATH}</install_path>
  <startup_param_format>-mod={MOD_LIST}</startup_param_format>
  <mod_separator>;</mod_separator>
  <mod_prefix>@</mod_prefix>
  <copy_keys enabled="1">
    <source_pattern>{MOD_PATH}/keys/*.bikey</source_pattern>
    <target_path>{GAME_PATH}/keys</target_path>
  </copy_keys>
</workshop_support>
```

### Job Model

Workshop/content installs should be asynchronous jobs:

- queued.
- running.
- success.
- failed.
- canceled.

The Panel should poll progress logs instead of blocking a page load.

## 15. Support/Newsletter/Utilities/TShock Plan

### Support

Use `support` as a simple customer entry page, but make `tickets` the real support workflow.

Server-specific support should include:

- server ID/home ID.
- game name/mod.
- node/agent.
- current agent status.
- recent lifecycle events.
- recent log excerpt.
- last failed scheduler/content/backup job.
- customer message.
- optional screenshots/attachments.

### Tickets

The tickets module already includes:

- ticket table.
- message table.
- attachment table.
- settings table.
- attachment limits.
- notification setting.

Improvements:

- attach server context automatically.
- add staff assignment and status filters.
- add customer-visible timeline.
- allow staff to request logs/backups.
- support ticket links from each server page.

### Newsletter / News / Circular

The old `news` module should not be the primary notification system unless modernized.

Use or improve `circular` for:

- maintenance notices.
- node outages.
- product announcements.
- billing reminders.
- planned restarts.

Notifications should appear in:

- dashboard.
- server monitor.
- email when configured.
- Discord webhook later.

### Utilities

Keep useful utilities but clean presentation:

- Steam ID converter.
- network/agent tools for admins.
- SourceMod/AMX helpers only for relevant games.

Hide or label legacy utilities that are not verified.

### TShock

The TShock module can be valuable for Terraria hosting, but it should be exposed only when:

- game XML indicates Terraria/TShock support.
- required ports/tokens are configured.
- documentation exists.

TShock features should include:

- users.
- bans.
- token/status.
- docs for enabling REST/RCON safely.

## 16. Security Plan

### File Boundaries

All file operations must validate:

- resolved real path.
- allowed root.
- no path traversal.
- no symlink escape unless explicitly permitted.
- file size limits for editor.
- extension/content rules for executable uploads if needed.

### Command Execution

Customers should not be allowed to schedule or run arbitrary shell commands by default.

Allowed customer operations should map to trusted actions:

- start.
- stop.
- restart.
- backup.
- update.
- install content.
- run approved RCON preset.

Admin-only raw commands must be:

- permission-gated.
- logged.
- time-limited.
- clearly marked dangerous.

### Startup Parameters

Startup parameters should allow advanced control without arbitrary host command execution:

- structured variable substitution.
- whitelist/validation where practical.
- path normalization.
- documented advanced mode.
- no injection into shell wrappers without escaping.

### Secrets

Protect:

- Steam credentials.
- RCON passwords.
- FTP passwords.
- API tokens.
- billing gateway keys.
- SMTP credentials.
- agent encryption keys.

Logs should redact secrets. Scheduler command files should not expose API tokens where avoidable.

### Roles and Permissions

Permissions should cover:

- power controls.
- force stop.
- file manager.
- FTP.
- config edit.
- startup params.
- mods/content.
- backups.
- scheduler.
- RCON.
- billing.
- support.
- subuser management.

### Audit Logs

Record:

- who started/stopped/restarted.
- who edited files.
- who changed startup params.
- who installed/uninstalled mods.
- who restored backups.
- who ran RCON commands.
- who created scheduler tasks.
- admin raw commands.

## 17. User Experience Plan

### Server Overview

The server monitor should answer these questions immediately:

- Is my server offline, starting, online, stopping, unresponsive, or unknown?
- What is GSP checking to decide that?
- Are the process/session and port present?
- Is query info available?
- What is the latest log output?
- What can I do next?

### Clear Status Language

Examples:

- ONLINE: "Server process is running and the game port is listening."
- STARTING: "Server process started. Waiting for the game port."
- ONLINE, query unavailable: "Server is online. Player/map query is unavailable for this game or did not respond."
- UNRESPONSIVE: "Server process is running, but the game port did not open before the timeout."
- UNKNOWN: "The agent could not be reached."

### Common Actions

Each server page should expose:

- Power.
- Logs.
- Files.
- Config.
- Startup.
- Mods/Content.
- Backups.
- Scheduler.
- Support.
- Docs.

### Mobile

Mobile pages should prioritize:

- current status.
- start/stop/restart.
- latest log.
- support.
- docs.

Large file management can remain desktop-focused, but it should not break on mobile.

## 18. Admin Experience Plan

Admins need tools for:

- node health and agent connectivity.
- server state audit.
- stuck server detection.
- port allocation.
- game XML capability management.
- provisioning tests.
- billing lifecycle.
- backup storage usage.
- content job failures.
- scheduler task failures.
- support ticket triage.

Admin pages should distinguish:

- customer-visible issue.
- node/agent issue.
- provisioning issue.
- billing issue.
- game XML/config issue.

## 19. Implementation Phases

| Phase | Priority | Files/Modules Involved | Notes |
|---|---|---|---|
| Phase 1: Lifecycle validation | Critical | `Agent-Windows/gsp_agent.pl`, `Agent_Linux/gsp_agent.pl`, `includes/lib_remote.php`, `modules/gamemanager` | Finish and test agent-truth status, start, stop, restart, log viewer. |
| Phase 2: Hide unsafe legacy modules | Critical | `backup-restore`, `steam_workshop`, `news`, `status`, nav/module settings | Hide customer access to broken/deprecated modules before launch. |
| Phase 3: Live logs and diagnostics | Critical | `gamemanager/log.php`, `view_server_log.php`, agent `get_log` | Dated log discovery, startup logs, download logs, error highlighting. |
| Phase 4: File/FTP safety audit | High | `litefm`, `ftp`, `editconfigfiles`, agent file methods | Safe roots, editor backups, protected dirs, FTP docs, credential reset. |
| Phase 5: Backup system replacement | High | new backup tables/jobs, agents, scheduler | Replace `backup-restore` with real create/restore/download/retention. |
| Phase 6: Scheduler action registry | High | `cron`, agents, Panel DB | Typed actions, task logs, conflict rules, safe customer actions. |
| Phase 7: Server Content foundation | High | `addonsmanager`, agents, XML schema | Async jobs, progress logs, install history, enable/disable. |
| Phase 8: Steam Workshop / DayZ | High | `addonsmanager`, SteamCMD scripts, `config_games` | Workshop URL/ID, DayZ key copy, load order, startup param integration. |
| Phase 9: Docs integration | High | `faq`, `config_games`, `billing/docs`, server pages | Game-specific docs linked from server workflows. |
| Phase 10: Support polish | Medium | `support`, `tickets`, `circular` | Server-context tickets, notifications, maintenance notices. |
| Phase 11: Billing/provisioning audit | High | `billing`, `user_games`, APIs | Validate server creation, expiration, suspension, passwords, FTP. |
| Phase 12: Companion programs | Medium | agents, XML schema, Panel UI | PID-tracked BEC/B3/bot/log watcher support. |
| Phase 13: Utilities/TShock | Medium | `util`, `tshock`, XML capabilities | Expose only where relevant and document setup. |
| Phase 14: Commercial polish | Medium | Dashboard, UX, notifications | Smooth customer experience, mobile, alerts, user education. |
| Future: infrastructure services | Future | `mysql`, new service modules | DB hosting, Git/Forgejo, web hosting, LXC/container services. |

## 20. Open Questions

1. Which games are launch priorities for first commercial release?
2. Should Windows/Cygwin remain a first-class target long term, or only compatibility?
3. What FTP server is expected in production: FTP, FTPS, SFTP, or multiple?
4. Where should customer backups be stored: same node, separate backup node, object storage, or multiple options?
5. What is the desired retention policy by plan?
6. Should customers be allowed to edit all files under game home, or should some files be protected by default?
7. How should advanced startup params be limited to prevent shell injection while preserving real control?
8. Which games require authenticated SteamCMD credentials?
9. How should Steam credentials be stored and rotated?
10. Should Workshop cache be global per node, per user, or per server only?
11. Should GSP support SFTP directly or rely on existing FTP tooling first?
12. Which billing gateway is production priority: PayPal, Stripe, manual, or all?
13. How should expired/suspended servers behave for files, backups, FTP, scheduler, and support?
14. Which notification channels are required first: panel, email, Discord?
15. Should customers be able to grant subusers billing access, or only technical access?
16. Should raw scheduled commands ever be customer-accessible under an advanced plan, or always admin-only?
17. Is containerization a near-term requirement or a future product line?

## 21. Final Recommendations

1. Treat lifecycle/status/logging as the launch gate. GSP cannot be professional if it lies about server state.
2. Keep the agent as source of truth. Use screen/session, PID, and port checks; keep query data optional.
3. Hide `backup-restore` until replaced. A broken backup module is worse than no backup module.
4. Use LiteFM plus FTP as the customer file-control foundation, but audit path boundaries and legacy FTP tooling.
5. Consolidate mods/addons/Workshop under Server Content Manager. Keep `steam_workshop` hidden for migration only.
6. Replace scheduler command strings with a typed action registry and DB-backed task run logs.
7. Make docs part of the server workflow, not a separate afterthought.
8. Use tickets as the real support workflow and include server context automatically.
9. Keep advanced users empowered: files, FTP, startup params, logs, RCON, mods, and schedules should remain accessible.
10. Keep dangerous operations structured and permissioned: raw shell commands, host paths, credentials, and agent files must stay protected.
11. Build improvements in phases. Do not rewrite the entire panel at once.

The most important commercial differentiator for GSP should be honest control: customers can manage servers like they own them, while GSP provides secure boundaries, accurate state, automation, backups, docs, and support.
