# Task

## User Request

Rebuild the GSE Panel fresh/default installed module set on branch `testing` so
broken or specialty legacy modules no longer appear in top-level navigation on a
fresh install. Specifically remove Support Tickets from default registration and
hide TShock, TS3 Admin, Reseller Packs, LGSL, and DSI by default while keeping
optional module files where safe. Validate the installer/module registration,
commit with `fix: reduce default panel modules`, push to `origin/testing`, and
provide deployment guidance for `/var/www/html/panel-test`.

## Problem

Fresh installs registered nearly every shipped module as a built-in module. Any
module declaring `$module_menus` then received rows in `module_menus`, which
made old or specialty modules appear in the main user/admin menus.

## Root Cause

`includes/install_engine.php::gsp_install_builtin_modules()` included the full
legacy module inventory, including `tickets`, `tshock`, `TS3Admin`,
`teamspeak3`, `lgsl_with_img_mod`, `dsi`, and `reseller`. The canonical
installer then loaded each module's `module.php` metadata and registered its
menus during `gsp_install_register_builtin_modules()`.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/development/CODEX_GUIDE.md`
- `docs/architecture/MODULE_DEPENDENCIES.md`
- `docs/modules/MODULE_INDEX.md`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/codex-history/2026/2026-07-17-1319-consolidate-installer-schema.md`
- `docs/codex-history/2026/2026-07-17-1453-simplify-port-allocation-installer-ui.md`
- `docs/codex-history/2026/2026-07-18-0822-testing-app-server-config.md`
- `includes/install_engine.php`
- `panel.sql`
- `install.php`
- `home.php`
- `index.php`
- `includes/helpers.php`
- `includes/database_mysqli.php`
- `modules/modulemanager/module_handling.php`
- `modules/modulemanager/modulemanager.php`
- `modules/*/module.php`
- `modules/*/navigation.xml`
- `modules/tshock/shared.php`
- `modules/tshock/server.php`
- `modules/tshock/functions.php`
- `docs/modules/tickets.md`
- `docs/modules/tshock.md`
- `docs/modules/TS3Admin.md`
- `docs/modules/teamspeak3.md`
- `docs/modules/dsi.md`
- `docs/modules/lgsl_with_img_mod.md`

## Files Changed

- `includes/install_engine.php`
- `tools/validate_default_modules.php`
- `docs/deployment/GSP_INSTALLER.md`
- `docs/deployment/INSTALL_README.md`
- `docs/modules/MODULE_INDEX.md`
- `docs/modules/tickets.md`
- `docs/modules/tshock.md`
- `docs/modules/TS3Admin.md`
- `docs/modules/teamspeak3.md`
- `docs/modules/dsi.md`
- `docs/modules/lgsl_with_img_mod.md`
- `docs/codex-history/2026/2026-07-18-1100-reduce-default-panel-modules.md`

## Architecture Decisions

- Keep optional and legacy module directories on disk so Module Manager can
  install them manually where a deployment needs them.
- Reduce fresh installer registration instead of deleting module files or
  adding upgrade migrations that would alter existing installs.
- Keep the general `support` module in the default set and remove only the
  separate `tickets` module from fresh registration.
- Leave existing optional module schema in `panel.sql` intact because the task
  targets fresh module registration/menu visibility and destructive schema
  removal would be higher risk for upgrades.
- Document that TShock belongs behind Terraria context in Game Monitor/server
  actions, and LGSL/DSI belong behind contextual status/image actions instead
  of global default navigation.

## Validation Performed

- Confirmed branch `testing`.
- Confirmed no staged changes before editing.
- Reviewed current status, diff, and latest commits before editing.
- Inspected installer canonical schema and built-in registration in
  `includes/install_engine.php`.
- Inspected `panel.sql` and confirmed it contains module-related table schema
  but no default `modules` or `module_menus` seed rows.
- Inspected `modules/*/module.php`, `modules/*/navigation.xml`, and
  `modules/modulemanager/*`.
- Identified that the observed top-level menu items came from fresh
  registration of module metadata into `module_menus`.
- Identified likely TShock PHP error cause: the global menu opens `?m=tshock`
  without the required Terraria server context parameter
  `home_id-mod_id-ip-port`; TShock shared routing assumes that context and also
  directly reads `$_GET['p']`.
- `php -l includes/install_engine.php`
- `php -l install.php`
- `php -l tools/validate_default_modules.php`
- `php tools/validate_default_modules.php`
- `bash -n scripts/validate-installer-cli.sh`
- `bash scripts/validate-installer-cli.sh`
- `find modules/tshock -maxdepth 1 -type f -name '*.php' -print -exec php -l {} \;`
- Static assertion confirmed `tickets`, `tshock`, `TS3Admin`, `teamspeak3`,
  `lgsl_with_img_mod`, `dsi`, and `reseller` are absent from the fresh default
  module list.
- Static assertion confirmed menu labels `Support Tickets`, `tshock`,
  `ts3admin`, `LGSL`, `DSi Settings`, and `Reseller Packs` are absent from
  fresh default module metadata.
- Static assertion confirmed optional module directories remain present.
- `git diff --check`

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

`fix: reduce default panel modules`

Hash

Pending at record creation; final report records the pushed commit hash.

## Remaining Work

- Deploy manually to `/var/www/html/panel-test` if desired.
