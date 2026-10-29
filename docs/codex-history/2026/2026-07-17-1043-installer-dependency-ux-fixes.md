# Task

## User Request

Fix the GSE Panel browser installer and dependency checker on branch `testing`:
remove SVN/subversion dependency checks, create missing runtime folders during
dependency checks, make missing `includes/config.inc.php` normal for fresh
installs, guide users from dependency check to a prominent `Install Now` action,
modernize the settings page, default table prefix to blank, and require
blank-prefix reset confirmation only when existing unprefixed Panel tables would
actually be dropped. Preserve gp-10 browser form retention, partial-install
recovery, password messaging, and repeat-install drop/recreate behavior. Validate,
commit, push, deploy to `/var/www/html/panel-test`, and verify HTTP access.

## Problem

The dependency checker still treated SVN as a dependency, reported fresh-install
runtime/config state as warnings, and did not clearly advance users from checks
to installation. The browser settings form still used the legacy table layout.
Blank-prefix installs were blocked merely because the prefix was blank when any
tables existed in the selected database.

## Root Cause

`check.php` had stale command/package guidance and treated missing optional
runtime directories and missing config as pre-install warnings. `install.php`
still rendered the old settings form and defaulted to `gsp_`. The shared install
engine mapped a blank table prefix to all base tables instead of known
unprefixed Panel tables, so it required broad confirmation even when only
prefixed tables existed.

## Repositories Inspected

- Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/features/INSTALLERS.md`
- `docs/decisions/0006-installers.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `docs/codex-history/2026/2026-07-16-1423-panel-installer-prompts-prereqs.md`
- `docs/codex-history/2026/2026-07-16-1505-release-zip-panel-installer.md`
- `docs/codex-history/2026/2026-07-16-1957-installer-repeatable-dev-install.md`
- `install.php`
- `check.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- `scripts/validate-installer-cli.sh`
- `panel.sql`

## Files Changed

- `check.php`
- `install.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- `scripts/validate-installer-cli.sh`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-17-1043-installer-dependency-ux-fixes.md`

## Architecture Decisions

- Keep install and reinstall behavior centralized in `includes/install_engine.php`.
- Preserve gp-10 recovery and form-state behavior.
- Treat blank/no table prefix as the product default across browser, CLI, shell,
  generated config, and docs.
- For blank-prefix reinstalls, detect known unprefixed Panel tables from
  `panel.sql`, module install SQL, and core table names. Require explicit
  confirmation only when those tables would be dropped.
- Do not drop unrelated or unrecognized tables automatically during blank-prefix
  installs.
- Let `check.php` create missing runtime directories with `0775` where possible,
  but report a warning if creation or writability fails.
- Keep XML-RPC and PEAR as current required dependencies; remove SVN entirely
  from Panel installer dependency guidance.

## Validation Performed

- Confirmed branch `testing` and no staged changes before editing.
- Reviewed current unstaged gp-10 installer changes and prior gp-10 history.
- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l tools/install_panel_cli.php`
- `php -l check.php`
- `bash -n scripts/install-panel.sh`
- `bash -n scripts/install-panel-prereqs.sh`
- `bash -n scripts/validate-installer-cli.sh`
- `bash scripts/validate-installer-cli.sh`
- CLI install dry-run with blank table prefix.
- Shell installer dry-run with blank table prefix.
- Temporary dependency-check run proving missing `upload/`, `cache/`, `log/`,
  and `temp/` directories are created and writable.
- Temporary dependency-check render proving missing `includes/config.inc.php` is
  described as installer-created fresh-install state.
- Source grep proving `subversion`/`svn` is absent from dependency checks and
  package guidance.
- Source/render grep proving `Install Now`, blank prefix default, modern settings
  layout, and blank-prefix table reset messaging are present.
- Source-level reset-scope check proving blank prefix drops known unprefixed
  Panel tables, ignores `gsp_` prefixed tables, and reports unrelated tables as
  unmatched.
- `git diff --check`

## Git Commits

Repository

Panel

Commit

fix: improve panel installer dependency flow

Hash

Pending at history creation; final hash is reported in the task final report.

## Remaining Work

- Commit and push after final diff review.
- Deploy committed `testing` tree to `/var/www/html/panel-test`.
- Verify deployed file parity and local HTTP response.
