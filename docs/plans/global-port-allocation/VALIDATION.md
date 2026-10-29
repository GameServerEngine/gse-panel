# Validation Plan

## Production Phase 1 Validation

Implemented validation for the 2026-07-16 Phase 1 code task:

- PHP syntax checks for `includes/port_allocator.php`, `includes/database_mysqli.php`, `modules/server/module.php`, `modules/billing/create_servers.php`, and `modules/server/tests/port_allocator_test.php`.
- Focused allocator tests in `modules/server/tests/port_allocator_test.php`.
- Module migration inspection confirms server module `db_version = 3` and seven migration statements in `$install_queries[3]`.
- Schema grep confirms the six new allocation tables are present in `modules/server/module.php`, `sql/003_global_port_allocation.sql`, and `panel.sql`.
- `git diff --check` must pass before commit.

Allocator test coverage:

- Overflow `32000-32999` exposes exactly 100 ten-port blocks per IP.
- Preferred mapped pool allocation owns `base+0` through `base+9`.
- Primary/base port is mirrored to `gsp_home_ip_ports`.
- Same-IP overlap advances to the next block.
- The same block remains reusable on a different `ip_id`.
- Static service reservations block overlapping game blocks.
- Required offsets outside the assigned ten-port block reject standard allocation.
- Legacy `gsp_arrange_ports` fallback reserves at least ten ports.

## Simplified Automatic Allocation Validation

Current allocator tests cover:

- default global block size is `10`;
- custom global block size is honored;
- game default base port is tried first;
- occupied and reserved blocks are skipped;
- the next free contiguous block is found by scanning upward by one port;
- allocation does not require manual port pool mappings;
- unknown games fall back to a safe base port with fallback metadata.

## Future Functional Validation

- Same base port can be reused on different IPs.
- Blocks never overlap on the same IP.
- Static service reservations are not allocated to games.
- A single-port static service reservation either blocks only that exact port or blocks the containing 10-port range according to the selected policy; the default policy should be containing-block for public services.
- Preferred pool is used first.
- Compatible/nearby pool is used only when explicitly mapped.
- Overflow `32000-32999` is used only when preferred/compatible pools are full.
- Overflow provides 100 blocks per IP with 10-port blocks; it is not 1000 server allocations.
- Allocation fails clearly when no available block exists on that IP.
- Existing homes still work through `gsp_home_ip_ports` compatibility.
- Billing-created homes and manual homes get the same block allocation behavior.
- Start, Stop, Restart, Console, Logs, Scheduler, File Manager, Config, and Server Content remain unaffected.
- XML configs import even after LGSL/protocol cleanup proceeds.
- Every game profile has reviewed offsets or is flagged `needs-review`/`non-standard`.
- Per-game offsets fit inside the allocated block unless the game is explicitly marked non-standard and reviewed.
- New block allocations still write the primary/base port into `gsp_home_ip_ports`.
- `gsp_arrange_ports` compatibility remains available until rollout explicitly removes the legacy path.

## Future Database Validation

- Migration dry run completes on sample/current-shaped schema data without modifying production databases.
- Backfill creates a conflict report before enforcement.
- Backfill imports every existing `gsp_home_ip_ports` row into a proposed block row or marks it `needs-review`.
- Existing primary ports are preserved exactly during import.
- Same block can be allocated on two different `ip_id` values.
- Overlapping block on the same `ip_id` is rejected.
- Static service reservation prevents game allocation on the same resolved `ip_id`.
- Manual reservation prevents game allocation on the same `ip_id`.
- Preferred pool exhaustion uses compatible pool only when mapped.
- Preferred and compatible exhaustion uses `gse-overflow-32000`.
- Overflow exhaustion fails clearly after 100 10-port allocations per IP.
- Imported non-aligned primary ports remain usable and are marked `legacy_imported` or `needs-review`.
- Billing-created and admin-created homes allocate through the same allocator and produce equivalent block plus `home_ip_ports` records.
- Existing homes can start, stop, restart, show console/logs, run scheduler actions, and render Game Monitor after migration/backfill.
- Query/RCON/web/Steam offset imports match representative XML-derived startup values before database offsets drive runtime tokens.

## Required Game Coverage

Tests must cover at least:

- Arma/DayZ `2302` pool.
- Source/SRCDS `27015` pool.
- Minecraft `25565` pool.
- Rust `28015` pool.
- 7 Days to Die `26900` pool.
- One non-standard game with a large offset, such as ARK/Atlas/Killing Floor 2-style `+19238` query offsets.

## Planning-Task Validation

For this documentation task, validate:

- all requested planning files exist;
- `DATABASE_DESIGN.md` exists and includes proposed tables, indexes, migration/backfill, and compatibility strategy;
- `CURRENT_SYSTEM.md` states that the current system uses configured port ranges/increments while only persisting the selected primary/base port per home;
- `docs/plans/ACTIVE.md` references this plan;
- `GAME_PORT_REVIEW_TABLE.md` includes a row for every top-level XML config inspected or clearly explains exclusions;
- `PORT_POOLS.md` includes capacities and collision notes;
- `STATIC_SERVICE_RESERVATIONS.md` includes requested service ports and marks uncertain bindings as verify-per-deployment;
- `IMPLEMENTATION.md` and `PLAN.md` list likely database changes, compatibility rules, and potential impact;
- no code/schema/runtime files changed;
- markdown/path sanity checks pass;
- final diff is reviewed before commit.
