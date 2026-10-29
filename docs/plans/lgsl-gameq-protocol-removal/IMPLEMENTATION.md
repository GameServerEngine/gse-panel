# Future Implementation Rules

This document defines rules for a future code implementation. Do not use this planning task as authorization to remove code today.

## Lifecycle First

- Preserve lifecycle action rendering independent of query support.
- Keep Start, Stop, Restart, Kill, Console, Logs, Scheduler, Config, and File actions visible according to permissions and server state, not query availability.
- Do not let `protocol`, `lgsl_query_name`, `gameq_query_name`, or query result variables decide whether lifecycle controls exist.
- Keep single-server controls and multi-server bulk controls covered by tests before removing query detail blocks.

## Agent Status Authority

- Treat agent `server_status` as the online/offline source of truth.
- Keep process/session/game-port evidence as the minimum authoritative status path.
- Query port and RCON port checks may remain optional readiness detail, but public query metadata must not decide online/offline.
- Keep `UNKNOWN`, `STARTING`, `STOPPING`, `ONLINE`, `OFFLINE`, and `UNRESPONSIVE` mapping explicit.

## Query Panel Removal

- Remove/deactivate LGSL/GameQ/player/map/query panels separately from lifecycle controls.
- Remove Dashboard current-player/map query panels only after there is a non-query status replacement or intentional empty state.
- Remove settings for LGSL feed, remote query, query cache life, and query server stop threshold after callers are gone.
- Remove XML creator query protocol prompts after XML import and schema behavior are verified.

## XML Compatibility

- Modify XML validation safely and maintain backward compatibility.
- Existing XML with protocol/query tags must still import.
- New managed-server XML should not require protocol tags.
- Keep `query_port` only if it still serves startup/port-allocation behavior; otherwise replace with a generic optional port concept in a separate design.

## Test Requirements

- Add tests before removing UI branches.
- Test that lifecycle controls render without `protocol`, `lgsl_query_name`, or `gameq_query_name`.
- Test XML configs missing protocol tags.
- Test existing configs with protocol tags.
- Test English language lookup for removed/remaining constants.

## Language Cleanup

- Change English constants first after source references are removed.
- Decide later whether to mass-clean translations.
- Do not remove unrelated strings for RCON, TeamSpeak ServerQuery, billing max players/slots, reseller slots, user management, SourceMod flags, map selection, map uploads, or startup params.

## Do Not Remove

- Do not remove RCON, LCON, Arma BE, or control-protocol shutdown behavior.
- Do not remove TeamSpeak ServerQuery unless a separate product decision says to remove voice-server query features.
- Do not remove billing max players/slots or reseller slot language.
- Do not remove unrelated player-management language.
- Do not remove the Panel `protocol/` folder.

## Commit Strategy

- Keep commits small and scoped.
- Separate Panel, Linux agent, and Windows agent commits.
- Do not combine query UI cleanup with lifecycle-control refactors unless tests prove the separation.
- Do not commit if validation fails.
