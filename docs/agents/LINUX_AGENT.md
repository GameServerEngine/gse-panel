# Linux Agent

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

## Role

`Agent_Linux/gsp_agent.pl` is the Linux execution agent for GameServer Engine (GSE). It is responsible for:

- starting and stopping game servers
- managing `screen` sessions
- reading logs
- running update/install tasks
- handling scheduler jobs
- performing query checks and status checks

## Important Files

- `Agent_Linux/gsp_agent.pl`
- `Agent_Linux/startups/`
- `Agent_Linux/includes/`
- `Agent_Linux/php-query/`
- `Agent_Linux/Cfg/`
- `Agent_Linux/Schedule/`
- `Agent_Linux/steamcmd/`
- `Agent_Linux/systemd/`

## Startup Logic

The Linux agent creates a managed `screen` session per server.

Key flow in `gsp_agent.pl`:

- `universal_start_without_decrypt`
- `create_screen_cmd`
- `create_screen_cmd_loop`
- `replace_GSP_Env_Vars`

The agent builds the startup command from:

- the game XML template
- server parameters
- mod data
- control password
- path variables

The session name follows the legacy `GSP` compatibility naming convention, for example:

```text
GSP_HOME_000000123
```

## Status Logic

Relevant functions:

- `is_screen_running_without_decrypt`
- `get_screen_pid_without_decrypt`
- `server_status_without_decrypt`
- `verify_server_stopped_without_decrypt`

The status implementation should check:

- screen/session existence
- PID or child process information when available
- whether the game port is listening
- whether query metadata can be fetched

Marker files such as `SERVER_STOPPED` should not be treated as the final source of truth.

If the configured game port is listening but the managed screen/session is missing, the agent reports `ONLINE` with a warning in `last_error`. This mirrors the Windows agent and prevents a live game process from showing false offline/unknown solely because session tracking failed.

## Optional Resource Stats Database

Resource stats database submission uses Perl `DBI`, but `DBI` is optional for normal agent startup. The agent lazy-loads `DBI` only when resource stats are actually submitted. Missing `DBI.pm` should disable database stats submission with a readable log message, not abort the agent at startup.

## Logging

Relevant function:

- `get_log`

The agent reads screen logs and may also copy a local log file into the game home. Logs should be treated as runtime output, not as a state store.

## Workshop / Server Content

The current customer-facing Workshop workflow is the dedicated Panel `steam_workshop` module. The older `steam_workshop` XML-RPC method is still used by that module for compatibility. For Linux servers the Panel:

1. writes `gsp_server_content/workshop_manifest.json` under the server home
2. writes a generated per-job shell script under `gsp_server_content/jobs/workshop/`
3. the generated job writes a temporary SteamCMD runscript and calls SteamCMD with `+runscript`
4. invokes the generated job script through the authenticated `exec` RPC

The generated job uses Python and SteamCMD, validates numeric Workshop IDs, keeps writes under the server home, logs to `gsp_server_content/workshop_install.log`, and supports DayZ/Arma-style `@mod` folders plus `.bikey` copying. The Linux agent does not need a permanent `generic_steam_workshop_linux.sh` file on disk.

For legacy `steam_workshop` RPC installs:

- blank `config_file_path` means no config-file editing
- the generated post-install script still runs
- `WorkshopModsInfo` is still written so uninstall can work without parsing a game config file

## Scheduler

Linux scheduler functions live in `gsp_agent.pl`:

- `scheduler_dispatcher`
- `scheduler_server_action`
- `scheduler_log_events`
- `scheduler_add_task`
- `scheduler_del_task`
- `scheduler_edit_task`
- `scheduler_read_tasks`
- `scheduler_stop`
- `scheduler_list_tasks`

The scheduler is agent-driven. Panel pages create or edit jobs, but the agent executes them.

## Configuration Files

Useful configuration and runtime areas:

- `Agent_Linux/Cfg/`
- `Agent_Linux/startups/`
- `Agent_Linux/steamcmd/`
- `Agent_Linux/systemd/`

The agent also maintains screen logs and helper scripts inside its runtime area.

## Remote Git Self-Update

The Linux agent exposes the admin-only `component_update` XML-RPC method. The Panel update page uses this to queue a Git-based Linux agent update.

Flow:

1. Panel sends an encrypted payload containing repo URL, branch, source folder, optional Git path, optional backup path, and optional admin post-update command.
2. Agent validates the payload.
3. Agent writes `gsp_component_update_<timestamp>.sh` under the agent run directory.
4. The updater script runs detached in `screen`.
5. The script clones the configured branch into staging.
6. It copies only the configured Linux agent source folder, usually `Agent_Linux`.
7. It preserves `Cfg/`, `ServerFiles/`, `Schedule/`, logs, screen logs, `steamcmd/`, `startups/`, temporary folders, backups, and PID files.
8. It validates the updated `gsp_agent.pl` with `perl -c`.
9. It restarts `gsp_agent.service` through `systemd` if available, otherwise uses the existing `screen` startup fallback.

The agent returns `queued` immediately with the log path `gsp_component_update.log`. A queued response means the updater launched; check the log for final success/failure.

## Linux-Specific Notes

- The Linux agent uses `screen` and `sudo_exec_without_decrypt`.
- It can resolve server ownership and screen users via helper functions such as `find_user_by_screen_id`.
- It must remain portable across distro variants, so avoid assuming one exact init system or one exact binary path.
- For Windows-targeted games running under Linux, Wine-related path conversion appears in startup path handling.
