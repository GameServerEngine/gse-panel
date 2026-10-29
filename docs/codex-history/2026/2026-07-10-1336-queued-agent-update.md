# Task

## User Request

Complete workflow `gsp-agent-modernization`, step 4 of 5. Implement consistent queued update request format/state for both agents using original OGP update-on-restart semantics as baseline. Running agents must only record pending update requests; startup wrappers apply pending updates on the next normal restart when enabled, preserve config/runtime/customer data, expose pending/running/completed/failed/rolled-back status, validate and roll back safely, and commit/push verified work to `origin/unstable`.

## Problem

The Panel agent interface called `agent_self_update`, `agent_update_status`, and `agent_resource_snapshot`, but `GSPRemoteLibrary` did not define the agent update/status wrappers. The Panel command docs still described detached self-update behavior.

## Root Cause

Panel-facing update UI and documentation had moved ahead of the remote library implementation. The agent update defaults also still assumed older monorepo source paths instead of the separate Linux and Windows agent repositories.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`
- `/home/dev/Projects/GSP/references/OGP`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `/home/dev/Projects/GSP/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/Panel/modules/server/agent_helpers.php`
- `/home/dev/Projects/GSP/Panel/modules/server/agent_interface.php`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/gsp_agent_run`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP/gsp_agent.pl`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/GSP/agent_launch.sh`
- `/home/dev/Projects/GSP/references/OGP/OGP-Agent-Linux/ogp_agent_run`

## Files Changed

- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `/home/dev/Projects/GSP/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/Panel/modules/server/agent_helpers.php`

## Architecture Decisions

- Preserve the Panel command name `agent_self_update`; the semantic change is agent-side queuing and startup-wrapper application.
- Add remote wrappers for `agent_self_update`, `agent_update_status`, and `agent_resource_snapshot` using the same structured response decoder as component updates.
- Update default queued update source paths to the split repositories: Linux source `.` and Windows source `GSP`.
- Document the state contract `pending`, `running`, `completed`, `failed`, and `rolled_back`.

## Validation Performed

- `php -l includes/lib_remote.php`
- `php -l modules/server/agent_helpers.php`
- `git diff --check`
- Confirmed Linux and Windows wrapper simulations returned the same state contract for success, validation failure, disabled/pending, and rollback paths.

## Git Commits

Repository

`Panel`

Commit

`Wire panel queued agent updates`

Hash

`f3d16d9010821944ce1f25b18ca52c1554dc7b85`

## Remaining Work

- Panel UI polling can be improved in a later step to present queued update progress more prominently.
