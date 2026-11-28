# Task
Resource Intelligence: safe crash recovery for migrations 0017/0018 and Windows resource_host telemetry.

## User Request
Continue ONLY GSE Resource Intelligence: implement safe crash recovery for 0017/0018 (verify exact column definitions, no blanket duplicate-error ignoring), complete Windows resource_host telemetry with unknowns failing closed, test fixtures, update docs and handoff, identify a safe dev Windows VM and execute only safe isolated tests, no production deploy, no unrelated commits.

## Problem
ALTER ... ADD COLUMN cannot be repeated after a crash between the ALTER and the schema_migrations insert. Windows hosts sent no resource_host, so they stayed closed.

## Root Cause
The runner treated every statement as all-or-nothing with no check of the live schema. The Windows agent had no host-level report (limits, volume window).

## Repositories Inspected
gse-panel, gse-agent-windows, gse-agent-linux (read only).

## Files Read
/home/dev/projects/AGENTS.md, panel AGENTS.md, WORK_STATUS.md, feature record, migrations 0017/0018, migration_runner.php, migration_replay_mysql_test.php, resource_model.php, Linux and Windows agent_core.pl status code, InventoryWin.pm, Windows tests and fixtures.

## Files Changed
Panel: modules/update/migration_runner.php, modules/update/tests/migration_runner_test.php, modules/update/tests/migration_replay_mysql_test.php, tests/resource_model_test.php, tests/fixtures/windows_resource_host.json, docs/TODO.md, docs/WORK_STATUS.md, docs/features/resource-intelligence.md. Windows: AgentScripts/agent_core.pl, tests/status_telemetry_windows_test.pl, docs/PANEL_INTEGRATION.md, docs/TODO.md.

## Architecture Decisions
Recovery only for ADD COLUMN statements with plain type and NULL/NOT NULL; exact match of type, nullability, no default, no extra, else fail. Windows limits state the VM-visible capacity and explicitly name what is not visible; they are in the fingerprint. No steal or process I/O invented. The report is dropped when a core fact is unknown.

## Validation Performed
Passed: migration_runner_test, update_safety_test, resource_model_test (incl. Windows report), game_usage_test, PlacementFlowTest (SQLite), Windows status_telemetry (all) and inventory tests. Not run: MySQL replay (Leroy and docker not permitted), any PowerShell on Windows, benchmark, browser. No dev Windows VM was identified.

## Git Commits
Repository: none. Commit: none. Hash: none. Validation incomplete; unrelated dirty work present.

## Remaining Work
Run extended MySQL replay; dev Windows VM status_sample run; calibration with owner approval; browser and provisioning tests; legacy 0005 replay issue (TODO).
