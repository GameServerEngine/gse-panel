# Task

## User Request

Clean up the GSE Panel installer UI after live installer testing and simplify the global port allocation model. The installer recovery/admin form must stack cleanly on mobile, red text on dark installer backgrounds must be removed, gp-14 consolidated installer behavior must be preserved, and the port allocator must stop requiring manual Port Pools, Game Pool Mappings, and Game Port Offsets. The new allocator should use each game's default base port plus a global block size, skip reserved and occupied ports, preserve compatibility with existing homes, update the admin UI, validate the implementation, commit on `testing`, push to `origin/testing`, and deploy to `/var/www/html/panel-test` if possible.

## Problem

The installer was functionally successful but still had mobile readability and contrast issues in recovery/admin states. The global port allocation system required too much manual administration through port pools, game mappings, and offsets before an allocation could succeed.

## Root Cause

The installer recovery form reused the general grid layout instead of forcing the admin fields into a single mobile-safe column, and some installer error/status styles used dark red panels with foreground choices that could still read as red-on-dark. The allocator still treated pool mappings and offsets as the primary allocation model, so normal provisioning depended on manual configuration instead of deriving a default game base port and scanning for a free contiguous block.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/decisions/0006-installers.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/codex-history/2026/2026-07-16-1618-global-port-allocation-plan.md`
- `docs/codex-history/2026/2026-07-16-1758-global-port-allocation-implementation.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `docs/codex-history/2026/2026-07-16-1957-installer-repeatable-dev-install.md`
- `.devplus/codex-runs/gp-10*`
- `.devplus/codex-runs/gp-11*`
- `.devplus/codex-runs/gp-12*`
- `.devplus/codex-runs/gp-13*`
- `.devplus/codex-runs/gp-14*`
- `install.php`
- `includes/install_engine.php`
- `includes/port_allocator.php`
- `modules/server/port_blocks.php`
- `modules/billing/create_servers.php`
- `includes/database_mysqli.php`
- `modules/user_games/edit_home.php`
- `modules/reseller/assign_server.php`
- `gsp_api.php`
- `panel.sql`
- `sql/003_global_port_allocation.sql`
- `modules/server/tests/port_allocator_test.php`
- `modules/server/tests/port_blocks_validation_test.php`

## Files Changed

- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/modules/server.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `includes/install_engine.php`
- `includes/port_allocator.php`
- `install.php`
- `lang/English/modules/settings.php`
- `modules/server/module.php`
- `modules/server/port_blocks.php`
- `modules/server/tests/port_allocator_test.php`
- `modules/server/tests/port_blocks_validation_test.php`
- `modules/settings/settings.php`
- `sql/003_global_port_allocation.sql`
- `docs/codex-history/2026/2026-07-17-1453-simplify-port-allocation-installer-ui.md`

## Architecture Decisions

- Preserve the canonical consolidated installer flow and seed the global port block size through installer defaults and non-destructive upgrade SQL.
- Keep legacy port pool, game mapping, and offset tables for compatibility instead of dropping or migrating them destructively.
- Make automatic allocation the normal path: determine a game default base port, read global `port_block_size`, scan upward by one port for the first free contiguous block, and check allocated blocks, legacy home ports, and static reservations.
- Keep reserved/static ports, allocated blocks, and allocation events visible in the admin workflow while hiding manual pools, game mappings, and offsets from normal use.
- Use a fallback base port when game metadata cannot provide a default and record that fallback in allocation metadata.

## Validation Performed

- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l includes/port_allocator.php`
- `php -l modules/server/port_blocks.php`
- `php -l modules/server/module.php`
- `php -l modules/settings/settings.php`
- `php -l modules/billing/create_servers.php`
- `php -l includes/database_mysqli.php`
- `php -l modules/user_games/edit_home.php`
- `php -l modules/reseller/assign_server.php`
- `php -l gsp_api.php`
- `php -l modules/server/tests/port_allocator_test.php`
- `php -l modules/server/tests/port_blocks_validation_test.php`
- `php modules/server/tests/port_allocator_test.php`
- `php modules/server/tests/port_blocks_validation_test.php`
- `git diff --check`
- Static CSS inspection for installer red-on-dark text and recovery form stacking.
- Static UI inspection confirming Port Pools, Game Pool Mappings, and Game Port Offsets headings/forms are not rendered in the normal port allocation admin workflow.
- Static schema/settings inspection confirming `port_block_size` is seeded for fresh installer defaults and non-destructive upgrades.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`fix: simplify port allocation and installer UI`

Hash

Pending at record creation; final report records the pushed commit hash.

## Remaining Work

- Deploy the pushed `testing` branch to `/var/www/html/panel-test` if local permissions allow.
- Exercise a real Panel provisioning flow against a test database/agent to confirm runtime allocation metadata matches the lightweight allocator tests.
