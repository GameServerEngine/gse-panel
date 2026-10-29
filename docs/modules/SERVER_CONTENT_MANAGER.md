# Server Content Manager

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

`modules/addonsmanager` is the current home of GSP's Server Content / Add-ons work.

The module title has already been moved toward `Server Content Manager`, but the schema and some folder names remain backward-compatible.

Important files:

- `modules/addonsmanager/module.php`
- `modules/addonsmanager/addons_manager.php`
- `modules/addonsmanager/user_addons.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/server_content_categories.php`

## Database Tables

Known tables used by the module:

- `addons`
- `server_content_workshop`
- `server_content_workshop_catalog`
- `server_content_manifest`
- `server_content_install_history`

## What It Already Does

The module can already represent several content types, including:

- downloads/extracted packages
- post-script driven installs
- config packs
- generic Server Bot entries
- future profile-type content

## Path Contract

For new or manually rearranged homes, `server_homes.home_path` is the
server-ID management home. The game payload root is:

```text
<management_home>/<validated XML game_key>
```

Server Content payload targets resolve `{SERVER_ROOT}` and `{GAME_ROOT}` to the
game payload root. `{MANAGEMENT_HOME}` is available when a management-side path
is explicitly required, and legacy `{HOME_PATH}` remains the management home.

Generated Server Content management files stay above the game payload:

- `gsp_server_content/workshop_manifest.json`
- `gsp_server_content/jobs/workshop/*`
- `gsp_server_content/manifests/*`
- generated `bot.def` lifecycle bot definition
- compatibility `_alsoRun.cmd` bot launch definition while older agents are deployed

Relative file downloads, config edits, Workshop targets, and relative Server
Bot payload install folders resolve beneath `game_root`. Absolute Server Bot
payload paths are allowed only when they remain inside the management home.

Steam Workshop is no longer a user-facing Server Content category. Workshop access belongs to the dedicated `steam_workshop` module.

Generic Server Bot content stores a per-game template with install folder,
working directory, executable/command, arguments, OS support, enable-by-default
behavior, optional checksum/provenance, and optional uninstall behavior. When a
server starts, the Panel resolves the first enabled Server Bot manifest row in
deterministic catalog/manifest order and sends one transparent bot definition
in the lifecycle payload:

```text
working_directory=/path/to/bot
command=./bot
arguments=--config bot.cfg
```

The active agent lifecycle stores the current parser file as
`<management_home>/bot.def` with only:

```text
workdir=/path/to/bot
executable=./bot
arguments=--config bot.cfg
```

There are no process-control loops in the bot definition. The agent watchdog
owns supervision and restart behavior.

While older agents are deployed, installing or toggling Server Bot content also
generates the compatibility parser file:

- Linux: `<server_home>/_alsoRun.cmd`
- Windows/Cygwin: `<server_home>/_alsoRun.cmd`

The compatibility file uses the same simple keys:

```text
workdir=/path/to/bot
executable=./bot
arguments=--config bot.cfg
```

The Panel only overwrites files carrying its generated marker. Existing manual
`_alsoRun.cmd` files are left untouched and must be migrated before Server Bot
content can generate that file.

Phase 2 seeds two practical Server Bot package definitions:

- `BEC - BattlEye Extended Controls` for Windows Arma/DayZ-style configs. This
  uses the existing repository evidence for BEC: working directory `bec`,
  executable `bec.exe`, and arguments `--dsc --dec -f config.cfg`.
- `DZSA Launcher Sidecar` for `dayz_win64`. This downloads the open-source
  `node-mod-server.exe` release and keeps `DayZServer_x64.exe` as the main game
  executable. The sidecar arguments default to `"-port={PORT}" "{MODLIST}"`;
  users can override arguments during install.

The DZSA catalog row records the release URL but has no SHA-256 checksum because
no trusted checksum is currently present in the repository evidence.

## Current Limitations

- Cache and cleanup policy need a clearer product design.
- This implementation intentionally supports one optional bot per game home.

## Where To Start Reading

1. `modules/addonsmanager/module.php`
2. `modules/addonsmanager/addons_manager.php`
3. `modules/addonsmanager/user_addons.php`
4. `modules/addonsmanager/server_content_categories.php`

## Important Concept

This module is the right place for:

- mods
- add-ons
- config packs
- script-driven installs
- server content manifests
- install history

## Validation

Relevant smoke tests:

```bash
php modules/addonsmanager/tests/workshop_helpers_test.php
php modules/addonsmanager/tests/server_bot_helpers_test.php
php modules/config_games/tests/validate_server_configs.php
```

`validate_server_configs.php` validates every XML file under `modules/config_games/server_configs/` against `schema_server_config.xml`.
