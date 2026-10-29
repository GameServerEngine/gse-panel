# Global Port Allocation Plan

## Purpose

Design a durable GSE global port-pool allocation model for the Panel/control plane. This is a planning document only; no runtime code, database migration, schema, XML game config, language, service, or deployment behavior changes are authorized by this task.

The current Panel repository is `/home/dev/Projects/GSE-Panel`. The workspace guidance identifies the Panel as the control plane, and port allocation policy belongs here because the Panel owns provisioning decisions before agents execute lifecycle actions.

## User Intent

Move away from manually defining a separate port range for each game on each dedicated server. Define reusable port pools first, assign games to pools, and allocate fixed blocks per IP.

The target model is:

- Port Pool -> assigned games -> per-game offsets.
- Normal game allocations own 10 ports: `base+0` through `base+9`.
- The same block can be reused on different IPs.
- Blocks cannot overlap another game block or static service reservation on the same IP.
- Preferred/default pools are used before compatible pools.
- Overflow is controlled at `32000-32999`, block size 10, exactly 100 blocks per IP.
- Avoid broad high ranges such as `40000-59999` by default because OS ephemeral and FTP passive ranges can overlap there.

## Scope

Owner repository: `/home/dev/Projects/GSE-Panel`

Related repositories for future runtime work:

- `/home/dev/Projects/GSE-Agent-Linux`
- `/home/dev/Projects/GSE-Agent-Windows`

This planning task inspected the Panel/control-plane repository only for code and XML inventory. Future implementation that changes protocol shape, lifecycle behavior, status checks, or agent assumptions must review and update the relevant agent docs too.

## Deliverables

- `CURRENT_SYSTEM.md`: current OGP-style range and primary-port behavior.
- `DATABASE_DESIGN.md`: proposed future schema for pools, game mappings, offsets, static reservations, block allocations, audit events, migration/backfill, compatibility, and impact.
- `GAME_PORT_REVIEW_TABLE.md`: initial inventory of all 230 top-level XML game configs in `modules/config_games/server_configs/*.xml`.
- `PORT_POOLS.md`: proposed initial pool catalog and capacity notes.
- `STATIC_SERVICE_RESERVATIONS.md`: service-port reservation policy and review table.
- `IMPLEMENTATION.md`: future phases only.
- `VALIDATION.md`: future validation matrix and this planning-task validation.

## Non-Negotiable Guardrails

- Do not allocate game ports outside a known pool unless a future product decision adds that pool.
- Do not use an unbounded high fallback range.
- Do not consume static service ports on the same IP/host.
- Do not break `gsp_home_ip_ports` compatibility; existing homes and lifecycle controls still need their primary IP/port.
- Do not remove or immediately replace `gsp_arrange_ports`; it is the current configured range/increment source and needs a migration/backfill period.
- Reserve a whole block in the new allocator, then mirror the primary/base port into `gsp_home_ip_ports`.
- Treat allocation uniqueness as per IP. The same block can be reused on a different `ip_id`.
- Do not let LGSL/GameQ metadata become required for managed-server lifecycle behavior.
- Do not force non-standard games into a 10-port block when XML/reserve-port data shows they need external offsets.

## Current Status

Planning documents exist. No runtime behavior changed.

The current system already allocates from configured ranges/blocks: admins configure `gsp_arrange_ports.start_port`, `end_port`, and `port_increment` per `ip_id` and `home_cfg_id`, and billing/admin allocation scans those ranges by increment. The current persistence gap is that `gsp_home_ip_ports` records the selected primary/base port, not an explicit full block ownership record.

The next action is product and operations review of `GAME_PORT_REVIEW_TABLE.md`, `PORT_POOLS.md`, `STATIC_SERVICE_RESERVATIONS.md`, and `DATABASE_DESIGN.md`, followed by an approved migration/implementation task.

## Proposed Database Direction

Future implementation should add new tables alongside current tables:

- `gsp_port_pools` for reusable global pool definitions.
- `gsp_port_pool_games` for preferred/compatible/fallback game-to-pool mappings.
- `gsp_game_port_offsets` for normalized primary/query/RCON/web/Steam/admin/custom offsets.
- `gsp_static_port_reservations` for per-IP, per-remote-server, or global service reservations.
- `gsp_home_port_blocks` for per-home/IP whole-block ownership.
- `gsp_port_allocation_events` if audit history is approved.

`gsp_home_ip_ports` remains the compatibility source during rollout. `gsp_arrange_ports` remains readable during migration and rollback.

## Potential Impact Summary

Future implementation will likely affect billing provisioning, admin/manual provisioning, server edit IP/port assignment, startup/config token generation, admin pool/reservation UI, migration scripts, and database references. Agent behavior should not change unless a later task changes lifecycle protocol shape; agents should continue executing explicit Panel decisions.

Start, Stop, Restart, Console, Logs, Scheduler, File Manager, Config, and Server Content must continue resolving the same primary IP:port through `gsp_home_ip_ports` until every code path is migrated and validated.

This plan intersects with the separate LGSL/GameQ removal plan only around query-port metadata. The port-pool model should keep generic query/RCON/secondary port offsets without making LGSL/GameQ public query libraries required for lifecycle.
