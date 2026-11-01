# Task

## User Request

Make billing admin pages use the panel database when opened inside the panel, tolerate a missing billing config file, show visible database errors, validate via CLI, and commit on testing.

## Problem

Core panel installs do not contain `modules/billing/includes/config.inc.php`. Billing bootstrap defaults then replaced the panel database values, causing silent connection failure and blank admin pages.

## Root Cause

The billing loader treated its optional standalone config as mandatory and synchronized it. The bootstrap also loaded that loader before the panel-context admin authorization code copied panel runtime values.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `index.php`
- `home.php`
- `modules/billing/bootstrap.php`
- `modules/billing/includes/admin_auth.php`
- `modules/billing/includes/config_loader.php`
- Seven billing admin pages and `modules/billing/tests/BillingLifecycleServiceTest.php`

## Files Changed

- `modules/billing/bootstrap.php`
- `modules/billing/includes/admin_auth.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/admin_payments.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_cancellations.php`
- `modules/billing/admin_locations.php`
- `modules/billing/admin_coupons.php`
- This history record

## Architecture Decisions

Panel DB values are captured before billing config loading and restored in memory only for panel requests. Missing billing config is a silent in-memory fallback and is never written. Standalone behavior remains config-file-driven.

## Validation Performed

All changed PHP files passed `php -l`; `php modules/billing/tests/BillingLifecycleServiceTest.php` passed. The temporary CLI harness was deleted after use. Full form-count verification was blocked because this checkout lacks the panel `includes/config.inc.php`; direct probes also hit a pre-existing `StripeGateway` abstract-method fatal on cancellations and did not reproduce panel routing reliably.

## Git Commits

Repository

Commit

Hash

## Remaining Work

Run the requested seven-page harness on a checkout with the real panel config and resolve the unrelated Stripe gateway fatal if cancellations must render in that environment.
