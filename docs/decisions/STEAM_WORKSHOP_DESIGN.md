# GSP Steam Workshop and Server Content Design

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This document is an investigation and design report only. It does not implement code.

The goal is a professional Steam Workshop and general server content system for GSP that works across:

- `Agent-Windows`
- `Agent_Linux` (the Linux agent directory currently uses an underscore in this repository)
- `Panel`
- `Website`

The system should support games that use Steam Workshop directly, games that require server-side mod installation, and games that use non-Workshop add-ons or content packs.

Target games include DayZ, Arma / Arma 2 / Arma 3, Rust, Garry's Mod, CS / Source games where applicable, and future games with installable server content.

## Current Code Findings

### Add-ons manager state

The old add-ons manager has already been partially converted into a broader Server Content Manager.

Relevant files:

- `modules/addonsmanager/addons_manager.php`
- `modules/addonsmanager/addons_installer.php`
- `modules/addonsmanager/server_content_helpers.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/addonsmanager/workshop_content.php`
- `modules/addonsmanager/workshop_action.php`
- `modules/addonsmanager/server_content_categories.php`
- `modules/addonsmanager/scripts/workshop/generic_steam_workshop_linux.sh`
- `modules/addonsmanager/scripts/workshop/generic_steam_workshop_windows_cygwin.sh`
- `modules/addonsmanager/SERVER_CONTENT_ROADMAP.md`
- `modules/addonsmanager/SERVER_CONTENT_WORKSHOP_PHASE1.md`

What exists:

- Server Content language and categories have started replacing legacy "add-ons" language.
- Install methods include `download_zip`, `steam_workshop`, `config_edit`, and `post_script`.
- Workshop content has a user-facing page where customers can enter Workshop item IDs.
- Workshop IDs are validated as numeric strings.
- A `server_content_workshop` table is created/migrated by helper code.
- A remote manifest is written under the game server home.
- Panel-owned Linux and Cygwin/Windows templates are used to generate per-job scripts under the server home.
- The generated job script creates a temporary SteamCMD runscript and calls SteamCMD with `+runscript`.
- Basic install, update, update selected, update all, and remove selected actions exist.
- Actions are logged through the panel logger and the script writes a text log under `gsp_server_content`.

What is incomplete:

- No search or metadata lookup workflow exists in the UI.
- Titles are stored but not reliably resolved.
- There is no load order management.
- There is no enabled/disabled state separate from installed/removed state.
- There is no per-game install strategy model.
- There is no first-class DayZ/Arma `-mod=` generation.
- There is no robust copy-key behavior for DayZ `.bikey` files.
- There is no clone/copy mod list workflow.
- There is no asynchronous job/progress system for long Workshop installs.
- Current generic scripts are synchronous from the Panel request.
- Current scripts assume anonymous SteamCMD login.
- Current scripts only constrain writes under the server home, so they do not support a true agent global cache outside the game directory.
- Current scripts run optional `post_install_script` using `bash -lc`, which should remain admin-only and should not be customer-provided.
- The XML schema does not yet define the Workshop support tags that the helper code expects.

### Current Workshop Phase 1 flow

The current user flow is:

1. User opens the Workshop Mods page for a server.
2. User enters numeric Workshop item IDs.
3. Panel inserts or updates rows in `server_content_workshop`.
4. Panel writes a manifest to:
   - `{GAME_HOME}/gsp_server_content/workshop_manifest.json`
5. Panel writes a generated job script to:
   - `{GAME_HOME}/gsp_server_content/jobs/workshop/workshop_job_<timestamp>_<random>.sh`
6. Panel executes:
   - `bash <script_path> <manifest_path>`
7. The script writes a temporary SteamCMD runscript:
   - `@ShutdownOnFailedCommand 0`
   - `@NoPromptForPassword 1`
   - `force_install_dir <server_root>`
   - `login anonymous`
   - `workshop_download_item <workshop_app_id> <workshop_id> validate`
8. The script runs SteamCMD with `+runscript <scriptfile>`.
9. The script copies downloaded files to a target folder, defaulting to:
   - `{SERVER_ROOT}/@{WORKSHOP_ID}`
10. Panel updates DB row state to `installed`, `failed`, or `removed`.

This is useful as a proof of concept, but it is not yet a commercial Workshop/mod system.

### Current DB/storage state

The current helper creates:

`server_content_workshop`

Known columns:

- `id`
- `content_id`
- `home_id`
- `home_cfg_id`
- `remote_server_id`
- `workshop_app_id`
- `workshop_item_id`
- `title`
- `install_state`
- `last_installed_at`
- `last_updated_at`
- `last_error`
- `created_by`
- `created_at`
- `updated_at`

The current helper also writes agent-side files under the server home:

- `gsp_server_content/workshop_manifest.json`
- `gsp_server_content/manifests/*.json`
- `gsp_server_content/installed_content.json`
- `gsp_server_content/workshop_install.log`
- `gsp_server_content/workshop_install_windows.log`
- `gsp_server_content/workshop/removed/*`
- `gsp_server_content/jobs/workshop/workshop_job_*.sh`

Current DB weaknesses:

- No load order column.
- No enabled column.
- No install path column per row.
- No install folder/name column per row.
- No installed Workshop timestamp/version/hash metadata from Steam.
- No job table for long-running installs.
- No normalized global Workshop item metadata table.
- No clean split between desired state, installed state, and job state.

### Current Panel-Agent communication

Panel communicates with agents through XML-RPC using `includes/lib_remote.php`.

Relevant methods:

- `steam_cmd(...)`
- `steam_workshop(...)`
- `get_workshop_mods_info(...)`
- `start_file_download(...)`
- `remote_writefile(...)`
- `remote_readfile(...)`
- `exec(...)`

There are two Workshop paths:

1. Legacy agent RPC:
   - Panel method: `GSPRemoteLibrary::steam_workshop(...)`
   - Agent subroutine: `steam_workshop_without_decrypt(...)`
   - Runs SteamCMD in a screen update session.
   - Generates post-install shell snippets that edit a config file.

2. Current Server Content manifest path:
   - Panel writes a JSON manifest using `remote_writefile`.
   - Panel copies an approved script to the agent using `remote_writefile`.
   - Panel runs the script through `exec`.
   - This path does not use the old `steam_workshop` RPC.

The second path is cleaner for future Server Content work because it is manifest-driven, but it should be moved away from direct synchronous `exec` into a first-class agent job/action.

### Current agent SteamCMD behavior

Linux agent:

- File: `Agent_Linux/gsp_agent.pl`
- SteamCMD path constants:
  - `AGENT_RUN_DIR/steamcmd`
  - `steamcmd.sh`
- `check_steam_cmd_client` downloads and installs SteamCMD if missing.
- `steam_cmd_without_decrypt` creates a SteamCMD runscript and runs it in a screen update session.
- `steam_workshop_without_decrypt` creates a SteamCMD runscript containing `workshop_download_item` lines and runs it in a screen update session.
- `get_workshop_mods_info` reads `.gspmod` files from `AGENT_RUN_DIR/WorkshopModsInfo`.

Windows/Cygwin agent:

- File: `Agent-Windows/gsp_agent.pl`
- SteamCMD path constants:
  - `/GSP/steamcmd`
  - `steamcmd.exe`
- `steam_cmd_without_decrypt` uses Cygwin path conversion for `force_install_dir`.
- `steam_workshop_without_decrypt` uses Cygwin path conversion for the mods path and the SteamCMD runscript.
- `get_workshop_mods_info` mirrors the Linux behavior.

Agent limitations:

- The legacy `steam_workshop` RPC is not tied to the newer `server_content_workshop` DB table.
- It edits config files through generated shell script content instead of structured startup parameter state.
- It does not understand enabled/disabled state or load order.
- It does not expose a durable install job ID with pollable progress.
- It does not track per-server install manifests as the source of truth.
- It does not provide a DayZ-specific strategy.

### Current game XML and startup parameter behavior

Relevant files:

- `modules/config_games/schema_server_config.xml`
- `modules/config_games/config_servers.php`
- `modules/config_games/cli-params.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/cfg_text_replace.php`
- `modules/gamemanager/update_actions.php`

The XML schema currently supports:

- `cli_template`
- `cli_params`
- `replace_texts`
- `custom_fields`
- `pre_install`
- `post_install`
- `pre_start`
- `post_start`
- `environment_variables`
- `lock_files`
- `configuration_files`

The XML schema does not currently support a first-class `workshop_support` block. Helper code already checks ad hoc fields such as:

- `workshop_app_id`
- `workshop_appid`
- `steam_workshop_app_id`
- `steam_workshop_appid`
- `workshop_script_linux`
- `workshop_script_windows`

Because `read_server_config()` validates XML against `schema_server_config.xml`, these ad hoc fields are not safe to use in game XML until the schema is extended.

Startup parameters are currently generated in `mini_start.php` from `cli_template` and `cli_params`, with stored server values/custom fields layered in. This should be the integration point for generated mod parameters, but the mod list must be structured data, not raw customer command text.

### Current config/custom field behavior

`cfg_text_replace.php` reads custom fields from the database and applies configured replacements to files through `remote_readfile` and `remote_writefile`.

This is useful for game config editing, but it should not be used as the primary Workshop/mod list system because:

- It is text replacement based.
- It does not model enabled/disabled mods.
- It does not model load order.
- It does not model install state.
- It can be fragile for repeated add/remove/reorder operations.

### Existing Workshop support in game configs

Some Source/Garry's Mod configs expose Workshop-related startup params directly:

- `+host_workshop_collection`
- `+host_workshop_map`
- `+workshop_start_map`

This is game-managed Workshop support. GSP should preserve that behavior and not force all games through server-side file copying.

## Current Add-ons/Content Module State

The current module is best described as "Server Content Phase 1."

Partially working pieces:

- Admins can define content records in the `addons` table.
- Users can view server content from the add-ons manager.
- `steam_workshop` is recognized as an install method.
- Workshop IDs can be entered manually.
- A manifest-driven script runner exists.
- Basic install/update/remove states exist.

Broken or incomplete pieces for commercial use:

- No schema-level game capability declaration.
- No game-specific install strategy selection.
- No load order UI.
- No enable/disable UI.
- No safe startup parameter generation for mod lists.
- No DayZ/Arma key copy strategy.
- No metadata discovery or title resolution.
- No async job/progress model.
- No central cache design.
- No cache cleanup policy.
- No rollback/repair strategy.
- No robust uninstall ownership model for copied keys/files.
- No support for private Workshop items requiring login beyond older SteamCMD update settings.

## Recommended Architecture

### Main design rule

The Panel database should be the source of truth for desired server content state.

The agent should be the source of truth for execution state and filesystem results.

Agent files should be treated as runtime manifests, logs, caches, and recovery aids, not as the only source of truth.

### Core model

Create a first-class Server Content / Workshop system with:

- XML-declared game capabilities.
- Database-stored per-server content state.
- Agent-executed install/update/uninstall jobs.
- Structured startup parameter generation.
- Game-specific install strategies.
- Safe, pollable job logs.
- Optional global cache.
- Cross-platform behavior for Linux and Cygwin/Windows agents.

### Required states

For a server Workshop item:

- `selected`: saved but not installed.
- `queued`: job has been queued.
- `installing`: agent is installing it.
- `installed`: installed and available.
- `disabled`: installed but excluded from generated startup parameters.
- `updating`: update job is running.
- `failed`: last install/update failed.
- `removing`: uninstall job is running.
- `removed`: removed from desired state.

For an install job:

- `queued`
- `running`
- `succeeded`
- `failed`
- `cancelled`

### Recommended component responsibilities

Panel:

- Reads game XML capability definitions.
- Shows Workshop/Mods/Server Content UI only when supported.
- Stores desired content state.
- Queues install/update/remove/reorder actions.
- Generates desired startup mod list from DB state.
- Sends structured manifests/jobs to the agent.
- Polls agent for progress/logs.
- Shows clear error messages and restart-required prompts.

Agent:

- Runs SteamCMD.
- Stages downloads.
- Copies/installs files using approved strategies.
- Copies DayZ/Arma keys when configured.
- Writes progress logs.
- Writes per-job result JSON.
- Tracks installed file manifests where useful.
- Does not trust customer-supplied shell commands.
- Does not decide desired load order.

Game XML:

- Declares whether Workshop/content support exists.
- Declares the provider, Steam app IDs, install strategy, path templates, startup parameter format, key copy behavior, cache policy, and authentication needs.
- Provides admin-defined strategy data, not customer-editable commands.

## XML Capability Proposal

Add a first-class optional block to `schema_server_config.xml`.

Example:

```xml
<workshop_support>
  <enabled>1</enabled>
  <provider>steam</provider>
  <steam_app_id>221100</steam_app_id>
  <workshop_app_id>221100</workshop_app_id>
  <download_method>steamcmd</download_method>
  <install_strategy>dayz_mod_folder</install_strategy>
  <install_path>{GAME_PATH}</install_path>
  <mod_folder_format>@{SAFE_TITLE}</mod_folder_format>
  <startup_param_format>-mod={MOD_LIST}</startup_param_format>
  <mod_separator>;</mod_separator>
  <mod_prefix>@</mod_prefix>
  <requires_restart>1</requires_restart>
  <supports_load_order>1</supports_load_order>
  <supports_disable>1</supports_disable>
  <allow_cache>1</allow_cache>
  <requires_steam_login>0</requires_steam_login>
  <copy_keys enabled="1">
    <source_pattern>{MOD_PATH}/keys/*.bikey</source_pattern>
    <target_path>{GAME_PATH}/keys</target_path>
  </copy_keys>
</workshop_support>
```

### Required XML fields

- `enabled`
- `provider`
- `download_method`
- `install_strategy`

For Steam Workshop:

- `steam_app_id`
- `workshop_app_id`

For strategies that install files:

- `install_path`

For strategies that update startup parameters:

- `startup_param_format`
- `mod_separator`

### Optional XML fields

- `mod_prefix`
- `mod_folder_format`
- `server_mod_param_format`
- `client_mod_param_format`
- `copy_keys`
- `requires_restart`
- `supports_load_order`
- `supports_disable`
- `allow_cache`
- `cache_scope`
- `requires_steam_login`
- `steam_login_profile`
- `max_items`
- `allowed_item_types`
- `install_script_key`
- `post_install_strategy`
- `validate_files`
- `backup_before_update`
- `preserve_previous_version`

### Supported install strategies

- `game_managed_workshop`
- `steamcmd_download_only`
- `copy_to_game_root`
- `copy_to_mod_folder`
- `dayz_mod_folder`
- `arma_mod_folder`
- `config_only`
- `custom_scripted_install`

### Strategy meanings

`game_managed_workshop`

- The game downloads/uses Workshop content internally.
- GSP should generate game-supported startup params such as Workshop collection IDs.
- No server-side file copy is required.
- Example: Garry's Mod or Source games using `+host_workshop_collection`.

`steamcmd_download_only`

- Agent downloads Workshop item to a known cache/staging path.
- No direct install into game root.
- Useful for games/tools that consume downloaded content separately.

`copy_to_game_root`

- Agent downloads then copies files directly under `{GAME_PATH}` or a safe subpath.

`copy_to_mod_folder`

- Agent downloads then copies content to a generated mod folder.

`dayz_mod_folder`

- Agent downloads content.
- Agent installs under `{GAME_PATH}/@ModName`.
- Agent copies `.bikey` files to `{GAME_PATH}/keys`.
- Panel generates `-mod=@Mod1;@Mod2` from enabled ordered items.

`arma_mod_folder`

- Similar to DayZ, but should support Arma-specific client/server mod parameter variants as needed.

`config_only`

- The content changes structured config values only.
- No Workshop download.

`custom_scripted_install`

- Admin-defined trusted script/action.
- Should be used sparingly.
- Customer must never provide arbitrary script text.

### XML validation rules

- `enabled` must be `0` or `1`.
- App IDs and Workshop IDs must be numeric.
- Strategy must be one of the allowed strategy names.
- Path templates must only use approved variables.
- Path templates must resolve under allowed roots.
- Startup param format must only contain approved placeholders.
- `copy_keys` target path must resolve under the game home unless explicitly allowed by admin policy.
- `custom_scripted_install` must reference an approved script key/path, not inline customer text.

### Backward compatibility

Older XML files should continue to work with no Workshop support.

If `workshop_support` is absent:

- Existing startup parameters such as `+host_workshop_collection` continue to work.
- Existing add-ons manager content can still be shown if admin-created content records exist.
- No Workshop Mods page should be shown unless enabled by admin record or capability detection.

Existing ad hoc fields such as `workshop_app_id` should either be migrated into `workshop_support` or tolerated by a compatibility parser after the schema is explicitly updated.

## DB Table Proposal

### `gsp_workshop_items`

Global metadata cache for Workshop items.

Suggested columns:

- `id`
- `provider`
- `app_id`
- `workshop_id`
- `title`
- `description_short`
- `preview_url`
- `author_name`
- `visibility`
- `file_size`
- `time_created`
- `time_updated`
- `metadata_json`
- `last_metadata_fetch_at`
- `last_metadata_error`
- `created_at`
- `updated_at`

Unique key:

- `(provider, app_id, workshop_id)`

### `gsp_server_workshop_items`

Desired per-server Workshop state.

Suggested columns:

- `id`
- `home_id`
- `home_cfg_id`
- `remote_server_id`
- `content_id`
- `provider`
- `app_id`
- `workshop_app_id`
- `workshop_id`
- `item_title`
- `install_name`
- `safe_folder_name`
- `install_path`
- `enabled`
- `load_order`
- `install_strategy`
- `install_state`
- `installed_version`
- `installed_timestamp`
- `last_update_check`
- `last_installed_at`
- `last_updated_at`
- `last_error`
- `restart_required`
- `installed_by`
- `created_at`
- `updated_at`

Recommended unique key:

- `(home_id, provider, workshop_id)`

### `gsp_workshop_install_jobs`

Pollable install/update/remove jobs.

Suggested columns:

- `id`
- `job_uuid`
- `home_id`
- `remote_server_id`
- `action`
- `status`
- `requested_by`
- `started_at`
- `finished_at`
- `agent_job_id`
- `manifest_path`
- `log_path`
- `result_path`
- `progress_percent`
- `last_message`
- `last_error`
- `created_at`
- `updated_at`

### `gsp_workshop_install_job_items`

Per-item job details.

Suggested columns:

- `id`
- `job_id`
- `server_workshop_item_id`
- `workshop_id`
- `action`
- `status`
- `install_path`
- `message`
- `error`
- `started_at`
- `finished_at`

### `gsp_server_content_profiles`

Optional admin-defined reusable profiles per game/config.

Suggested columns:

- `id`
- `home_cfg_id`
- `profile_key`
- `display_name`
- `provider`
- `steam_app_id`
- `workshop_app_id`
- `install_strategy`
- `install_path_template`
- `startup_param_format`
- `copy_keys_json`
- `cache_policy`
- `created_at`
- `updated_at`

### Source of truth

The Panel DB should be the source of truth for:

- Which mods are desired.
- Which mods are enabled.
- Load order.
- Generated startup params.
- Last known install state.

The agent should write runtime state for:

- Active job progress.
- Install logs.
- Download/cache manifests.
- Files installed during a job.
- Last job result.

## Agent Execution Proposal

### Preferred direction

Add a dedicated agent action for server content jobs instead of running generic `exec` synchronously from a web request.

Potential XML-RPC methods:

- `server_content_start_job(home_id, manifest_json)`
- `server_content_job_status(home_id, job_id)`
- `server_content_job_log(home_id, job_id, offset)`
- `server_content_cancel_job(home_id, job_id)`

The agent should return quickly with a job ID. The Panel polls status and logs.

### Manifest shape

Example:

```json
{
  "manifest_version": 2,
  "job_uuid": "uuid",
  "home_id": 123,
  "home_path": "/home/gsp_agent/GSP_User_Files/123",
  "provider": "steam",
  "action": "install_update",
  "strategy": "dayz_mod_folder",
  "steam_app_id": "221100",
  "workshop_app_id": "221100",
  "startup": {
    "param_format": "-mod={MOD_LIST}",
    "separator": ";",
    "mod_prefix": "@"
  },
  "copy_keys": {
    "enabled": true,
    "source_pattern": "{MOD_PATH}/keys/*.bikey",
    "target_path": "{GAME_PATH}/keys"
  },
  "items": [
    {
      "workshop_id": "1559212036",
      "title": "CF",
      "safe_folder_name": "@CF",
      "enabled": true,
      "load_order": 10,
      "install_path": "{GAME_PATH}/@CF"
    }
  ]
}
```

### Agent job execution

For each job:

1. Validate manifest fields.
2. Resolve paths from approved templates only.
3. Create job directory under agent control.
4. Start a background worker, preferably screen-backed for consistency with existing agent behavior.
5. Write progress logs.
6. Run SteamCMD download.
7. Verify expected download path exists.
8. Stage content.
9. Install/copy/sync to final path.
10. Copy keys if strategy requires it.
11. Write installed file manifest.
12. Write job result JSON.
13. Return status to Panel polling.

### SteamCMD command

Baseline command:

```bash
steamcmd +login anonymous +workshop_download_item <workshop_app_id> <workshop_id> validate +quit
```

Authenticated variant:

```bash
steamcmd +login <configured_user> <configured_password> +workshop_download_item <workshop_app_id> <workshop_id> validate +quit
```

The agent must redact credentials from logs.

### Agent paths

Recommended paths:

- Job root:
  - `{CONTROL_PATH}/server_content/jobs/{job_uuid}`
- Job manifest:
  - `{CONTROL_PATH}/server_content/jobs/{job_uuid}/manifest.json`
- Job log:
  - `{CONTROL_PATH}/server_content/jobs/{job_uuid}/job.log`
- Job result:
  - `{CONTROL_PATH}/server_content/jobs/{job_uuid}/result.json`
- Per-server content manifest:
  - `{CONTROL_PATH}/server_content/installed_manifest.json`
- Per-server staging:
  - `{CONTROL_PATH}/server_content/staging/{job_uuid}`
- Per-server Workshop staging:
  - `{CONTROL_PATH}/server_content/workshop/staging/{workshop_id}`

`CONTROL_PATH` should be agent-managed and should not be customer-editable through FTP/file manager.

### Cache paths

Optional global cache:

- `{AGENT_CACHE}/steam_workshop/{workshop_app_id}/{workshop_id}`

Optional per-server cache:

- `{CONTROL_PATH}/server_content/cache/steam_workshop/{workshop_app_id}/{workshop_id}`

The first version can use per-server staging only. Global cache can be added once ownership, quota, cleanup, and sharing rules are defined.

## User Workflow

Customer workflow:

1. Open a game server.
2. Click `Workshop`, `Mods`, or `Server Content`.
3. Paste a Workshop URL or numeric Workshop ID.
4. Panel extracts and validates the numeric ID.
5. Panel optionally fetches title/metadata.
6. User adds the item to the install queue.
7. User chooses enabled or disabled.
8. User adjusts load order where the game supports it.
9. User clicks `Install / Update`.
10. Panel shows live progress and logs.
11. When complete, Panel marks restart required if the game is running.
12. User restarts server.
13. Startup params are generated from the enabled ordered mod list.

Installed mod list should show:

- Enabled checkbox/toggle.
- Load order controls.
- Workshop ID.
- Title.
- Folder/install name.
- Installed status.
- Last updated.
- Last error.
- Actions: update, repair, disable, uninstall.

## Admin Workflow

Admin workflow:

1. Edit game XML or admin content profile.
2. Enable Workshop support.
3. Set provider and Steam app/workshop IDs.
4. Select install strategy.
5. Define install path template.
6. Define startup parameter format.
7. Define key copy behavior.
8. Choose cache policy.
9. Choose whether Steam login is required.
10. Save and validate schema.
11. Test install/update/uninstall on Linux and Cygwin/Windows agents.

Admins should not need to write shell scripts for common games like DayZ, Arma, Rust, Garry's Mod, or Source games.

## Workshop Item Discovery

### First version

Support:

- Numeric Workshop ID entry.
- Steam Workshop URL paste.
- Extraction of the `id=` query parameter.
- Basic local validation.
- Optional metadata fetch when a Steam Web API key is configured.

Accepted examples:

- `1559212036`
- `https://steamcommunity.com/sharedfiles/filedetails/?id=1559212036`
- Multiple IDs separated by newline or comma.

Validation:

- Extract only numeric IDs.
- Reject non-numeric values.
- Deduplicate IDs.
- Enforce optional max item count per game/server.

### Professional version

Support:

- Steam Web API metadata fetch.
- Cached metadata table.
- Search by title when API key is configured.
- Preview image/title/author display.
- Visibility and removed/private item warnings.
- Update timestamp checks.

Limitations:

- Some Workshop content requires authenticated Steam credentials.
- Some items are private, age-gated, removed, or region-restricted.
- Search can be rate-limited.
- SteamCMD may download content even when metadata fetch fails, or vice versa.

Recommendation:

- Build the first version around URL/ID entry.
- Add metadata enrichment as a progressive enhancement.
- Do not block install solely because metadata is unavailable.

## Download and Install Strategy

### SteamCMD execution

SteamCMD should run on the agent host because the agent has filesystem access to the game server install.

Linux:

- Use `steamcmd.sh`.
- Existing agent already knows `AGENT_RUN_DIR/steamcmd/steamcmd.sh`.

Cygwin/Windows:

- Use `steamcmd.exe`.
- Convert Cygwin paths to Windows paths when passing paths to SteamCMD.
- Keep scripts and screen usage as similar to Linux as possible.

### Download flow

Recommended flow:

1. Resolve SteamCMD path.
2. Resolve download/cache path.
3. Run `workshop_download_item`.
4. Capture stdout/stderr to job log.
5. Verify expected content folder exists.
6. Stage content.
7. Install via strategy.
8. Record installed file manifest.

### DayZ install flow

For each enabled mod:

1. Download Workshop item with app ID `221100`.
2. Resolve safe display title if available.
3. Generate safe folder name:
   - Prefer `@Title` normalized for filesystem and startup use.
   - Fall back to `@<workshop_id>`.
4. Copy content to:
   - `{GAME_PATH}/@ModName`
5. Copy `.bikey` files from common locations:
   - `{MOD_PATH}/keys/*.bikey`
   - `{MOD_PATH}/Keys/*.bikey`
   - optionally recursive search if configured.
6. Copy keys to:
   - `{GAME_PATH}/keys`
7. Track copied keys in an installed file manifest.
8. Generate `-mod=@Mod1;@Mod2` from enabled ordered items.
9. Mark restart required if server is running.

### Generic game support

Game-managed Workshop games:

- Store Workshop collection/map IDs.
- Generate the game's native Workshop startup params.
- Do not copy files unless configured.

Copy-to-folder games:

- Download Workshop item.
- Copy folder contents to a configured target path.
- Optionally add startup parameters.

Config-only content:

- Apply structured config changes from approved templates.

Custom scripted install:

- Admin-defined trusted scripts only.
- Scripts should receive a manifest path.
- Scripts should write structured result JSON.

## Startup Parameter Integration

Startup parameter generation must be structured and repeatable.

For DayZ/Arma style mods:

1. Load enabled Workshop items for the server ordered by `load_order`.
2. Build `MOD_LIST` using the XML `mod_separator`.
3. Render `startup_param_format`.
4. Inject the rendered mod parameter into startup generation.
5. Avoid duplicate `-mod=` entries.
6. Preserve unrelated startup parameters.
7. Remove generated mod params when all mods are disabled/uninstalled.

Example:

```text
-mod=@CF;@Dabs Framework;@VPPAdminTools
```

Rules:

- Customers should not edit the raw generated `-mod=` string.
- Customers may reorder/enable/disable structured mod rows.
- Generated startup fragments should be labeled internally as GSP-managed.
- Existing custom startup params should remain separate where possible.

Recommended implementation:

- Add a startup fragment resolver before `mini_start.php` finalizes `$start_cmd`.
- The resolver reads the server content DB state and XML `workshop_support`.
- It returns a generated fragment such as `-mod=...`.
- The fragment is inserted through a reserved placeholder or appended according to XML rules.

Possible XML placeholder:

```xml
<cli_template>%GAME_TYPE% -config=serverDZ.cfg {GSP_WORKSHOP_PARAMS}</cli_template>
```

If no placeholder exists, XML can specify:

```xml
<startup_injection mode="append" />
```

## Uninstall, Disable, Update, Repair

### Disable

Disable should:

- Set `enabled=0`.
- Keep installed files.
- Remove the item from generated startup parameters.
- Mark restart required if server is running.

### Uninstall

Uninstall should:

- Set job status to `removing`.
- Remove item from startup mod list.
- Stop using the mod immediately after next restart.
- Optionally delete installed files.
- Optionally leave global cache intact.
- Remove copied keys only if safe.
- Update DB row status.

Key cleanup rule:

- Only remove keys that GSP knows it copied and that are not still required by another installed mod.
- If ownership is unclear, leave the key and warn the user/admin.

### Update

Update should:

- Download new Workshop content.
- Stage it separately.
- Preserve old installed folder until staging succeeds.
- Swap/copy into place only after successful download.
- Roll back if install fails where feasible.
- Mark restart required if server is running.

### Repair/reinstall

Repair should:

- Re-run download with `validate`.
- Re-copy content.
- Re-copy keys.
- Rebuild startup parameters.

### Clone mod set

Clone should:

- Copy desired mod list, enabled state, and load order to another compatible server.
- Validate same game/config capability.
- Queue install/update job for the target server.
- Reuse cache when safe and available.

## Caching and Cleanup Plan

### Cache options

No cache:

- Lowest complexity.
- Highest repeated bandwidth usage.

Per-server cache:

- Safer ownership model.
- Easier cleanup.
- Less sharing benefit.

Agent global cache:

- Best reuse across servers.
- Saves bandwidth and time.
- Requires quota, permissions, locking, and cleanup.

### Recommended phases

First implementation:

- Use per-server staging/cache under agent control.
- Do not expose cache through customer file manager.

Later:

- Add optional global cache by app ID and Workshop ID.
- Track size and last-used timestamp.
- Admin-configurable max cache size.
- Cleanup least recently used items.
- Support hardlink/symlink/junction only after cross-platform testing.

### Cache metadata

Cache manifest should track:

- Provider.
- App ID.
- Workshop ID.
- Download timestamp.
- Steam update timestamp if known.
- Size.
- Source path.
- Last used by home ID.
- Last validation result.

## Security Considerations

### Customer input

Customers may provide:

- Workshop URLs.
- Workshop IDs.
- Enable/disable choices.
- Load order.

Customers must not provide:

- Shell commands.
- SteamCMD command fragments.
- Arbitrary install paths.
- Arbitrary startup command fragments.
- Arbitrary copy patterns.

### Command safety

- Commands should be generated from trusted XML/admin config and validated DB state.
- Workshop IDs must be numeric.
- URL parsing must only extract numeric `id` values.
- Path templates must resolve under allowed roots.
- No path traversal.
- No null bytes or control characters.
- Logs must redact Steam credentials.
- Admin scripts must be explicitly marked trusted.

### File location safety

Managed manifests, job files, logs, and scripts should live outside customer-editable roots where possible.

If current deployment requires storing under game home temporarily:

- Use a `gsp_server_content` directory.
- Prevent customer file manager/FTP access to that control directory if possible.
- Validate that all write targets stay under approved paths.

### Credentials

- Steam credentials should remain in admin settings or a dedicated secure credential profile.
- Do not expose credentials in manifests shown to customers.
- Do not write raw passwords to logs.
- Prefer passing credentials through agent-managed secure files or redacted runscript generation.

### Avoid arbitrary process/file damage

- Install/uninstall should act only on paths from the per-item manifest.
- Remove only files/folders GSP installed or explicitly owns.
- Do not recursively delete broad paths such as `{GAME_PATH}`.
- Do not remove shared keys unless manifest ownership is clear.

## Cross-platform Considerations

### Linux

- Use `steamcmd.sh`.
- Use POSIX path handling.
- Use screen-backed jobs for consistency with existing agent update behavior.
- Use `rsync` if available, otherwise safe recursive copy.
- Preserve file ownership for the game server user.

### Cygwin/Windows

- Use `steamcmd.exe`.
- Convert paths with `cygpath -wa` when passing to SteamCMD.
- Be careful with spaces and backslashes.
- Use Cygwin shell for shared scripts where practical.
- Avoid Linux-only tools unless bundled or checked.
- Use safe copy behavior that works under Cygwin.

### Common risks

- Quoting differences.
- Case-insensitive paths on Windows.
- Long paths.
- File locks while server is running.
- Different SteamCMD output.
- Different permission/ownership behavior.
- Symlink/junction behavior is not portable enough for first version.

## DayZ-Specific Plan

DayZ should be the first full strategy because it exercises the hard cases.

XML:

```xml
<workshop_support>
  <enabled>1</enabled>
  <provider>steam</provider>
  <steam_app_id>221100</steam_app_id>
  <workshop_app_id>221100</workshop_app_id>
  <download_method>steamcmd</download_method>
  <install_strategy>dayz_mod_folder</install_strategy>
  <install_path>{GAME_PATH}</install_path>
  <mod_folder_format>@{SAFE_TITLE}</mod_folder_format>
  <startup_param_format>-mod={MOD_LIST}</startup_param_format>
  <mod_separator>;</mod_separator>
  <copy_keys enabled="1">
    <source_pattern>{MOD_PATH}/keys/*.bikey</source_pattern>
    <target_path>{GAME_PATH}/keys</target_path>
  </copy_keys>
</workshop_support>
```

Panel behavior:

- Accept Workshop URL/ID.
- Resolve title when possible.
- Store desired item row.
- Allow reorder.
- Allow enabled/disabled.
- Generate `-mod=` from enabled ordered mods.
- Prompt restart after changes.

Agent behavior:

- Download item.
- Install to `@ModName`.
- Copy keys.
- Write install manifest.
- Report clear failures:
  - SteamCMD missing.
  - Steam login required.
  - Workshop item not found.
  - Download path missing.
  - Disk full.
  - Key copy failed.

## Generic Game Support Plan

Each game should choose a strategy:

- Source/Garry's Mod: `game_managed_workshop`
- Rust: likely server-managed or game-specific strategy depending on current Rust mod ecosystem support.
- DayZ: `dayz_mod_folder`
- Arma 2/3: `arma_mod_folder`
- Generic file content: `copy_to_mod_folder` or `copy_to_game_root`
- Config packs: `config_only`

The UI can stay mostly the same while XML changes behavior behind the scenes.

## Commercial-Quality Expected Result

Customer experience:

- Paste Workshop URL or ID.
- See item title if available.
- Install one or many mods.
- See progress and logs.
- Enable/disable mods without deleting them.
- Reorder mods.
- Update selected or update all.
- Uninstall cleanly.
- See restart-required prompts.
- See clear errors that explain what failed.

Admin experience:

- Game XML declares support.
- Install strategy is selected by game.
- Steam app IDs are configured centrally.
- Startup parameter format is configured centrally.
- Key copy behavior is configured centrally.
- Cache policy is configured centrally.
- No customer-editable helper batch files are needed.

## Recommended Implementation Phases

### Phase 1: Inventory/report only

- Complete this report.
- Do not modify code.

### Phase 2: XML schema design

- Add `workshop_support` schema.
- Add parser helpers.
- Add validation warnings in config editor.
- Preserve old XML behavior.

### Phase 3: DB schema

- Add normalized Workshop metadata table.
- Add per-server Workshop item table.
- Add install job and job item tables.
- Migrate existing `server_content_workshop` rows.

### Phase 4: Panel UI

- Replace Phase 1 Workshop page with:
  - URL/ID entry.
  - Installed list.
  - Enable/disable.
  - Load order.
  - Update/uninstall/repair.
  - Live job log.

### Phase 5: Agent job runner

- Add first-class agent job actions.
- Return job ID immediately.
- Poll status/logs.
- Keep screen as shared backend.

### Phase 6: SteamCMD download job

- Implement cross-platform SteamCMD runner.
- Support anonymous and configured login.
- Redact credentials.
- Write progress/result files.

### Phase 7: DayZ strategy

- Download Workshop items.
- Install `@ModName` folders.
- Copy `.bikey` files.
- Generate `-mod=` startup fragment.
- Test install/update/disable/uninstall.

### Phase 8: Startup parameter integration

- Add structured generated startup fragments.
- Avoid duplicate raw params.
- Preserve existing game startup behavior.

### Phase 9: Update/uninstall/reorder

- Add update all/selected.
- Add repair.
- Add safe uninstall.
- Add load order persistence.

### Phase 10: Cache/clone support

- Add optional global cache.
- Add cleanup policy.
- Add clone mod list workflow.

### Phase 11: Additional game strategies

- Add Arma strategy.
- Add game-managed Workshop strategy for Garry's Mod/Source where appropriate.
- Add generic copy/config strategies.

## Open Questions

- Should the agent expose a new XML-RPC job API, or should the Panel continue to use `exec` for Phase 2?
- What should the canonical agent control path be outside customer-editable files?
- Can the current file manager/FTP layer hide `gsp_server_content` reliably if control files remain under game home?
- Which DB migration system should own the new tables?
- How should existing `server_content_workshop` rows be migrated to the proposed normalized tables?
- Where should Steam credentials be stored for Workshop items requiring login?
- Should per-server Steam credentials ever be supported, or admin-only credential profiles?
- Which games require authenticated Workshop downloads?
- How should Workshop metadata be fetched without a Steam Web API key?
- Should title resolution be required before install, or should `@<workshop_id>` always be allowed?
- How should conflicts be handled when two Workshop items normalize to the same folder name?
- Should DayZ keys be copied recursively by default or only from configured paths?
- How should key ownership be tracked for safe uninstall?
- Should updating mods while a server is running be blocked, staged, or allowed with restart required?
- Should global cache be enabled in commercial deployments by default?
- How should disk quota account for downloaded cache and installed copies?
- Should symlinks/junctions ever be used to avoid duplicate copies?
- How should Windows long paths be handled?
- Should Workshop install logs be exposed through the existing log viewer or a dedicated Server Content job log page?

## Summary Recommendation

Use the current Phase 1 Server Content work as a starting point, but do not keep extending it as a synchronous script runner.

The professional path is:

- Add XML-declared Workshop/content capabilities.
- Store desired Workshop state in the Panel database.
- Add pollable agent jobs for install/update/remove.
- Use SteamCMD on the agent.
- Implement game-specific strategies, starting with DayZ.
- Generate startup parameters from structured enabled/load-ordered rows.
- Treat LGSL/GameQ and game query systems as unrelated to content installation.
- Keep customers away from raw commands, helper scripts, and managed control files.
