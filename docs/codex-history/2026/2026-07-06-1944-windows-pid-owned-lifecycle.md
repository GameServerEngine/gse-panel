# Task

## User Request

Implement the Windows PID-owned lifecycle model without returning to `SERVER_STOPPED` sentinel files. Track owning screen, wrapper/watchdog, game, port-owner, and sidecar/alsoRun PIDs; use live PID/session/port truth for status; make stop strict and fail if the game port remains live; make Panel stop verification fail active post-stop statuses; fix malformed startup flag parsing warnings; update documentation; run validation; do not commit inside Codex.

## Problem

Windows stop could return success while the Panel still observed `ONLINE` or `WARNING` because metadata was shallow or stale, child PIDs could be hidden behind `start /wait`, and the Panel treated an active follow-up status as informational.

## Root Cause

The Windows agent tracked only a small subset of runtime ownership (`screen_pid`, `windows_pid`, `game_pid`) and did not consistently merge sidecar, wrapper/watchdog, or current port-owner PIDs into the per-home registry. Status could not report complete live/stale PID diagnostics, and Panel stop handling printed success before enforcing the follow-up status result.

## Repositories Inspected

- `Panel`
- `GSP-Agent-Windows`
- `GSP-Agent-Linux`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`

## Files Changed

- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-06-1944-windows-pid-owned-lifecycle.md`

## Architecture Decisions

- Keep the PID registry as the existing per-home kv file and make parsing/writing stricter instead of adding a new dependency.
- Treat status hints as advisory only; live screen, PID, and port state decide runtime status.
- Treat sidecar-only liveness as `WARNING`, not `ONLINE`.
- Require Panel stop success to be verified `OFFLINE` before success messaging, audit logging, or firewall close behavior.
- Preserve compatibility fields such as `windows_pid` while adding `wrapper_pid`, `watchdog_pid`, `port_owner_pid`, `sidecar_pids`, `also_run_pids`, `tracked_pids`, and `stale_pids`.

## Validation Performed

- `perl -c GSP64/GSP/gsp_agent.pl` was attempted directly and failed because this checkout does not contain runtime `Cfg/Config.pm`; only `Cfg/Config.pm.default` is present.
- `perl -I/tmp/gsp-perl-stubs -c GSP64/GSP/gsp_agent.pl` passed using temporary config stubs copied from the default config files.
- `php -l Panel/modules/gamemanager/stop_server.php` passed.
- `php -l Panel/modules/gamemanager/home_handling_functions.php` passed.
- `php -l Panel/includes/lib_remote.php` passed.
- Verified `pidfile=s` remains supported.
- Verified new status/metadata fields appear in the Windows agent: `wrapper_pid`, `watchdog_pid`, `game_pid`, `port_owner_pid`, `sidecar_pids`, `also_run_pids`, `tracked_pids`, `stale_pids`, `status_source`, and `managed_process_running`.
- Verified `GSP64/GSP/gsp_agent.pl` has no `SERVER_STOPPED`/`Server_Stopped` code dependency.
- `git diff --check` passed in `GSP-Agent-Windows`.
- `git diff --check` passed in `Panel`.

## Git Commits

Repository

`Panel`

Commit

Not committed per explicit user instruction.

Hash

N/A

Repository

`GSP-Agent-Windows`

Commit

Not committed per explicit user instruction.

Hash

N/A

## Remaining Work

- GPT review, commit, and push.
