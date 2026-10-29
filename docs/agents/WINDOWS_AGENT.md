# Windows Agent

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

## Role

`Agent-Windows/GSP/gsp_agent.pl` is the Windows/Cygwin execution agent source path in the canonical GSE repository. It mirrors the Linux agent as closely as practical, while using Windows-compatible paths, processes, and wrappers.

## Important Files

- `Agent-Windows/GSP/gsp_agent.pl`
- `Agent-Windows/GSP/Cfg/Config.pm`
- `Agent-Windows/GSP/Cfg/Preferences.pm`
- `Agent-Windows/GSP/Cfg/bash_prefs.cfg`
- `Agent-Windows/GSP/Cfg/*.default`
- `Agent-Windows/GSP/agent_launch.sh`
- `Agent-Windows/Installer-Agent-Windows.cmd`
- `Agent-Windows/Installer-Agent-Windows.ps1`
- `Agent-Windows/agent_start.bat`
- `Agent-Windows/agent_stop.bat`
- `Agent-Windows/GSP/php-query/`
- `Agent-Windows/GSP/ArmaBE/`
- `Agent-Windows/GSP/ServerFiles/`
- `Agent-Windows/GSP/Schedule/`

## Cygwin Requirements

The Windows agent assumes a Cygwin-style environment that can provide:

- `screen`
- Perl
- shell utilities such as `ps`, `grep`, `cut`, `awk`, `sed`
- `cygpath`
- a usable `bash`

The goal is to keep the Windows agent behavior close to the Linux agent so the Panel does not need separate semantics for basic lifecycle operations.

## Startup Logic

Relevant functions:

- `universal_start_without_decrypt`
- `create_screen_cmd`
- `create_screen_cmd_loop`
- `replace_GSP_Env_Vars`

The Windows agent also uses `screen` sessions for managed server execution. Depending on the game and binary type, it may wrap commands in `cmd /Q /C start` or run a batch file wrapper.

The session naming scheme also follows the legacy `GSP` compatibility convention:

```text
GSP_HOME_000000123
```

### Windows Startup Launcher

Relevant files:

- `Agent-Windows/agent_start.bat`
- `Agent-Windows/agent_stop.bat`
- `/GSP/Cfg/bash_prefs.cfg` on an installed node

The foreground batch launcher must not assume that `bash` is in the Windows `PATH`. It checks the bundled repository-root runtime:

- `%~dp0bin\bash.exe`

The supported foreground troubleshooting launcher is `C:\GSP\Agent\agent_start.bat`. The Cygwin root is the folder containing that BAT file, so path checks stay simple and deterministic. Production service start, stop, and restart are managed through Windows Services.

Manual launches pause on fatal errors so the error remains visible.

The launcher prints the detected Cygwin root before validation, then keeps all startup output in the same console window. It does not spawn a second generic wrapper window.

The BAT launcher delegates shell work to `/GSP/agent_launch.sh foreground`:

1. enter `/GSP`
2. normalize CRLF to LF for `.pl`, `.pm`, `.sh`, and `.cfg` files under `/GSP`
3. create missing `Cfg/Config.pm`, `Cfg/Preferences.pm`, and `Cfg/bash_prefs.cfg` from tracked `.default` files
4. strip CRLF and leading whitespace before assignments in `/GSP/Cfg/bash_prefs.cfg`
5. reject placeholder config values
6. refuse startup if a valid agent PID file or the configured agent port indicates another instance
7. run `perl -c ./gsp_agent.pl` before launch
8. launch `/GSP/gsp_agent.pl` in the current desktop session

Default optional update source:

- repo: `https://git.runlevelsystems.com/GSE/Agent-Windows.git`
- branch: `unstable`
- source file: `GSP/gsp_agent.pl` in the current repo layout
- raw URL: `https://git.runlevelsystems.com/GSE/Agent-Windows/raw/branch/unstable/GSP/gsp_agent.pl`
- target file: `/GSP/gsp_agent.pl`

Auto-update failure is non-fatal. Missing `curl`/`git`, download failure, clone failure, missing source file, HTTP error-page downloads, empty files, or failed validation should warn and continue with the current installed agent.

Restart-time auto-update is allowlisted. It may replace only `/GSP/gsp_agent.pl`. It must never overwrite `Cfg/Config.pm`, `Cfg/Preferences.pm`, `Cfg/bash_prefs.cfg`, service settings, passwords, generated installer config, server homes, logs, or user-created files. Broader agent updates belong to the admin-only Panel `component_update` flow, which also preserves `Cfg/`.

### Startup Log And Troubleshooting

The launcher does not create a separate startup wrapper window. If startup fails, the real Perl or config error remains in the same console window before the pause prompt. This is the primary place to look for a bad config, missing package, or bad downloaded agent file.

Manual troubleshooting flow:

1. Launch `C:\GSP\Agent\agent_start.bat` directly from the Windows console or Explorer.
2. Read the path summary printed at the top of the window.
3. Inspect the same window for the real Perl/config error.
4. Check the current Cygwin output if you need more context.

### Auto-Update Allowlist

Restart-time auto-update must not touch production config files. Only the following file is allowed to be replaced automatically:

- `/GSP/gsp_agent.pl`

It must never overwrite:

- `Cfg/Config.pm`
- `Cfg/Preferences.pm`
- `Cfg/bash_prefs.cfg`
- service settings
- generated installer config
- server homes
- logs
- user-created files
- passwords or keys

### Windows User Account

The production service account is configured by `Installer-Agent-Windows.ps1`; `gameserver` is the default named account. Foreground mode runs in the current desktop user session and is only for troubleshooting.

The Windows agent file does not use `DBI` at startup. If a Windows node reports `Can't locate DBI.pm` at line 48, it is a strong signal that a Linux agent file was copied onto the Windows node.

Required Cygwin Perl packages include `perl`, `perl_vendor`, `perl-HTTP-Daemon`, `perl-Path-Class`, `perl-XML-Parser`, `perl-XML-Simple`, `perl-Archive-Zip`, and `perl-Archive-Extract`. A clean non-Cygwin Linux workstation may fail `perl -c` on these dependencies even though the bundled Windows Cygwin tree contains them.

Default tracked config template files contain placeholders only. Production installs must replace `CHANGE_ME_PANEL_AGENT_KEY`, `web_api_url`, and related values with the values configured in the Panel remote-server record. If startup creates `Config.pm` from `Config.pm.default`, it stops with a clear error until those values are changed.

## Status Logic

Relevant functions:

- `is_screen_running_without_decrypt`
- `get_screen_pid_without_decrypt`
- `server_status_without_decrypt`
- `verify_server_stopped_without_decrypt`

The status model should check:

- screen/session existence
- process/PID information when available
- game port listening
- optional query metadata

The old `SERVER_STOPPED` file should not be the source of truth.

If the game port is listening but the managed screen session is not found, the agent reports `ONLINE` with a warning. This protects customers from false offline/unknown states when a process survives a screen/session mismatch or an older agent cannot report the session correctly.

## Server Content Runtime

The Windows agent mirrors the Linux server-owned content runtime:

- `_gsp_content/hooks/`
- `_gsp_content/generated/`
- `_gsp_content/runtime/`
- `_gsp_content/runtime/server_content.pids`

Stop and restart clean this PID registry before main server escalation. Watchdog PIDs are stopped before app PIDs. Main game server PIDs remain in `runtime_status/pid-<home_id>.kv` and must not be written to `server_content.pids`.

Legacy `_alsoRun.bat` is compatibility-only. When `_alsoRun.pid` exists, the Windows agent imports those PIDs into the server-content cleanup path so helper processes do not survive Panel Stop or Restart.

## Logging

Relevant function:

- `get_log`

Windows/Cygwin logs come from screen logs and/or local copies. Log retrieval should remain compatible with the Panel's AJAX log view.

## Workshop / Server Content

The current customer-facing Workshop workflow is the dedicated Panel `steam_workshop` module. The older `steam_workshop` XML-RPC method is still used by that module for compatibility. For Windows/Cygwin servers the Panel:

1. writes `gsp_server_content/workshop_manifest.json` under the server home
2. writes a generated per-job shell script under `gsp_server_content/jobs/workshop/`
3. the generated job writes a temporary SteamCMD runscript and calls SteamCMD with `+runscript`
4. invokes the generated job script through the authenticated `exec` RPC

The generated job uses Python and SteamCMD, validates numeric Workshop IDs, keeps writes under the server home, logs to `gsp_server_content/workshop_install_windows.log`, and supports DayZ/Arma-style `@mod` folders plus `.bikey` copying. The Windows agent does not need a permanent `generic_steam_workshop_windows_cygwin.sh` file on disk.

For legacy `steam_workshop` RPC installs:

- blank `config_file_path` means no config-file editing
- the generated post-install script still runs
- `WorkshopModsInfo` is still written so uninstall can work without parsing a game config file

## Scheduler

Relevant functions:

- `scheduler_dispatcher`
- `scheduler_server_action`
- `scheduler_log_events`
- `scheduler_add_task`
- `scheduler_del_task`
- `scheduler_edit_task`
- `scheduler_read_tasks`
- `scheduler_stop`
- `scheduler_list_tasks`

The Windows scheduler implementation should remain aligned with the Linux scheduler implementation so the Panel can treat both the same way.

## Remote Git Self-Update

The Windows agent exposes the same admin-only `component_update` XML-RPC method as the Linux agent. In this repository the Windows agent is explicitly Cygwin-based, so the first implementation uses a Cygwin-compatible detached shell updater rather than a separate native PowerShell service wrapper.

Flow:

1. Panel sends an encrypted payload containing repo URL, branch, Windows agent source folder, optional Git path, optional backup path, and optional admin post-update command.
2. Agent validates the request and writes `gsp_component_update_<timestamp>.sh` under the current agent run directory, usually `/GSP`.
3. The updater runs detached in `screen`.
4. The updater clones the configured branch into staging.
5. It copies only the configured Windows agent source folder, usually `Agent-Windows`.
6. It preserves `Cfg/`, `ServerFiles/`, `Schedule/`, logs, screen logs, `steamcmd/`, `startups/`, temporary folders, backups, and PID files.
7. It validates `gsp_agent.pl` with `perl -c`.
8. It restarts the agent using the existing Cygwin/screen fallback.

The immediate response is `queued` with the agent-side log path `gsp_component_update.log`.

## Windows-Specific Notes

- Path conversion between Cygwin and native Windows paths matters during startup.
- Batch wrappers are often needed for Windows executables.
- Process cleanup must avoid killing unrelated processes that happen to share an executable name.
- The agent should continue to use `screen` where it already does so, to stay aligned with Linux behavior.
