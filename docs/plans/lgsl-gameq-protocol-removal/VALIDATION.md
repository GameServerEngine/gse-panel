# Validation Plan

Use this checklist for future implementation. This planning task validates documentation structure only.

## Static Validation

- Run PHP syntax checks for every changed PHP file.
- Run `git diff --check`.
- Run targeted grep to confirm no runtime LGSL/GameQ references remain except intentionally documented legacy/deferred references.
- Confirm no Panel `protocol/` folder deletion.
- Confirm no code/schema/language/settings files are touched in planning-only commits.

## XML Validation

- Run `php modules/config_games/tests/validate_server_configs.php`.
- Validate a game config missing `<protocol>`, `<lgsl_query_name>`, and `<gameq_query_name>`.
- Validate existing XML files with protocol tags.
- Import existing XML definitions with and without protocol tags.
- Confirm XML creator and config editor do not require query protocol fields for managed servers.

## Panel UI Validation

- Dashboard loads without LGSL/GameQ panels.
- Home/Game Monitor page loads for single-server and multi-server views.
- Game Manager page loads.
- Start, Stop, Restart, and Kill buttons remain visible and functional according to status/permissions.
- Console, Logs, Scheduler, File Manager, Config, Update, and Server Content actions remain accessible according to permissions.
- No LGSL/GameQ UI/settings remain visible after the cleanup phase.
- No required query protocol validation remains.

## Runtime Status Validation

- Server online/offline uses agent status.
- `STARTING`, `STOPPING`, `ONLINE`, `OFFLINE`, `UNRESPONSIVE`, and `UNKNOWN` display correctly.
- Query failure does not mark an otherwise running server offline.
- Agent unreachable state does not remove permitted lifecycle controls that should still allow Stop/Restart attempts.

## Agent Regression Checks

- Linux agent `server_status` returns expected lifecycle/status fields.
- Windows agent `server_status` returns equivalent lifecycle/status fields.
- Linux and Windows start/stop/restart/force-stop behavior remains unchanged.
- Removing/deactivating `remote_query` callers does not break agent process/session/port status.
- If agent `remote_query` is removed later, verify Panel no longer calls it and document compatibility impact.

## Language and Settings Validation

- English language constants used by remaining templates exist.
- Removed settings are not saved or rendered.
- Stale translation constants do not produce undefined constants or raw lookup keys.
