# LGSL/GameQ/protocol-removal Plan

## Purpose

Remove Panel dependency on LGSL/GameQ/public game query data for managed game-server status and UI, without removing LGSL/GameQ code in this planning task.

The user intent is:

- LGSL is not worthwhile in the Panel because it lags and is not needed.
- Player info, map info, and public query data are not needed.
- Online/offline should come from agent status/heartbeat, not LGSL/GameQ server query.
- Do not remove the Panel `protocol/` folder.
- Future implementation should remove LGSL checks/options from Panel files and UI.
- Future implementation should remove LGSL/GameQ/query protocol as required XML schema fields.
- XML should not require protocol tags for managed game servers.
- Protocol tags may remain optional/legacy metadata only if compatibility requires it after investigation.
- Preserve lifecycle controls: Start, Stop, Restart, Kill, Console, Logs, Scheduler, Config/File actions.
- Avoid the previous regression where removing monitor/player-query code removed lifecycle buttons.

## Scope

Owner repository: `/home/dev/Projects/GSE-Panel`

Related repositories:

- `/home/dev/Projects/GSE-Agent-Linux`
- `/home/dev/Projects/GSE-Agent-Windows`

This plan is documentation/planning only. It does not remove code, schemas, language strings, settings, modules, query libraries, or XML fields today.

## Non-Negotiable Guardrails

- Lifecycle action rendering must be independent from query support.
- Agent `server_status`/heartbeat semantics must be authoritative for online/offline.
- Removing query panels must not remove Start, Stop, Restart, Kill, Console, Logs, Scheduler, config, or file-manager access.
- Do not remove `protocol/` from the Panel.
- Do not confuse RCON, LCON, Arma BE, TeamSpeak ServerQuery, billing max players/slots, reseller slots, or player-management language with LGSL/GameQ public query removal.
- Existing XML game definitions must continue importing whether protocol tags are present or absent.

## Phases

### 1. Read-only full inventory

Inventory Panel, Linux agent, and Windows agent references to LGSL, GameQ, query protocol, XML protocol tags, player/map/query-derived stats, heartbeat/status, and lifecycle controls.

Required outputs:

- Update `INVENTORY.md` with exact files and categories.
- Confirm no secrets were read or printed.
- Confirm `protocol/` is retained as a folder until specific files are proven obsolete in a later implementation phase.

### 2. Dependency classification

Classify each dependency as:

- `REMOVE`: LGSL/GameQ UI, feed settings, query-player/map/status display, or code that exists only for public query output.
- `REPLACE_WITH_AGENT_STATUS`: online/offline or startup readiness paths that currently inspect protocol/query data.
- `KEEP_AS_LEGACY_OPTIONAL`: XML metadata or compatibility call paths that must remain readable for old configs during migration.
- `KEEP_UNRELATED`: RCON, TeamSpeak ServerQuery, billing slots/max players, map selection for startup params, file/log/config actions, scheduler actions, or other non-LGSL/GameQ uses.
- `DEFER`: vendored protocol libraries or database tables whose removal needs a separate migration decision.

### 3. XML/schema plan

Make protocol/query tags optional or remove required validation while preserving import compatibility.

Current schema already has `protocol`, `lgsl_query_name`, `gameq_query_name`, and `query_port` as `minOccurs="0"` in `modules/config_games/schema_server_config.xml`. Future implementation must verify code assumptions, creator UI, XML import, and existing configs before removing fields from generated or displayed managed-server definitions.

### 4. UI/settings cleanup plan

Remove or hide LGSL/GameQ/player/map/query UI separately from lifecycle controls:

- Settings: remote query, LGSL feed, query cache life, query server stop threshold, and related help text.
- Dashboard: query-ref player/map/current-player statistics panels.
- Game Monitor: LGSL/GameQ/TeamSpeak live detail panels only if they are query-derived and separate from lifecycle action controls.
- DSI/LGSL image modules: evaluate as legacy/deferred modules, not as required managed-server UI.
- XML creator/config pages: stop presenting query protocol as a required or recommended managed-server setup step.

### 5. Runtime cleanup plan

Replace query-based online/offline with agent status/heartbeat where not already done.

Use:

- `includes/lib_remote.php::remote_server_status()`
- `modules/gamemanager/home_handling_functions.php::get_agent_server_status()`
- agent `server_status` fields such as `status`, `StatusState`, `process_running`, `session_running`, `game_port_listening`, and `last_error`

Do not use LGSL/GameQ success or failure to decide whether Start/Stop/Restart controls render.

### 6. Agent verification plan

Confirm Linux and Windows agents provide the authoritative lifecycle/status signal:

- Linux `gsp_agent.pl` exposes `server_status` and returns process/session/port fields.
- Windows `AgentScripts/gse_agent.pl` exposes `server_status` and returns equivalent status fields.
- Both agents currently also expose `remote_query`; future removal/deactivation must not break Panel lifecycle status.
- Document gaps before any agent-side code removal.

### 7. Language/documentation cleanup plan

After runtime behavior is safe, clean English language constants first, then decide whether to mass-clean translations.

Do not remove unrelated player, map, slot, billing, reseller, RCON, TeamSpeak, or startup-parameter language.

### 8. Validation and regression plan

Use `VALIDATION.md` before and after each implementation commit. Regression coverage must explicitly prove lifecycle controls remain visible and functional without query support.

### 9. Implementation sequencing and commit strategy

Use focused commits:

1. Inventory and tests for lifecycle control separation.
2. XML/schema compatibility changes.
3. Panel UI/settings query cleanup.
4. Runtime status replacement and query deactivation.
5. Agent verification or agent-side deactivation, only if required.
6. Language/docs cleanup.

Commit Panel, Linux agent, and Windows agent separately when changes are needed in each repository.

## Current Status

Planning documents exist. No runtime behavior changed. The next implementation task must start with Phase 1 inventory refresh and tests.
