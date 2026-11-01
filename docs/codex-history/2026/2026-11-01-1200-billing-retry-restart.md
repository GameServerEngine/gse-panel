# Task

## User Request

Fix the billing restart gap so paid billing-suspended homes retry starting, add a shared restart decision helper and tests, validate PHP files, and commit on `testing` without pushing.

## Problem

When a billing-suspended home was paid but its immediate restart failed, `billing_suspended` remained set without a cron retry path.

## Root Cause

The payment path intentionally preserved the suspension flag on failed starts, but the billing cron had no follow-up restart step.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `modules/billing/cron-shop.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `AGENTS.md`

## Files Changed

- `modules/billing/cron-shop.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `docs/codex-history/2026/2026-11-01-1200-billing-retry-restart.md`

## Architecture Decisions

- Reused the same `exec_operation('start', ...)` address iteration as `BillingLifecycleService`.
- Added `BillingLifecycleService::shouldRetryBillingRestart()` as the shared eligibility decision.
- Cleared `billing_suspended` only after every address starts successfully; failed retries remain eligible.
- Confirmed payment processing already ignores restart return value and preserves the flag on failure.

## Validation Performed

- `php -l modules/billing/cron-shop.php`
- `php -l modules/billing/classes/BillingLifecycleService.php`
- `php -l modules/billing/tests/BillingLifecycleServiceTest.php`
- `php modules/billing/tests/BillingLifecycleServiceTest.php`

## Git Commits

Repository

Commit

Hash

## Remaining Work

None.

