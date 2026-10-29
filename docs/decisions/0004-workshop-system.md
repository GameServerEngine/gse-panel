# Decision 0004: Server Content Manager Is The Workshop Layer

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Status

Accepted, Panel-side orchestration active

## Decision

`modules/addonsmanager` should remain the primary future home for Workshop items, mods, add-ons, and server content. `steam_workshop` should remain a deprecated compatibility layer only.

Phase 1 implements this decision by routing the user-facing Workshop install flow through `addonsmanager/workshop_content.php` and suppressing the standalone `steam_workshop` monitor button.

The current implementation keeps Workshop business logic in the Panel. Agents are generic executors: the Panel writes manifests, writes generated per-job scripts, invokes `exec`, reads logs/results, and updates database state. New first-class Workshop subsystems should not be added to `Agent-Windows` or `Agent_Linux` unless a future decision explicitly changes this.

## Reasoning

- `addonsmanager` already has the richer schema and more complete product direction.
- It supports content types beyond Steam Workshop.
- It is a better fit for load order, enable/disable, install history, and metadata.

## Alternatives Considered

- keep `steam_workshop` as the main module
- split mods, add-ons, and Workshop into separate modules

## Why Those Were Not Chosen

- `steam_workshop` is explicitly deprecated in the codebase.
- Separate modules would fragment user workflows and duplicate install logic.

## Implementation Notes

- Workshop input accepts numeric IDs or Steam URLs, then stores numeric IDs only.
- Manifests are written under the server home in `gsp_server_content`.
- A generated Linux/Cygwin-compatible job script is written under `gsp_server_content/jobs/workshop/` before execution.
- The generated job script creates a temporary SteamCMD runscript and runs SteamCMD with `+runscript`.
- Default script names are not treated as existing agent paths.
- Static custom Workshop scripts are deprecated for the primary path and logged if encountered.
- Known Workshop items are cataloged in `server_content_workshop_catalog`.
- Per-server item state, enablement, load order, update policy, and pending action are stored in `server_content_workshop`.
- Scheduler integrates via `workshop_update`, `workshop_update_and_restart`, `workshop_download_only`, and `workshop_install_pending_on_restart`.
- Generic content installs under `{SERVER_ROOT}/workshop/{MOD_FOLDER}` by default.
- DayZ/Arma-style installs default to `@<workshop_id>` folders and copy `.bikey` files into `keys` when present.
- Startup parameter generation remains a later phase.
- Game XML uses the canonical `workshop_support` block. The schema validates this block and no longer requires loose top-level Workshop tags.
- The Panel helpers read `workshop_support` first, then tolerate older direct tags only as compatibility fallbacks.
- Arma 3 Linux and Windows configs declare Workshop app ID `107410` through `workshop_support`.

## Reference Module Findings

The old `reference/Module-Steam_Workshop` module stored per-game profile XML under its own module folder, required admin-managed Workshop mod lists for some workflows, called dedicated agent RPCs such as `steam_workshop`, and used regex/profile rules to mutate game config values. Useful lessons retained:

- Customers should be able to paste one or more Workshop IDs or URLs.
- The Panel should store per-server installed Workshop rows.
- SteamCMD command generation should be controlled by admin/game configuration, not customer shell text.

Rejected legacy behavior:

- making `steam_workshop` the primary user module
- requiring admins to pre-enter every Workshop item ID
- mutating base game XML per customer install
- adding new Workshop-specific agent business logic

## Validation

Current validation commands:

```bash
php modules/addonsmanager/tests/workshop_helpers_test.php
php modules/config_games/tests/validate_server_configs.php
bash -n modules/addonsmanager/scripts/workshop/generic_steam_workshop_linux.sh
bash -n modules/addonsmanager/scripts/workshop/generic_steam_workshop_windows_cygwin.sh
```
