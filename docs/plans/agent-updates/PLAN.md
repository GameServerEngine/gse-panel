# Agent Updates From the Panel

Status: decided, not started. Decisions dated 2026-10-20.

## Goal
From the Remote Servers page, update one agent, several, or all, with a select-all checkbox and
per-agent deselect.

## Decisions
- The Remote Servers page gets a select-all checkbox at the top. Every agent row has its own
  checkbox, and any row can be unchecked.
- Selected agents update one at a time, in order. Each row shows its own result.
- An agent update uses the same single restore point rule as the panel. The restore point is
  the agent files, plus a database backup taken from the panel when the update starts. Agents do
  not have their own database, so the database backup is a safeguard in case an agent update
  needs a panel-side change.
- Before each agent update, the previous agent files are saved as that agent's one restore point,
  replacing the one before it.

## Flow per agent
1. Check the agent is reachable and reports its current version.
2. Save the previous agent files as the agent's restore point.
3. Queue the update through the existing agent update mechanism.
4. Restart the agent as a service (Windows) or systemd unit (Linux).
5. Confirm the new version and the agent's health.
6. On failure: restore the agent files from the restore point and report.

## Open items
- How to show progress when several agents update: one row per agent, with status.
- Whether to stop the whole batch on the first failure or continue with the rest.
