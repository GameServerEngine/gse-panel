# Task

## User Request

Fix duplicate language constants, duplicate PayPal admin rendering, exposed internal database fields, and billing-admin failure/status contrast on branch testing. Do not change core config, storefront behavior, or push.

## Problem

Billing admin pages emitted a duplicate `LANG_expired` warning, rendered two PayPal forms, exposed system-managed database fields, and used low-contrast failure/status colors.

## Root Cause

`modules/billing/admin_config.php` rendered a panel-only PayPal form at lines 446-455 in the pre-existing file, then rendered the canonical encrypted-database PayPal configuration form at lines 501 onward. `lang/English/modules/billing.php` defined `LANG_expired` at original lines 70 and 154 without guards.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel` (testing branch only)

## Files Read

- `AGENTS.md`
- `modules/billing/admin_config.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/includes/paypal_credentials.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_payments.php`
- `modules/billing/admin_locations.php`
- `modules/billing/admin_orders.php`
- `modules/billing/admin_cancellations.php`
- `modules/billing/css/header.css`
- `lang/English/modules/billing.php`

## Files Changed

- `lang/English/modules/billing.php`
- `modules/billing/admin_config.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_payments.php`
- `modules/billing/admin_locations.php`
- `modules/billing/admin_orders.php`
- `modules/billing/admin_cancellations.php`
- `modules/billing/css/header.css`

## Architecture Decisions

- Kept the later PayPal form because it is the canonical form that saves `billing_paypal_encrypt_secret` output into `billing_config`.
- Removed Database Name and Table Prefix from the UI. They remain panel-managed runtime variables and are not collected by the interactive save handler.
- Kept Site Base URL as a read-only display. It is loaded by `config_loader.php`, used to compute the PayPal webhook URL in the admin diagnostics, and used by storefront URL helpers; the interactive save handler now preserves the existing value rather than accepting or writing a submitted value.
- Added billing-admin-specific failure/status selectors and retained success badge colors.

## Validation Performed

- `php -l` passed for every changed PHP file.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- `git diff --check` passed.
- Exact duplicate language-constant scan found no remaining duplicate constants; `LANG_expired` has one guarded definition.
- Failure text color `#8b1e2d` on `#f8d7da` has a calculated contrast ratio of 6.77:1.
- The requested CLI render harness was run and stopped before billing rendering because this checkout has no `includes/config.inc.php`; therefore output counts and no-warning assertions could not be executed. The temporary harness was deleted.

## Git Commits

Repository

Commit

Hash

## Remaining Work

None within the requested scope. The render harness should be rerun in an environment with the panel database/core config available.
