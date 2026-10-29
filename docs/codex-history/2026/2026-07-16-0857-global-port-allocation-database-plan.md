# Task

## User Request

Extend the existing GSE global port allocation planning documents with a detailed database and current-allocation design. This is a planning/documentation task only. Accurately document that the current system already allocates from configured ranges/blocks through per-IP/game ranges and increments, distinguish that from full block persistence, propose future database/schema changes for global pools, game mappings, offsets, static reservations, overflow, and per-IP block tracking, validate that no runtime code/schema/XML behavior changed, commit the intended docs, and push to `origin/unstable`.

## Problem

The existing global port allocation plan did not contain a detailed database design and could be read as saying the current system has no range/block concept. The current Panel does have configured per-IP ranges and increments, but it records only selected primary/base ports per home.

## Root Cause

The legacy OGP-style design splits allocation intent across `gsp_arrange_ports.port_increment`, `gsp_home_ip_ports.port`, XML query/reserve-port rules, and provisioning code. There is no normalized database table for reusable pools, game-to-pool mappings, per-game offsets, static service reservations, or whole-block ownership.

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
- `docs/plans/README.md`
- `docs/plans/ACTIVE.md`
- `docs/plans/global-port-allocation/CURRENT_SYSTEM.md`
- `docs/plans/global-port-allocation/PLAN.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/plans/global-port-allocation/PORT_POOLS.md`
- `docs/plans/global-port-allocation/STATIC_SERVICE_RESERVATIONS.md`
- `docs/plans/global-port-allocation/GAME_PORT_REVIEW_TABLE.md`
- `docs/plans/lgsl-gameq-protocol-removal/PLAN.md`
- `docs/plans/lgsl-gameq-protocol-removal/IMPLEMENTATION.md`
- `docs/plans/lgsl-gameq-protocol-removal/VALIDATION.md`
- `docs/plans/lgsl-gameq-protocol-removal/IMPACT_ANALYSIS.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/features/XML_SYSTEM.md`
- `docs/modules/server.md`
- `panel.sql`
- `includes/database_mysqli.php`
- `modules/server/module.php`
- `modules/server/arrange_ports.php`
- `modules/billing/module.php`
- `modules/billing/create_servers.php`
- `modules/user_games/add_home.php`
- `modules/user_games/edit_home.php`
- `modules/gamemanager/module.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/mini_start.php`
- `modules/config_games/schema_server_config.xml`
- `modules/config_games/server_configs/dayz_win64.xml`
- `modules/config_games/server_configs/arkse_linux64.xml`
- `modules/config_games/server_configs/rust_linux64.xml`
- `modules/config_games/server_configs/rust_win64.xml`
- `modules/config_games/server_configs/minecraft_linux64.xml`
- `modules/config_games/server_configs/7daystodie_linux64.xml`
- `modules/config_games/server_configs/teamspeak3_linux64.xml`
- `modules/config_games/server_configs/killingfloor2_linux64.xml`

## Files Changed

- `docs/plans/ACTIVE.md`
- `docs/plans/global-port-allocation/CURRENT_SYSTEM.md`
- `docs/plans/global-port-allocation/DATABASE_DESIGN.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/PLAN.md`
- `docs/plans/global-port-allocation/PORT_POOLS.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/codex-history/2026/2026-07-16-0857-global-port-allocation-database-plan.md`

## Architecture Decisions

- Preserve `gsp_home_ip_ports` as the compatibility source for primary IP:port during rollout.
- Preserve `gsp_arrange_ports` during a migration/backfill period instead of immediately replacing it.
- Model future allocation as whole blocks per `ip_id`, with primary/base port mirrored to compatibility fields.
- Treat static service reservations as allocation conflicts in the same per-IP overlap model.
- Normalize game query/RCON/web/Steam/admin offsets into database rows only after XML import/review and parity validation.
- Keep LGSL/GameQ removal separate from generic port-offset modeling.

## Validation Performed

- Read required instructions and architecture/planning docs before editing.
- Inspected Git status, diff, branch, remotes, and recent commits before editing.
- Inspected current schema, module install definitions, DB helper methods, billing allocation, admin allocation, lifecycle startup/status code, XML schema, and representative game XML files.
- Verified the current system uses configured ranges and increments, while `gsp_home_ip_ports` stores selected primary/base ports only.
- Confirmed `DATABASE_DESIGN.md` includes proposed tables, indexes, migration/backfill, compatibility strategy, and impact sections.
- Confirmed `CURRENT_SYSTEM.md` states that the current system uses configured ranges/increments while persisting only the selected primary/base port.
- Reviewed final documentation diff before staging.
- Confirmed changed files are limited to planning docs and the new history record; no runtime code, SQL schema, migrations, XML configs, services, language files, or deployment behavior files were edited.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`Plan global port allocation database design`

Hash

Recorded in final task report because the commit hash is not known until after this history file is committed.

## Remaining Work

- Product/operations review of the proposed database design, pool catalog, service-reservation policy, and game offset review table.
- Future approved implementation for migrations, admin UI, allocator service, backfill/conflict reports, and provisioning migration.
