# Task

## User Request

Verification gate first, then Phase 3 only in `/home/dev/Projects/GSP/Panel`: inspect current git state, recent commits, Phase 2 centralized billing lifecycle service/repository APIs and tests; trace active invoice/order/server/admin editing paths; if compatible, repair billing buttons and admin billing editing by routing active status/date mutations through the centralized lifecycle service. Add safe admin controls for invoice payment status, paid_date, and approved service-term changes with authorization, CSRF, strict validation, confirmations, clear messages, focused tests, second-opinion trace, commit, and push if validation passes.

## Problem

Active billing admin and button flows still had unsafe or incomplete Phase 3 behavior after Phase 2. Admin invoice actions had no CSRF and only coarse one-click payment buttons. Panel admin orders still directly updated `billing_orders.status` for bulk status actions. Renewal links created invoices through GET. Provisioning could still be invoked with `order_id` in GET and the Panel provisioning forms had no CSRF token.

## Root Cause

Phase 2 centralized the core lifecycle transitions, but some active UI/request paths had not yet been rewired. The missing Phase 3 surface allowed direct order status writes, state-changing GET requests, and admin payment edits without explicit confirmation or CSRF protection.

## Repositories Inspected

- `Panel`

The user explicitly limited work to `/home/dev/Projects/GSP/Panel`.

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `README.md`
- `docs/modules/billing.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/deployment/billing-cron.md`
- `docs/codex-history/2026/2026-07-11-1856-panel-billing-lifecycle-service.md`
- `docs/codex-history/2026/2026-07-12-0834-panel-migration-verification.md`
- `modules/billing/navigation.xml`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_orders.php`
- `modules/billing/admin.php`
- `modules/billing/admin_payments.php`
- `modules/billing/admin_coupons.php`
- `modules/billing/create_servers.php`
- `modules/billing/my_orders_panel.php`
- `modules/billing/my_servers.php`
- `modules/billing/my_account.php`
- `modules/billing/renew_server.php`
- `modules/billing/cart.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/checkout_free.php`
- `modules/billing/includes/admin_auth.php`
- `modules/billing/includes/menu.php`
- `modules/billing/includes/login_required.php`
- `modules/billing/bootstrap.php`
- `modules/billing/billing_bootstrap.php`
- `home.php`
- `includes/navig.php`

## Files Changed

- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/includes/action_security.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_orders.php`
- `modules/billing/create_servers.php`
- `modules/billing/my_orders_panel.php`
- `modules/billing/my_servers.php`
- `modules/billing/my_account.php`
- `modules/billing/renew_server.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/tests/BillingActionSecurityTest.php`
- `modules/billing/tests/BillingRequestPathSourceTest.php`
- `docs/codex-history/2026/2026-07-13-0825-panel-billing-phase3-buttons.md`

## Architecture Decisions

- Keep `billing_invoices.payment_status` as payment truth and continue synchronizing legacy `billing_invoices.status` only through lifecycle/repository APIs.
- Add `BillingLifecycleService::adminUpdateInvoicePayment()` for explicit admin payment transitions, including paid_date validation and idempotent manual payment replay.
- Add `BillingLifecycleService::adminChangeServiceTerm()` for approved service-term edits, with `server_homes` lifecycle dates authoritative and `billing_orders.end_date` synchronized as compatibility.
- Reject service-term edits while an open renewal invoice exists, while the linked server home is not Active, or when chronology would make the latest paid invoice period inconsistent.
- Remove Panel admin order direct status bulk updates rather than preserving a duplicate transition system.
- Convert renewal and provisioning buttons to CSRF-protected POST flows; reject direct provisioning GET requests.
- Reuse a shared billing action CSRF helper for standalone billing admin pages and Panel billing forms.

## Validation Performed

- `git status --short --branch`
- `git diff`
- Recent commit inspection with `git log --oneline -n 12`
- PHP lint on all changed PHP files.
- `php modules/billing/tests/BillingLifecycleServiceTest.php`
- `php modules/billing/tests/BillingActionSecurityTest.php`
- `php modules/billing/tests/BillingRequestPathSourceTest.php`
- Direct-write/source scan for modified request paths.
- GET state-change scan for renewal/provision links.
- `git diff --check`

## Git Commits

Repository

Panel

Commit

Pending

Hash

Pending

## Remaining Work

- Live browser/database validation was not run in this environment.
- Existing billing tables may still be MyISAM on live installs, so database transaction rollback isolation remains limited until a separately approved engine migration.
