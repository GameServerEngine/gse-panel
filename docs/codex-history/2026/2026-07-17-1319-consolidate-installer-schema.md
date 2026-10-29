# Task

## User Request

Consolidate the GSE Panel installer schema setup on branch `testing` so fresh
install/recovery creates one canonical current schema, registers built-in
modules as part of the base product, stops using legacy per-module
`install_module()` during browser setup, preserves recent installer recovery,
dependency, responsive layout, contrast, and reinstall safety behavior,
validates the change, commits with message
`fix: consolidate panel installer schema setup`, pushes to `origin/testing`,
and deploys to `/var/www/html/panel-test` if possible.

## Problem

Fresh browser install/recovery still depended on the legacy OGP-style module
installation loop. That path could print raw module progress and leave installs
partial when a module schema step failed, while completion checks only covered
the original core tables.

## Root Cause

`gsp_install_run()` created the module metadata tables and then called
`gsp_install_all_modules()`, which calls `install_module()` for every module.
`install_module()` echoes `Adding module called ...` and executes each module's
historical install query list. The Server Content module query chain also has
fresh-install-sensitive historical ALTER ordering, so the base installer was
coupled to legacy per-module migration behavior instead of a canonical current
schema path.

## Repositories Inspected

- Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/decisions/0006-installers.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/APACHE_SETUP.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `docs/codex-history/2026/2026-07-16-1957-installer-repeatable-dev-install.md`
- `docs/codex-history/2026/2026-07-17-1043-installer-dependency-ux-fixes.md`
- `docs/codex-history/2026/2026-07-17-1135-installer-layout-recovery-flow.md`
- `docs/codex-history/2026/2026-07-17-1211-installer-accessibility-permissions.md`
- `.devplus/codex-runs/gp-10/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-10/verification.md`
- `.devplus/codex-runs/gp-11/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-11/verification.md`
- `.devplus/codex-runs/gp-12/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-12/verification.md`
- `.devplus/codex-runs/gp-13/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-13/verification.md`
- `.devplus/codex-runs/latest/FINAL_REPORT.md`
- `install.php`
- `includes/install_engine.php`
- `panel.sql`
- `sql/003_global_port_allocation.sql`
- `modules/modulemanager/module_handling.php`
- `includes/database_mysqli.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- module metadata files under `modules/*/module.php`

## Files Changed

- `install.php`
- `includes/install_engine.php`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-17-1319-consolidate-installer-schema.md`

## Architecture Decisions

- Keep `install_module()` available for module manager/runtime compatibility.
- Remove `install_module()` from the fresh browser/CLI installer path.
- Create fresh schema through a prefix-aware canonical import plus controlled
  GSE schema guards for current built-in tables, including global port
  allocation, billing lifecycle, updater metadata, and Server Content.
- Register shipped modules idempotently in `modules`, `module_menus`, and
  `module_access_rights` at their current versions instead of installing them
  one by one.
- Extend installer completion checks to include global port allocation tables.
- Preserve blank-prefix reinstall safeguards and do not drop unrelated tables.

## Validation Performed

- Confirmed branch `testing`.
- Confirmed no staged changes before editing.
- Reviewed current status, diff, and latest commits before editing.
- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l tools/install_panel_cli.php`
- `bash -n scripts/install-panel.sh`
- `bash -n scripts/install-panel-prereqs.sh`
- `bash -n scripts/validate-installer-cli.sh`
- `bash scripts/validate-installer-cli.sh`
- `php tools/install_panel_cli.php --base "$PWD" --config <temp-json> --dry-run`
- `git diff --check`
- Local PHP built-in server smoke check returned expected installer,
  settings-form, and dependency-check content for `install.php`,
  `install.php?step=1`, and `check.php`.
- Static inspection confirmed fresh install/recovery calls
  `gsp_install_create_canonical_schema()` and
  `gsp_install_register_builtin_modules()` rather than `install_module()`.
- Static inspection confirmed the remaining `install_module()` implementation is
  isolated to `modules/modulemanager/module_handling.php` for runtime/admin
  compatibility.
- Static inspection confirmed required global port allocation tables are part of
  completion validation.
- Static inspection confirmed browser result pages include visible success and
  failure actions and sanitize captured progress output.
- A destructive database install/reinstall was not run because no local
  non-production MySQL socket/config was available.

## Git Commits

Repository

Panel

Commit

fix: consolidate panel installer schema setup

Hash

Final hash reported in the task final report; a commit cannot contain its own
final hash without changing that hash.

## Remaining Work

- Attempt deployment to `/var/www/html/panel-test` if permissions allow.
