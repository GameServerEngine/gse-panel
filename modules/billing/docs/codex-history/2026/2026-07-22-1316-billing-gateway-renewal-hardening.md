# Task

## User Request

Complete workflow step `billing-gateways-renewals-provisioning` for `gse-panel-billing-phase-4-production-runtime-v1`: continue within `GSE-Panel/modules/billing` only and harden payment gateway behavior, webhook/callback handling, idempotency, renewal lifecycle, customer-visible status, and provisioning hooks. Avoid secrets, unrelated refactors, and duplicate billing systems. Preserve separation of payment, order, invoice, and provisioning status. Add validation/tests for retry, duplicate, pending, paid, failed, and renewal cases when practical.

## Problem

The billing runtime had a canonical PayPal webhook handler, but the legacy root webhook entrypoint still carried older duplicated JSON-file processing code. PayPal create/capture requests did not send provider idempotency keys. Webhook capture fulfillment could report unmatched captures as processed, amount-only matching could be ambiguous, and multi-invoice captures reused the total captured amount for each invoice instead of validating and allocating per invoice. Pending and denied gateway callbacks had no central non-mutating lifecycle path.

## Root Cause

Gateway behavior was split between the canonical PayPal webhook, a legacy root webhook, direct capture API calls, and lifecycle transitions. The paid-invoice lifecycle was central, but gateway attempts that were not paid had no equivalent central audit method, causing webhook code to either skip them or mutate order status directly.

## Repositories Inspected

GSE-Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/modules/billing.md`
- `docs/deployment/billing-cron.md`
- `modules/billing/README.md`
- `modules/billing/docs/README.md`
- `modules/billing/docs/codex-history/2026/2026-07-22-1307-billing-runtime-hardening.md`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/PaymentGatewayInterface.php`
- `modules/billing/classes/PayPalGateway.php`
- `modules/billing/classes/ManualGateway.php`
- `modules/billing/classes/StripeGateway.php`
- `modules/billing/classes/GatewayFactory.php`
- `modules/billing/api/create_order.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/webhook.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/payment_success.php`
- `modules/billing/return.php`
- `modules/billing/my_servers.php`
- `modules/billing/checkout_free.php`
- `modules/billing/renew_server.php`
- `modules/billing/cron-shop.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/tests/BillingCouponHelperTest.php`
- `modules/billing/tests/BillingActionSecurityTest.php`
- `modules/billing/tests/BillingRequestPathSourceTest.php`

## Files Changed

- `modules/billing/api/capture_order.php`
- `modules/billing/api/create_order.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/PayPalGateway.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/tests/BillingRequestPathSourceTest.php`
- `modules/billing/webhook.php`
- `modules/billing/docs/codex-history/2026/2026-07-22-1316-billing-gateway-renewal-hardening.md`

## Architecture Decisions

- Kept the existing invoice-first billing model and `BillingLifecycleService` as the central lifecycle boundary.
- Added `recordGatewayAttempt()` for pending/failed gateway audit rows without mutating invoice, order, or provisioning truth.
- Preserved canonical invoice payment statuses and order statuses; denied PayPal callbacks no longer write non-canonical order statuses.
- Routed the legacy root webhook endpoint to the canonical PayPal webhook handler to avoid duplicate callback behavior.
- Added provider idempotency request IDs to PayPal create/capture calls without storing or exposing secrets.
- Made webhook capture fulfillment retry-aware: unmatched and mismatched captures are not marked processed, duplicate transaction IDs are recognized, and multi-invoice captures are validated against invoice totals before payment lifecycle activation.

## Validation Performed

- `php -l` on every touched PHP file.
- `for test in modules/billing/tests/*Test.php; do php "$test" || exit $?; done`
- `find modules/billing -maxdepth 3 -type f -name '*.php' -print0 | xargs -0 -n1 php -l`
- `git diff --check`
- `git diff --stat`
- `git diff --name-only`
- `git status --short --branch`

## Git Commits

Repository

GSE-Panel

Commit

Pending

Hash

Pending

## Remaining Work

- Run live PayPal sandbox webhook/capture smoke tests against a configured gateway and database.
