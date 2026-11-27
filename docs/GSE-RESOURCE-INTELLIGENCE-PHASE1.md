# GSE Resource Intelligence - Phase 1 Architecture Review

Date: 2026-11-26
Scope: read-only review of the existing code. No code, schema, or configuration was changed.
Source handoff: GSE Resource Intelligence Development Handoff, revision 2.0.

## 1. What the code does today

### 1.1 Placement and storefront

- `modules/billing/includes/storefront_locations.php`
  - `storefront_agent_unavailable_reason()` decides whether an agent is offered.
  - A snapshot older than `STOREFRONT_SNAPSHOT_FRESH_SECONDS` (21600 s) returns "available" (empty reason).
  - Resource fields that are null or non-numeric are skipped.
  - Headroom comes from `billing_config` (defaults CPU 70, RAM 70, disk 85).
  - The check is per machine, percent used against headroom. It does not consider what the game needs.
- `modules/billing/order.php` line 200: blocked offers are derived from the storefront check. This is the only placement gate.
- `modules/billing/admin_catalog.php` line 461: the same check is shown to administrators.
- `modules/billing/includes/placement_capacity.php`: `agent_fits_requirements()` is defined here. It is called only by `modules/billing/tests/PlacementCapacityTest.php`. It is not used by any production path.
- `add_to_cart.php`, `checkout_free.php`, and `includes/provision_body.php` contain no capacity, headroom, or snapshot checks. An order that passes the storefront can be paid for and provisioned on a full machine.

### 1.2 Agent snapshot

- Table `DB_PREFIXagent_resource_snapshots` (install/gse_panel.sql line 84):
  - One row per agent (primary key `remote_server_id`), so no history is kept.
  - Percentages only: `cpu_percent`, `ram_percent`, `disk_percent` (decimal 6,2).
  - `raw_payload` longtext, `collected_at`, `status`, `last_error`.
  - Engine: MyISAM.
  - Used by `status_collect.php`, `storefront_locations.php`, `modules/server/agent_helpers.php`, `modules/server/module.php`, and `tools/refresh_status_snapshot.php`.

### 1.3 Status history (existing telemetry foundation)

- Module: `modules/status_history/` (default module; admins can turn reporting off).
- Tables already present: `DB_PREFIXmachine_samples`, `DB_PREFIXprocess_samples`, `DB_PREFIXserver_status`, `DB_PREFIXstatus_cache`, `DB_PREFIXserver_content_install_history`.
- Hourly and daily rollups are part of this module.
- Per-game samples do not exist yet.

### 1.4 Resource Planner

- `modules/administration/resource_planner.php` and `resource_calc.php`.
- Read-only bandwidth and storage calculator. Nothing is saved.
- Game profiles are hard-coded in `resource_profiles()` (generic, cstrike, czero, counterstrike_source, csgo, garrysmod, tf2, left4dead2, hl2dm, dods). They contain bandwidth (kbps), install size, mod size, and player count. They contain no CPU or RAM values.
- The values differ from the handoff seed ranges. For example, Garry's Mod here is 6 GB install and 10 GB mods. The handoff seed is 10-30 GB disk and 2-4 GiB RAM.

### 1.5 Benchmark and inventory tooling

- `gse-loadtest` (Go) already contains:
  - `hw.go`: `collectHW`, `guessKind`, `measureBaseline`, `runHW`, `applyKind`.
  - `bench.go`: `runCPU` (7-Zip based) and `runDisk`, with background probes.
  - `sys.go`, `sys_linux.go`, `sys_windows.go`: CPU snapshots, percentile helpers, platform code.
- These are standalone command-line tools. They are not part of either agent.

## 2. Gaps against the handoff

| Handoff requirement | Current state |
|---|---|
| Hardware inventory per agent | None in agents or panel. Exists only in gse-loadtest. |
| Absolute RAM, CPU, disk values | Not stored. Percentages only. |
| Peak, sustained, allocatable CPU | None. |
| Benchmark persistence and history | None in the panel. |
| Per-game resource samples | None. Status history is per machine and per process. |
| Game resource profiles (versioned, approved) | Hard-coded, bandwidth and disk only, no approval. |
| Reservations | None. Nothing records installed games against an agent. |
| Parent resource pools | None. |
| Checkout and provisioning gate | None. |
| Stale or missing data must not pass | Currently passes (storefront). |
| Single allocation engine | Not present. `agent_fits_requirements()` is unused. |

## 3. Verified against the handoff

- `agent_fits_requirements()` is not used in production. Confirmed.
- Stale or missing snapshots pass the storefront check. Confirmed.
- Missing values are skipped. Confirmed.
- `agent_resource_snapshots` uses MyISAM. Confirmed.
- Existing Resource Planner and status history are the intended foundation. Confirmed.

## 4. Discrepancies to resolve

1. The two reference files (`GSE-HARDWARE-RESOURCE-REFERENCE.txt`, 18 lines; `GSE-GAME-RESOURCE-REFERENCE.txt`, 20 lines) are shorter than the handoff describes. The current measurements are at the end of each file.
2. `resource_profiles()` and the handoff seed ranges disagree. The handoff's profile table should replace the hard-coded list.
3. The storefront comment says a missing or old snapshot "never blocks an order". That is intended for the storefront only, and it conflicts with the handoff rule that unknown critical data must not silently pass. Needs a decision.

## 5. Recommended Phase 2 scope

1. Define one shared inventory schema for Linux and Windows agents, using the fields in `hw.go`.
2. Add `DB_PREFIXagent_hardware_inventory` as an InnoDB table. New tables follow the `DB_PREFIX` convention.
3. Extend the agent RPC with a read-only inventory call. No benchmark runs in Phase 2.
4. Store inventory in the panel. Keep the existing storefront behaviour unchanged.

Out of scope for Phase 2: benchmarks, parent pools, reservations, checkout changes, and any change to live placement.

## 6. Open decisions for the operator

1. Whether storefront gating should treat stale or missing snapshots as unavailable, or keep the current permissive behaviour until the new engine is ready.
2. Whether the Resource Planner profile list should move to the database now, or after Phase 2.
3. Whether `gse-loadtest` remains a standalone tool, or its inventory and benchmark code moves into the agents.
