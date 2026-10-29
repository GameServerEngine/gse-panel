# Steam Workshop

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Dedicated Steam Workshop support for game servers.

## Current Status

- Active in this repository checkout
- User-facing Workshop module

## Dependencies

- Steam Workshop DB helpers
- legacy helper functions

## Database Tables

- `steam_workshop_game_profiles`
- `steam_workshop_server_mods`
- `steam_workshop_server_settings`

## Agent Interaction

- workshop download/update helpers

## User Workflow

- open the Steam Workshop monitor button from Game Monitor
- search by Workshop ID, Workshop URL, or keyword from the dedicated module
- search results are scoped to the current game's configured Workshop App ID
- select one or more search results with checkboxes and install them through the existing workflow
- manual Workshop ID entry remains available as a fallback
- install/update/uninstall Workshop items through the dedicated module

## Admin Workflow

- configure Workshop game XML files under `modules/steam_workshop/game_configs/`
- use `workshop_admin.php` for module administration
- `File Path` and the regex/mod-string fields are optional
- a blank `File Path` means post-install and uninstall scripts run without any automatic config-file editing
- the admin page can copy configuration settings from one existing XML config into the currently selected game/OS config
- copied settings include Workshop App ID, download method, anonymous login, paths, regex/config-edit fields, post-install script, and uninstall script
- copied settings do not include installed mods, cached mod lists, game server files, or any files from a game home directory

## Search Backend

- discovery is server-side in `modules/steam_workshop/main.php`
- the panel searches Steam Community Workshop browse results for the current game App ID
- result metadata is hydrated with `ISteamRemoteStorage/GetPublishedFileDetails`
- no Steam API key is currently required for the user-facing search flow
- selected result IDs and manual IDs are deduplicated before the existing install RPC runs

## UI Notes

- the main Workshop page `Back` link is rendered as a real panel button
- uninstall remains in the dedicated `steam_workshop` module
- the uninstall page is also the installed-mods view and shows tracked title, Workshop ID, installed folder/path, status, install time, and a copyable mod line

## Legacy RPC Behavior

- the dedicated module still calls the legacy agent `steam_workshop` XML-RPC method
- when `config/filepath` is blank, the Panel now passes an empty `config_file_path`
- both agents skip the generated `cat` / regex / config-write block when `config_file_path` is blank
- both agents still run custom post-install scripts and still write `WorkshopModsInfo`
- uninstall falls back to `WorkshopModsInfo` when no config file is managed
- new agents write per-home installed mod records under `WorkshopModsInfo/home_<home_id>/`
- each installed record includes mod string, Workshop item ID, title, installed folder/path, Workshop App ID, status, and timestamps
- agents also write `<game_home>/WorkshopInstallStatus.json` with `running`, `completed`, or `failed` Workshop install state
- Workshop install logs end with `GSP_WORKSHOP_INSTALL_COMPLETE` or `GSP_WORKSHOP_INSTALL_FAILED`; the Panel uses these markers to avoid stale `Update in progress` displays when the generic update screen remains visible

## Security Concerns

- should not be duplicated under `addonsmanager`

## Known Issues

- some repository docs still describe this module as deprecated

## Missing Functionality

- documentation cleanup so all references match the current module ownership

## Suggested Future Improvements

- keep Workshop links and workflows isolated to this module
- remove old addonsmanager Workshop assumptions

## Recommendation

- Keep as the dedicated Workshop module
