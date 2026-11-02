# Task

## User Request

Work on branch testing in `/home/dev/Projects/GSE-Panel` only. Remove secret Show/Hide toggles and database/config-path display from `home.php?m=billing&p=billing_setup`, ensure PayPal settings save to the global `billing_config` row with encrypted secrets and blank-secret preservation, verify with a temporary CLI harness, run lint/tests, and commit without pushing.

## Problem

The billing setup page rendered internal configuration context and secret reveal buttons. Its interactive save path also attempted to write the billing config file after the PayPal database write, and its form values were not consistently reloaded from the database row.

## Root Cause

The page combined legacy config-file editing with the panel-managed PayPal database settings. The active PayPal row was written, but the form was initialized primarily from config variables and the secret fields were accompanied by inline reveal JavaScript.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel` (Panel repository; requested scope)

## Files Read

- `AGENTS.md`
- `modules/billing/admin_config.php`
- `modules/billing/includes/paypal_credentials.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/includes/action_security.php`
- `modules/billing/panel_admin.php`
- `modules/billing/module.php`
- `modules/billing/sql/010_paypal_credentials.sql`
- `modules/billing/tests/BillingLifecycleServiceTest.php`

## Files Changed

- `modules/billing/admin_config.php`
- `docs/codex-history/2026/2026-11-02-0425-billing-config-ui-save.md`

## Architecture Decisions

- PayPal settings remain panel database state in `billing_config` for `game_key='__global__'`.
- Blank submitted secrets preserve `paypal_client_secret_enc`; nonblank secrets use `billing_paypal_encrypt_secret`.
- The interactive PayPal save no longer writes `includes/config.inc.php`, preserving the user’s explicit no-config-file-change constraint.
- The schema has one PayPal credential set per global row, so the selected mode’s sandbox or live fields map to the shared five PayPal columns.
- Secret fields remain `password` inputs without reveal controls.

## Validation Performed

- `php -l modules/billing/admin_config.php` passed.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- `git diff --check` passed.
- The requested GET/POST harness could not render because the devops checkout has no `includes/config.inc.php`; creating one was intentionally avoided because the request forbids changing that file. Therefore no page-output counts or DB write could be verified in this environment.
- Repository grep found no `TEST_SECRET_ONLY`.

## Git Commits

Repository

`GSE-Panel`

Commit

`billing config: remove show/hide toggles and database display`

Hash

Final hash recorded after the history update commit.

## Remaining Work

- Run the requested GET/POST harness in an environment with the panel’s existing `includes/config.inc.php` and database.
- Verify the five-column SQL write against the live `billing_config` table.
