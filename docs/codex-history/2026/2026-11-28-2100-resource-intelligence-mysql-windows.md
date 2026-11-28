# Task
Continue Resource Intelligence only: real-MySQL tests, Windows telemetry parity, handoff.

## User Request
Fix PlacementFlowTest temporary-table self join with unique InnoDB fixture tables, add concurrent reservation and migration replay tests, run on the isolated container if possible, implement Windows telemetry parity, run what can run, update the feature record, report remaining privileged commands.

## Problem
Real MySQL cannot reopen a TEMPORARY table twice in one query; the placement SQL joins billing_locations to itself. The Windows agent had no per-server resource telemetry.

## Root Cause
The test fixture used TEMPORARY tables. Windows status_sample never emitted usage.<id>, thread or process-identity data.

## Repositories Inspected
gse-panel, gse-agent-linux (read only), gse-agent-windows.

## Files Read
AGENTS.md (both levels), CLAUDE.md, docs/TODO.md, WORK_STATUS.md, the feature record, placement_store.php, migration_runner.php, resource_model.php, Linux and Windows agent_core.pl status code, both PANEL_INTEGRATION.md files.

## Files Changed
Panel: modules/billing/tests/placement_mysql.inc, PlacementFlowTest.php, PlacementConcurrencyTest.php, modules/update/tests/migration_replay_mysql_test.php, docs. Windows: AgentScripts/agent_core.pl, docs/PANEL_INTEGRATION.md, docs/TODO.md, tests/status_telemetry_windows_test.pl.

## Architecture Decisions
Normal InnoDB tables with a unique prefix and name-guarded databases. Windows reports no process I/O and no resource_host until limits and volume semantics are decided, so it fails closed.

## Validation Performed
See the feature record checkpoints. Passed: SQLite flow test, Windows fixture tests (26), Linux main-thread test, panel resource/usage/billing tests, node view test. Not run: MySQL tests, Windows PowerShell commands, browser.

## Git Commits
None. Validation is incomplete, so the work is left uncommitted.

## Remaining Work
Privileged docker commands and MySQL runs; Windows host run; benchmark calibration; browser test; Windows resource_host.
