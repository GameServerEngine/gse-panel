# Task

## User Request

Fix the GSE Panel installer and dependency checker so the shell installer can run successfully from varied working directories, uses clearer defaults and prompts, parses database host ports safely, labels current XML-RPC and PEAR dependencies as required, provides a prerequisites helper, updates deployment docs, validates the changes, commits, and pushes.

## Problem

The CLI installer could fatal before reaching the shared install engine when launched from a non-Panel working directory. The shell installer also defaulted to MariaDB and `/var/www/gsp-panel`, had unclear domain/vhost/database host prompts, did not parse `host:port` database hosts, and the dependency checker labeled current XML-RPC and PEAR requirements as warnings.

## Root Cause

`tools/install_panel_cli.php` loaded legacy language helpers before changing PHP's process working directory to the Panel base. `includes/lang.php` and `makefilelist()` resolved `lang/` relative to the caller's current directory, and `makefilelist()` called `readdir()` even when `opendir()` failed. The installer prompt defaults and dependency checker status labels had not been updated to match current operator expectations or the current `includes/lib_remote.php` and `Crypt/XXTEA.php` requirements.

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
- `docs/deployment/APACHE_SETUP.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/admin-guide.md`
- `docs/decisions/0006-installers.md`
- `docs/plans/ACTIVE.md`
- `docs/plans/BACKLOG.md`
- `docs/codex-history/2026/2026-07-10-1046-panel-shell-installer.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `.devplus/codex-runs/FINAL_REPORT.md`
- `scripts/install-panel.sh`
- `tools/install_panel_cli.php`
- `includes/install_engine.php`
- `includes/helpers.php`
- `includes/lang.php`
- `install.php`
- `check.php`
- `includes/lib_remote.php`
- `Crypt/XXTEA.php`

## Files Changed

- `check.php`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/admin-guide.md`
- `docs/modules/PANEL_UPDATE.md`
- `docs/modules/UPDATE.md`
- `includes/helpers.php`
- `includes/lang.php`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- `scripts/validate-installer-cli.sh`
- `tools/install_panel_cli.php`
- `docs/codex-history/2026/2026-07-16-1423-panel-installer-prompts-prereqs.md`

## Architecture Decisions

- Keep browser and CLI installer behavior on the shared install engine and preserve legacy relative-path expectations by changing the CLI process working directory to the validated Panel base before loading legacy includes.
- Make language loading path-safe by resolving language directories from the Panel base and make `makefilelist()` return an empty list with an error-log diagnostic instead of throwing a fatal when a directory is missing.
- Keep MySQL as the default database server while retaining MariaDB as a supported choice.
- Keep vhost creation tied to a supplied domain; blank domain means use the existing web path and skip vhost creation.
- Parse `host:port` for domains, IPv4, and bracketed IPv6; leave bracketless IPv6 to the separate port prompt.
- Treat XML-RPC and PEAR as current required dependencies because the current agent transport and encryption helper require them.
- Add a prerequisites-only helper script that installs host packages and Apache modules without running the Panel installer or touching database state.

## Validation Performed

- Reproduced the original `/tmp` CLI dry-run crash before changes.
- `bash -n scripts/install-panel.sh`
- `bash -n scripts/install-panel-prereqs.sh`
- `bash -n scripts/validate-installer-cli.sh`
- `php -l tools/install_panel_cli.php`
- `php -l includes/helpers.php`
- `php -l includes/lang.php`
- `php -l includes/install_engine.php`
- `php -l install.php`
- `php -l check.php`
- `scripts/validate-installer-cli.sh`
- `php tools/install_panel_cli.php --base "$PWD" --config <temp-json> --dry-run`
- `(cd scripts && php ../tools/install_panel_cli.php --base .. --config <temp-json> --dry-run)`
- `bash scripts/install-panel.sh --validate-db-host-port` for `localhost`, `mysql.iaregamer.com`, `mysql.iaregamer.com:3307`, `127.0.0.1:3307`, `[2001:db8::1]:3307`, `2001:db8::1`, and invalid port `99999`.
- `bash scripts/install-panel.sh --dry-run --path "$PWD"` with separate database port input.
- `bash scripts/install-panel.sh --dry-run --path "$PWD"` with `mysql.iaregamer.com:3307` host input.
- `php -n -d include_path=/tmp check.php` confirmed XML-RPC and PEAR render as missing required items when absent.
- `php check.php` confirmed the prerequisite install block includes `php-xmlrpc`, `php-pear`, and `scripts/install-panel-prereqs.sh`.
- `git diff --check`

## Git Commits

Repository

Panel

Commit

fix: improve panel installer prompts and prerequisites

Hash

Pending at history creation; final hash is reported in the task final report.

## Remaining Work

- Run a full non-dry-run installer on a disposable Debian/Ubuntu VM with real Apache and MySQL services.
- Pre-existing uncommitted installer/database-name validation work remains in the working tree and was not reset.
