# Task

## User Request

Work on branch testing in `/home/dev/Projects/GSE-Panel` only. Do not touch stable. Do not push. Do not change `includes/config.inc.php` or any core config. Remove billing admin menu registration from `modules/billing/module.php`, make db-version 12 a no-op, keep db-version 11 unchanged, run the requested validations, and commit with the specified message and trailers.

## Problem

Billing admin pages are standalone storefront pages but the billing module registered them in the panel menu. The db-version 12 migration also reinserted those menu rows for existing installations.

## Root Cause

`$module_menus` contained seven billing admin subpages, and `$install_queries[12]` inserted corresponding rows into `module_menus`.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `modules/billing/module.php`

## Files Changed

- `modules/billing/module.php`
- `docs/codex-history/2026/2026-11-01-0000-billing-menu-registration.md`

## Architecture Decisions

- Keep `$module_menus` defined as an empty array so the module interface remains intact.
- Keep `install_queries[12]` present as a no-op using the existing `"SELECT 1"` migration style, preventing future menu-row insertion.
- Leave `install_queries[11]` unchanged.

## Validation Performed

- `php -l modules/billing/module.php` — passed.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` — passed.

## Git Commits

Repository

Commit

`GSE-Panel`

`billing: keep admin pages out of the panel menu`

`17a29f59` (superseded by the amended commit containing this history update)

## Remaining Work

None.
