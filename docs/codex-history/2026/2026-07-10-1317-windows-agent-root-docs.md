# Task

## User Request

Complete workflow `gsp-agent-modernization`, step 3 of 5, including downstream Panel documentation updates for the Windows agent root-layout and service-mode contract.

## Problem

Panel documentation still referenced `Agent-Windows/GSP64/...`, `C:\GSP64`, scheduled-task startup, and legacy `/GSP/Install/agent_start.bat` troubleshooting paths after the Windows agent runtime was moved to repository root and service mode became the production default.

## Root Cause

The Panel docs described the previous Windows packaging layout and launcher behavior. Moving the Windows agent repository root to the bundled Cygwin root changed source paths and operational guidance, even though the XML-RPC contract remained unchanged.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`

## Files Read

- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/agents/WINDOWS_AGENT.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/*`

## Files Changed

- `docs/agents/WINDOWS_AGENT.md`
- `docs/architecture/API_REFERENCE.md`
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- `docs/architecture/REPOSITORY_OVERVIEW.md`
- `docs/architecture/RPC_STATUS_REPAIR_REPORT.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/features/COMPANION_PROGRAMS.md`
- `docs/features/INSTALLERS.md`
- `docs/features/LOGGING_SYSTEM.md`
- `docs/features/SCHEDULER_ACTIONS.md`
- `docs/features/SCHEDULER_SYSTEM.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/modules/GAMEMANAGER.md`
- `docs/modules/SCHEDULER.md`
- `docs/codex-history/2026/2026-07-10-1317-windows-agent-root-docs.md`

## Architecture Decisions

- Panel command and XML-RPC behavior did not change.
- Panel docs now refer to `GSP-Agent-Windows/GSP/gsp_agent.pl` for source paths.
- Production Windows lifecycle guidance now points to the `GSPAgent` Windows service.
- `agent_start.bat` is documented only as foreground troubleshooting.

## Validation Performed

- Active Panel docs scan passed for stale `GSP64`, `C:\GSP64`, and `Agent-Windows/GSP64` references outside codex history.
- Confirmed the unrelated local modification to `modules/administration/panel_update.php` was not staged or changed.

## Git Commits

Repository

`Panel`

Commit

`Document Windows agent root service layout`

Hash

Assigned after this history record is committed; see final report.

## Remaining Work

- None for Panel behavior in this step.
