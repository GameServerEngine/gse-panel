# Task
Collect real usage of every running game server all the time, for the capacity data that decides whether an agent can take another game of a type. Track the Valheim test container on game5.

## User Request
Delete the old copy of the panel on core, do the next step to fix what is missing for game usage data, and track Valheim if possible.

## Problem
Status history kept machine totals and the five busiest processes per machine, not tied to a game server, so there was no record of how much one game uses. The Valheim test runs in a Docker container that is not a panel server.

## Root Cause
Neither agent reported usage per game server, and the panel had nowhere to keep it.

## Repositories Inspected
gse-panel, gse-agent-windows, gse-agent-linux.

## Files Read
Agents: status_sample, status_win_proc_snapshot / status_read_proc_ticks, GameManager read_state, gm_known_homes, GameManagerWin process_table, GSE/Container/Runtime.pm container_name. Panel: modules/status_history (poller.php, status_history_lib.php, status_view.php, README.md), sql/migrations/0009_status_history.sql, modules/update/migration_runner.php, modules/administration/resource_planner.php.

## Files Changed
Panel: sql/migrations/0013_game_usage.sql (new), modules/status_history/includes/game_usage_lib.php (new), modules/status_history/includes/status_history_lib.php, modules/status_history/poller.php, modules/administration/resource_planner.php, modules/status_history/README.md, tests/game_usage_test.php (new), CHANGELOG.md, docs/TODO.md.
Agents: agent_core.pl, CHANGELOG.md, docs/TODO.md, docs/PANEL_INTEGRATION.md in both.

## Architecture Decisions
- The existing status_sample call carries the data (home.<id> and container.<n> lines); no new RPC, no extra poll.
- Per server CPU and RAM come from the two process snapshots the sample already takes: game PID tree plus bot PID tree from the status files. Windows takes the snapshot from Win32_Process (about 0.4 s) to know parents.
- Folder size is measured by du in the background at most once an hour and cached in <home>/disk-usage, so the sample never waits for it.
- Linux finds container processes from their cgroup (docker-<id>.scope); a server's own container gse-h<id>-a<n> counts for that server, others are reported as containers. Valheim is tracked that way.
- CPU is stored as cores in use. Averages and peaks count running samples only. Same keep times as status history.
- Migration 0013 was applied by hand on core and recorded in schema_migrations, because 2026-11-08-remove-prefixes is also unrecorded there and must not run (logged in TODO).

## Validation Performed
tests/game_usage_test.php: 21 checks pass. php -l on every changed file.
game3 (harness with the new functions, running agent untouched): DayZ 52 at 12.41% CPU and 6.67 GB, Arma homes about 165 MB with BEC, folder sizes 5.8 to 38 GB after the background measurement; snapshot 0.36 s.
Demo agent on core: home 952 RUNNING 0.25% CPU, 100 MB, 1.49 GB disk; core's Docker containers listed by id.
game5: container valtest-leroy, 5.98% CPU, 1.45 GB, main process valheim_server.

## Git Commits
See the commits that add this file and the agent commits of the same day.
