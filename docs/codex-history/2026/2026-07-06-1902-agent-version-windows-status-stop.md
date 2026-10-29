# Task

## User Request

Make one focused implementation pass to improve GSP agent version reporting and Windows status/stop reliability without redesigning the RPC surface or committing changes.

## Problem

Agents reported the legacy `v1.4` config version. Windows server status could show offline while the assigned game port was live, Start could be offered for an already-running server, Stop could race the autorestart wrapper, and stale PID/hint state could keep status from reflecting live process/port truth.

## Root Cause

Both agents used `%Cfg::Config{version}` as the reported version, so old configs kept surfacing old OGP-era values. Windows status trusted managed screen/PID metadata too much and did not return enough diagnostics. The Windows autorestart batch loop only checked STOPPING/STOPPED after the game process exited, which left timing windows around launch and restart.

## Repositories Inspected

- Panel
- GSP-Agent-Windows
- GSP-Agent-Linux

## Files Read

- Panel/README.md
- Panel/docs/ARCHITECTURE.md
- Panel/docs/PANEL_ARCHITECTURE.md
- Panel/docs/AGENT_COMMUNICATION.md
- Panel/docs/REPOSITORY_STRUCTURE.md
- Panel/docs/COMMAND_FLOW.md
- Panel/docs/UPDATE_POLICY.md
- GSP-Agent-Linux/README.md
- GSP-Agent-Linux/docs/PANEL_INTEGRATION.md
- GSP-Agent-Windows/README.md
- GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md
- GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md
- GSP-Agent-Windows/docs/COMMAND_EXECUTION.md
- GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md
- GSP-Agent-Windows/docs/PANEL_INTEGRATION.md
- GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl
- GSP-Agent-Linux/gsp_agent.pl
- Panel/Panel/includes/lib_remote.php
- Panel/Panel/modules/gamemanager/home_handling_functions.php
- Panel/Panel/modules/gamemanager/server_monitor.php
- Panel/Panel/modules/server/agent_interface.php
- Panel/Panel/modules/server/servers.php

Missing documented Linux entry-point files:

- GSP-Agent-Linux/docs/AGENT_ARCHITECTURE.md
- GSP-Agent-Linux/docs/COMMAND_EXECUTION.md
- GSP-Agent-Linux/docs/PROCESS_MANAGEMENT.md

## Files Changed

- GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl
- GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md
- GSP-Agent-Windows/docs/PANEL_INTEGRATION.md
- GSP-Agent-Linux/gsp_agent.pl
- GSP-Agent-Linux/docs/PANEL_INTEGRATION.md
- Panel/Panel/includes/lib_remote.php
- Panel/Panel/modules/gamemanager/home_handling_functions.php
- Panel/Panel/modules/gamemanager/server_monitor.php
- Panel/Panel/modules/server/agent_interface.php
- Panel/docs/AGENT_COMMUNICATION.md
- Panel/docs/codex-history/2026/2026-07-06-1902-agent-version-windows-status-stop.md

## Architecture Decisions

- Preserve existing XML-RPC names and add diagnostic fields to `server_status` instead of changing the RPC contract.
- Report code-level agent versions from constants so legacy config `version` remains harmless.
- Treat Windows game-port listening as live runtime truth; return `WARNING` with diagnostics when the port is live but management metadata is missing.
- Do not report running solely from stale PID metadata.
- Use STOPPING/STOPPED status hints as the stop sentinel checked throughout the Windows autorestart wrapper loop.
- Treat `WARNING` as active in the Panel so Start is not offered for an already-bound game port.

## Validation Performed

- `perl -I GSP-Agent-Linux -c GSP-Agent-Linux/gsp_agent.pl` passed. Perl emitted the existing warning `Name "DBI::errstr" used only once`.
- `php -l` passed for changed Panel PHP files.
- Windows static check confirmed `pidfile=s` is still accepted.
- Windows static check confirmed `AGENT_VERSION` is sourced from `AGENT_CODE_VERSION` and used by startup log, `agent_version`, and `agent_resource_snapshot`.
- Windows static check confirmed `server_status_without_decrypt` returns `status_source`, `hint_state`, `hint_age_seconds`, `autorestart_enabled`, and `pid_metadata_present`.
- `git diff --check` passed in Panel, GSP-Agent-Windows, and GSP-Agent-Linux.
- Extra Windows `perl -c` was attempted but could not run on this Linux checkout because runtime `Cfg/Config.pm` is not present; only `Cfg/Config.pm.default` exists.

## Git Commits

Repository

Commit

Hash

No commits created. User requested review before commit/push.

## Remaining Work

Review the uncommitted diff, then commit/push if approved.
