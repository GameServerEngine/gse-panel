# Task

## User Request

Create a durable project planning / todo system across the related GSE repositories so future DEV+1 questions like "what are we planning for GSE?" or "continue the LGSL removal work" can be answered by inspecting repository files instead of chat history. Start by creating the LGSL/GameQ/protocol-removal plan and link it across Panel, Linux agent, and Windows agent. This is documentation/planning only; do not remove LGSL/GameQ code yet.

## Problem

Cross-project plans were only recoverable from chat history and prior codex-history records. The LGSL/GameQ/protocol-removal work also needed guardrails to prevent repeating the previous monitor cleanup regression where query detail removal affected lifecycle controls.

## Root Cause

The repositories had durable completed-work history under `docs/codex-history/`, but no forward-looking `docs/plans/` system. Prior monitor history showed query details and lifecycle controls were coupled in parts of the Panel render path.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`
- `/home/dev/Projects/GSE-Agent-Linux`
- `/home/dev/Projects/GSE-Agent-Windows`

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/features/STATUS_SYSTEM.md`
- `docs/features/XML_SYSTEM.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/architecture/PANEL_AGENT_COMMANDS.md`
- prior Panel codex-history records for lifecycle display cleanup, query ports, fast status lights, single monitor controls, restored monitor structure, game-server log endpoint, and installed server content lifecycle
- representative Panel runtime files: `home.php`, `includes/lib_remote.php`, `modules/gamemanager/*`, `modules/dashboard/query_ref.php`, `modules/settings/settings.php`, `modules/config_games/schema_server_config.xml`, `modules/config_games/xml_config_creator.php`
- Linux agent `README.md`, `AGENTS.md`, `docs/PANEL_INTEGRATION.md`, `docs/AGENT_ACTIVITY_EVENTS.md`, `documentation/agent-guide.md`, and `gsp_agent.pl`
- Windows agent `README.md`, `AGENTS.md`, `docs/AGENT_ARCHITECTURE.md`, `docs/CYGWIN_INTEGRATION.md`, `docs/COMMAND_EXECUTION.md`, `docs/PROCESS_MANAGEMENT.md`, `docs/PANEL_INTEGRATION.md`, `docs/WINDOWS_OPERATIONS.md`, and `AgentScripts/gse_agent.pl`

## Files Changed

- `docs/plans/README.md`
- `docs/plans/ACTIVE.md`
- `docs/plans/BACKLOG.md`
- `docs/plans/lgsl-gameq-protocol-removal/PLAN.md`
- `docs/plans/lgsl-gameq-protocol-removal/INVENTORY.md`
- `docs/plans/lgsl-gameq-protocol-removal/IMPACT_ANALYSIS.md`
- `docs/plans/lgsl-gameq-protocol-removal/IMPLEMENTATION.md`
- `docs/plans/lgsl-gameq-protocol-removal/VALIDATION.md`
- `docs/codex-history/2026/2026-07-16-0706-gse-planning-system-lgsl-plan.md`

## Architecture Decisions

- Make Panel the primary plan owner.
- Link Linux and Windows agent plans back to the Panel-owned detailed plan.
- Treat `server_status` as the authoritative future status path and LGSL/GameQ as removable public query metadata.
- Explicitly preserve lifecycle controls independently from query support.
- Retain `protocol/` unless a later implementation proves individual files are obsolete.

## Validation Performed

- Verified there was no existing `docs/plans/` convention in the three repositories.
- Performed read-only searches for LGSL, GameQ, query protocol, XML protocol tags, player/map/stat query code, heartbeat/status, and lifecycle controls.
- Confirmed this task changed documentation/history files only.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

Pending validation

Hash

Pending validation

## Remaining Work

- Implement LGSL/GameQ/protocol removal in a future task using the staged plan.
- Add tests before removing query UI or runtime branches.
