# Scheduler v2: typed actions, permissions, run history

Status: design only. No runtime changes until approved.
Owner repository: GSE-Panel. Related: GSE-Agent-Linux, GSE-Agent-Windows.
Current behavior: docs/features/SCHEDULER_SYSTEM.md and SCHEDULER_ACTIONS.md.

## Problem
Today the Panel builds a cron expression plus a command string, usually a `wget`
call back into `gsp_api.php`, and the agent stores that string in its own cron.
That means:
- Schedules are text commands, so they are hard to validate, audit, or limit.
- The agent holds schedule state the Panel cannot fully see.
- There is no per-run history, so failures are invisible unless someone looks at logs.
- Customer and admin actions are not separated cleanly.

## Goals
1. Customers pick from a fixed catalog of typed actions. No raw commands, ever.
2. The Panel owns the schedule. The agent only executes "run this typed action now" requests.
3. Every run is recorded with status, timing, result, and a log reference.
4. Conflicts are handled by policy, not by luck.
5. Workshop and content automation goes through Server Content actions only. The
   `steam_workshop` module holds the Workshop configuration and the Server Content
   actions are the only way a schedule touches Workshop items.

## Core model
Task: owner_id, home_id, action_key, params (validated JSON), schedule (cron + timezone),
enabled, conflict_policy, next_run_at, last_run_at, last_status, created_by, created_at.

Run: run_id, task_id, trigger (schedule | manual | retry), started_at, finished_at,
status (queued | running | success | failed | skipped | cancelled), result (JSON, redacted),
log_ref, error_code.

Tables (new): gsp_scheduled_task, gsp_scheduled_task_run. Runs kept 90 days by default.

## Typed action catalog (customer-visible subset)
Each action declares: key, params schema, required server state, permission level,
agent call, and what it touches.

| Action key | Params | Required state | Permission | Agent call |
|---|---|---|---|---|
| server.start | none | stopped | customer | universal_start |
| server.stop | grace_seconds (0-300) | running | customer | stop_server |
| server.restart | grace_seconds (0-300) | any | customer | restart_server |
| server.backup | target (config or world) | any | customer | backup helper |
| workshop.check_updates | none | any | customer | server_content check |
| workshop.install_updates_if_stopped | none | stopped | customer | server_content conditional install |
| workshop.install_updates_and_restart | none | any | customer | server_content install + restart |
| workshop.install_updates_next_restart | none | any | customer | deferred marker |
| content.validate_files | none | stopped | customer | server_content validate |
| steam.update_game | branch (allowlisted) | stopped | customer | steam_cmd update |
| companion.restart | companion_key | running | customer | Server Bot content restart |
| admin.* | varies | varies | admin only | not exposed to customers |

Adding an action means adding a catalog entry, a params schema, and an agent handler.
There is no way to add an action from the UI.

## Conflict policy
Only one mutating action may run per server at a time. A per-server lock is held by the Panel.
Each task picks a policy when a run would overlap:
- skip: record a skipped run and move on (default for updates)
- queue: wait up to N minutes, then skip
- replace: cancel the running action only if it is a read-only check

Restart and stop always wait for an in-progress install to finish or time out.

## Failure handling
- Retry once after 60 seconds on agent-unreachable errors. Never retry a mutating
  action that may have partly run without a status check first.
- After 3 consecutive failures, disable the task and notify the owner.
- Every failure stores an error code and a short redacted message.

## Guardrails
- Minimum interval between runs of the same task: 15 minutes.
- Maximum tasks per server: 25 (configurable per plan).
- Params validated against the schema on save and again on run.
- The owner must own the server at save time and at run time.
- Logs and results are redacted: no passwords, keys, or tokens.
- Admin-only actions are never returned to the customer API.

## Migration
1. Keep the current cron tables read-only and mark them legacy.
2. Map each existing action key (from SCHEDULER_ACTIONS.md) to a v2 catalog entry.
   Entries with no clean mapping are listed for admin review, not auto-converted.
3. Turn off the agent-side cron entries per server after the Panel has taken over
   that server's schedule, one server at a time, with a rollback copy kept.
4. Remove the wget-to-API path once no legacy entries remain.

## Phases
- P0: this design, reviewed and approved.
- P1: tables, catalog, and read-only run history UI. No new execution paths.
- P2: Panel-owned execution through existing agent RPCs for start, stop, restart.
- P3: Workshop and content actions, then steam update.
- P4: retire legacy cron entries and the wget path.

## Open questions
- Should customers be allowed to schedule server.stop without a confirmation step?
- Notification channel for failures: email, ntfy topic per server, or both?
- Plan limits: how many tasks and how frequent by billing tier?

## Decisions (2026-10-19)
- Customers may schedule a stop without a confirmation step. It is still server-side guarded
  (ownership, per-server lock, minimum interval) and audited.
- Failure notifications go through ntfy (see below).
- Plan limits (defaults): 10 scheduled tasks per server on the base tier, 25 on higher tiers;
  minimum 15 minutes between runs.

## Notifications: ntfy (decided)
- One ntfy topic per game server ID (e.g. `gse-4`, `gse-6`, `gse-10`). Customers subscribe to
  their own server's topic from a download link in the Panel.
- Alert levels: expected events (start, stop, normal restart, scheduled task success) are low
  priority; crashes, give-ups, failed tasks, and other problems are high priority.
- An admin topic receives every event for every server.
- The ntfy server is installed by the Panel installer, as a container.
- Email stays available as a fallback channel, not the default.
