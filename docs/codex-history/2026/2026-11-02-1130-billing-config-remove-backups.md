# Task

## User Request

Remove billing-specific config backup retention, billing config backup creation/pruning/listing/restoration, and the raw PHP config editor from the billing admin. Preserve PayPal settings saves, validate the changed PHP files and lifecycle test, run the requested billing page harness, and commit on `testing` without pushing.

## Problem

The billing admin exposed a config backup retention setting and a raw editor for the legacy billing `config.inc.php`, including timestamped backup creation and pruning. Billing configuration backups belong to the whole-panel backup system.

## Root Cause

`modules/billing/admin_config.php` contained the retention setting, backup helpers, raw save handler, backup listing, and raw editor UI. The billing loader and example config also retained the obsolete setting.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `modules/billing/admin_config.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/includes/config.example.php`
- `home.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`

## Files Changed

- `modules/billing/admin_config.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/includes/config.example.php`
- `docs/codex-history/2026/2026-11-02-1130-billing-config-remove-backups.md`

## Architecture Decisions

- PayPal settings continue to save through `billing_config` exactly through the existing database path.
- Billing no longer owns retention metadata or backups for its legacy config file.
- The raw config editor and its file-write/lint/rollback flow are removed without replacement.
- Unrelated game-server/XML backup functionality was left unchanged.

## Validation Performed

- `php -l modules/billing/admin_config.php` — passed.
- `php -l modules/billing/includes/config_loader.php` — passed.
- `php -l modules/billing/includes/config.example.php` — passed.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` — passed.
- `git diff --check` — passed.
- Targeted grep for `SITE_CONFIG_BACKUP_RETENTION`, `config backup`, `backup retention`, and `Raw Config Editor` under `modules/billing` — no matches.
- Requested `/tmp` page harness was created and removed, but could not run because this checkout lacks the protected `includes/config.inc.php`; no protected config was created or changed.

## Git Commits

Repository

`GSE-Panel`

Commit

`billing config: remove backup retention and raw config editor`

Hash

Final amended commit hash is reported in the task handoff.

## Remaining Work

The full page harness should be rerun in an environment with the panel's existing `includes/config.inc.php` available.
