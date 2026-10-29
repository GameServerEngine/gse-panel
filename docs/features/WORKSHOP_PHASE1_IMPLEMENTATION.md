# Workshop Phase 1 Implementation

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Summary

Phase 1 makes the active Server Content Manager Workshop path usable for DayZ/Arma-style Workshop installs without reviving the deprecated standalone `steam_workshop` user workflow.

Active workflow:

`Game Monitor` -> `Server Content` -> `Steam Workshop Mods`

## Files Changed

- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/workshop_action.php`
- `modules/addonsmanager/workshop_content.php`
- `modules/addonsmanager/addons_manager.php`
- `modules/addonsmanager/module.php`
- `modules/addonsmanager/scripts/workshop/generic_steam_workshop_linux.sh` (Panel template for generated jobs)
- `modules/addonsmanager/scripts/workshop/generic_steam_workshop_windows_cygwin.sh` (Panel template for generated jobs)
- `modules/steam_workshop/monitor_buttons.php`

## User Flow

1. Open a server from the Panel.
2. Click `Server Content`.
3. Open the `Steam Workshop Mods` category.
4. Paste one or more Steam Workshop URLs or numeric Workshop IDs.
5. Click `Install / Queue`.
6. The Panel validates input, stores numeric Workshop IDs, writes a manifest, writes a generated per-job script, executes it through the agent's existing `exec` primitive, and shows the result.
7. Installed items can be enabled/disabled, updated, removed, downloaded without immediate install, assigned an update policy, and later used by Scheduler actions.

Accepted input examples:

- `450814997`
- `https://steamcommunity.com/sharedfiles/filedetails/?id=450814997`

Invalid text is rejected before reaching the manifest or shell script.

## Admin Flow

1. Create a Server Content template in `addonsmanager`.
2. Set content type to `Steam Workshop Mods`.
3. Configure `Workshop App ID` when the game XML/profile does not provide it.
4. Leave `Default Workshop IDs` blank for normal user-supplied installs.
5. Optionally configure a target path template.

The admin template defines capability and policy. The customer supplies only Workshop item IDs or URLs.

## Install Flow

1. `workshop_content.php` receives the form post.
2. `workshop_action.php` parses URLs/IDs and records rows in `server_content_workshop`.
3. The Panel builds a manifest with per-item install details.
4. The manifest is written to:
   - `{SERVER_HOME}/gsp_server_content/workshop_manifest.json`
5. A generated job script is written to:
   - `{SERVER_HOME}/gsp_server_content/jobs/workshop/workshop_job_<timestamp>_<random>.sh`
6. The agent runs:
   - `bash <script> <manifest>`
7. The script writes a temporary SteamCMD runscript containing `workshop_download_item <appid> <workshop_id> validate`.
8. The script runs SteamCMD with `+runscript`, copies downloaded content into the target mod folder when the action requires install, logs output, and copies `.bikey` files for DayZ/Arma-style strategies.

Current repair:

- The default script filename is no longer treated as an agent-host path.
- Static custom Workshop scripts are deprecated for the primary path; the Panel generates a per-job script instead.
- Generic installs default to `{SERVER_ROOT}/workshop/{MOD_FOLDER}`.
- DayZ/Arma installs keep `{SERVER_ROOT}/{MOD_FOLDER}` for `@<workshop_id>` compatibility.
- `download_only` and `validate_files` are accepted script actions and do not copy into live mod folders.
- `remove` does not require SteamCMD.

## Manifest Fields

Phase 1 manifests include:

- `manifest_version`
- `action`
- `home_id`
- `home_cfg_id`
- `game_path`
- `server_path`
- `workshop_app_id`
- `steam_app_id`
- `items`
- `item_details`
- `install_strategy`
- `target_path`
- `extra`

Each `item_details` entry includes:

- `workshop_item_id`
- `folder_name`
- `target_path_template`
- `target_path_resolved`
- `install_strategy`
- `copy_keys`
- `keys_target_path`

## DayZ / Arma Behavior

The Panel detects `dayz_mod_folder` or `arma_mod_folder` from the game key/name/config file when no explicit strategy exists.

Default install folder:

- `@<workshop_id>`

Arma 3 game XML now declares:

- Workshop app ID: `107410`
- Dedicated server app ID: `233780` through the existing `installer_name`

Key-copy behavior:

- `.bikey` files found anywhere in the installed mod folder are copied to `{SERVER_HOME}/keys`.
- Missing `.bikey` files are logged and do not fail the install.

## Database Tracking

`server_content_workshop` now tracks:

- `content_id`
- `install_path`
- `install_strategy`
- `enabled`
- `load_order`
- `update_policy`
- `pending_action`
- `install_state`
- `last_installed_at`
- `last_updated_at`
- `last_error`

Phase 1 states:

- `queued`
- `installing`
- `installed`
- `downloaded`
- `failed`
- `removed`

`server_content_workshop_catalog` tracks known/common items:

- `workshop_id`
- `app_id`
- `title`
- `install_count`
- `first_seen`
- `last_installed`
- `last_updated`
- `published_date`
- `tags`
- `game_key`
- `local_cache_path`

Supported update policies:

- `manual`
- `scheduled`
- `update_now`
- `update_and_restart`
- `download_only`
- `install_on_restart`

Scheduler action keys:

- `workshop_update`
- `workshop_update_and_restart`
- `workshop_download_only`
- `workshop_install_pending_on_restart`

## Security Notes

- Customer input is reduced to numeric Workshop IDs only.
- Invalid text is rejected.
- Target paths are generated from trusted templates and checked to stay under the server home.
- Scripts are copied from the Panel module source into a GSP-controlled folder under the server home.
- Customers do not supply shell commands.
- Admin-defined post-install scripts remain possible but should be treated as trusted admin configuration only.
- Steam credentials are not introduced by Phase 1; scripts use anonymous login.

## Startup Parameters

Phase 1 does not rewrite startup parameter generation.

Current DayZ/Arma XML files already expose user-editable `-mod=` / `-serverMod=` parameters through `server_params`.

Phase 2 should generate structured mod lists from enabled `server_content_workshop` rows ordered by `load_order`, avoid duplicate `-mod=` entries, and preserve existing user parameters.

## Validation Performed

- PHP syntax checks passed for changed PHP files.
- Bash syntax checks passed for both bundled Workshop scripts.
- Parser test confirmed:
  - numeric ID accepted
  - full Steam URL accepted
  - invalid text rejected
- Temporary manifest test with fake SteamCMD confirmed:
  - Linux script installs generic content into `workshop/@<workshop_id>`
  - Windows/Cygwin script installs generic content into `workshop/@<workshop_id>`
  - Arma/DayZ strategies resolve to root `@<workshop_id>` folders
  - `.bikey` files are copied to `keys`
  - useful logs are written under `gsp_server_content`
- Current smoke tests also confirm:
  - default Linux/Windows script staging no longer reports `script not found`
  - full URLs, numeric IDs, whitespace, comma, newline, duplicates, and invalid values parse correctly
  - generic no-template installs use `server_root/workshop`
  - missing SteamCMD returns a clear failure

## Phase 2 Work

- Add real asynchronous install jobs and progress polling.
- Resolve Workshop item titles/metadata.
- Add load-order UI.
- Wire enable/disable and load order into safe startup parameter generation.
- Support separate client mods and server-only mods.
- Add update-all and repair semantics that can preserve old installs until success.
- Add cache policy and cleanup controls.
- Add admin XML/schema fields for explicit install strategy and key-copy behavior.
- Add Steam account credential support without leaking secrets.
