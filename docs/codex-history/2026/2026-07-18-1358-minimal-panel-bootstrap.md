# Task

## User Request

Fix `scripts/install-panel.sh` so the latest unstable bootstrap no longer asks
old CLI questions after download, including web server type, database server
type, Panel web domain/vhost/SSL, database host/port/name/user/password, table
prefix, or initial admin credentials. The shell bootstrap must only ask for the
release source/channel or custom ZIP URL and install path, then download,
extract, install/check packages, set permissions, and print a clear browser URL
for `install.php`. The web installer must own database/admin/vhost/SSL
questions, installer action buttons must remain readable, required grep and
syntax validation must pass, and the result must be committed as
`fix: keep bootstrap installer minimal` and pushed to `origin/unstable`.

## Problem

The previous bootstrap simplification removed the main old prompt sequence, but
`scripts/install-panel.sh` still carried obsolete full-installer helpers for
secret prompts, two-choice prompts, database host/port validation, database/user
creation, vhost/SSL writing, and direct PHP install-engine execution.

## Root Cause

The prior change disconnected the legacy CLI install flow from the normal main
path but left stale helper code and a DB validation command in the bootstrap
script. That kept old bootstrap-owned database/vhost behavior present in the
downloaded unstable script and made the shell installer contract ambiguous.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`
- `/home/dev/Projects/GSE-Agent-Linux`
- `/home/dev/Projects/GSE-Agent-Windows`

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/UPDATE_POLICY.md`
- `docs/features/INSTALLERS.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/decisions/0006-installers.md`
- `docs/codex-history/2026/2026-07-18-1312-simplify-bootstrap-installer-ux.md`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- `tools/install_panel_cli.php`
- `includes/install_engine.php`
- `install.php`
- `check.php`
- `/home/dev/Projects/GSE-Agent-Linux/AGENTS.md`
- `/home/dev/Projects/GSE-Agent-Linux/README.md`
- `/home/dev/Projects/GSE-Agent-Windows/AGENTS.md`
- `/home/dev/Projects/GSE-Agent-Windows/README.md`

## Files Changed

- `scripts/install-panel.sh`
- `install.php`
- `check.php`
- `docs/codex-history/2026/2026-07-18-1358-minimal-panel-bootstrap.md`

## Architecture Decisions

- Keep `scripts/install-panel.sh` as a file bootstrap only: source selection,
  install path, OS packages, release archive copy, permissions, and final
  browser installer URL.
- Remove obsolete shell functions for database/user creation, vhost/SSL
  creation, secret prompts, two-choice legacy prompts, DB host/port parsing, and
  direct CLI install-engine execution.
- Keep database, schema, table prefix, admin account, domain/vhost, SSL, and
  reinstall/recovery ownership in `install.php` and `includes/install_engine.php`.
- Harden installer button CSS by explicitly setting accessible text color for
  link, input, button, hover, focus, active, visited, and disabled states.
- Do not change agent repositories because their installer contracts are
  separate and no Panel bootstrap prompt behavior is shared with them.

## Validation Performed

- `git status --short --branch`
- `git diff`
- `git branch --show-current`
- `git remote -v`
- `git log --oneline -n 12 --decorate`
- Static grep check:
  `for label in "Panel database host" "Panel database port" "Panel database name" "Panel database user" "Panel database password" "Panel table prefix" "Initial admin" "Panel web domain" "Web server (apache/nginx)" "Database server (mysql/mariadb)"; do if rg -n -F "$label" scripts/install-panel.sh; then printf 'FOUND: %s\n' "$label"; else printf 'NOT FOUND: %s\n' "$label"; fi; done`
- Grep result: all required removed labels returned `NOT FOUND`.
- `bash -n scripts/install-panel.sh`
- `php -l install.php`
- `php -l check.php`
- `git diff --check`
- `shellcheck scripts/install-panel.sh` was not run because `shellcheck` is not installed.
- `printf 'unstable\n/var/www/html/panel\n' | bash scripts/install-panel.sh --dry-run`
- `bash scripts/install-panel.sh --dry-run --channel unstable --path /var/www/html/panel`
- `rg -n "prompt(_release_source)? \"|read -r" scripts/install-panel.sh`
- `rg -n "prompt_secret|prompt_choice|create_database|write_vhost|run_engine|--validate-db-host-port|parse_db_host_port|Panel database|Panel web domain|Initial admin|Web server \\(|Database server \\(" scripts/install-panel.sh`

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`fix: keep bootstrap installer minimal`

Hash

Pending until commit is created.

## Remaining Work

- Run a real browser install on a disposable host/database to exercise the full
  post-bootstrap web installer flow.
