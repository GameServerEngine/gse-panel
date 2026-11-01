# Task

## User Request

Make the billing PayPal admin usable and safe, register billing admin pages in the Administration menu, update stale admin help, validate the changes, and commit on branch testing without pushing.

## Problem

The billing admin configuration writer regenerated PayPal client secrets into a PHP config file, while existing billing admin pages were not registered in the panel menu. The dashboard help also referenced obsolete configuration and webhook paths.

## Root Cause

The legacy config editor treated PayPal credentials as file-backed settings even though the billing module already had encrypted database credential helpers and columns. Menu metadata and migration registration had not been updated for the billing admin pages.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `modules/billing/admin_config.php`
- `modules/billing/includes/paypal_credentials.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/classes/PayPalGateway.php`
- `modules/billing/module.php`
- `modules/billing/admin.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/billing/tests/PayPalCredentialsTest.php`
- `includes/install_engine.php`
- `includes/database_mysqli.php`
- `modules/update/module.php`

## Files Changed

- `modules/billing/admin_config.php`
- `modules/billing/module.php`
- `modules/billing/admin.php`
- `docs/codex-history/2026/2026-11-01-1200-billing-paypal-admin-safety.md`

## Architecture Decisions

- PayPal secrets remain in the existing encrypted `billing_config.paypal_client_secret_enc` column and are never emitted by the config-file generator.
- Legacy config values remain read-only runtime fallbacks for existing installations.
- Billing menu rows use the panel’s established `subpage`, `group`, `menu_name`, and `pos` representation.
- Migration 12 inserts missing billing menu rows by billing module ID and is safe to rerun.

## Validation Performed

- `php -l` on every changed PHP file.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- `php modules/billing/tests/PayPalCredentialsTest.php` passed.
- `git diff --check` passed.
- Checked `modules/billing/includes/config.inc.php` for PayPal secret assignments without printing values.

## Git Commits

Repository

Commit

Hash

## Remaining Work

- No push performed. Production migration execution remains to be performed through the normal panel update path.
