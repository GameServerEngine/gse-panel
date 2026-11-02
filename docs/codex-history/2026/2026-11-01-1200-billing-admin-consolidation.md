# Task

## User Request

Investigate the blank billing admin page through the real panel dispatch path, consolidate billing administration into Billing Setup and Billing Operations tabs, validate the result, and commit on testing without changing core configuration or pushing.

## Problem

The panel navigation and billing dispatcher exposed seven legacy billing admin page keys. The consolidated page keys did not exist, so the requested panel route could not dispatch to billing content. The requested CLI entry-point harness also could not load the absent local `includes/config.inc.php` from `/tmp`.

## Root Cause

`includes/navig.php` performs an exact `navigation.xml` key lookup, then calls `exec_gsp_module()`. The billing dispatcher only accepted the legacy `admin_*.php` keys. The real `home.php` harness stopped earlier because its relative core config include was resolved from `/tmp` and the development checkout has no core config file.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `home.php`
- `includes/navig.php`
- `includes/view.php`
- `modules/billing/panel_admin.php`
- `modules/billing/navigation.xml`
- `modules/billing/module.php`
- `modules/billing/includes/admin_auth.php`
- Billing admin section implementations and billing bootstrap/config-loader files

## Files Changed

- `modules/billing/panel_admin.php`
- `modules/billing/navigation.xml`
- `modules/billing/module.php`
- This history record

## Architecture Decisions

- Keep existing section implementations and include exactly one section per request.
- Whitelist page and section keys in `panel_admin.php`.
- Keep panel-context authentication and database behavior in `admin_auth.php` unchanged.
- Add an idempotent db version 13 menu migration; leave db version 12 unchanged.

## Validation Performed

- PHP lint passed for all changed PHP files.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- `navigation.xml` parsed successfully with `simplexml_load_file`.
- The requested `/tmp` harness reached `home.php` but stopped at line 41 because `includes/config.inc.php` is unavailable in the checkout and relative includes resolve from `/tmp`; no core config was changed.

## Git Commits

Repository

Commit

Hash

## Remaining Work

Production DB migration execution and full rendered HTML verification require devops database/config access, which is unavailable in this environment.
