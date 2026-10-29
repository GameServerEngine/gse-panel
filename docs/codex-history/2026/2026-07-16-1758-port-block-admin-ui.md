# Task

## User Request

Add the next practical admin UI step for the GSE Panel global 10-port block allocator on branch `testing`, preserving Phase 1 allocator behavior and compatibility writes. Provide a dev-panel admin page to inspect and manage port pools, game mappings, game port offsets, static reservations, allocated blocks, and allocation events. Validate, commit as `feat: add port block administration UI`, push to `origin/testing`, deploy the validated files to the dev web panel folder, preserve deployed config/runtime data, apply the non-destructive Phase 1 migration to the dev database only if safe, and smoke test the web panel.

## Problem

Phase 1 added the global port allocation schema and allocator service, but the Panel had no admin UI for reviewing or safely managing the new tables during dev-panel testing.

## Root Cause

The Phase 1 implementation intentionally deferred admin screens. Operators could only inspect or modify the new pool, mapping, offset, reservation, block ledger, and event tables directly through SQL.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/COMMAND_FLOW.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/UPDATE_POLICY.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/modules/server.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/plans/global-port-allocation/PLAN.md`
- `docs/plans/global-port-allocation/CURRENT_SYSTEM.md`
- `docs/plans/global-port-allocation/DATABASE_DESIGN.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/PORT_POOLS.md`
- `docs/plans/global-port-allocation/STATIC_SERVICE_RESERVATIONS.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/codex-history/2026/2026-07-16-1618-global-port-block-allocation.md`
- `.devplus/codex-runs/gp-8/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-8/verification.md`
- `.devplus/codex-runs/gp-8/status.json`
- `includes/database.php`
- `includes/database_mysqli.php`
- `includes/helpers.php`
- `includes/lang.php`
- `includes/navig.php`
- `includes/port_allocator.php`
- `modules/modulemanager/module_handling.php`
- `modules/server/module.php`
- `modules/server/navigation.xml`
- `modules/server/servers.php`
- `modules/server/edit_server.php`
- `modules/server/arrange_ports.php`
- `modules/server/tests/port_allocator_test.php`
- `lang/English/modules/server.php`
- `panel.sql`
- `sql/003_global_port_allocation.sql`

## Files Changed

- `docs/codex-history/2026/2026-07-16-1758-port-block-admin-ui.md`
- `docs/modules/server.md`
- `lang/English/modules/server.php`
- `modules/server/edit_server.php`
- `modules/server/navigation.xml`
- `modules/server/port_blocks.php`
- `modules/server/servers.php`
- `modules/server/tests/port_blocks_validation_test.php`

## Architecture Decisions

- Keep the work Panel-only because port allocation policy and admin review belong to the control plane.
- Add a single admin route, `?m=server&p=port_blocks`, under the existing server module instead of replacing `arrange_ports`.
- Preserve Phase 1 allocator behavior, `gsp_home_ip_ports` compatibility writes, and the legacy `gsp_arrange_ports` fallback.
- Treat this as dev testing UI only; it does not run production backfill or destructive migration.
- Use the existing server module routing model and add an explicit admin check inside the page.
- Add page-local CSRF tokens because the older server module forms do not have a consistent CSRF helper.
- Check the Phase 1 tables before querying them and show a safe migration warning when missing.
- Prefer enabled/status updates over delete actions.
- Record simple `gsp_port_allocation_events` audit rows for admin creates/updates when the events table exists.

## Validation Performed

Baseline before editing:

- `php -l includes/port_allocator.php`
- `php -l includes/database_mysqli.php`
- `php -l modules/server/module.php`
- `php -l modules/server/arrange_ports.php`
- `php -l modules/server/tests/port_allocator_test.php`
- `php modules/server/tests/port_allocator_test.php`

After implementation:

- `php -l includes/port_allocator.php`
- `php -l includes/database_mysqli.php`
- `php -l modules/server/module.php`
- `php -l modules/server/arrange_ports.php`
- `php -l modules/server/port_blocks.php`
- `php -l modules/server/servers.php`
- `php -l modules/server/edit_server.php`
- `php -l lang/English/modules/server.php`
- `php -l modules/server/tests/port_allocator_test.php`
- `php -l modules/server/tests/port_blocks_validation_test.php`
- `php modules/server/tests/port_allocator_test.php`
- `php modules/server/tests/port_blocks_validation_test.php`
- `php -r` route check confirmed `port_blocks` is an admin-only server module page.
- `php -r` module check confirmed `db_version=3` and seven Phase 1 migration statements.
- `rg` checks confirmed the new route, explicit admin check, compatibility text, and arrange-ports preservation.
- `git diff --check`

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`feat: add port block administration UI`

Hash

Pending at history creation; recorded in the final task report after commit.

## Remaining Work

- Browser-test the page against the dev panel database after deployment.
- Import reviewed XML offsets into `gsp_game_port_offsets`.
- Add a safe preview/backfill workflow for existing `gsp_home_ip_ports` rows.
- Add richer capacity/conflict reporting after operators review the Phase 2 UI.
