# Task

## User Request

Work on branch testing in /home/dev/Projects/GSE-Panel only. Do not touch stable. Do not push. Do not change includes/config.inc.php or any core config. Read the code before changing it.

Register the seven billing admin pages in modules/billing/navigation.xml through a new modules/billing/panel_admin.php dispatcher, whitelist the page names, preserve panel rendering context, verify XML/PHP/tests, and commit with message "billing admin: register pages in navigation.xml so the panel loads them".

## Problem

Billing admin pages were not consistently registered through the panel navigation dispatcher, causing invalid subpage or missing module execute-function errors.

## Root Cause

The panel resolves billing pages from navigation.xml and requires the selected target file to define exec_gsp_module(). The requested admin pages lacked a common panel dispatcher, while admin_orders.php also defined that function itself.

## Repositories Inspected

- /home/dev/Projects/GSE-Panel

## Files Read

- modules/billing/navigation.xml
- includes/navig.php
- modules/billing/includes/admin_auth.php
- modules/billing/bootstrap.php
- billing admin page sources

## Files Changed

- modules/billing/navigation.xml
- modules/billing/panel_admin.php
- modules/billing/admin_orders.php
- docs/codex-history/2026/2026-11-01-1400-billing-admin-navigation.md

## Architecture Decisions

- Route the seven requested filename keys to panel_admin.php.
- Keep storefront navigation entries unchanged.
- Use an exact whitelist before including a billing admin page.
- Rename the orders renderer to avoid a global exec_gsp_module() redeclaration when dispatched through panel_admin.php.

## Validation Performed

- `php -l modules/billing/panel_admin.php` passed.
- `php -l modules/billing/admin_orders.php` passed.
- `simplexml_load_file()` parsed navigation.xml and confirmed all seven requested keys resolve to panel_admin.php with admin access.
- The temporary `/tmp` panel-context harness resolved panel_admin.php and rendered admin_config.php; it was deleted after use.
- `php modules/billing/tests/BillingLifecycleServiceTest.php` passed.
- `git diff --check` passed.
- `xmllint` was unavailable; PHP SimpleXML validation was used instead.

## Git Commits

Repository

/home/dev/Projects/GSE-Panel

Commit

Pending at record creation; final commit SHA is reported in the task response.

Hash

Pending at record creation; final commit SHA is reported in the task response.

## Remaining Work

None for the requested change. A full web request through the production panel was not run because the requested devops harness is code-path only and has no database.
