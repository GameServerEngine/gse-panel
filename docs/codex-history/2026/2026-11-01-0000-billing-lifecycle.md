# Task

## User Request

Implement billing expiry suspension, one-week reminders, a 30-day grace default, lifecycle tests, validation, and a focused commit on `testing`.

## Problem

Billing expiry did not explicitly suspend homes, paid restoration did not distinguish billing suspension from a manual stop, and expiry reminders had no idempotency marker.

## Root Cause

The billing lifecycle stored billing status but had no runtime suspension flag or reminder date, and cron did not call the panel lifecycle stop path at expiry.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `modules/billing/cron-shop.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/billing/module.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`

## Files Changed

- `modules/billing/cron-shop.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/module.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`

## Architecture Decisions

- Use `exec_operation('stop', ..., true)` and `exec_operation('start', ..., true)` for the existing panel lifecycle path.
- Persist `billing_suspended` so manual stops are never restarted by payment.
- Persist `billing_reminder_sent_for` as the per-expiry idempotency key.

## Validation Performed

- `php -l` passed for every changed PHP file.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.

## Git Commits

Repository

Commit

Hash

## Remaining Work

None.
