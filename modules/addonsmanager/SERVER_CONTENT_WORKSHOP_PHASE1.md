# Server Content Workshop – Phase 1

Phase 1 adds manual Workshop ID support inside the existing `addonsmanager` module (user-facing label: **Server Content**).

## Scope (Phase 1)

- No Steam Workshop browser/search UI yet.
- No Steam scraping.
- User enters Steam Workshop numeric IDs or full Workshop URLs.
- Input may be comma-separated, newline-separated, or whitespace-separated.
- Panel validates IDs, removes duplicates, and stores them per server home.
- Panel lists saved IDs and supports:
  - Install New
  - Update Selected
  - Remove Selected
  - Update All
- Panel generates a per-server manifest at:
  - `%home_path%/server_content/workshop_manifest.json`
- Panel generates an approved per-job handler only, never a user-supplied command/path.
- The generated job is written under:
  - `%home_path%/server_content/jobs/workshop/workshop_job_<timestamp>_<random>.sh`
- The generated job writes a temporary SteamCMD runscript and invokes SteamCMD with `+runscript`.
- Static Workshop script paths in XML are deprecated for the primary workflow.
- The agent does not need `generic_steam_workshop_linux.sh` or `generic_steam_workshop_windows_cygwin.sh` to exist on disk.

## Security model

- Ownership check: non-admin users can only access homes assigned to them; admins can access any home.
- Actions are scoped to one `home_id`.
- IDs must be numeric only.
- Script path is not user-editable.
- Manifest path is validated to remain under server home.
- Default generic installs stay under `{SERVER_ROOT}/workshop/@<workshop_id>`.
- Arma/DayZ-style strategies install under `{SERVER_ROOT}/@<workshop_id>` so
  startup parameters can later use normal `-mod=@...` values.
- Remove is non-destructive in the generic scripts (preserve/move behavior for Phase 1).
- All actions are logged through panel logging.

## Database

Phase 1 introduces:

- `TABLE_PREFIX_PLACEHOLDERserver_content_workshop`

and keeps `TABLE_PREFIX_PLACEHOLDERaddons.addon_type` at `VARCHAR(32)` so `workshop` is valid.

## Game/admin config TODO (next phase hardening)

Each game should define and document:

- `workshop_app_id`
- install strategy and target path
- target install location
- restart/update behavior

If `workshop_app_id` is not defined in the content profile, server content
template, or game XML, Phase 1 returns a clear error rather than guessing.

Known Phase 1 app IDs:

- Arma 3 Workshop app ID: `107410`
- Arma 3 dedicated server installer app ID remains `233780`

## Phase 2 (not included here)

- Workshop browsing/search/select UI
- richer metadata/title lookups
- per-game install adapters and deeper status reporting
