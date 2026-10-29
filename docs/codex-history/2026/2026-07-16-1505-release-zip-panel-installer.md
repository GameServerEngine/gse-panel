# Task

## User Request

Replace the GSE Panel installer's normal Git clone/update flow with a release
ZIP download/extract flow. Default ordinary installs and updates to `stable`,
allow `unstable`, allow an optional custom ZIP URL for controlled testing,
preserve config/runtime data, update the admin update page and docs, validate,
commit, and push.

## Problem

The root shell installer prompted ordinary users for a repository URL and branch,
then cloned or updated a Git checkout in the web root. The admin Panel update
page also treated repository URL/path and branch as the normal update settings
and ran Git fetch/reset against the live Panel root.

## Root Cause

The installer and updater were created around Git checkouts. That model is
fragile in web roots owned by `www-data` because Git can reject operations with
dubious ownership or safe-directory errors. It also exposed implementation
details that ordinary Panel installers do not need.

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
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/admin-guide.md`
- `docs/modules/PANEL_UPDATE.md`
- `docs/modules/UPDATE.md`
- `docs/features/INSTALLERS.md`
- `docs/decisions/0006-installers.md`
- `docs/codex-history/2026/2026-07-10-1046-panel-shell-installer.md`
- `docs/codex-history/2026/2026-07-13-1030-panel-production-update-path.md`
- `docs/codex-history/2026/2026-07-16-1248-panel-testing-installer-validation.md`
- `docs/codex-history/2026/2026-07-16-1423-panel-installer-prompts-prereqs.md`
- `.devplus/codex-runs/gp-5/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-6/FINAL_REPORT.md`
- `.devplus/codex-runs/latest.status.json`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- `scripts/validate-installer-cli.sh`
- `tools/install_panel_cli.php`
- `tools/post_update_cli.php`
- `includes/install_engine.php`
- `install.php`
- `check.php`
- `modules/update/update.php`
- `modules/update/updating.php`
- `modules/update/tests/update_config_smoke.php`
- `modules/administration/panel_update.php`

## Files Changed

- `check.php`
- `docs/codex-history/2026/2026-07-16-1505-release-zip-panel-installer.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/admin-guide.md`
- `docs/modules/PANEL_UPDATE.md`
- `docs/modules/UPDATE.md`
- `modules/administration/panel_update.php`
- `modules/update/tests/update_config_smoke.php`
- `modules/update/updating.php`
- `scripts/install-panel-prereqs.sh`
- `scripts/install-panel.sh`
- `tools/post_update_cli.php`

## Architecture Decisions

- Make release ZIP archives the default source for shell installs and updates.
- Default the release channel to `stable`; allow `unstable` for development and
  testing.
- Keep developer Git source mode only behind explicit `--source git`.
- Validate archive payloads by requiring exactly one root containing
  `install.php`, `panel.sql`, `includes/install_engine.php`, and
  `tools/install_panel_cli.php`.
- Preserve `includes/config.inc.php` and runtime/writable directories during
  archive copy updates.
- Keep the admin update page admin-only and CSRF-protected while replacing its
  normal update action with release ZIP download/extract/copy.
- Disable the legacy GET-driven `modules/update/updating.php` side-effect route
  and point admins to the CSRF-protected update page.

## Validation Performed

- Verified `stable` archive URL with `curl -fsSIL --max-time 20
  https://git.runlevelsystems.com/GSE/Panel/archive/stable.zip`.
- Verified `unstable` archive URL with `curl -fsSIL --max-time 20
  https://git.runlevelsystems.com/GSE/Panel/archive/unstable.zip`.
- Ran `bash -n scripts/install-panel.sh`.
- Ran `bash -n scripts/install-panel-prereqs.sh`.
- Ran PHP syntax checks for changed PHP files:
  `modules/administration/panel_update.php`, `modules/update/updating.php`,
  `modules/update/tests/update_config_smoke.php`, `tools/post_update_cli.php`,
  and `check.php`.
- Rechecked unchanged installer entry points with PHP syntax checks:
  `tools/install_panel_cli.php`, `includes/install_engine.php`, and
  `install.php`.
- Ran `php modules/update/tests/update_config_smoke.php`.
- Tested release URL derivation for default stable, explicit stable, explicit
  unstable, invalid channel, custom ZIP URL, and invalid custom URL.
- Ran a guided shell installer dry-run using the stable release ZIP source.
- Created local ZIP fixtures to verify successful copy, zero payload-root
  failure, and multiple payload-root failure.
- Verified local archive copy preserves `includes/config.inc.php`, `upload/`,
  `cache/`, `log/`, and `temp/`.
- Verified `.git`, `.github`, `.devplus`, `.vscode`, and `.log` artifacts are
  excluded from copied archive payloads.
- Downloaded and copied the real `unstable` release ZIP into a temporary target
  and verified required Panel files landed there.
- Exercised the PHP admin updater release ZIP download/extract helper against
  the real `unstable` archive and verified the payload root contained
  `install.php`.
- Ran a direct PHP `gsp_component_copy_tree()` preservation/exclusion
  simulation.
- Ran `php check.php` and verified Git is reported as optional for normal Panel
  install/update.
- Rechecked database host-port parsing for domain and bracketed IPv6 inputs and
  invalid port rejection.
- Ran `git diff --check`.
- Searched final installer/update paths for remaining normal-flow Git clone,
  pull, or safe-directory reliance.

## Git Commits

Repository

Panel

Commit

Pending until final commit.

Hash

Pending until final commit.

## Remaining Work

- Full destructive/non-dry-run installation was not performed against a real
  Apache/MySQL deployment in this workspace.
