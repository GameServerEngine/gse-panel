# Impact Analysis

## Start/Stop/Restart Regression

Risk: Removing monitor/player-query code can accidentally remove Start, Stop, Restart, or bulk Execute controls because `ref_servermonitor.php` mixes query detail rendering with lifecycle control markup.

Mitigation:

- Separate lifecycle controls from query detail before deleting query panels.
- Keep single-server direct controls in the owner render path.
- Keep multi-server bulk radio controls and Execute workflow independent from LGSL/GameQ support.
- Add static and browser/regression checks before removal.

## Blank Dashboard or Monitor Status

Risk: Dashboard or Game Monitor can become blank if code expects `$status`, `$map`, `$players`, `$player_list`, `$name`, or `$address` from LGSL/GameQ includes.

Mitigation:

- Replace status display with agent status fields.
- Remove query-derived panels in a separate commit from lifecycle rendering.
- Default optional metadata to empty display, not fatal output.

## XML Import Failures

Risk: Removing or reordering XML tags can break existing game definition import.

Mitigation:

- Keep parser compatibility for old XML containing protocol/query tags.
- Preserve schema validation for current files.
- Validate configs with and without protocol tags.
- Treat protocol tags as optional legacy metadata until a migration proves they can be removed.

## Schema Migration Breakage

Risk: Older game definitions or admin-created XML may depend on query tags or `query_port` for startup variables.

Mitigation:

- Distinguish public query metadata from startup `QUERY_PORT` port math.
- Do not remove `query_port` if startup templates still use `%QUERY_PORT%`.
- Document any conversion from query-port semantics to generic additional-port semantics before implementation.

## Settings and Language Constants

Risk: Removing settings fields while templates still reference language constants can show raw `_key_` output or undefined constants.

Mitigation:

- Remove English constants and settings fields in the same focused commit only after source references are gone.
- Use English cleanup first.
- Decide later whether translation cleanup should be broad or leave stale constants harmlessly.

## RCON and TeamSpeak Confusion

Risk: RCON, LCON, Arma BE, or TeamSpeak ServerQuery features may be removed because they share words like protocol, query, players, or map.

Mitigation:

- Classify RCON/LCON/Arma BE as `KEEP_UNRELATED`.
- Classify TeamSpeak ServerQuery separately; only remove TeamSpeak query display if the product decision says to remove voice-server query UI.
- Keep `control_protocol` lifecycle shutdown/admin commands out of LGSL/GameQ cleanup.

## Billing, Reseller, and Slot Terminology

Risk: `players` appears in billing/reseller slot counts, max player limits, startup parameter forms, and user-facing game settings.

Mitigation:

- Remove only public query-derived player lists/counts.
- Keep billing max players, reseller slots, max-player provisioning, and startup max-player parameter fields unless a separate task changes them.

## Protocol Folder Removal

Risk: Removing `protocol/` would delete TeamSpeak/RCON-adjacent libraries or compatibility assets and violate the user request.

Mitigation:

- Do not remove `protocol/`.
- In future implementation, remove only proven-obsolete LGSL/GameQ files after every caller is removed or documented as legacy-disabled.

## Agent Status Gaps

Risk: If Panel removes query fallback before Linux and Windows status semantics are verified, online/offline could regress.

Mitigation:

- Verify both agents return `server_status` with process/session/game-port evidence.
- Keep status fallback behavior until both agents are validated.
- Do not remove `remote_query` from agents until the Panel no longer calls it and backward compatibility is considered.
