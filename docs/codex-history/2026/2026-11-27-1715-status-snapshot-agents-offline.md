# Task
End-to-end check of the panel, agents and game servers after the 2026-11-26 changes, and fix what is not right.

## User Request
Validate that the agents and panel are up to date, that all the pieces and the game servers are running, and fix anything that is not perfect.

## Problem
The public status snapshot (refresh_status_snapshot.php / billing_status_collect) reported 0 agents and every game server Offline, while all agents were up and six game servers were running.

## Root Cause
1. The agent query had `WHERE rs.enabled = 1`. The live remote_servers table has no `enabled` column (add_remote_server_enabled_column.sql says the billing module no longer uses it), so the query failed and returned no agents.
2. An agent counted as online only when agent_resource_snapshots was under 3 minutes old. Those rows are only written when an admin opens a server page, so with no admin activity every agent looked down.

## Repositories Inspected
gse-panel, gse-agent-linux, gse-agent-windows (versions on working, testing and stable).

## Files Read
modules/billing/includes/status_collect.php, status_snapshot.php, tools/refresh_status_snapshot.php, module.php, add_remote_server_enabled_column.sql, server_status.php; modules/status_history/poller.php, includes/status_view.php, includes/status_history_lib.php; install/gse_panel.sql.

## Files Changed
modules/billing/includes/status_collect.php, CHANGELOG.md, docs/TODO.md.

## Architecture Decisions
Online is taken from the status poller's newest status_sample (status_code ok, younger than 2 x agents x interval + 60 s, the same rule as sv_effective_status). The cached resource snapshot stays as the fallback when status history has no samples. status_history_lib.php is loaded only if present, so billing still works without that module.

## Validation Performed
php -l. Dry run of refresh_status_snapshot.php with the new file against the live database from a temporary copy: 4 agents online, homes 49, 50, 51, 52, 951, 952 running, 953 and 954 stopped, which matches the processes on game3, game5, game6 and core.
Live database: added remote_servers.enabled INT NOT NULL DEFAULT 1 (matches install/gse_panel.sql), table backed up first to /home/leroy/panel-db-backups on core.

Check results: panel live files match testing 2.28.7; Linux agents on game5, game6 and the demo agent run 1.8.4 and the Windows agent on game3 runs 1.8.5, the testing versions.

## Git Commits
See the commit that adds this file.
