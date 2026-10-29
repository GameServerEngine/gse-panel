# Task

## User Request

Fix the GSE Panel installer on branch `testing` so installer pages use
accessible colors, config write permission failures explain the unwritable path
and panel-test `www-data:www-data` model, deployment docs/scripts align with a
group-writable test permissions model, validation passes, the change is
committed and pushed to `origin/testing`, and deployment to
`/var/www/html/panel-test` is attempted if permissions allow.

## Problem

The installer and dependency check used red/orange status text on dark red or
dark orange panels, leaving error and missing states hard to read. Failed
recovery writes returned a generic `Unable to write includes/config.inc.php.`
message without explaining which path lacked write permission or how the
panel-test deployment should be owned. Permission repair set directories to
`775` without setgid inheritance and docs still recommended installing-user
ownership as the primary model, which could leave deploy-created files outside
Apache's writable group model.

## Root Cause

Installer CSS encoded status by foreground color as much as text labels, and
the write path attempted `file_put_contents()` before a dedicated filesystem
permission preflight. The CLI permission repair helper preserved older
`owner:www-data` and non-setgid directory behavior instead of the newer
panel-test model where Apache owns the tree and group write is inherited.

## Repositories Inspected

- Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/APACHE_SETUP.md`
- `docs/deployment/admin-guide.md`
- `docs/decisions/0006-installers.md`
- `.devplus/codex-runs/gp-10/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-10/verification.md`
- `.devplus/codex-runs/gp-10/prompt.md`
- `.devplus/codex-runs/gp-11/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-11/verification.md`
- `.devplus/codex-runs/gp-11/prompt.md`
- `.devplus/codex-runs/gp-12/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-12/verification.md`
- `.devplus/codex-runs/gp-12/prompt.md`
- `docs/codex-history/2026/2026-07-17-1043-installer-dependency-ux-fixes.md`
- `docs/codex-history/2026/2026-07-17-1135-installer-layout-recovery-flow.md`
- `install.php`
- `check.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`

## Files Changed

- `check.php`
- `install.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `docs/deployment/INSTALL_README.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/APACHE_SETUP.md`
- `docs/deployment/admin-guide.md`
- `docs/codex-history/2026/2026-07-17-1211-installer-accessibility-permissions.md`

## Architecture Decisions

- Keep browser and CLI install behavior centralized in
  `includes/install_engine.php`.
- Preserve gp-10/gp-11/gp-12 behavior for blank table prefixes, partial
  recovery, form-state retention, and buffered module progress.
- Use high-contrast light text on dark status panels instead of red text as the
  primary error signal.
- Preflight `includes/` and `includes/config.inc.php` writability before
  recovery/install config writes when possible.
- Keep credentials out of permission errors; show filesystem path and ownership
  guidance only.
- Align permission repair with group-writable files and setgid directories.
  For panel-test, recommend `www-data:www-data`; for production, avoid
  world-writable permissions and allow tighter local policy when browser writes
  are not needed.

## Validation Performed

- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l tools/install_panel_cli.php`
- `php -l check.php`
- `bash -n scripts/install-panel.sh`
- `bash -n scripts/install-panel-prereqs.sh`
- `git diff --check`
- Temporary permission-preflight probe confirmed unwritable `includes/` reports
  the exact path plus non-sensitive `www-data:www-data` panel-test guidance.
- Static inspection confirmed installer and dependency-check error, warning,
  missing, success, info, button, hover, disabled, and secondary states use
  high-contrast foregrounds on dark panels and do not rely only on red text.
- Static inspection confirmed visible recovery actions remain available for
  failed install/recovery paths.
- Static inspection confirmed docs/scripts recommend the panel-test
  `www-data:www-data`, group-writable, setgid directory model and avoid
  world-writable production guidance.
- Local PHP built-in server smoke check returned HTTP 200 for `install.php`,
  `install.php?step=1`, and `check.php`.

## Git Commits

Repository

Panel

Commit

fix: improve installer readability and deploy permissions guidance

Hash

Pending at history creation; final hash is reported in the task final report.

## Remaining Work

- Review final diff.
- Commit and push to `origin/testing` if validation passes.
- Attempt deployment to `/var/www/html/panel-test` if sudo/permissions allow.
