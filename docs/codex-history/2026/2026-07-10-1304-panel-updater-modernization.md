# Task

## User Request

Workflow `gsp-panel-installation-modernization`, step 4 of 4: verify the Panel updater architecture first, then simplify the updater UI to repository URL and branch only, default to `https://git.runlevelsystems.com/GSP/Panel.git` and `unstable`, derive the Panel root from the running application, create exactly one backup snapshot before pulling, preserve runtime secrets/state such as `includes/config.inc.php`, update safely from the configured Git repository and branch, validate state and permissions, report clear failures, use rollback where safely supported, remove stale updater questions, validate with PHP syntax checks and targeted tests, then commit and push `origin/unstable`.

## Problem

The active Panel updater still exposed repository root, Panel path, source folder, Git path, backup path, retention, backup toggle, and post-update command fields. The update execution path could create two managed full backups in a single update run: one before the Git update and another before the Panel copy step.

## Root Cause

The updater had been normalized toward the flattened Panel repository layout, but the settings model and form still retained older multi-path/component update assumptions. `gsp_do_configured_git_update()` preserved the earlier two-stage update flow and called `gsp_create_full_backup()` once for the Git checkout and again for the Panel update.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/PANEL_UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/UPDATE.md`
- `/home/dev/Projects/GSP/Panel/modules/update/update.php`
- `/home/dev/Projects/GSP/Panel/modules/update/module.php`
- `/home/dev/Projects/GSP/Panel/modules/update/navigation.xml`
- `/home/dev/Projects/GSP/Panel/modules/update/tests/update_config_smoke.php`
- `/home/dev/Projects/GSP/Panel/modules/update/patches/001_layout_bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/update/patches/20260518_layout_safety.php`
- `/home/dev/Projects/GSP/Panel/modules/administration/panel_update.php`
- `/home/dev/Projects/GSP/Panel/modules/administration/navigation.xml`
- `/home/dev/Projects/GSP/Panel/.gitignore`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/AGENT_ACTIVITY_EVENTS.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/WINDOWS_AGENT_UPDATE_BAT.md`

The Linux agent repository does not currently contain the workspace-listed `docs/AGENT_ARCHITECTURE.md`, `docs/COMMAND_EXECUTION.md`, or `docs/PROCESS_MANAGEMENT.md`; the present Linux docs above were read instead.

## Files Changed

- `/home/dev/Projects/GSP/Panel/modules/administration/panel_update.php`
- `/home/dev/Projects/GSP/Panel/modules/update/tests/update_config_smoke.php`
- `/home/dev/Projects/GSP/Panel/docs/modules/PANEL_UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-10-1304-panel-updater-modernization.md`

## Architecture Decisions

- Treat the running Panel root as the update root, derived from `GSP_PANEL_DIR`.
- Keep Git as the source of truth for Panel code updates.
- Keep only `gsp_update_repo_url` and `gsp_update_branch` as editable updater settings.
- Always create one managed backup before a configured Panel update; do not expose a disable-backup toggle.
- Name managed update backups with a descriptive prefix and timestamp, such as `panel-update_YYYY-MM-DD_HH-MM-SS`.
- Preserve runtime-generated secrets/state through temporary update-local copies before Git reset and restore them after checkout validation.
- Require the derived Panel root to be an existing Git checkout during preflight.
- Use the existing restore path for automatic rollback if a failure occurs after the managed backup is created.
- Leave Linux and Windows agent update behavior unchanged because this step changes only the Panel self-updater and does not alter Panel-agent RPC shape.

## Validation Performed

- `git status --short --branch` in Panel before edits.
- `git diff` in Panel before edits.
- `git status --short --branch` in Linux and Windows agent repositories.
- `php -l modules/administration/panel_update.php`
- `php -l modules/update/update.php`
- `php -l modules/update/tests/update_config_smoke.php`
- `php modules/update/tests/update_config_smoke.php`
- `git diff --check`
- Reviewed final diff/stat.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

`Modernize Panel updater flow`

Hash

Pending until commit is created.

## Remaining Work

- Run the real updater once in a production-like web environment with database credentials and filesystem ownership matching deployment.
- Consider a future cleanup migration that removes stale updater setting keys from existing databases after deployed code no longer reads them.
