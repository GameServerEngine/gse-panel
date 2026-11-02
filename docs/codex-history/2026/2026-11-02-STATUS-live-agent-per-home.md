# Task

## User Request

Make the panel server list and game monitor show each home's live agent status rather than stale status_cache data; read the agent lifecycle fields, preserve the monitor job, validate, and commit on testing without pushing.

## Problem

The game monitor used legacy ONLINE/screen evidence for its green state. That could disagree with the agent's per-home status file, while status_cache contained old rows for unrelated ports.

## Root Cause

`server_monitor.php` converted agent responses into an `ONLINE`/screen-running decision and did not refresh status_cache during rendering. The cache API is keyed by ip_id and port and was not suitable as an authoritative display source.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`
- `/home/dev/Projects/GSE-Agent-Windows`

## Files Read

- `AGENTS.md`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/home_handling_functions.php`
- `includes/lib_remote.php`
- `includes/database_mysqli.php`
- `tests/status_sync_test.php`
- `docs/server-lifecycle.md`
- `AgentScripts/GameManager.pm`

## Files Changed

- `modules/gamemanager/server_monitor.php`
- This history record.

## Architecture Decisions

- The agent response is authoritative per home for display.
- Green requires status RUNNING plus process_running=1 and port_listening=1.
- Cache rows are refreshed for reachable and unreachable hosts but are never used to make a home green.
- Agent status RPC uses a five-second startup/status timeout for page rendering.

## Validation Performed

- `php -l modules/gamemanager/server_monitor.php`
- `php -l modules/gamemanager/home_handling_functions.php`
- `php tests/status_sync_test.php` — 6 passed.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` — passed.
- CLI render harness was attempted and removed; it could not run because devops has no panel database/config or live agents (`includes/config.inc.php` unavailable from the CLI context).

## Git Commits

Repository

Commit

Hash

## Remaining Work

Verify rendered status classes against a live panel database and agents, including homes 50 and 51 on core.
