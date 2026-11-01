# Task

## User Request

Make all seven billing admin pages render inside the panel for panel administrators on branch testing, without changing stable, core configuration, storefront behavior, or includes/config.inc.php. Implement the Stripe interface method, run the specified harness and tests, and commit the work.

## Problem

Several billing admin pages either suppressed their markup in panel context, depended on the panel dispatcher when directly included, or could not load Stripe because its class did not satisfy the gateway interface.

## Root Cause

Standalone pages wrapped their content, rather than only storefront chrome, in `if (!billing_is_panel_context())`. `admin_orders.php` defined `exec_gsp_module()` but did not invoke it when directly included by the render harness. `StripeGateway` lacked the required `refund(array): array` method.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- Billing admin pages, billing bootstrap/auth/config/session bridge includes
- Billing gateway interface and PayPal/Stripe gateway classes
- Billing lifecycle test

## Files Changed

- Eight billing PHP files listed by `git status` for this task

## Architecture Decisions

- Storefront headers, menus, footers, and document wrappers remain outside panel context.
- Panel requests retain page content and graceful database-error fallbacks.
- Stripe remains intentionally unsupported for live payments; `refund()` returns `stripe_not_supported`.
- Orders remain compatible with the normal panel dispatcher through an idempotent renderer guard.

## Validation Performed

- Read-only `/tmp` render harness run for all seven pages.
- `php -l` passed for every changed PHP file.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- `git diff --check` passed.
- Devops has no panel database credentials; database-backed pages were verified through graceful DB-error paths.

## Git Commits

Repository

Commit

Hash

## Remaining Work

Repeat the seven-page render harness with panel database access to verify populated tables and location/cancellation forms. Storefront behavior was not changed.
