# Future Implementation Plan

This document defines future phases only. Do not implement code, migrations, schema changes, XML edits, language edits, services, or deployment changes from this planning task.

## Production Phase 1 Implemented On 2026-07-16

The first production implementation is intentionally narrower than the full future plan:

- Added the approved schema beside `gsp_arrange_ports` and `gsp_home_ip_ports`.
- Seeded the documented global pool catalog, including fixed overflow pool `gse-overflow-32000` (`32000-32999`, block size `10`).
- Added a shared Panel allocator in `includes/port_allocator.php`.
- Updated billing provisioning to allocate a whole block first, then mirror the primary/base port into `gsp_home_ip_ports`.
- Updated direct compatibility writes through `GSPDatabaseMySQL::addGameIpPort()` to create a block ledger row when the new schema is present.
- Kept the legacy `gsp_arrange_ports` scan as a migration fallback. When used, it reserves at least a 10-port block and records `allocation_source='legacy_imported'`.
- Left XML/query/RCON token generation unchanged. `gsp_game_port_offsets` exists for reviewed/imported data, but runtime token generation still uses existing XML behavior.
- Did not add admin pool/reservation UI in this phase.
- Did not remove, rewrite, or disable `gsp_arrange_ports`.

Concrete files:

- `modules/server/module.php` owns the module migration to `db_version = 3`.
- `sql/003_global_port_allocation.sql` is the standalone operator SQL equivalent for the default `gsp_` prefix.
- `panel.sql` includes the new baseline table definitions.
- `modules/server/tests/port_allocator_test.php` covers allocator behavior.

Allocator order for this phase:

1. mapped preferred/compatible/fallback pools in `gsp_port_pool_games`;
2. legacy `gsp_arrange_ports` exact/fallback rows as migration compatibility;
3. controlled overflow pool `gse-overflow-32000`;
4. clear failure when no block is available.

## Simplified Automatic Allocation Implemented On 2026-07-17

The normal allocator path no longer requires admins to create port pools, game
pool mappings, or game offset rows.

- A global `settings.port_block_size` value controls how many consecutive ports
  each new game server receives. Fresh installs and update SQL seed `10`; UI
  validation accepts `1` through `1000`.
- For each game config, the allocator determines a default base port from GSE
  game-name metadata first, protocol metadata second, then uses `27015` as a
  safe fallback and records the fallback source in allocation metadata/events.
- Allocation scans upward by one port from the default base to maximize packing.
- Conflicts are checked against active `home_port_blocks`, legacy
  `home_ip_ports`, and active static reservations in the selected IP/remote
  server/global scope.
- `home_port_blocks` remains the block ledger and `home_ip_ports` remains the
  compatibility write for lifecycle/display paths.
- The admin page now exposes global block size, static reservations, allocated
  blocks, and allocation events. Pool/mapping/offset tables are retained for
  compatibility and historical review but are hidden from the normal workflow.

## Phase 1: Final Inventory And Product Approval

- Review `GAME_PORT_REVIEW_TABLE.md` with product/operations.
- Externally verify every `needs external verification` default port.
- Decide which `needs-review` games are supported by initial global allocation and which remain manual/non-standard.
- Decide whether low-numbered service ports reserve whole 10-port blocks or explicit single ports.

## Phase 2: Data Model And Migrations

Use `DATABASE_DESIGN.md` as the starting database contract. This phase still requires a separate implementation task and migration review.

Add database tables/migrations for:

- port pools;
- game-to-pool mappings;
- per-game offset maps;
- static service reservations;
- allocated port blocks;
- allocation source/reason metadata.

Allocation records should track home/server, IP, pool, block start/end, primary port, source (`preferred`, `compatible`, `overflow`, `static_service`), and reason.

Required compatibility rules:

- Add new tables alongside `gsp_arrange_ports` and `gsp_home_ip_ports`.
- Do not remove or disable `gsp_arrange_ports` during initial rollout.
- Continue writing `gsp_home_ip_ports.ip_id` and `port` for every active home.
- Store whole-block ownership in the new block table.
- Enforce all overlap checks per `ip_id`.
- Treat static service reservations as allocation conflicts, not advisory notes.
- Keep the overflow pool fixed at `32000-32999`, block size `10`, 100 allocations per IP.

## Phase 3: Admin UI

Add admin views for:

- Port Pools;
- assigned games;
- per-game offsets;
- static service reservations;
- per-IP usage/capacity;
- non-standard and needs-review warnings.

The UI model should be `Port Pool -> assigned games -> per-game offsets`, not repeated `Game -> port range per dedicated server`.

## Phase 4: Compatibility Layer

Preserve `gsp_home_ip_ports` while adding block allocations. Existing lifecycle paths should continue reading the primary IP/port until each path is migrated.

Backfill existing homes by creating block allocation records around their current primary ports where possible. Conflicts must be flagged instead of silently moved.

`gsp_arrange_ports` remains the legacy configured-range source during this phase. Backfill should infer block size from `port_increment` when a matching range exists and mark uncertain rows `legacy_imported` or `needs-review`.

## Phase 5: Billing And Manual Provisioning

Update billing and manual provisioning so both paths allocate a whole 10-port block per IP using this order:

1. preferred default-port pool on that IP;
2. compatible/nearby pool if explicitly mapped;
3. controlled GSE overflow pool `32000-32999`;
4. fail with a clear `no available port blocks` message for that IP.

Manual and billing-created homes must use the same allocator.

## Phase 6: Config Token Generation

Update token generation so `%PORT%`, `%QUERY_PORT%`, `%RCON_PORT%`, `%WEB_PORT%`, Steam/admin/peer ports, and game-specific tokens come from the offset map within the assigned block.

Do not rely on LGSL/GameQ metadata as required lifecycle input. XML configs must still import as legacy metadata while the LGSL/protocol cleanup proceeds.

## Phase 7: Migration And Backfill

- Inventory current `arrange_ports` and `home_ip_ports` rows.
- Create proposed pool mappings from existing ranges.
- Seed global pools from `PORT_POOLS.md` and approved `arrange_ports` patterns.
- Import XML query and reserve-port offsets into the proposed offset table for review.
- Detect overlapping blocks and static service conflicts per IP.
- Produce an admin review report before writing changes.
- Leave existing homes usable even when a conflict requires manual resolution.
- Preserve homes whose primary ports do not align to 10-port boundaries.
- Continue using `gsp_home_ip_ports` until all read paths are migrated.

## Phase 8: Collision Prevention And Errors

- Prevent overlapping game blocks on the same IP.
- Prevent game blocks from overlapping static service reservations on the same IP.
- Permit the same block on different IPs.
- Return clear admin/customer-safe errors when no block is available.

## Phase 9: Tests And Rollout

- Add unit/integration tests for the allocator and migration planner.
- Add UI tests for pool/service reservation screens.
- Roll out behind an admin-controlled feature flag or staged migration.
- Keep rollback path for legacy `arrange_ports`/`home_ip_ports` behavior until production data is clean.

## Likely Future Files To Change

Schema, migration, and install SQL:

- `panel.sql`
- `modules/server/module.php`
- a new migration under `sql/` or a module-owned migration path
- `docs/DATABASE_SCHEMA_REFERENCE.md`

Database access layer:

- `includes/database_mysqli.php`
- possibly `includes/database.php` if shared interface methods are added

Allocation service/helper:

- new shared allocator under `modules/server/` or `includes/`
- overlap/conflict-report helpers

Billing provisioning:

- `modules/billing/create_servers.php`

Manual/admin provisioning:

- `modules/user_games/add_home.php`
- `modules/user_games/edit_home.php`
- `modules/server/arrange_ports.php` or a replacement pool/reservation UI

Server edit/config token generation:

- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- XML import/parser helpers if offsets are read into database rows

Admin UI:

- new pool, reservation, usage, and conflict review pages under `modules/server/`
- language/theme additions only as needed

Tests:

- allocator tests
- migration dry-run/backfill tests
- billing/admin provisioning parity tests
- XML offset import tests

Docs:

- `docs/plans/global-port-allocation/*`
- `docs/features/XML_SYSTEM.md`
- `docs/modules/server.md`
- `docs/modules/GAMEMANAGER.md`
- agent docs only if future protocol/lifecycle assumptions change

## Potential Impact

User/admin workflows affected:

- billing checkout provisioning;
- admin-created home provisioning;
- manual IP/port assignment;
- pool/range administration;
- service reservation administration;
- game config review for query/RCON/web/Steam offsets.

Existing compatibility tables:

- `gsp_home_ip_ports` remains for lifecycle compatibility.
- `gsp_arrange_ports` remains for migration, read-only comparison, and rollback during the compatibility period.

Agent-side behavior:

- No agent-side behavior is expected to change for the database phase.
- Agents should continue receiving explicit IP/port/startup commands from the Panel.

Regression prevention:

- Start, Stop, Restart, Console, Logs, Scheduler, File Manager, Config, and Server Content continue reading the primary IP:port from `gsp_home_ip_ports` until migrated.
- Startup/query/RCON token generation must compare XML-derived values against imported database offsets before switching sources.
- The separate LGSL/GameQ removal plan must not remove generic query/RCON/secondary port concepts needed by this allocator.
