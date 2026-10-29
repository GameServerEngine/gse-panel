# Task

## User Request

Perform a verification-gate schema audit before Phase 2. Work only in `/home/dev/Projects/GSP/Panel`, use `/home/dev/Projects/GSP/panel.sql` as the live production schema source of truth, compare `Panel/panel.sql` only as baseline, inspect module SQL/PHP usage/recent git state/Phase 1 report, produce durable schema reference and audit documents, update the Phase 2 plan from verified schema only, validate documentation references, and do not commit, push, stage `.devplus`, modify PHP, or make speculative schema changes.

## Problem

Phase 2 needs a verified database map before any billing/server lifecycle schema implementation. The repository baseline schema is stale relative to the uploaded live production dump, and active PHP usage spans billing, user_games, cron, gamemanager, and shared database helpers.

## Root Cause

The production database has evolved through module migrations, one-off SQL repair scripts, and live operational changes that are not fully reflected in `Panel/panel.sql`. Billing also has overlapping payment, invoice, order, and server lifecycle status fields that must not be collapsed without proof.

## Repositories Inspected

- `Panel`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/modules/billing.md`
- `docs/deployment/billing-cron.md`
- `/home/dev/Projects/GSP/panel.sql`
- `panel.sql`
- `modules/billing/PHASE1_COMPLETE_SUMMARY.md`
- `modules/billing/module.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/create_servers.php`
- `modules/billing/cron-shop.php`
- `modules/billing/add_to_cart.php`
- `modules/billing/checkout_free.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/admin_orders.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/adminserverlist.php`
- `modules/billing/*.sql`
- `modules/billing/sql/*.sql`
- `modules/user_games/module.php`
- `modules/user_games/admin_billing_migration.sql`
- `modules/user_games/billing_integration.php`
- `modules/user_games/edit_home.php`
- `modules/user_games/check_expire.php`
- `includes/database_mysqli.php`
- `includes/database.php`
- `includes/install_engine.php`
- `home.php`
- `index.php`

## Files Changed

- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/codex-history/2026/2026-07-11-1832-database-schema-audit.md`

## Architecture Decisions

- Treat `/home/dev/Projects/GSP/panel.sql` as the live source of truth because it is timestamped Jul 11, 2026 and materially differs from the repo baseline.
- Keep invoice payment state, invoice lifecycle state, order provisioning state, and server-home lifecycle state distinct.
- Do not create a redundant JSON mapping file because the markdown reference contains the complete live table/column inventory plus focused usage mapping.
- Do not modify schema, PHP, generated files, or application behavior in this verification gate.

## Validation Performed

- Parsed all live `CREATE TABLE` blocks and columns.
- Compared live schema to repository baseline table inventory.
- Inspected billing and user_games module migration SQL and PHP references.
- Verified cited PHP paths exist.
- Verified referenced live table and column names exist in the parsed live schema.
- Reviewed git status, git diff, and recent commits.

## Git Commits

Repository: Panel

Commit: Not committed per user instruction.

Hash: N/A

## Remaining Work

- Implement Phase 2 migrations only after reviewing the audit recommendations.
- Before altering production schema, inspect actual data distributions for nullable `coupon_id`, legacy order statuses, and `server_homes` lifecycle dates.
- Update module migration code and then run focused migration tests.
