# Task

## User Request

Workflow step 1 of `gsp-panel-installation-modernization`: verify the Panel repo state, confirm the nested `Panel/Panel` application layout, move the actual Panel application contents to the repository root, preserve required docs/deployment references under sensible locations, remove clearly obsolete root garbage, update path references, rename the working branch to `unstable` tracking `origin/unstable`, validate, commit, and push.

## Problem

The deployable Panel application lived one level below the repository root in `Panel/`, while repository-level documentation and deployment references also existed above it. This made update/deployment paths rely on a parent repository root plus nested Panel source folder and encouraged stale path references such as `Panel/modules/...` in current docs.

## Root Cause

The repository retained an older monorepo-style shape where the Panel was a component under `Panel/`. The current workflow requires the Panel repository itself to be the deployable web root, with application entry points and runtime directories at repository root.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`

The workspace-level `/home/dev/Projects/GSP-WORKSPACE.md` referenced by instructions was not present at that absolute path during this task. The local `AGENTS.md` instructions were read from `/home/dev/Projects/GSP/AGENTS.md`.

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/development/CODEX_GUIDE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/PANEL_UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/UPDATE.md`
- `/home/dev/Projects/GSP/Panel/Panel/INSTALL_README.md`
- `/home/dev/Projects/GSP/Panel/Panel/APACHE_SETUP.md`
- `/home/dev/Projects/GSP/Panel/Panel/docs/GSP_INSTALLER.md`
- `/home/dev/Projects/GSP/Panel/Panel/documentation/admin-guide.md`
- `/home/dev/Projects/GSP/Panel/Panel/docs/COPILOT_TODO.md`
- `/home/dev/Projects/GSP/Panel/Panel/modules/administration/panel_update.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/update/tests/update_config_smoke.php`
- `/home/dev/Projects/GSP/Panel/examples/apache/panel.example.com.conf`
- `/home/dev/Projects/GSP/Panel/examples/apache/gameservers.world-billing-vhost.conf`
- `/home/dev/Projects/GSP/Panel/SITES_AVAILABLE_EXAMPLE.conf`

## Files Changed

- Moved application runtime directories from `Panel/` to repository root: `.well-known/`, `Crypt/`, `css/`, `favicon/`, `ftp/`, `images/`, `includes/`, `js/`, `lang/`, `modules/`, `protocol/`, `scripts/`, `sql/`, `status/`, `themes/`, `tools/`.
- Moved application entry points and operational files from `Panel/` to repository root, including `index.php`, `home.php`, `install.php`, `gsp_api.php`, `status_api.php`, `server_status.php`, `panel.sql`, `panel.conf`, and `fileserver.conf`.
- Moved nested documentation into `docs/deployment/`, `docs/development/`, and `docs/development/legacy-summaries/`.
- Moved `SITES_AVAILABLE_EXAMPLE.conf` to `docs/deployment/SITES_AVAILABLE_EXAMPLE.conf`.
- Removed obsolete generated/empty artifacts: nested `Panel/README.md`, `logs/update_trace.log`, empty wrapper/reference directories, and generated Python cache.
- Updated current docs, `.github` guidance, Apache examples, `.gitignore`, updater defaults, updater smoke test, and branch defaults.
- Restored the active `modules/addonsmanager/scripts/workshop/panel_generated_steamcmd_job` template from the newest tracked backup so the existing Workshop helper smoke test passes after the move.
- Applied minimal PHP 8 parse-compatibility fixes in bundled PHPMailer, GameQ, TeamSpeak3, HTMLPurifier, and net2ftp files so full-tree PHP lint can run successfully.

## Architecture Decisions

- The Panel repository root is now the deployable web root.
- `modules/`, `includes/`, `protocol/`, `themes/`, `sql/`, and entry point PHP files live at repository root.
- The Panel updater defaults to branch `unstable`, repository root `/var/www/html/GSP`, Panel path `/var/www/html/GSP`, and Panel source folder `.`.
- Legacy Apache stale-path detection intentionally retains old nested paths so live deployments can detect and repair old vhosts.
- `origin/Panel-unstable` was not deleted.

## Validation Performed

- Verified initial branch was `Panel-unstable` tracking `origin/Panel-unstable`.
- Fetched remote branch metadata and confirmed `origin/unstable` already existed separately from `origin/Panel-unstable`.
- Renamed local branch to `unstable` and set it to track `origin/unstable`.
- Verified required root entry points/directories exist: `index.php`, `home.php`, `install.php`, `gsp_api.php`, `status_api.php`, `server_status.php`, `includes/`, `modules/`, `themes/`, `sql/`, `docs/`, `panel.sql`, `panel.conf`, and `fileserver.conf`.
- Verified no tracked `Panel/*` paths remain after the flattening.
- `git diff --check` passed.
- Full PHP lint across all `*.php` files passed.
- `php modules/update/tests/update_config_smoke.php` passed.
- `php modules/config_games/tests/validate_server_configs.php` passed.
- `php modules/addonsmanager/tests/workshop_helpers_test.php` passed.
- `bash -n backup.sh`, `bash -n push_to_github.sh`, `bash -n examples/apache/install-sites-available.sh`, and `bash -n` over shell scripts under `scripts/` and `tools/` passed.
- Stale nested path search found only historical changelog text and intentional Apache stale-path detector entries.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

Flatten Panel application root

Hash

a368400e

## Remaining Work

- Push status pending until remote update completes.
- `origin/Panel-unstable` still exists and was intentionally not deleted.
