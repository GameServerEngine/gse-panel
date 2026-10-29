# Task

## User Request

Build per-agent game-process operations page plus safe agent self-update with rollback.

## Problem

Admin -> Servers was the only agent management surface. It checked live agent details inline, could expose the full encryption key, and had no dedicated page for per-agent game-process operations, cached resource pressure warnings, hard force-stop, or full-agent self-update status/rollback.

## Root Cause

The Panel had remote wrappers for common lifecycle operations and agents already had much of the low-level PID/session/port stop logic, but there was no Panel route that scoped operations to a single agent and no structured RPC contract for force-stop, resource snapshots, or detached self-update state.

## Repositories Inspected

- Panel
- Panel.wiki
- GSP-Agent-Linux
- GSP-Agent-Windows

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/API_REFERENCE.md`
- `/home/dev/Projects/GSP/Panel/docs/features/STATUS_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/development/CODEX_GUIDE.md`
- `/home/dev/Projects/GSP/Panel.wiki/Remote-Servers-and-Agents.md`
- `/home/dev/Projects/GSP/Panel.wiki/Game-Server-Management.md`
- `/home/dev/Projects/GSP/Panel.wiki/Database.md`
- `/home/dev/Projects/GSP/Panel.wiki/Development.md`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/module.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/servers.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/navigation.xml`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/view_log.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/restart.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/reboot.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/mon_stats.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/database.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/database_mysqli.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/restart_server.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/view_server_log.php`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP64/GSP/documentation/agent-guide.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`

## Files Changed

- `/home/dev/Projects/GSP/Panel/Panel/includes/database.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/database_mysqli.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/module.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/navigation.xml`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/servers.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/agent_helpers.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/server/agent_interface.php`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel.wiki/Remote-Servers-and-Agents.md`
- `/home/dev/Projects/GSP/Panel.wiki/Game-Server-Management.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP64/GSP/documentation/agent-guide.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-05-1500-per-agent-operations-console-and-self-update.md`

## Architecture Decisions

- Admin -> Servers remains an agent list and does not render all game servers inline.
- A new admin-only server module route, `agent_interface`, owns per-agent process operations.
- Resource pressure is stored in `agent_resource_snapshots`; the main list reads cache and refreshes at most one stale/missing snapshot per page load.
- The Panel calls explicit RPC wrappers for force-stop, resource snapshot, version, self-update, and update status rather than exposing generic shell execution.
- Force Stop is separate from normal Stop/Restart and returns a structured verification result.
- Agent self-update runs in a detached updater script because the RPC process may stop during replacement.
- Self-update preserves local config, logs, server data, startup/runtime state, temp/shared directories, backup directories, and PID files.
- Rollback restores the backup and restarts the previous agent if the updated agent does not listen on its RPC port before timeout.

## Validation Performed

- `git status` and `git diff` were run in Panel, GSP-Agent-Linux, and GSP-Agent-Windows before modifications; all were clean.
- PHP lint passed for changed PHP files:
  - `Panel/Panel/modules/server/agent_helpers.php`
  - `Panel/Panel/modules/server/agent_interface.php`
  - `Panel/Panel/modules/server/servers.php`
  - `Panel/Panel/includes/lib_remote.php`
  - `Panel/Panel/includes/database.php`
  - `Panel/Panel/includes/database_mysqli.php`
  - `Panel/Panel/modules/server/module.php`
- Direct Perl checks were attempted:
  - `perl -c GSP-Agent-Linux/gsp_agent.pl` was blocked by missing bundled/runtime modules outside the agent runtime.
  - `perl -c GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl` was blocked by missing local Windows `Cfg/Config.pm` in this checkout.
- Perl syntax checks passed with temporary local stubs for missing deployment-only modules/config:
  - Linux `gsp_agent.pl syntax OK`
  - Windows `gsp_agent.pl syntax OK`

## Git Commits

Repository

Panel

Commit

Pending - not committed by Codex per current task instruction.

Hash

Pending

Repository

Panel.wiki

Commit

Pending - not committed by Codex per current task instruction.

Hash

Pending

Repository

GSP-Agent-Linux

Commit

Pending - not committed by Codex per current task instruction.

Hash

Pending

Repository

GSP-Agent-Windows

Commit

Pending - not committed by Codex per current task instruction.

Hash

Pending

## Remaining Work

- Exercise the UI against live Linux and Windows agents with real game servers.
- Add a first-class bulk/canary agent update page if bulk rollout is required in the next pass.
- Consider moving resource snapshot refresh to a true Panel-side cron job if a deployment has a suitable central scheduler entry point.
