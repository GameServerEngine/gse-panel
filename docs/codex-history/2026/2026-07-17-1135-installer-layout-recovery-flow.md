# Task

## User Request

Fix the GSE Panel browser installer on branch `testing` so the installation
settings form is responsive on mobile, partial/incomplete install recovery is
not a dead-end text/progress page, module installation progress is captured in
the modern installer UI, success/failure paths provide visible actions, prior
gp-10/gp-11 installer behavior is preserved, validation passes, the change is
committed and pushed, and the result is deployed to `/var/www/html/panel-test`.

## Problem

The settings page used a two-column grid that could overflow narrow viewports,
especially around long checkbox help text. Browser install/recovery called the
shared engine directly, while legacy module installers printed progress such as
`Adding module called ...` during execution. That output could appear as plain
text before the modern result UI, leaving users without a clear success/failure
summary or recovery actions after an incomplete install.

## Root Cause

The browser installer did not buffer legacy module installer output, unlike the
CLI wrapper. The partial-install gate only showed a warning and relied on the
normal wizard path instead of rendering a guided recovery panel. The settings
form grid lacked enough narrow-screen guardrails (`min-width: 0`, wrapping
checkbox layout, and a higher one-column breakpoint). The shared engine also
wrote installer state after module execution without a final core-table/admin
completion check.

## Repositories Inspected

- Panel

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/decisions/0006-installers.md`
- `docs/codex-history/2026/2026-07-16-1957-installer-repeatable-dev-install.md`
- `docs/codex-history/2026/2026-07-17-1043-installer-dependency-ux-fixes.md`
- `.devplus/codex-runs/gp-10/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-10/verification.md`
- `.devplus/codex-runs/gp-10/prompt.md`
- `.devplus/codex-runs/gp-11/FINAL_REPORT.md`
- `.devplus/codex-runs/gp-11/verification.md`
- `.devplus/codex-runs/gp-11/prompt.md`
- `install.php`
- `includes/install_engine.php`
- `tools/install_panel_cli.php`
- `scripts/install-panel.sh`
- `modules/modulemanager/module_handling.php`
- `includes/helpers.php`

## Files Changed

- `install.php`
- `includes/install_engine.php`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-17-1135-installer-layout-recovery-flow.md`

## Architecture Decisions

- Keep install/reinstall behavior centralized in `includes/install_engine.php`.
- Preserve gp-10/gp-11 safe form-state retention, blank-prefix collision
  detection, and prefix-scoped reinstall behavior.
- Buffer legacy browser installer progress at the web entrypoint and render
  sanitized progress lines inside the modern installer UI.
- Do not display saved database passwords in recovery forms; reuse saved config
  server-side only when the partial install config is readable and can connect.
- Require final core-table and admin-user verification before writing installer
  state, so incomplete installs remain recoverable.
- Fix the mobile form with CSS containment and wrapping rather than changing the
  installer data model.

## Validation Performed

- Confirmed branch `testing`.
- Confirmed no staged changes before editing.
- Reviewed current git diff before editing.
- Reviewed gp-10 and gp-11 reports/history.
- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l tools/install_panel_cli.php`
- `bash -n scripts/install-panel.sh`
- `git diff --check`
- Static inspection confirmed a one-column responsive breakpoint, no horizontal
  overflow rules, `min-width: 0` on form/grid controls, and wrapping checkbox
  text.
- Static inspection confirmed incomplete install recovery renders modern actions
  for settings, dependency check, and retry/recovery.
- Static inspection confirmed browser installer output is buffered and
  sanitized before rendering.
- Local PHP built-in server smoke check returned HTTP 200 for `install.php` and
  `install.php?step=1`.
- Local failing POST smoke check returned the modern error panel with visible
  recovery actions and did not echo the submitted database password.

## Git Commits

Repository

Panel

Commit

fix: refine installer layout and recovery flow

Hash

Pending at history creation; final hash reported in the task final report.

## Remaining Work

- Commit and push after final diff review.
- Deploy committed `testing` tree to `/var/www/html/panel-test`.
- Verify deployed file parity and local HTTP response.
