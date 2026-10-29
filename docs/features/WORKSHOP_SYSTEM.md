# Workshop System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

The current Workshop/content work is split across two module lines:

- `modules/steam_workshop` - active user-facing Workshop module
- `modules/addonsmanager` - Server Content module, no longer a Workshop entry point

Important files:

- `modules/steam_workshop/module.php`
- `modules/steam_workshop/main.php`
- `modules/steam_workshop/monitor_buttons.php`
- `modules/steam_workshop/workshop_admin.php`

## Current Implemented Behavior

Steam Workshop is handled by the dedicated `steam_workshop` module.

Server Content must not show a `Steam Workshop Mods` category or route users into `addonsmanager/workshop_content.php`.

## Database State

The dedicated module still uses its own Workshop tables and per-game XML files under `modules/steam_workshop/game_configs/`.

## What Exists Today

The dedicated module still provides:

- user-facing Workshop pages
- monitor-button access from Game Monitor
- per-game Workshop configuration files
- uninstall and admin support pages
- admin copy support for copying Workshop configuration settings between XML configs without copying installed mods or server files
- optional regex/config-file editing for games that need automatic mod-list updates
- post-install-only workflows for games that only need a move/copy script

## Legacy RPC Install Semantics

The dedicated `steam_workshop` module still uses the legacy `steam_workshop` agent RPC for installs.

Current rule:

- if `config/filepath` is blank, the Panel passes a blank `config_file_path`
- agents must skip the generated config-file editing block entirely
- post-install scripts still run
- `WorkshopModsInfo` still records installed items
- uninstall can rely on `WorkshopModsInfo` when no config file is managed
- installed-mod tracking is per home when the agent supports `get_workshop_mods_info(home_id)`
- Workshop installs write `<game_home>/WorkshopInstallStatus.json` and terminal log markers so the Panel can clear stale Workshop update messages without hiding unrelated running updates

This keeps advanced regex editing available without forcing it for simple Arma/DayZ-style `@mod` installs.

## Main Limitations

- Some repository docs still describe the abandoned addonsmanager Workshop direction and should be treated as historical until updated.

## Scheduler Integration

Scheduler behavior for Workshop should be evaluated against the dedicated module, not inferred from `addonsmanager`.

## Troubleshooting

| Symptom | Meaning | Fix |
|---|---|---|
| `Steam Workshop Mods` appears under `Server Content` | Old addonsmanager Workshop category logic is still present. | Remove the `workshop_item` category from Server Content UI and use the dedicated `steam_workshop` module. |

## Recommended Mental Model

Use `steam_workshop` for Workshop. Use `addonsmanager` for normal Server Content items only.

## References Reviewed

- `modules/steam_workshop` remains the active module in this repository checkout.
- `game_path`
- `server_path`
- `workshop_app_id`
- `steam_app_id`
- `items`
- `item_details`
- `install_strategy`
- `target_path`
- `extra.copy_keys`
- `extra.keys_target_path`

Generated Workshop jobs validate numeric item IDs, keep writes under the server home, use SteamCMD through a temporary runscript, copy files into the resolved target path, and copy `.bikey` files for DayZ/Arma strategies when enabled.

Bundled handler actions:

- `install` - download with SteamCMD, copy/install into target path.
- `update` - validate/download with SteamCMD, copy/install into target path.
- `check_updates` - validate/download only; does not alter live mod folders.
- `download_only` - download/cache only and leave install pending.
- `validate_files` - SteamCMD validate/download only.
- `remove` - move the installed target folder into `gsp_server_content/workshop/removed/`; this does not require SteamCMD.
