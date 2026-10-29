# Task

## User Request

Complete workflow `windows-agent-modernization`, step 4 of 6: Complete queued update/restart workflow from Panel through agents, validate, commit and push.

## Problem

The Panel could queue an agent restart-time update through `agent_self_update`, but the per-agent update action stopped after queueing. The admin then had to separately trigger the agent restart that causes the Linux `gsp_agent_run` or Windows `GSP/agent_launch.sh` startup wrapper to apply the pending request.

## Root Cause

Earlier work had implemented the shared queued update/status RPCs and both agent startup wrappers, but the Panel per-agent update form still reflected the older detached self-update wording and did not chain the successful queue operation to the existing `agent_restart` RPC.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`
- `/home/dev/Projects/GSP/references/OGP` through existing divergence/history documentation only

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-10-1336-queued-agent-update.md`
- `/home/dev/Projects/GSP/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/Panel/modules/server/agent_helpers.php`
- `/home/dev/Projects/GSP/Panel/modules/server/agent_interface.php`
- `/home/dev/Projects/GSP/Panel/modules/server/restart.php`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent_run`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/codex-history/2026/2026-07-11-0005-agent-lifecycle-parity.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP/agent_launch.sh`

## Files Changed

- `/home/dev/Projects/GSP/Panel/modules/server/agent_interface.php`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-11-0011-queued-agent-update-restart.md`

## Architecture Decisions

- Keep `agent_self_update` as the durable queue operation.
- Request a normal `agent_restart` only after the queue operation succeeds.
- Leave failed queue attempts non-disruptive by not restarting the agent.
- Keep restart-time update application in agent startup wrappers, preserving the Panel/agent responsibility boundary.

## Validation Performed

- `php -l modules/server/agent_interface.php`
- `git diff --check`
- `bash -n gsp_agent_run`
- `perl -c gsp_agent.pl` in `GSP-Agent-Linux`
- `bash -n GSP/agent_launch.sh`
- `perl -c GSP/gsp_agent.pl` in `GSP-Agent-Windows` with temporary config files copied from tracked `.default` templates and removed after validation

## Git Commits

Repository

`Panel`

Commit

`Complete queued agent update restart`

Hash

`8c8a688c576c173cae4b4a5a1895c9f5f2f2810f`

## Remaining Work

- Runtime service restart validation should still be performed on installed Linux and Windows hosts because this workspace cannot exercise real remote agents.
