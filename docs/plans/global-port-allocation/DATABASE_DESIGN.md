# Global Port Allocation Database Design

## Purpose

This document proposes future database/schema changes for the GSE global port-pool allocator. It is planning only. Do not apply these tables, migrations, SQL edits, XML edits, or runtime code changes until a separate implementation task is approved.

The design supplements the current `gsp_arrange_ports` and `gsp_home_ip_ports` model. It must reserve whole blocks per IP while continuing to write the selected primary/base port into `gsp_home_ip_ports` for existing lifecycle compatibility.

## Implementation Status

Production Phase 1 was implemented on 2026-07-16 in the Panel repository.

- `modules/server/module.php` creates the new tables at server module `db_version = 3`.
- `sql/003_global_port_allocation.sql` contains the standalone default-prefix migration.
- `panel.sql` includes the baseline table definitions.
- `includes/port_allocator.php` is the shared allocator service.
- Billing-created homes use the allocator first and still write `gsp_home_ip_ports`.
- Existing `gsp_arrange_ports` rows remain in place and are used as a migration fallback before controlled overflow when no reviewed pool mapping exists.
- XML-derived token generation has not moved to database offsets in this phase.

## Design Rules

- Use the current `gsp_` table prefix convention.
- Keep `gsp_home_ip_ports` initially; existing lifecycle and monitor code depends on it.
- Keep `gsp_arrange_ports` during a compatibility period; read and migrate existing data instead of deleting it.
- All allocation uniqueness is per `ip_id`. The same block can be reused on another IP.
- No overlap is allowed on the same IP between game blocks, overflow blocks, manual blocks, static service reservations, or imported legacy reservations.
- Normal default block size is `10`, but pool-level and game-level overrides must exist.
- Overflow is `32000-32999`, block size `10`, 100 allocations per IP, used only after preferred and compatible pools are exhausted.
- Static service reservations participate in the same conflict checks as game allocations.
- Per-game offsets must fit inside the allocated block unless the game is marked non-standard and explicitly reviewed.
- Existing homes that do not align to 10-port boundaries must be preserved and marked for review, not silently moved.

## Proposed Tables

### `gsp_port_pools`

Purpose: global reusable pool catalog. A pool defines intent once, then can be consumed per IP.

Important columns:

- `pool_id INT AUTO_INCREMENT PRIMARY KEY`
- `pool_key VARCHAR(64) NOT NULL`
- `name VARCHAR(128) NOT NULL`
- `purpose ENUM('game','overflow','service','manual','legacy') NOT NULL DEFAULT 'game'`
- `base_port INT UNSIGNED NOT NULL`
- `range_start INT UNSIGNED NOT NULL`
- `range_end INT UNSIGNED NOT NULL`
- `block_size INT UNSIGNED NOT NULL DEFAULT 10`
- `priority INT NOT NULL DEFAULT 100`
- `enabled TINYINT(1) NOT NULL DEFAULT 1`
- `allow_manual TINYINT(1) NOT NULL DEFAULT 0`
- `notes TEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NULL`

Indexes and constraints:

- Unique key `uq_port_pools_key (pool_key)`.
- Key `idx_port_pools_enabled_priority (enabled, priority)`.
- Key `idx_port_pools_range (range_start, range_end)`.
- Check in future migration/service code: `range_start <= range_end`, `block_size > 0`, and `base_port BETWEEN range_start AND range_end`.

Replaces or supplements: supplements `gsp_arrange_ports`. It becomes the preferred source for future allocation once the new allocator is enabled.

Migration/backfill source:

- Seed approved pools from `PORT_POOLS.md`.
- Create a `gse-overflow-32000` row with `range_start=32000`, `range_end=32999`, `block_size=10`, `purpose='overflow'`, and low priority.
- Derive additional candidate pools from existing `gsp_arrange_ports` only when they match approved policy or are marked `legacy`.

Compatibility risk: low if read-only at first. Risk rises when provisioning switches from `gsp_arrange_ports` to `gsp_port_pools`.

### `gsp_port_pool_games`

Purpose: map game configs to preferred and compatible pools.

Important columns:

- `pool_game_id INT AUTO_INCREMENT PRIMARY KEY`
- `pool_id INT NOT NULL`
- `home_cfg_id INT NOT NULL`
- `role ENUM('preferred','compatible','fallback','excluded') NOT NULL DEFAULT 'preferred'`
- `priority INT NOT NULL DEFAULT 100`
- `block_size_override INT UNSIGNED NULL`
- `allocation_policy ENUM('standard','non_standard','manual_review','service_specific') NOT NULL DEFAULT 'standard'`
- `enabled TINYINT(1) NOT NULL DEFAULT 1`
- `notes TEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NULL`

Indexes and constraints:

- Unique key `uq_pool_game_role (home_cfg_id, pool_id, role)`.
- Key `idx_pool_games_home_role_priority (home_cfg_id, role, priority)`.
- Key `idx_pool_games_pool (pool_id)`.
- Future foreign-key intent: `pool_id -> gsp_port_pools.pool_id`, `home_cfg_id -> gsp_config_homes.home_cfg_id`.

Replaces or supplements: supplements `gsp_arrange_ports.home_cfg_id`. It turns repeated per-IP arrangements into a global game-to-pool map.

Migration/backfill source:

- Seed from `GAME_PORT_REVIEW_TABLE.md` and `PORT_POOLS.md`.
- Use current `gsp_arrange_ports.home_cfg_id` rows to identify local custom mappings and mark uncertain rows `manual_review`.

Compatibility risk: medium. Incorrect mappings could allocate a game into the wrong default-port neighborhood, so initial enforcement must require admin review.

### `gsp_game_port_offsets`

Purpose: normalize per-game port offsets that are currently scattered through XML `<query_port>`, `<reserve_ports>`, LGSL conversion, and startup token handling.

Important columns:

- `offset_id INT AUTO_INCREMENT PRIMARY KEY`
- `home_cfg_id INT NOT NULL`
- `port_role ENUM('primary','query','rcon','web','steam','peer','admin','filetransfer','query_ssh','custom') NOT NULL`
- `token_name VARCHAR(64) NULL`
- `protocol ENUM('tcp','udp','tcp_udp','unknown') NOT NULL DEFAULT 'unknown'`
- `offset_type ENUM('add','subtract','absolute') NOT NULL DEFAULT 'add'`
- `offset_value INT NOT NULL DEFAULT 0`
- `required TINYINT(1) NOT NULL DEFAULT 1`
- `fits_standard_block TINYINT(1) NOT NULL DEFAULT 1`
- `source ENUM('xml_query_port','xml_reserve_ports','lgsl_conversion','manual','imported') NOT NULL DEFAULT 'manual'`
- `notes TEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NULL`

Indexes and constraints:

- Unique key `uq_game_port_offset (home_cfg_id, port_role, token_name)`.
- Key `idx_game_port_offsets_home (home_cfg_id)`.
- Key `idx_game_port_offsets_fit (fits_standard_block)`.

Replaces or supplements: supplements XML during compatibility. Future startup/config token generation can read offsets from this table after parity validation, but XML remains import/source metadata until migration is complete.

Migration/backfill source:

- Parse `modules/config_games/server_configs/*.xml`.
- Import `<query_port>` rows as `port_role='query'`.
- Import `<reserve_ports>` rows using token IDs such as `RCON_PORT`, `WEB_ADMIN_PORT`, `STEAM_PORT`, `QUERY_SSH_PORT`, and `FILETRANSFER_PORT`.
- Mark offsets outside `+0..+(block_size-1)` as `fits_standard_block=0` and set the game mapping to `non_standard` or `manual_review`.

Compatibility risk: medium. Startup behavior must not switch to database offsets until XML parity tests prove identical output for representative games.

### `gsp_static_port_reservations`

Purpose: reserve host/service ports so games cannot consume them.

Important columns:

- `reservation_id INT AUTO_INCREMENT PRIMARY KEY`
- `scope ENUM('ip','remote_server','global') NOT NULL DEFAULT 'ip'`
- `ip_id INT NULL`
- `remote_server_id INT NULL`
- `service_name VARCHAR(128) NOT NULL`
- `reservation_type ENUM('single_port','block','range') NOT NULL DEFAULT 'block'`
- `port_start INT UNSIGNED NOT NULL`
- `port_end INT UNSIGNED NOT NULL`
- `protocol ENUM('tcp','udp','tcp_udp','unknown') NOT NULL DEFAULT 'tcp_udp'`
- `source ENUM('admin','migration','detected','policy') NOT NULL DEFAULT 'admin'`
- `status ENUM('active','disabled','needs-review') NOT NULL DEFAULT 'active'`
- `notes TEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NULL`

Indexes and constraints:

- Key `idx_static_res_ip_range (ip_id, port_start, port_end, status)`.
- Key `idx_static_res_remote (remote_server_id, status)`.
- Key `idx_static_res_service (service_name)`.
- Optional unique key for exact duplicates: `uq_static_res_exact (scope, ip_id, remote_server_id, service_name, port_start, port_end, protocol)`.
- Future service code must enforce `port_start <= port_end` and no active overlap on the same resolved `ip_id`.

Replaces or supplements: supplements current deployment knowledge. Static services are not first-class in the current allocation schema.

Migration/backfill source:

- Seed policy rows from `STATIC_SERVICE_RESERVATIONS.md`.
- Bind rows to real `ip_id`/`remote_server_id` only after deployment review confirms where each service listens.
- Include SSH, HTTP, HTTPS, MySQL, RDP, FTP control/passive ranges, Panel/API ports, agent listener ports, mail, VPN, TeamSpeak service ports, and other host services.

Compatibility risk: medium. Overly broad reservations can reduce capacity. Under-reservation can allow collisions with host services.

Single-port versus containing-block policy:

- Single-port reservation preserves more capacity but makes allocation checks more complex and may allow adjacent game ports near operational services.
- Containing 10-port block reservation is conservative, easier to reason about, and aligns with standard game block checks.
- Recommended default: reserve the containing 10-port block for public or externally reachable services. Allow explicit `single_port` only for tightly scoped/localhost/private bindings after admin review.

### `gsp_home_port_blocks`

Purpose: authoritative future allocation ledger for whole blocks assigned to homes, imported legacy rows, manual reservations, and static-service occupancy.

Important columns:

- `block_id BIGINT AUTO_INCREMENT PRIMARY KEY`
- `home_id INT NULL`
- `ip_id INT NOT NULL`
- `pool_id INT NULL`
- `home_cfg_id INT NULL`
- `block_start INT UNSIGNED NOT NULL`
- `block_end INT UNSIGNED NOT NULL`
- `primary_port INT UNSIGNED NOT NULL`
- `block_size INT UNSIGNED NOT NULL`
- `allocation_source ENUM('preferred','compatible','overflow','manual','static_service','legacy_imported','admin_override') NOT NULL`
- `status ENUM('active','pending','released','needs-review','conflict') NOT NULL DEFAULT 'active'`
- `reservation_id INT NULL`
- `legacy_range_id INT NULL`
- `notes TEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at DATETIME NULL`
- `released_at DATETIME NULL`

Indexes and constraints:

- Key `idx_home_port_blocks_home (home_id, status)`.
- Key `idx_home_port_blocks_ip_range (ip_id, block_start, block_end, status)`.
- Key `idx_home_port_blocks_pool (pool_id, status)`.
- Key `idx_home_port_blocks_primary (ip_id, primary_port, status)`.
- Unique key option for active primary compatibility: `uq_active_primary (ip_id, primary_port, status)` if status handling allows only one active-like value, otherwise enforce in service code.
- Future service code must reject any active/pending overlap where `same ip_id` and `new.block_start <= existing.block_end` and `new.block_end >= existing.block_start`.

Replaces or supplements: supplements `gsp_home_ip_ports` at first. Eventually becomes the allocator source of truth while still mirroring primary ports into `gsp_home_ip_ports` for compatibility.

Migration/backfill source:

- For every existing `gsp_home_ip_ports` row, join `gsp_server_homes.home_cfg_id`, `gsp_remote_server_ips.ip_id`, and the best matching `gsp_arrange_ports` range.
- Infer block size from matching range `port_increment` when available; otherwise default to `10` and mark `needs-review`.
- Set `primary_port` from `gsp_home_ip_ports.port`.
- Set `block_start=primary_port`.
- Set `block_end=primary_port + inferred_block_size - 1` for standard imports, unless the allocation is non-standard or conflicts.
- Preserve non-aligned or conflicting rows as `legacy_imported` with `status='needs-review'` or `status='conflict'`.

Compatibility risk: high if enforced too early. Backfill must report conflicts and avoid changing existing ports.

### `gsp_port_allocation_events`

Purpose: optional audit trail for allocator decisions, migration imports, conflict reports, releases, and manual overrides.

Important columns:

- `event_id BIGINT AUTO_INCREMENT PRIMARY KEY`
- `block_id BIGINT NULL`
- `home_id INT NULL`
- `ip_id INT NULL`
- `pool_id INT NULL`
- `event_type ENUM('allocated','released','imported','conflict_detected','reservation_added','reservation_removed','manual_override','failed_allocation') NOT NULL`
- `actor_user_id INT NULL`
- `source VARCHAR(64) NOT NULL DEFAULT 'system'`
- `message VARCHAR(255) NOT NULL DEFAULT ''`
- `metadata_json MEDIUMTEXT NULL`
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

Indexes and constraints:

- Key `idx_port_events_home_time (home_id, created_at)`.
- Key `idx_port_events_ip_time (ip_id, created_at)`.
- Key `idx_port_events_type_time (event_type, created_at)`.

Replaces or supplements: supplements current logs. This is optional but useful for admin review and support investigations.

Migration/backfill source: generated by migration/backfill and future allocator actions.

Compatibility risk: low.

## Allocation Algorithm Target

For a new home on a selected `remote_server_id`:

1. Resolve candidate `ip_id` rows for that remote server.
2. Resolve the game config `home_cfg_id` to enabled `gsp_port_pool_games` rows ordered by role and priority.
3. For each IP and preferred pool, scan block starts by the effective block size.
4. Reject a candidate if it overlaps any active/pending row in `gsp_home_port_blocks` for the same `ip_id`.
5. Reject a candidate if it overlaps any active static reservation resolved to the same `ip_id`.
6. Reject a standard game if any required `gsp_game_port_offsets` port falls outside the candidate block.
7. If preferred pools are exhausted, try compatible/fallback pools.
8. If compatible pools are exhausted, try `gse-overflow-32000`.
9. If a block is selected, insert `gsp_home_port_blocks` and write the primary/base port into `gsp_home_ip_ports`.
10. If no block is available, fail clearly with a no-available-port-blocks error.

## Migration And Backfill Plan

### Phase 1: Add Tables Beside Current Tables

- Add the new tables without removing or altering `gsp_arrange_ports` or `gsp_home_ip_ports`.
- Leave all provisioning code on the current allocator.
- Add read-only admin/reporting code first.

### Phase 2: Seed Pool And Offset Data

- Seed approved global pools from `PORT_POOLS.md`.
- Seed `gse-overflow-32000` as `32000-32999`, block size `10`, 100 allocations per IP.
- Import XML query/reserve offsets into `gsp_game_port_offsets`.
- Map reviewed `home_cfg_id` values to `gsp_port_pool_games`.
- Mark non-standard games such as large-offset ARK/Atlas/Killing Floor 2-style configs for manual review unless a custom pool/block policy is approved.

### Phase 3: Import Current Arranged Ranges

- Read `gsp_arrange_ports` per `ip_id` and `home_cfg_id`.
- Match rows to known pool ranges when possible.
- Mark unmatched rows as `legacy` or `manual_review`.
- Do not delete or rewrite current arranged ranges.

### Phase 4: Backfill Existing Homes

- Read every `gsp_home_ip_ports` row.
- Join to `gsp_server_homes` to get `home_cfg_id`.
- Find the matching current arranged range and infer block size from `port_increment`.
- Create `gsp_home_port_blocks` rows with `allocation_source='legacy_imported'`.
- Keep exact existing primary ports.
- Preserve homes that do not align to 10-port boundaries; mark them `needs-review`.

### Phase 5: Detect Conflicts

- Report overlaps per `ip_id` between imported game blocks.
- Report overlaps between imported blocks and static reservations.
- Report offsets that fall outside imported blocks.
- Do not silently change ports, delete rows, or move homes.

### Phase 6: Admin Review

- Add review screens before enforcement.
- Let admins resolve conflicts intentionally.
- Show capacity by IP, pool, and status.
- Keep legacy provisioning active until review is complete.

### Phase 7: Switch Provisioning

- Update billing and manual/admin provisioning to call the new block allocator.
- Keep writing `gsp_home_ip_ports`.
- Keep a feature flag or rollback path to current `gsp_arrange_ports` allocation until production data is clean.

### Phase 8: Migrate Runtime Reads

- Migrate startup/config token generation and display paths only after compatibility tests pass.
- Keep `gsp_home_ip_ports` populated until every lifecycle path has been audited and updated.

## Compatibility Strategy

- `gsp_home_ip_ports` remains the compatibility source for primary IP:port.
- `gsp_home_port_blocks.primary_port` must match `gsp_home_ip_ports.port` for active standard game allocations.
- Existing Start/Stop/Restart/Console/Logs/Scheduler paths should see the same IP and primary port before and after migration.
- XML remains the source of startup behavior during initial rollout; database offsets are imported, compared, and reviewed before they drive runtime token generation.
- `gsp_arrange_ports` remains available for fallback/rollback during the compatibility period.

## Potential Impact

Likely affected user/admin workflows:

- Billing checkout and auto-provisioning.
- Admin-created homes.
- Manual IP/port assignment in server edit.
- Admin range/pool management.
- Game config review for query/RCON/web/Steam offsets.
- Capacity reporting by node/IP.

Agent-side behavior:

- No direct agent-side behavior should change for the database phase.
- Agents still receive explicit commands with IP and primary/derived ports from the Panel.
- Future agent docs need review only if lifecycle protocol shape or status semantics change.

Start/Stop/Restart/Console/Logs/Scheduler risk:

- These paths depend on `gsp_home_ip_ports` and derived query/RCON ports.
- The plan prevents regressions by continuing to mirror the primary/base port into `gsp_home_ip_ports`.
- Query/RCON offsets should not move from XML/runtime logic to database-driven logic until command-generation parity is validated.

Migration risks:

- Existing homes may not align to 10-port boundaries.
- Existing `port_increment` values may not equal `10`.
- XML offsets may exceed standard blocks.
- Static services may already bind ports inside arranged ranges.
- `gsp_arrange_ports` ranges may encode local operational intent that is not visible in planning docs.

Relationship to LGSL/GameQ removal:

- This plan must not make LGSL/GameQ metadata required for lifecycle.
- Query-port values may still be needed for startup, firewall, readiness detail, or config generation.
- The separate LGSL/GameQ removal plan can remove public query/status UI later, but generic port-offset concepts should remain independent of LGSL/GameQ libraries.

## Likely Future Files To Change

Schema, migration, and install SQL:

- `panel.sql`
- `modules/server/module.php`
- possibly a new migration under `sql/` or a module-specific migration file
- `docs/DATABASE_SCHEMA_REFERENCE.md`

Database access layer:

- `includes/database_mysqli.php`
- possibly `includes/database.php` if interface methods are added

Allocation service/helper:

- new helper/service under `modules/server/` or `includes/` for block allocation
- shared validation helper for overlap detection

Billing provisioning:

- `modules/billing/create_servers.php`
- billing provisioning tests/fixtures when available

Manual/admin provisioning:

- `modules/user_games/add_home.php`
- `modules/user_games/edit_home.php`
- `modules/server/arrange_ports.php` or replacement pool UI

Server edit/config token generation:

- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- XML parser/import helpers if offsets are imported from XML

Admin UI for pools/reservations/usage:

- new or existing `modules/server/` admin pages
- language files for new admin labels
- theme CSS only if needed for new screens

Tests:

- allocator unit/integration tests if a test harness is added
- XML offset import validation
- billing/admin provisioning parity tests
- migration dry-run/conflict-report tests

Docs:

- `docs/plans/global-port-allocation/*`
- `docs/features/XML_SYSTEM.md` if runtime offset ownership changes
- `docs/modules/server.md`
- `docs/modules/GAMEMANAGER.md`
- agent docs only if protocol or lifecycle assumptions change
