# Task

## User Request

Complete workflow `panel-billing-buttons-lifecycle`, step `phase-2-domain-service`, only in `/home/dev/Projects/GSP/Panel`: verify schema docs and `/home/dev/Projects/GSP/panel.sql`, re-trace billing payment/provisioning/cron/admin/category paths, implement verified Phase 2 centralized billing lifecycle changes, add guarded migrations and focused tests, validate with focused PHP checks, and do not commit or push.

## Problem

Billing lifecycle state was being written independently by PayPal capture, webhook fulfillment, free checkout, the standalone payment processor, renewal invoice creation, provisioning linkage, admin invoice actions, and cron. These paths duplicated invoice/order/server date calculations and could replay payment or cron transitions in ways that duplicated transactions, extended dates more than once, or left invoice/order/server lifecycle fields out of sync.

## Root Cause

The active schema separates payment truth, legacy invoice/cart status, renewal invoice lifecycle, order/provisioning state, server-home lifecycle state, and transaction audit rows. Existing code did not consistently respect that separation because it lacked a single transactional lifecycle service and shared date rules for paid-through periods, renewal invoice periods, provisioning linkage, expiry, refund/cancel, and delete-prep checks.

## Repositories Inspected

- `Panel`

Workflow scope required work only in `Panel`. Parent `AGENTS.md` and `/home/dev/Projects/GSP/panel.sql` were inspected as workspace/schema inputs.

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/panel.sql`
- `README.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/modules/billing.md`
- `docs/deployment/billing-cron.md`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/checkout_free.php`
- `modules/billing/renew_server.php`
- `modules/billing/create_servers.php`
- `modules/billing/cron-shop.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_orders.php`
- `modules/billing/module.php`
- `modules/billing/add_to_cart.php`
- `modules/billing/serverlist.php`
- `modules/billing/order.php`
- `includes/database_mysqli.php`

## Files Changed

- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/checkout_free.php`
- `modules/billing/renew_server.php`
- `modules/billing/create_servers.php`
- `modules/billing/cron-shop.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/module.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `docs/codex-history/2026/2026-07-11-1856-panel-billing-lifecycle-service.md`

## Architecture Decisions

- Added `BillingLifecycleService` as the centralized billing transition path.
- Kept `billing_invoices.payment_status` as payment truth and synchronized legacy `billing_invoices.status` for active compatibility states.
- Kept `billing_invoices.billing_status` for renewal invoice lifecycle, `billing_orders.status` for provisioning linkage, and `server_homes` billing columns for running-server lifecycle.
- Reused one date rule path for duration normalization and paid-through calculation.
- Routed paid invoice activation, renewal invoice creation, provisioning linkage, cancellation/refund, expiry, restoration, and deletion preparation through lifecycle service methods.
- Added idempotent transaction logging by relying on invoice/external transaction uniqueness checks in `BillingRepository::logTransaction`.
- Added delete-prep guard that refuses deletion when the expired server lacks a last-invoice anchor or has a paid invoice at or after that anchor.
- Added only verified guarded migrations: `billing_orders.paypal_data`, invoice `home_id` index guard, cron-oriented `server_homes` billing index, and `server_homes.last_invoice_id` index.
- Did not alter `coupon_id` nullability or `out_of_stock` type.

## Validation Performed

- `git status --short`
- `git diff`
- `php -l` on all edited PHP files.
- `php modules/billing/tests/BillingLifecycleServiceTest.php`
- `git diff --check`
- `php -r 'define("GSP_DB_PREFIX", "gsp_"); include "modules/billing/module.php"; echo "db_version={$db_version}; migration8=" . count($install_queries[8] ?? []) . PHP_EOL;'`

## Git Commits

Repository

Panel

Commit

None. The workflow step explicitly said not to commit or push.

Hash

N/A

## Remaining Work

- Live schema uses MyISAM for `billing_invoices`, `billing_orders`, and `server_homes`; code now uses transaction wrappers, but MyISAM cannot provide real rollback isolation until a separately approved engine migration is designed and validated.
- Existing live data in `/home/dev/Projects/GSP/panel.sql` contains historical invoice status mismatches. No backfill was applied because this step only allowed guarded schema drift needed by the implementation.
- Admin order bulk status actions still write `billing_orders.status` directly; they do not write payment dates or server lifecycle dates, but a later admin workflow cleanup may route them through explicit lifecycle actions.
