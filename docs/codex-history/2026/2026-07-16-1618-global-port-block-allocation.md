# Task

## User Request

Implement the first production phase of the documented GSE Panel global port-block allocation system on a new `testing` branch, following `docs/plans/global-port-allocation/` as the source of truth. Add schema/migration changes, allocator logic, tests, documentation updates, validation, a focused commit named `feat: implement phase 1 global port block allocation`, and push `testing`.

## Problem

The Panel allocated primary ports through legacy `gsp_arrange_ports` ranges and stored only the selected primary/base port in `gsp_home_ip_ports`. It did not persist whole 10-port block ownership, static reservation conflicts, pool identity, or allocation source metadata.

## Root Cause

The inherited OGP-style allocation model stores range intent separately from assigned primary ports. The database had no authoritative block ledger and provisioning code had separate loops that could not enforce full-block overlap checks across billing and manual paths.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

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
- `docs/features/PROVISIONING.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/modules/server.md`
- `docs/plans/global-port-allocation/PLAN.md`
- `docs/plans/global-port-allocation/CURRENT_SYSTEM.md`
- `docs/plans/global-port-allocation/DATABASE_DESIGN.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/plans/global-port-allocation/PORT_POOLS.md`
- `docs/plans/global-port-allocation/STATIC_SERVICE_RESERVATIONS.md`
- `docs/plans/global-port-allocation/GAME_PORT_REVIEW_TABLE.md`
- `docs/codex-history/2026/2026-07-16-0838-global-port-allocation-plan.md`
- `docs/codex-history/2026/2026-07-16-0857-global-port-allocation-database-plan.md`
- `includes/database.php`
- `includes/database_mysqli.php`
- `includes/install_engine.php`
- `modules/modulemanager/module_handling.php`
- `modules/server/module.php`
- `modules/server/arrange_ports.php`
- `modules/billing/create_servers.php`
- `modules/user_games/edit_home.php`
- `modules/reseller/assign_server.php`
- `gsp_api.php`
- `protocol/TeamSpeak3/functions.php`
- `panel.sql`

## Files Changed

- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/modules/server.md`
- `docs/plans/global-port-allocation/DATABASE_DESIGN.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/codex-history/2026/2026-07-16-1618-global-port-block-allocation.md`
- `includes/database_mysqli.php`
- `includes/port_allocator.php`
- `modules/billing/create_servers.php`
- `modules/server/module.php`
- `modules/server/tests/port_allocator_test.php`
- `panel.sql`
- `sql/003_global_port_allocation.sql`

## Architecture Decisions

- Keep allocation policy in the Panel/control plane.
- Add the approved Phase 1 tables beside `gsp_arrange_ports` and `gsp_home_ip_ports`.
- Make `includes/port_allocator.php` the shared allocator for billing and compatibility writes.
- Preserve `gsp_home_ip_ports` as the lifecycle/display compatibility write.
- Preserve `gsp_arrange_ports` as a migration fallback and reserve at least 10 ports when it is used.
- Seed only the documented global pool catalog and controlled overflow range.
- Keep XML-driven runtime token generation unchanged in this phase.
- Do not add admin pool/reservation UI in this phase.

## Validation Performed

- `php -l includes/port_allocator.php`
- `php -l includes/database_mysqli.php`
- `php -l modules/server/module.php`
- `php -l modules/billing/create_servers.php`
- `php -l modules/server/tests/port_allocator_test.php`
- `php modules/server/tests/port_allocator_test.php`
- `php -r 'define("GSP_DB_PREFIX", "gsp_"); include "modules/server/module.php"; echo "db_version=$db_version\n"; echo "migration3=".count($install_queries[3])."\n";'`
- `rg` schema checks for all new allocation tables and `gse-overflow-32000`
- `git diff --check`

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`feat: implement phase 1 global port block allocation`

Hash

Recorded in final task report because this file is part of the commit.

## Remaining Work

- Add admin UI for pool/game/reservation management.
- Import reviewed XML offsets into `gsp_game_port_offsets`.
- Backfill existing `gsp_home_ip_ports` rows into `gsp_home_port_blocks` with conflict reports.
- Add production data migration dry-run tooling.
- Add game-to-pool mapping review workflow before disabling legacy fallback.
