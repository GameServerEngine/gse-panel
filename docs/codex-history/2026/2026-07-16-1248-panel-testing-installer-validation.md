# Task

## User Request

Create and validate a development test install of the GSE Panel by copying the
current Panel code to a dev web-host path, resetting the `panel-testing`
database on `mysql.iaregamer.com:3307`, and running the existing installer or
CLI installer to recreate schema and configuration.

## Problem

The development web deployment could not be completed from this session because
`/var/www/html` is owned by `root:root`, no safe existing Panel test path was
present, and passwordless sudo was unavailable. Destructive database reset was
therefore not performed.

During non-destructive installer validation, the CLI installer also rejected the
required database name `panel-testing` because database validation allowed only
letters, numbers, and underscores.

## Root Cause

The installer validation and backup/reset SQL assumed unhyphenated MySQL
database identifiers. The shared install engine stripped non-word characters
when building qualified backup/drop SQL, which would make a hyphenated database
name unsafe for existing-table backup/reset paths.

The deployment blocker was environmental: the requested Apache document root is
not writable by the current `dev` user, and `sudo -n true` reports that a
password is required.

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
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/APACHE_SETUP.md`
- `docs/features/INSTALLERS.md`
- `docs/decisions/0006-installers.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/plans/ACTIVE.md`
- `docs/codex-history/2026/2026-07-10-1046-panel-shell-installer.md`
- `docs/codex-history/2026/2026-07-11-1832-database-schema-audit.md`
- `docs/codex-history/2026/2026-07-12-0834-panel-migration-verification.md`
- `.devplus/codex-runs/FINAL_REPORT.md`
- `.gitignore`
- `install.php`
- `includes/install_engine.php`
- `includes/helpers.php`
- `includes/database_mysqli.php`
- `includes/database.php`
- `modules/modulemanager/module_handling.php`
- `panel.sql`
- `scripts/install-panel.sh`
- `tools/install_panel_cli.php`
- `check.php`

## Files Changed

- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `includes/install_engine.php`
- `scripts/install-panel.sh`

## Architecture Decisions

- Keep browser and CLI installer behavior centralized in
  `includes/install_engine.php`.
- Support hyphenated MySQL database names by validating a narrow safe character
  set and quoting identifiers instead of stripping characters.
- Stop installation if existing tables cannot be backed up and cleared, rather
  than continuing with a partial schema install.
- Do not reset `panel-testing` until the dev web copy can be created safely.

## Validation Performed

- Read initial Git status, branch, remote, upstream, recent commits, and diff.
- Verified `mysql.iaregamer.com:3307` selected database `panel-testing` with
  current user `gsetesting@%`.
- Captured pre-reset table count: 63 tables.
- Verified MySQL grants include all privileges on `panel-testing` only.
- Verified PHP 8.3 CLI and required installer extensions except `xmlrpc`.
- Verified MySQL client availability.
- Verified Apache is running and main document root is `/var/www/html`.
- Verified `/var/www/html` is not writable by the current user and passwordless
  sudo is unavailable.
- Ran `php -l install.php`.
- Ran `php -l includes/install_engine.php`.
- Ran `php -l tools/install_panel_cli.php`.
- Ran `bash -n scripts/install-panel.sh`.
- Ran `php tools/install_panel_cli.php --base "$PWD" --config <temp-json> --dry-run`
  for `panel-testing`.
- Ran `git diff --check`.

## Git Commits

Repository

Panel

Commit

Not committed because full deployment validation was blocked.

Hash

N/A

## Remaining Work

- Create or grant access to `/var/www/html/panel-testing` for the dev install.
- Reset all tables in `panel-testing` only after the web copy path is writable.
- Run the CLI installer from the deployed web copy.
- Verify generated `includes/config.inc.php`, created tables, admin user, basic
  web load, and Apache/PHP logs.
