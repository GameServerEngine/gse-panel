# Task

## User Request

Make billing admin pages open from the panel Administration menu for panel administrators while preserving direct storefront admin behavior. Restore the seven billing admin menu entries, avoid nested documents in the panel frame, validate the PHP files and lifecycle test, and commit on branch testing.

## Problem

Billing admin authorization redirected panel requests to the storefront login and exited. The standalone admin pages also emitted full HTML documents when included by the panel.

## Root Cause

`modules/billing/includes/admin_auth.php` only recognized the storefront session. The panel dispatcher includes billing pages from `index.php` with `m=billing`, but the pages had no embedded-layout mode. Billing menu metadata had also been removed.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `index.php`
- `includes/navig.php`
- `includes/install_engine.php`
- `includes/database_mysqli.php`
- `modules/billing/includes/admin_auth.php`
- `modules/billing/includes/session_bridge.php`
- `modules/billing/module.php`
- `modules/billing/navigation.xml`
- The seven billing admin page files
- `modules/billing/tests/BillingLifecycleServiceTest.php`

## Files Changed

- `modules/billing/includes/admin_auth.php`
- `modules/billing/module.php`
- `modules/billing/admin_config.php`
- `modules/billing/admin_payments.php`
- `modules/billing/admin_orders.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_cancellations.php`
- `modules/billing/admin_coupons.php`
- `docs/codex-history/2026/2026-11-01-1200-billing-admin-panel-pages.md`

## Architecture Decisions

Panel context is detected exactly when `$_SESSION['user_id']` is non-empty, `$_GET['m'] === 'billing'`, and `basename($_SERVER['PHP_SELF']) === 'index.php'`. In that context authorization uses the bridged panel session's `users_role`, case-insensitively, and requires `admin`. Unauthorized requests receive a short error div and stop without redirect headers. Standalone requests retain the existing storefront login redirect and role lookup.

The installer already registers `$module_menus` on fresh installs, so metadata supplies the seven rows there. Version 12 adds idempotent `INSERT ... SELECT ... WHERE NOT EXISTS` statements for existing installs.

## Validation Performed

- `php -l` passed for every changed PHP file.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- Static review confirmed the storefront branch does not satisfy the panel-context condition; a logged-in browser and live database menu migration were not available.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`billing: admin pages open inside the panel for panel admins`

Hash

`317031e4` (amended after recording this history entry)

## Remaining Work

Verify the menu and each page visually with a logged-in panel administrator and a direct storefront administrator in a deployed environment.
