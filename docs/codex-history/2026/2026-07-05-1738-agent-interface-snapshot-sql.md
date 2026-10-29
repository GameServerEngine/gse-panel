# Task

## User Request

Fix the Panel Agent Interface page so managed game servers load correctly and resource snapshot failures are visible. The requested scope is Panel only, focused on `getGameHomesByRemoteServerId()` SQL ambiguity and Agent Interface snapshot/resource display safety. Do not commit; validation and review will happen after Codex completes the patch.

## Problem

The Agent Interface page can show unknown or stale resource data and then report no managed game servers after MySQL raises `Column 'remote_server_id' in from clause is ambiguous`.

## Root Cause

`Panel/includes/database_mysqli.php::getGameHomesByRemoteServerId()` used `NATURAL JOIN` across tables that share `remote_server_id`, which can produce ambiguous column resolution on live MySQL. The snapshot UI also read some snapshot keys directly and did not surface cached failure details from `agent_resource_snapshot()` clearly.

## Repositories Inspected

- `Panel`
- `GSP-Agent-Linux` for response shape only
- `GSP-Agent-Windows` for response shape only

## Files Read

- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `Panel/includes/database_mysqli.php`
- `Panel/includes/lib_remote.php`
- `Panel/modules/server/agent_helpers.php`
- `Panel/modules/server/agent_interface.php`
- `Panel/modules/server/servers.php`
- `Panel/panel.sql`
- `GSP-Agent-Linux/gsp_agent.pl`
- `GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`

## Files Changed

- `Panel/includes/database_mysqli.php`
- `Panel/modules/server/agent_helpers.php`
- `Panel/modules/server/agent_interface.php`
- `Panel/modules/server/servers.php`
- `docs/codex-history/2026/2026-07-05-1738-agent-interface-snapshot-sql.md`

## Architecture Decisions

- Kept this as a Panel-only change because the failing behavior is in Panel SQL and Panel rendering.
- Replaced `NATURAL JOIN` in `getGameHomesByRemoteServerId()` with explicit table aliases and qualified join predicates.
- Preserved the existing row-per-home/IP-port/mod result shape by retaining the left joins to home IP ports, game mods, and config mods.
- Used decoded `raw_payload` as a safe display fallback for snapshot fields that are cached but not stored as first-class columns.
- Redacted diagnostic values whose key names indicate secrets before rendering raw payload details.

## Validation Performed

- `php -l Panel/includes/database_mysqli.php` passed.
- `php -l Panel/modules/server/agent_interface.php` passed.
- `php -l Panel/modules/server/agent_helpers.php` passed.
- `php -l Panel/modules/server/servers.php` passed.
- `git diff --check` passed.
- Manual grep of `getGameHomesByRemoteServerId()` found no `NATURAL JOIN`, no `SELECT *`, and no unqualified `WHERE remote_server_id =`.
- Positive manual check showed the function uses `WHERE sh.remote_server_id = %2$d`.

## Git Commits

Repository

Panel

Commit

Not committed per user request.

Hash

N/A

## Remaining Work

- Review and commit externally after verification.
