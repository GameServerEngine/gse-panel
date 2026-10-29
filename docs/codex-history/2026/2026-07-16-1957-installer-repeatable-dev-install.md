# Task

## User Request

Fix the GSE Panel browser installer so repeat development installs against an
existing database can drop and recreate existing prefixed tables, preserve
safe installer form values after validation/install errors and Back navigation,
and avoid locking the browser installer after partial failed database installs.
Then validate the installer against the dev database
`mysql.isaserver.net:3307`, database `panel-testing`, user `gsptesting`, and
commit/push `testing` only after successful validation.

## Problem

The browser installer rendered step 1 from defaults or existing config only, so
safe submitted values were lost after step 2 errors. The installer lock could
treat a partial installation as installed based on state/config/admin checks
that did not verify the core schema. Existing reinstall reset behavior was not
scoped to the configured table prefix and depended on creating a backup database
before dropping target tables.

Required destructive dev database validation could not proceed because the
provided MySQL credentials were rejected before any table drop was attempted.

## Root Cause

Browser form state was not stored separately from password fields, and step 2
error paths linked back to step 1 instead of re-rendering the form with safe
submitted values. Installer completion detection did not require readable
config, a working database connection, core tables, and an admin user together.
The shared reinstall helper enumerated all tables and attempted a backup
database, which is broader than a prefix-scoped reinstall and incompatible with
limited dev database privileges.

## Repositories Inspected

- Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/features/INSTALLERS.md`
- `docs/decisions/0006-installers.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `docs/codex-history/2026/2026-07-16-1423-panel-installer-prompts-prereqs.md`
- `docs/codex-history/2026/2026-07-16-1505-release-zip-panel-installer.md`
- `.devplus/codex-runs/gp-5/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-6/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-7/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-8/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-9/FINAL_REPORT.md`
- `install.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `panel.sql`
- `check.php`
- `includes/helpers.php`
- `includes/database_mysqli.php`
- `modules/modulemanager/module_handling.php`
- `modules/settings/module.php`
- `modules/user_admin/module.php`
- `modules/server/module.php`
- `modules/gamemanager/module.php`
- `scripts/validate-installer-cli.sh`

## Files Changed

- `install.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-16-1957-installer-repeatable-dev-install.md`

## Architecture Decisions

- Keep browser and CLI installer behavior centralized in
  `includes/install_engine.php`.
- Treat `gsp_install_run()` as the explicit install/reinstall action; normal
  page load never drops tables.
- Before table drops, verify `SELECT DATABASE()` equals the configured database
  name.
- Enumerate base tables only and drop only tables matching the configured
  prefix. Do not drop views or routines.
- Allow a blank prefix only with an explicit all-table-drop confirmation.
- Stop before schema/module creation if any table drop fails.
- Preserve only safe browser form values in session; never re-render database or
  admin passwords into HTML.
- Lock anonymous browser installer access only when config, database, core
  tables, and at least one admin user prove the installation is complete.

## Validation Performed

- Confirmed branch `testing` tracking `origin/testing`.
- Confirmed initial tracked diff was clean and unrelated untracked DEV+1
  artifacts were present.
- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l tools/install_panel_cli.php`
- `php -l check.php`
- `bash -n scripts/install-panel.sh`
- `bash -n scripts/validate-installer-cli.sh`
- `scripts/validate-installer-cli.sh`
- `php tools/install_panel_cli.php --base "$PWD" --config <temp-json> --dry-run`
- `bash scripts/install-panel.sh --dry-run --path /tmp/gse-panel-dry`
- PHP source-level check of prefix table filtering.
- `rg` search for raw password exposure in changed files and docs.
- `git diff --check`
- Attempted guarded dev database verification with a temporary MySQL defaults
  file for host `mysql.isaserver.net`, port `3307`, database `panel-testing`,
  and user `gsptesting`.

Dev DB verification result:

```text
ERROR 1045 (28000): Access denied for user 'gsptesting'@'173.208.136.14' (using password: YES)
```

No destructive database operation was performed.

## Git Commits

Repository

Panel

Commit

Not committed because required dev database verification failed before
destructive install validation.

Hash

N/A

## Remaining Work

- Provide working credentials or grants for `gsptesting` from
  `173.208.136.14` to `mysql.isaserver.net:3307/panel-testing`.
- Re-run guarded dev DB verification.
- Capture pre-reset table count/list.
- Run the CLI installer from an isolated temp copy to exercise
  drop/recreate/install behavior.
- Verify post-install required tables and admin user.
- Commit `fix: make panel installer recoverable for repeat installs`.
- Push `origin/testing`.
