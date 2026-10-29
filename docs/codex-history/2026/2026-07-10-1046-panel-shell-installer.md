# Task

## User Request

Workflow step 2 of `gsp-panel-installation-modernization`: add a downloadable Debian/Ubuntu-first root shell installer for the Panel, keep the web installer for manual clone users, share installer business logic between CLI and web paths, add install lock/state and secure web reinstall authorization, preserve backup/reinstall safety, validate, commit, and push `origin/unstable`.

## Problem

Panel installation was browser-only. `install.php` directly owned config generation, database backup/reset, module installation, and default admin creation. After install it replaced itself with a restore stub, but that restore path only had a session CSRF token and did not require an existing authenticated panel admin or a filesystem maintenance gate.

## Root Cause

Installer business logic was embedded in `install.php`, which made a secure CLI installer likely to duplicate database/module/admin behavior. The post-install self-disable model depended on renaming/restoring `install.php` instead of a durable installation state file and explicit authorization checks.

## Repositories Inspected

- `Panel`
- `GSP-Agent-Linux`
- `GSP-Agent-Windows`

## Files Read

- `AGENTS.md`
- `Panel/README.md`
- `Panel/docs/ARCHITECTURE.md`
- `Panel/docs/PANEL_ARCHITECTURE.md`
- `Panel/docs/AGENT_COMMUNICATION.md`
- `Panel/docs/REPOSITORY_STRUCTURE.md`
- `Panel/docs/COMMAND_FLOW.md`
- `Panel/docs/UPDATE_POLICY.md`
- `Panel/docs/development/CODEX_GUIDE.md`
- `Panel/docs/architecture/REPOSITORY_OVERVIEW.md`
- `Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `Panel/docs/architecture/API_REFERENCE.md`
- `Panel/docs/architecture/MODULE_DEPENDENCIES.md`
- `Panel/docs/architecture/LIBRARY_REFERENCE.md`
- `Panel/docs/modules/MODULE_INDEX.md`
- `Panel/docs/features/USER_API.md`
- `Panel/docs/deployment/GSP_INSTALLER.md`
- `Panel/docs/deployment/INSTALL_README.md`
- `Panel/docs/deployment/APACHE_SETUP.md`
- `Panel/docs/features/INSTALLERS.md`
- `Panel/docs/decisions/0006-installers.md`
- `Panel/install.php`
- `Panel/check.php`
- `Panel/includes/helpers.php`
- `Panel/includes/functions.php`
- `Panel/includes/database.php`
- `Panel/includes/database_mysqli.php`
- `Panel/includes/view.php`
- `Panel/includes/navig.php`
- `Panel/modules/modulemanager/module_handling.php`
- `Panel/modules/modulemanager/module.php`
- `GSP-Agent-Linux/README.md`
- `GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `GSP-Agent-Windows/README.md`
- `GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`

## Files Changed

- `Panel/check.php`
- `Panel/docs/deployment/GSP_INSTALLER.md`
- `Panel/docs/deployment/INSTALL_README.md`
- `Panel/includes/install_engine.php`
- `Panel/install.php`
- `Panel/scripts/install-panel.sh`
- `Panel/tools/install_panel_cli.php`
- `Panel/docs/codex-history/2026/2026-07-10-1046-panel-shell-installer.md`

## Architecture Decisions

- Moved install business logic into `includes/install_engine.php` so browser and CLI paths share config generation, database backup/reset, module installation, settings import, game config import, admin creation, and lock/state creation.
- Added `scripts/install-panel.sh` as the Debian/Ubuntu-first root installer. It supports Apache/Nginx, MariaDB/MySQL, Git clone/update retry, optional vhost/SSL, database creation, permission repair, and dry-run.
- Added `tools/install_panel_cli.php` as the PHP CLI bridge into the shared engine.
- Kept `install.php` for manual clone users, but made it a thin web wrapper around the shared engine.
- Added `includes/install.state.json` as the installation lock/state file. It stores non-secret metadata only.
- Browser reinstall is blocked for anonymous users on installed systems. Existing admin session plus short-lived `includes/install.maintenance` filesystem gate is required for web reinstall/repair.
- Root/sudo CLI execution counts as installer authorization.
- Secrets are accepted through prompts, hidden from output, and passed to PHP through a root-only temporary JSON file rather than command-line arguments.
- No agent repository code was changed because Panel installation does not alter agent RPC, lifecycle, or runtime file contracts.

## Validation Performed

- `git status --short --branch` before changes showed `Panel` clean on `unstable...origin/unstable`.
- `git diff` before changes showed no Panel changes.
- `php -l includes/install_engine.php`
- `php -l install.php`
- `php -l tools/install_panel_cli.php`
- `php -l check.php`
- `bash -n scripts/install-panel.sh`
- `git diff --check`
- `php tools/install_panel_cli.php --base "$PWD" --config <temp-json> --dry-run`
- Scripted `bash scripts/install-panel.sh --dry-run` against the current checkout with non-secret validation output inspected.
- `shellcheck` was attempted by command detection but is not installed in this environment.
- Code-path inspection verified anonymous web reinstall checks `gsp_install_panel_appears_installed()`, existing admin authorization, and the maintenance gate.
- Secret handling review verified the shell installer does not print supplied database/admin passwords and does not pass them as process arguments.

## Git Commits

Repository

Panel

Commit

Modernize panel installation automation

Hash

Pending at history creation; final hash is reported in the task final report.

## Remaining Work

- Install `shellcheck` in the development environment for stricter shell linting.
- Perform a full destructive install/reinstall test on a disposable Debian or Ubuntu VM with real Apache/Nginx and MariaDB/MySQL services.
- Plan a coordinated password hashing upgrade for the legacy MD5 panel login system.
