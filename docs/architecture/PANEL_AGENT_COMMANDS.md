# Panel-Agent Commands

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This file is the command catalog for the XML-RPC surface between the Panel and the agents.

Primary files:

- `includes/lib_remote.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

Transport:

- Panel -> agent uses XML-RPC over HTTP to `/RPC2`
- Arguments are encrypted by `GSPRemoteLibrary`
- Agents validate the encryption marker before executing commands

## Command Dispatch

Linux dispatch table:

- `Agent_Linux/gsp_agent.pl`

Windows dispatch table:

- `Agent-Windows/GSP/gsp_agent.pl`

Important parity note:

- Linux exposes `renice_process`
- Linux exposes `lock_additional_files`
- Windows does not expose those two commands in its current dispatcher

## Return Conventions

Common patterns:

- `1`: success
- `0`: false, offline, or not running depending on command
- negative values: error or unreachable state
- arrays / hashes: structured payloads for newer commands
- base64-encoded text payloads are common for file, shell, and stats responses

## Lifecycle And Status Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `quick_chk` | Lightweight reachability and encryption check | none | `1`, `0`, or error | Yes | Yes | `lib_remote.php::status_chk`, most modules before remote work | Safe probe; no server-side mutation |
| `is_screen_running` | Legacy managed-session check | `screen_type`, `home_id` | `1`, `0`, `-1` | Yes | Yes | `gamemanager`, status fallback, legacy monitor code | Session existence only; not a full readiness check |
| `server_status` | Structured server state check | `home_id`, `server_ip`, `server_port`, `query_port`, `rcon_port`, `startup_timeout`, `state_hint` | hash with desired state plus observed screen/PID/process/port flags | Yes | Yes | `gamemanager/home_handling_functions.php`, `server_monitor.php` | Preferred source of truth for runtime state; desired markers are intent only |
| `force_stop_server` | Hard stop one game server without restart | `home_id`, `server_ip`, `server_port`, optional `home_path` | structured hash with success, attempted/killed/remaining PIDs, session/process/port state | Yes | Yes | `server/agent_interface.php` | Admin-only; scoped to one assigned home/IP/port and must not restart |
| `universal_start` | Start a game server | legacy positional start fields plus final JSON `gse.lifecycle.v2` payload containing `management_home`, `game_key`, `game_root`, run directories, arguments, environment, ports/protocol, user options, replace-text results, and optional one-bot command triplet | structured hash on current agents; legacy `1`/negative codes remain supported narrowly | Yes | Yes | `gamemanager/start_server.php`, `mini_start.php`, API start | Agent regenerates watchdog and bot definition on every Start from current values |
| `stop_server` | Stop a running server | legacy positional stop fields plus final JSON `gse.lifecycle.v2` stop payload | structured verified result on current agents; legacy `0` success is treated as provisional until `server_status` verifies stopped | Yes | Yes | `gamemanager/stop_server.php`, restart flows, API stop, billing expiry flows | Desired state becomes stopped before termination; Panel must not report success while status evidence remains active |
| `restart_server` | Restart a running server | legacy positional restart fields plus final JSON `gse.lifecycle.v2` Start payload and restart reason | structured hash on current agents; legacy `1`/`-1`/`-2` remain supported narrowly | Yes | Yes | `gamemanager/restart_server.php`, API restart, scheduler | Verified Stop followed by a fresh generated Start |
| `send_rcon_command` | Send RCON / console command | `home_id`, `server_ip`, `server_port`, `control_protocol`, `control_password`, `control_type`, `command`, `return` | `1`, `-10`, error | Yes | Yes | `gamemanager/rcon.php`, `rcon`, util helpers, API rcon | Admin/customer command surface; must not accept untrusted shell text |
| `remote_query` | Query game metadata | `protocol`, `game_type`, `ip`, `c_port`, `q_port`, `s_port` | base64 payload or null/error | Yes | Yes | `gamemanager`, dashboard, DSI | Metadata only; not source of truth for online/offline |
| `renice_process` | Adjust process priority | process / nice inputs | success / error | No | Yes | `lib_remote.php::renice_process`, startup tuning | Linux-only; process control |
| `cpu_count` | Return CPU/core count | none | integer | Yes | Yes | start flows, provisioning, tuning UIs | Read-only system info |

## File And Filesystem Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `rfile_exists` | Check remote file existence | `path` | `1` / `0` | Yes | Yes | file tools, content helpers | Must remain path-safe |
| `readfile` | Read remote file | `path` | status + base64 data | Yes | Yes | `litefm`, `gamemanager`, `editconfigfiles`, `mysql`, `server`, API helpers | Reads customer server files and some agent logs |
| `writefile` | Write remote file | `path`, `content` | `1` / error | Yes | Yes | `litefm`, `editconfigfiles`, `gamemanager`, `addonsmanager`, `mysql`, `fast_download`, `server` | High-risk write path; caller must validate target path |
| `dirlist` | Directory listing | `path` | array / error | Yes | Yes | `gamemanager/mini_start.php`, older browsers | Read-only |
| `dirlistfm` | File-manager-specific directory listing | `path` | array / error | Yes | Yes | `litefm`, `user_games/browser.php` | Read-only |
| `get_file_part` | Stream file in chunks | `path`, `offset` | next offset + chunk | Yes | Yes | `litefm/get_file.php` | Download helper for large files |
| `secure_path` | Agent path validation helper | `action`, `path` | base64 payload / validation result | Yes | Yes | update/content/security flows | Important for safe agent-side path operations |
| `get_chattr` | Read filesystem attributes | `path` | attributes text | Yes | Yes | admin/security tools | Mostly Linux semantics |
| `lock_additional_files` | Lock or unlock extra files | `game_home`, `filesToLockUnlock`, `action` | success / error | No | Yes | `lib_remote.php::lock_additional_home_files` | Linux-only file-attribute helper |
| `clone_home` | Copy a game home | `source_home`, `dest_home`, `owner` | `1`, `0`, `-1` | Yes | Yes | `user_games/clone_home.php`, provisioning | Long-running filesystem mutation |
| `remove_home` | Delete a game home | `game_home_del` | `1`, `0`, `-1` | Yes | Yes | `user_games/del_home.php`, teardown flows | Destructive; must be admin or owner-controlled |
| `compress_files` | Build archive from remote files | `files`, `destination`, `archive_name`, `archive_type` | success / error | Yes | Yes | backup/content/fastdl flows | Archive path must stay inside allowed roots |
| `uncompress_file` | Extract archive | archive inputs | success / error | Yes | Yes | installers, content tools | Extraction path must be constrained |

## Download, Install, And Update Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `steam_cmd` | Run SteamCMD-based install/update | steam-specific arguments | status code | Yes | Yes | `gamemanager/update_server.php`, API update | Can change server binaries and Steam content |
| `fetch_steam_version` | Fetch Steam app version metadata | app arguments | version string / error | Yes | Yes | update pages | Read-only Steam helper |
| `installed_steam_version` | Read installed Steam app version | path/app inputs | version string / error | Yes | Yes | update pages | Read-only |
| `automatic_steam_update` | Auto-update via SteamCMD | update args | status code | Yes | Yes | scheduler/API update path | Mutation of game install |
| `start_file_download` | Begin remote file download | URL and target args | pid / handle / status | Yes | Yes | installers, update/content flows | External network fetch; validate destination |
| `is_file_download_in_progress` | Poll active download | download handle | status | Yes | Yes | update/content flows | Read-only |
| `start_rsync_install` | Begin rsync-based install/copy | rsync args | status | Yes | Yes | migration/provisioning | Long-running copy action |
| `rsync_progress` | Poll rsync copy | rsync handle | progress | Yes | Yes | migration/provisioning | Read-only |
| `master_server_update` | Legacy master server update helper | update args | status | Yes | Yes | legacy update flows | Administrative |
| `stop_update` | Abort active update | `home_id` | `1` / error | Yes | Yes | update UI, `lib_remote.php::stop_update` | Mutates running install/update |
| `component_update` | Update agent code from Git staging | encrypted JSON payload | structured hash | Yes | Yes | `administration/panel_update.php` | Admin-only; must preserve config and hosted server data |
| `agent_self_update` | Queue restart-time agent update request | encoded allowlisted payload containing `component`, `repo_url`, `branch`, `source_path` | queued/status hash | Yes | Yes | `server/agent_interface.php` | Admin-only; running agent records pending request and does not replace itself. Panel per-agent updates call `agent_restart` only after this queue call succeeds |
| `agent_update_status` | Return latest queued update state | none | structured hash from `gsp_agent_update_state.json` | Yes | Yes | `server/agent_interface.php` | Read-only update state; reports `pending`, `running`, `completed`, `failed`, or `rolled_back` plus repo/branch/commit fields |
| `agent_restart` | Restart the agent service/process | marker arg | `1` / error | Yes | Yes | `server/restart.php` | Admin-only node control |

## Workshop And Server Content Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `steam_workshop` | Legacy direct Workshop installer | `home_id`, `mods_full_path`, `workshop_id`, `mods_list`, regex/config args, auth args, download args | `1`, `-1`, `-2`, `-3`, `-4` | Yes | Yes | legacy `steam_workshop` module and compatibility helpers | High-risk legacy surface; keep compatibility but prefer Server Content Manager |
| `get_workshop_mods_info` | Enumerate installed Workshop mods | none / `mods_info` marker | status + mod list | Yes | Yes | Workshop UIs, compatibility views | Read-only |

Current preferred implementation path:

- `addonsmanager` stages a manifest and generated per-job script through `writefile`
- the generated script creates a temporary SteamCMD runscript and runs `steamcmd +runscript`
- it executes the generated job script through `exec`
- it records per-server items and policies in Panel database tables
- it uses `steam_workshop` only as legacy compatibility, not as the primary workflow
- no new Workshop-specific business logic should be added to agents for the current design

## Shell And System Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `exec` | Execute a constrained command and return output | `command` | base64 text output | Yes | Yes | status fallback, content helpers, provisioning, misc admin tools | Must never receive raw customer shell input |
| `sudo_exec` | Execute privileged command | `command` | base64 text output | Yes | Yes | advanced admin tooling | Highest-risk command; admin-only |
| `shell_action` | Structured shell action abstraction | `action`, `arguments` | array or text | Yes | Yes | newer advanced operations | Safer than raw shell when used correctly |
| `rebootnow` | Reboot remote host | none | status | Yes | Yes | `server/reboot.php` | Node-level destructive action |
| `what_os` | Return agent OS identifier | none | OS string | Yes | Yes | provisioning, workshop script selection, path handling | Read-only |
| `agent_version` | Return structured OS/version/install path | none | structured hash | Yes | Yes | `server/agent_interface.php`, cached agent summary | Read-only |
| `agent_resource_snapshot` | Return structured machine resource usage | none | structured hash with CPU/RAM/disk/load/version/OS | Yes | Yes | `server/agent_interface.php`, cached agent summary | Read-only; Panel caches results |
| `discover_ips` | Return agent IP list | marker arg | CSV / array | Yes | Yes | server provisioning | Read-only |
| `mon_stats` | Return agent monitoring stats | marker arg | base64 stats text | Yes | Yes | `server/mon_stats.php`, `status` | Read-only system metrics |

## FTP And Fast Download Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `ftp_mgr` | Manage FTP account state | `action`, `login`, `password`, `home_path` | text/status | Yes | Yes | `ftp/ftp_admin.php`, provisioning | Touches service credentials and user state |
| `stop_fastdl` | Stop FastDL service | none | status | Yes | Yes | `fast_download` admin UI | Node service control |
| `restart_fastdl` | Restart FastDL service | none | status | Yes | Yes | `fast_download` admin UI | Node service control |
| `fastdl_status` | Check FastDL status | none | status | Yes | Yes | `fast_download` | Read-only |
| `fastdl_get_aliases` | List FastDL aliases | none | alias list | Yes | Yes | `fast_download` | Read-only |
| `fastdl_add_alias` | Add FastDL alias | alias args | status | Yes | Yes | `fast_download` | Writes service config |
| `fastdl_del_alias` | Delete FastDL alias | alias list | status | Yes | Yes | `fast_download` | Writes service config |
| `fastdl_get_info` | Return FastDL config info | none | info payload | Yes | Yes | `fast_download` | Read-only |
| `fastdl_create_config` | Build FastDL config | bind/config args | status | Yes | Yes | `fast_download` | Writes service config |

## Scheduler Commands

| Agent Command | Purpose | Key Arguments | Returns | Windows | Linux | Panel Callers | Security Notes |
|---|---|---|---|---|---|---|---|
| `scheduler_add_task` | Add cron entry | `job` | status | Yes | Yes | `cron/cron.php`, `cron/user_cron.php` | Agent executes stored commands locally |
| `scheduler_edit_task` | Modify cron entry | `job_id`, `job` | status | Yes | Yes | `cron/cron.php`, token refresh helpers | Same risks as add |
| `scheduler_del_task` | Remove cron entry | `job_id` or comma list | status | Yes | Yes | `cron/cron.php`, cleanup helpers | Destructive |
| `scheduler_list_tasks` | Return cron table | none | map of job IDs to cron lines | Yes | Yes | `cron/shared_cron_functions.php`, `cron/events.php` | Read-only |

Important implementation note:

- the Panel scheduler stores intent in the UI
- the agent owns execution timing and the actual cron entries
- many agent cron commands are `wget` calls back into `gsp_api.php`

## Internal Agent Scheduler Actions

These are not XML-RPC command names, but they are part of the runtime architecture inside both agents:

- `scheduler_dispatcher`
- `scheduler_server_action`
- `scheduler_log_events`
- `scheduler_read_tasks`
- `scheduler_stop`

The built-in action names handled by the Panel-generated API URLs are:

- `start`
- `stop`
- `restart`
- `steam_auto_update`
- `server_content_check_updates`
- `server_content_check_workshop_updates`
- `server_content_install_updates_if_stopped`
- `server_content_install_updates_next_restart`
- `server_content_install_updates_now`
- `server_content_install_updates_and_restart`
- `server_content_notify_updates_only`
- `server_content_update_all`
- `server_content_validate_files`
- `server_content_backup_before_update`
- `workshop_update`
- `workshop_update_and_restart`
- `workshop_download_only`
- `workshop_install_pending_on_restart`

## Panel Wrapper Map

Primary wrapper file:

- `includes/lib_remote.php`

Notable wrapper methods:

| Wrapper Method | XML-RPC Command |
|---|---|
| `status_chk()` | `quick_chk` |
| `remote_server_status()` | `server_status` |
| `remote_force_stop_server()` | `force_stop_server` |
| `universal_start()` | `universal_start` |
| `remote_stop_server()` | `stop_server` |
| `remote_restart_server()` | `restart_server` |
| `remote_send_rcon_command()` | `send_rcon_command` |
| `remote_readfile()` | `readfile` |
| `remote_writefile()` | `writefile` |
| `remote_query()` | `remote_query` |
| `scheduler_add_task()` | `scheduler_add_task` |
| `scheduler_edit_task()` | `scheduler_edit_task` |
| `scheduler_del_task()` | `scheduler_del_task` |
| `scheduler_list_tasks()` | `scheduler_list_tasks` |
| `component_update()` | `component_update` |
| `agent_self_update()` | `agent_self_update` |
| `agent_update_status()` | `agent_update_status` |
| `agent_resource_snapshot()` | `agent_resource_snapshot` |
| `agent_version()` | `agent_version` |

Lifecycle wrappers also expose structured-result variants:

- `universal_start_result()`
- `remote_stop_server_result()`
- `remote_restart_server_result()`

The older wrapper method names still return legacy scalar values for older
callers, but maintained lifecycle callers use the structured-result variants
and append the JSON lifecycle payload after the legacy positional arguments.
The exact `gse.lifecycle.v2` handoff fields are documented in
[`LIFECYCLE_AGENT_HANDOFF.md`](LIFECYCLE_AGENT_HANDOFF.md).
| `steam_workshop()` | `steam_workshop` |

## Queued Agent Update Flow

The Panel per-agent update action uses this order:

1. Build an allowlisted `agent_self_update` payload from Panel settings.
2. Send `agent_self_update` to the target agent.
3. If queueing succeeds, send `agent_restart` to the same agent.
4. The Linux `gsp_agent_run` or Windows `GSP/agent_launch.sh` startup wrapper applies the queued request when `agent_auto_update=1`.
5. The Panel reads `agent_update_status` to show `pending`, `running`, `completed`, `failed`, or `rolled_back`.

If queueing fails, the Panel leaves the agent running and does not request a restart.

## Search Coverage Used For This Document

Commands were confirmed from:

- `rg -n "methods =>" Agent_Linux Agent-Windows`
- `rg -n "^sub .*without_decrypt|^sub component_update|^sub remote_query|^sub steam_workshop|^sub scheduler_" Agent_Linux/gsp_agent.pl Agent-Windows/GSP/gsp_agent.pl`
- `rg -n "public function .*\\(" includes/lib_remote.php`
