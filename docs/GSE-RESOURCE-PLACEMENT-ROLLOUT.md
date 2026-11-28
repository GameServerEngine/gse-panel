# Resource placement: how it works and how to roll it out

Status 2026-11-28: code and tests are in the working tree, uncommitted. Nothing is deployed, no migration
has run on any real database, and no benchmark has been run. Public sales stay closed until the steps
under "Rollout" are done, because the gate fails closed.

## What the storefront does (modules/billing, order.gameserverengine.com)

- Every location stays visible. A location with no eligible host is greyed out, marked Unavailable and cannot be selected.
- A game card shows Unavailable when no location can take it. Configure Server stays enabled, so a visitor
  can still choose slots and duration and see the price. Checkout is blocked.
- When slots, duration or quantity change, the page asks `api/configuration_quote.php`, which re-checks every
  location for that configuration, clears a selection that is no longer valid and recomputes the price.
  Code: `configuration.js`, `includes/configuration_quote.php`, `includes/storefront_locations.php`.

## What decides "eligible host" (includes/resource_model.php, `resource_decision`)

All gates apply independently. Any unknown, stale or uncalibrated input rejects. Nothing unknown counts as spare capacity.

| Gate | Rejects when |
| --- | --- |
| Host window | the agent's latest window is older than 180 s, is not version 2, or the host purpose is not `public` |
| Calibration | not approved, older than 30 days, from the future, different hardware fingerprint, platform/volume/game root differ |
| Profile | not approved, older than 30 days, build differs from the installed build, fewer than 30 active or 1 idle sample, slots above the tested maximum, method differs from the calibration, a saturated source sample |
| CPU (two gates) | the busiest thread (`cpu_s`) exceeds the host's single-thread floor, or total demand (`cpu_t`) exceeds the aggregate floor, each with headroom |
| Steal | Linux steal fraction unknown or above the calibration limit |
| RAM | bytes needed (peak working set) do not fit in available memory with headroom |
| Disk | install bytes (or the measured floor) + staging + growth, with no shared-file credit, do not fit in the free space of the target filesystem |
| Storage | read/write bytes per second or IOPS exceed the measured sustainable headroom, or latency exceeds the game's budget |
| Reservations | other held reservations on the host are charged first |

CS2 example (the operator's numbers): the install is 71,106,011,864 bytes. game5 has 43 GiB free, so it fails the
disk gate (`insufficient disk_bytes`). game6 has 129 GiB free and passes the disk gate only. Its CPU,
RAM, storage and calibration gates still apply on their own. Both cases are in `tests/resource_model_test.php`
with synthetic envelopes, not real scores.

## Reservations and checkout parity

`placement_reserve_invoices()` (includes/placement_store.php) runs in the same transaction as the host row
lock. It is called from `api/create_order.php`, `api/paypal_checkout.php`, `api/stripe_checkout.php`,
`checkout_free.php`, `api/capture_order.php` (payment return) and both branches of `includes/provision_body.php`.
`BillingLifecycleService::processPaidInvoice` re-checks an unpaid, order-less game invoice before it is marked paid.
A reservation expires (15 minutes for free checkout, 1 hour for card checkout) and becomes permanent when provisioning
starts. A changed catalog host or game after the quote is refused.

## Data the gate reads

- `placement_host`: purpose (`profiling` or `public`) and the calibration envelope per host.
- `placement_profile`: approved per-offer profile (game build, samples, peak and percentile demand).
- `placement_hold`: reservations.
- `status_sample.resource_json` and `game_sample.resource_json`: raw agent telemetry (see the Linux agent's `usage.<id>` line).
- Empty `placement_host` / `placement_profile` tables keep public placement closed. That is intended.

## Migrations

- `0017_hardware_neutral_game_usage.sql` (adds GRU columns and `agent_cpu_score` with preliminary, unapproved scores).
  `agent_cpu_score` is read only for the usage display in the status poller. It does not decide eligibility.
- `0018_resource_placement.sql` (placement tables and `resource_json` columns).
- Both use `ALTER TABLE ... ADD COLUMN`, which MySQL 8 cannot make repeatable. They are safe only through the
  migration runner (`schema_migrations`). If a statement fails half way, restore the dump taken before the update. Do not run them by hand a second time.
- **Number collision:** the `feature/panel-ssh-agent-install` workstream (worktree gse-panel-agent-deploy) may
  also add a `0017_*.sql`. Its committed tree stops at 0016, and that worktree was not touched. The runner sorts by file
  name and records the full name, so two files named 0017 do not break it, but the order is then alphabetical. Whichever
  branch merges second should renumber to the next free number (0019 or higher) before it merges.
- Not run: no scratch MySQL was available (no server binary, Docker socket denied). Only the runner's unit tests ran. The SQL
  was read, not executed.

## Rollout (needs the owner's approval for each live step)

1. Commit panel and Linux agent changes on `working`, merge to `testing`. Do not run the Sync testing workflow (see docs/TODO.md).
2. Dump the database, then update a dev panel and let the runner apply 0017 and 0018. Check the new tables and columns.
3. Update the Linux agents (game5, game6) so they send `usage.<id>`. Wait for samples. `tools/resource_profile.php` builds profiles from them.
4. On a host marked `profiling`, run the approved calibration (single-thread and aggregate CPU, mixed storage I/O). Record the result in
   `placement_host`. Never copy an unapproved or preliminary score.
5. Review a profile for each game, then approve it. Games without an approved profile stay Unavailable.
6. Set the host purpose to `public` last, one host at a time, and watch the storefront.

## Not done / blockers

- No migration was executed, no real payment, no benchmark, no live agent change.
- Windows agent: `/home/dev/projects/gse-agent-windows` was not reachable from this session (outside the allowed directories),
  so its status sampling, inventory and container handling were not inspected or changed. Defer until the tree is located.
- `perl -c agent_core.pl` cannot run on this machine (missing Frontier module). Only the extracted subs are tested.
- `tests/agent_inventory_db_test.php` skips without a test database.
- `tests/check_schema_drift.php` still fails on 85 tables (already in docs/TODO.md; install/gse_panel.sql is not regenerated).
- Placement tests use a SQLite stand-in (`modules/billing/tests/placement_sqlite.inc`). Row locking (`FOR UPDATE`) and
  `ON DUPLICATE KEY` behave differently on MySQL and need one run against a scratch MySQL.
