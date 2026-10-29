# Task

## User Request

Complete workflow step `billing-runtime-core-hardening` for `gse-panel-billing-phase-4-production-runtime-v1`: improve the existing billing runtime inside `modules/billing` only, hardening order, invoice, payment, coupon, checkout, persistence, and lifecycle code without creating a duplicate billing system or changing interfaces/data formats unless required.

## Problem

Billing coupon and payment handling duplicated validation across cart, free checkout, and PayPal capture. Session coupons could remain trusted after expiry, usage-limit exhaustion, or game-filter changes. PayPal capture completed invoices without first verifying that the captured amount still matched the server-side cart total. Some lifecycle transitions ignored failed secondary writes after beginning a billing state transition.

## Root Cause

Coupon eligibility, discount allocation, and open-invoice selection were implemented independently in several runtime paths. The lifecycle service relied on repository methods for persistence, but not every write result was checked before commit.

## Repositories Inspected

GSE-Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/architecture/REPOSITORY_OVERVIEW.md`
- `docs/architecture/MODULE_DEPENDENCIES.md`
- `docs/ARCHITECTURE.md`
- `docs/UPDATE_POLICY.md`
- `modules/billing/README.md`
- `modules/billing/docs/README.md`
- `modules/billing/includes/README.md`
- `modules/billing/INVOICE_FIRST_FLOW.md`
- `modules/billing/PAYMENT_IMPLEMENTATION_SUMMARY.md`
- `modules/billing/COUPON_SYSTEM.md`
- `modules/billing/PANEL_INTEGRATION.md`
- `modules/billing/TESTING_CHECKLIST.md`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/includes/cart_helper.php`
- `modules/billing/includes/action_security.php`
- `modules/billing/add_to_cart.php`
- `modules/billing/cart.php`
- `modules/billing/checkout_free.php`
- `modules/billing/api/create_order.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/renew_server.php`
- `modules/billing/cron-shop.php`
- `modules/billing/classes/PayPalGateway.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/tests/BillingActionSecurityTest.php`
- `modules/billing/tests/BillingRequestPathSourceTest.php`

## Files Changed

- `modules/billing/includes/coupon_helper.php`
- `modules/billing/cart.php`
- `modules/billing/checkout_free.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/tests/BillingCouponHelperTest.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/docs/codex-history/2026/2026-07-22-1307-billing-runtime-hardening.md`

## Architecture Decisions

- Kept the existing invoice-first billing model and central `BillingLifecycleService` architecture.
- Added a module-local coupon helper instead of a duplicate billing subsystem.
- Preserved invoice/order status fields and existing data formats.
- Recomputed PayPal capture totals from database invoices and validated coupons server-side before completing invoices.
- Kept the history record inside `modules/billing/docs/codex-history` because this workflow explicitly limited changes to `modules/billing`.

## Validation Performed

- `find modules/billing -maxdepth 3 -type f -name '*.php' -print0 | xargs -0 -n1 php -l`
- `for test in modules/billing/tests/*Test.php; do php "$test" || exit $?; done`
- `git diff --stat`
- `git diff --name-only`
- `git status --short`

## Git Commits

Repository

GSE-Panel

Commit

Pending

Hash

Pending

## Remaining Work

- Run live PayPal sandbox/free-checkout smoke tests against a configured database and gateway account.
