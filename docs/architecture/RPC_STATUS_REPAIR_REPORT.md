# RPC Status Repair Report

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

This report documents the investigation and repair for the Panel message:

```text
Error occurred on the remote host. Agent status: UNKNOWN. Agent status RPC unavailable.
```

The scope of this pass was status/RPC communication only. Steam Workshop, updater, scheduler, and unrelated module behavior were intentionally left unchanged.

## Root Cause

The repository agents currently expose the structured `server_status` RPC on both Linux and Windows/Cygwin:

- Linux: `Agent_Linux/gsp_agent.pl`
- Windows/Cygwin: `Agent-Windows/GSP/gsp_agent.pl`

The Panel also contains a fallback path for older or stale live agents where `server_status` is unavailable:

1. call `remote_server_status()` / agent `server_status`
2. call `is_screen_running()`
3. call agent `exec()` with an `ss`/`netstat` port probe

The fallback port probe was malformed. It shell-quoted the port before interpolating it into the grep regex. For port `2302`, the Panel generated a pattern equivalent to:

```text
[:.]'2302'([[:space:]]|$)
```

Normal `ss` and `netstat` output contains `:2302` or `.2302`, not quotes around the port. Therefore the port fallback never matched listening game ports. If the live agent was stale, unreachable for `server_status`, or unable to report the screen session, the Panel had no working fallback and displayed `UNKNOWN`.

The fix builds the numeric listener regex directly and shell-quotes only the complete grep pattern:

```text
[:.]2302([[:space:]]|$)
```

## Status Flow

| Step | File / Function | RPC / Handler | Purpose | Expected result |
|---|---|---|---|---|
| 1 | `modules/gamemanager/server_monitor.php` | `get_agent_server_status()` | Game Monitor asks for current state | structured status array |
| 2 | `modules/gamemanager/start_server.php` | `get_agent_server_status(..., "STARTING")` | Show post-start state | `STARTING` or `ONLINE` |
| 3 | `modules/gamemanager/stop_server.php` | `get_agent_server_status(..., "STOPPING")` | Verify stop result | `STOPPING`, `OFFLINE`, or `ONLINE` if stop failed |
| 4 | `modules/gamemanager/restart_server.php` | `get_agent_server_status()` | Verify restart state | active state or offline |
| 5 | `modules/gamemanager/home_handling_functions.php` | `remote_server_status()` | Primary structured status check | agent `server_status` hash |
| 6 | `modules/gamemanager/home_handling_functions.php` | `is_screen_running()` | Fallback managed session check | `1`, `0`, or inconclusive |
| 7 | `modules/gamemanager/home_handling_functions.php` | `exec()` | Fallback agent-side game port probe | `GSP_PORT_LISTENING` or `GSP_PORT_CLOSED` |

## Status Decision Rules

| Evidence | Panel status |
|---|---|
| Agent unreachable or no reliable check can be completed | `UNKNOWN` |
| Agent says process/session exists | `ONLINE` |
| Agent says configured game port is listening | `ONLINE` |
| Fallback `is_screen_running` returns true | `ONLINE` |
| Fallback `exec` port probe returns `GSP_PORT_LISTENING` | `ONLINE` |
| Agent/fallbacks confirm no session and no listening port | `OFFLINE` |
| Game query fails but session/process/port is known | `ONLINE` with query metadata unavailable |

LGSL/GameQ/Steam query success is not a base online/offline signal. It is optional metadata.

## Panel RPC Wrappers

Source: `includes/lib_remote.php`.

| Panel wrapper | Agent RPC |
|---|---|
| `rfile_exists()` | `rfile_exists` |
| `status_chk()` | `quick_chk` |
| `get_log()` | `get_log` |
| `remote_stop_server()` | `stop_server` |
| `remote_send_rcon_command()` | `send_rcon_command` |
| `remote_readfile()` | `readfile` |
| `remote_writefile()` | `writefile` |
| `remote_rebootnow()` | `rebootnow` |
| `steam()` | `steam` |
| `steam_cmd()` | `steam_cmd` |
| `fetch_steam_version()` | `fetch_steam_version` |
| `installed_steam_version()` | `installed_steam_version` |
| `automatic_steam_update()` | `automatic_steam_update` |
| `masterServerUpdate()` | `master_server_update` |
| `game_update_active()` | `game_update_active` |
| `start_file_download()` | `start_file_download` |
| `is_file_download_in_progress()` | `is_file_download_in_progress` |
| `uncompress_file()` | `uncompress_file` |
| `remote_dirlist()` | `dirlist` |
| `remote_dirlistfm()` | `dirlistfm` |
| `cpu_count()` | `cpu_count` |
| `renice_process()` | `renice_process` |
| `universal_start()` | `universal_start` |
| `lock_additional_home_files()` | `lock_additional_files` |
| `what_os()` | `what_os` |
| `discover_ips()` | `discover_ips` |
| `is_screen_running()` | `is_screen_running` |
| `remote_server_status()` | `server_status` |
| `mon_stats()` | `mon_stats` |
| `clone_home()` | `clone_home` |
| `remove_home()` | `remove_home` |
| `remote_restart_server()` | `restart_server` |
| `sudo_exec()` | `sudo_exec` |
| `exec()` | `exec` |
| `secure_path()` | `secure_path` |
| `get_chattr()` | `get_chattr` |
| `ftp_mgr()` | `ftp_mgr` |
| `compress_files()` | `compress_files` |
| `stop_fastdl()` | `stop_fastdl` |
| `start_fastdl()` | `start_fastdl` |
| `restart_fastdl()` | `restart_fastdl` |
| `fastdl_status()` | `fastdl_status` |
| `fastdl_get_aliases()` | `fastdl_get_aliases` |
| `fastdl_add_alias()` | `fastdl_add_alias` |
| `fastdl_del_alias()` | `fastdl_del_alias` |
| `fastdl_get_info()` | `fastdl_get_info` |
| `fastdl_create_config()` | `fastdl_create_config` |
| `agent_restart()` | `agent_restart` |
| `component_update()` | `component_update` |
| `scheduler_list_tasks()` | `scheduler_list_tasks` |
| `scheduler_del_task()` | `scheduler_del_task` |
| `scheduler_add_task()` | `scheduler_add_task` |
| `scheduler_edit_task()` | `scheduler_edit_task` |
| `remote_get_file_part()` | `get_file_part` |
| `shell_action()` | `shell_action` |
| `stop_update()` | `stop_update` |
| `remote_query()` | `remote_query` |
| `send_steam_guard_code()` | `send_steam_guard_code` |
| `steam_workshop()` | `steam_workshop` |
| `get_workshop_mods_info()` | `get_workshop_mods_info` |

## Agent RPC Methods

Linux and Windows/Cygwin expose the same current status-critical methods:

| Agent RPC | Linux | Windows/Cygwin | Notes |
|---|---|---|---|
| `quick_chk` | Yes | Yes | Agent reachability / key check |
| `server_status` | Yes | Yes | Structured status hash |
| `is_screen_running` | Yes | Yes | Managed screen/session fallback |
| `exec` | Yes | Yes | Generic command execution, used for port fallback |
| `universal_start` | Yes | Yes | Start server |
| `stop_server` | Yes | Yes | Stop server |
| `restart_server` | Yes | Yes | Restart server |
| `get_log` | Yes | Yes | Log retrieval |

Linux also exposes `renice_process` and `lock_additional_files`, which are absent from Windows/Cygwin. Those are not involved in this status regression.

## Mismatches Found

| Mismatch | Impact |
|---|---|
| Documentation referenced `Agent-Windows/gsp_agent.pl`, but the current tracked file is `Agent-Windows/GSP/gsp_agent.pl`. | Confuses future debugging and validation. Documentation was updated. |
| Panel fallback port regex inserted a shell-quoted port into a grep regex. | Prevented `ss`/`netstat` fallback from detecting running servers after `server_status` RPC was unavailable. Fixed. |
| Some non-status wrappers in `lib_remote.php` reference legacy RPC names whose current support should be checked before future work, such as `steam` and `game_update_active`. | Out of scope for this status repair. |

## Files Changed

- `modules/gamemanager/home_handling_functions.php`
- `docs/features/STATUS_SYSTEM.md`
- `docs/modules/GAMEMANAGER.md`
- `docs/architecture/API_REFERENCE.md`
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/agents/WINDOWS_AGENT.md`
- `docs/architecture/RPC_STATUS_REPAIR_REPORT.md`

## Validation Commands

Run from repository root:

```bash
php -l modules/gamemanager/home_handling_functions.php
perl -c Agent_Linux/gsp_agent.pl
perl -c Agent-Windows/GSP/gsp_agent.pl
rg -n "p=\\$port_arg|\\[:\\.\\]\\$p|Agent-Windows/gsp_agent\\.pl" \
  modules/gamemanager/home_handling_functions.php \
  docs/features/STATUS_SYSTEM.md \
  docs/modules/GAMEMANAGER.md \
  docs/architecture/API_REFERENCE.md \
  docs/architecture/PANEL_AGENT_COMMANDS.md \
  docs/agents/WINDOWS_AGENT.md
```

Live validation still requires a configured remote host:

1. Start or identify a known running server.
2. Confirm the game port is listening on the agent host with `ss -lntu` or `netstat -an`.
3. Open Game Monitor.
4. Confirm the server displays green `ONLINE` when either the screen/session or port is detected.
5. Stop the server.
6. Confirm the server displays `OFFLINE` only after session/process and port are gone.

## Recommended Automated Tests

- Unit test `gsp_agent_port_listening()` command generation for port `2302`; it must produce a grep pattern matching `:2302` and not `:'2302'`.
- Mock `remote_server_status()` returning `UNKNOWN` and mock `exec()` returning `GSP_PORT_LISTENING`; `get_agent_server_status()` must return `ONLINE`.
- Mock `remote_server_status()` unavailable, `is_screen_running()` returning `1`; `get_agent_server_status()` must return `ONLINE`.
- Mock all fallbacks returning closed/false; `get_agent_server_status()` may return `OFFLINE`.
- Integration smoke test against Linux and Windows/Cygwin agents for `quick_chk`, `server_status`, `is_screen_running`, and `exec`.
