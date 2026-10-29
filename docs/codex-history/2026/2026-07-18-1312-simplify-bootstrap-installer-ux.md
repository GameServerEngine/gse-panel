# Task

## User Request

Simplify the bootstrap installer and improve the web installer UX. The CLI
bootstrap should only ask for release source/channel or custom ZIP URL and
install path, should not ask for database/admin/vhost/SSL settings, should
download/extract the Panel, install prerequisites, set permissions, detect a
public IP when possible, and print a boxed browser URL. The web installer should
collect vhost/domain, SSL, MySQL settings, admin account, and install options
while preserving reinstall/recovery behavior. Installer action buttons should be
readable with accessible primary-button contrast. Validate, commit with
`feat: simplify bootstrap installer and improve installer UX`, and push
`unstable`.

## Problem

The shell installer still prompted for web server, database engine, domain/vhost,
SSL, database credentials, table prefix, and initial admin credentials, then ran
the shared PHP install engine directly. The browser installer handled database
and admin install state, but did not expose domain/vhost/SSL deployment metadata.
Installer and dependency-check action button styling was mostly readable but did
not explicitly cover all visited/focus/danger/button-input states.

## Root Cause

The release ZIP bootstrap had evolved from a full CLI installer and still owned
configuration steps that belong in the browser installer. Web installer state
did not include deployment target metadata, and action button CSS depended on
class-specific rules without a complete high-contrast baseline for all button
states.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/features/INSTALLERS.md`
- `docs/decisions/0006-installers.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-16-1423-panel-installer-prompts-prereqs.md`
- `docs/codex-history/2026/2026-07-16-1505-release-zip-panel-installer.md`
- `docs/codex-history/2026/2026-07-16-1957-installer-repeatable-dev-install.md`
- `docs/codex-history/2026/2026-07-17-1211-installer-accessibility-permissions.md`
- `docs/codex-history/2026/2026-07-17-1453-simplify-port-allocation-installer-ui.md`
- `.devplus/codex-runs/latest/FINAL_REPORT.md`
- `.devplus/codex-runs/latest/verification.md`
- `.devplus/codex-runs/latest.status.json`
- `.devplus/codex-runs/gp-20/prompt.md`
- `.devplus/codex-runs/gp-20/codex.log`
- `scripts/install-panel.sh`
- `scripts/install-panel-prereqs.sh`
- `tools/install_panel_cli.php`
- `includes/install_engine.php`
- `install.php`
- `check.php`

## Files Changed

- `check.php`
- `docs/codex-history/2026/2026-07-18-1312-simplify-bootstrap-installer-ux.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `includes/install_engine.php`
- `install.php`
- `scripts/install-panel.sh`

## Architecture Decisions

- Make `scripts/install-panel.sh` a bootstrapper only for the normal guided path:
  install default Debian/Ubuntu prerequisites, download/extract the selected
  release archive, repair permissions, and print `install.php`.
- Keep database config, table prefix/reinstall confirmation, admin creation, and
  final install execution in the browser installer through the existing shared
  `includes/install_engine.php`.
- Record browser-collected domain/vhost/SSL choices in
  `includes/install.state.json` as deployment metadata instead of attempting
  privileged vhost or certificate changes from PHP.
- Preserve the existing partial-install recovery form and saved-config retry
  behavior.
- Use explicit high-contrast foreground/background rules for installer action
  buttons, including anchors, submit inputs, visited links, focus, hover,
  disabled, secondary, and danger states.

## Validation Performed

- Verified current testing artifacts and prompt showed this same task in
  `.devplus/codex-runs/gp-20/prompt.md`.
- Confirmed the pre-change shell installer prompted for web server, database
  engine, domain/vhost/SSL, MySQL settings, table prefix, and admin credentials.
- Reviewed recent installer commits:
  `f404bea6`, `03c66e78`, `ce825aaa`, `8b9202aa`, `70824141`, `c5f3bb02`, and
  `fcff2c71`.
- `bash -n scripts/install-panel.sh`
- `bash -n scripts/install-panel-prereqs.sh`
- `php -l install.php`
- `php -l includes/install_engine.php`
- `php -l check.php`
- `php -l tools/install_panel_cli.php`
- `git diff --check`
- Ran `scripts/install-panel.sh --dry-run` with default stable source and an
  install path and confirmed it did not prompt for database/admin/vhost/SSL
  settings.
- Ran `scripts/install-panel.sh --dry-run --channel unstable --path
  /var/www/html/panel` and confirmed the completion box prints
  `http://PUBLIC-IP/panel/install.php`.
- Verified release URL derivation for stable, unstable, and custom `file://`
  ZIP values.
- Rendered `install.php?step=1` from a temporary unlocked Panel copy and
  confirmed the deployment target, vhost, SSL, and install option controls are
  present.
- Ran the shared installer CLI wrapper in dry-run mode with panel domain/vhost
  and SSL metadata.
- Validated `gsp_install_validate_options()` accepts a hostname with port and
  rejects a URL with scheme/path for `panel_domain`.
- Rendered `check.php` and confirmed installer action button CSS includes
  explicit foreground colors for primary, secondary, danger, visited, focus,
  hover, and disabled states.
- Attempted to use local MySQL for a full disposable database install, but the
  current `dev` user has no socket access and passwordless sudo is unavailable.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`feat: simplify bootstrap installer and improve installer UX`

Hash

Pending until final commit.

## Remaining Work

- Run a full non-dry-run browser installation against a disposable MySQL
  database when valid local or test database credentials are available.
