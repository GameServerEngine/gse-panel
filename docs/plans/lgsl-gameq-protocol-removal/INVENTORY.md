# Initial Inventory

This inventory was created from read-only searches on 2026-07-16. It is representative, not a final removal checklist. Refresh it before implementation.

## Panel Documentation

- `docs/features/STATUS_SYSTEM.md`: already states LGSL/GameQ query failure is not enough to mark a server offline and points toward agent-owned structured status.
- `docs/features/XML_SYSTEM.md`: documents `gameq`, `lgsl`, `teamspeak3`, query ports, `MAP`, `PLAYERS`, and `QUERY_PORT`.
- `docs/modules/GAMEMANAGER.md`: states Game Monitor should use agent status, not LGSL/GameQ query success alone.
- `docs/modules/lgsl_with_img_mod.md`: legacy LGSL status/image module.
- `docs/deployment/xml-notes.md` and `docs/deployment/admin-guide.md`: describe protocol/query XML tags and query ports.
- `protocol/PROTOCOL_UPGRADE_REVIEW.md`: prior safe-path review of `protocol/lgsl/`, `protocol/GameQ/`, `protocol/gsp_query.php`, and call sites.

## Panel Runtime and UI

- `home.php`: branches on `$server_xml->protocol` for `gameq`, `lgsl`, and `teamspeak3`, chooses query names, and uses LGSL icon fallback `protocol/lgsl/other/icon_unknown.gif`.
- `includes/lib_remote.php`: wraps `remote_server_status()` for agent status and `remote_query()` for LGSL/GameQ metadata calls through agents.
- `modules/gamemanager/home_handling_functions.php`: derives query ports from XML protocol/query tags for agent status expected-port validation; also populates startup `QUERY_PORT`.
- `modules/gamemanager/ref_servermonitor.php`: includes `protocol/GameQ/GameQMonitor.php`, `protocol/lgsl/LGSLMonitor.php`, or `protocol/TeamSpeak3/TS3Monitor.php`, renders map/player/query-derived detail blocks, and contains its own Start/Stop/Restart detail-control path.
- `modules/gamemanager/server_monitor.php`: owner of Game Monitor rows, compact controls, bulk controls, action execution, and status display.
- `modules/gamemanager/start_server.php` and `modules/gamemanager/restart_server.php`: currently use agent status for startup/restart readiness; prior protocol query code is no longer the primary status proof.
- `modules/gamemanager/mini_start.php`: displays max players, map selection, map path/list logic, and startup params. These player/map references are not all query-related.
- `modules/gamemanager/monitor_buttons.php`: gates RCON presets partly on `gameq_query_name == minecraft`; RCON must not be removed as part of LGSL/GameQ cleanup.
- `modules/dashboard/query_ref.php`: includes GameQ/LGSL/TeamSpeak monitors and renders player/map/current-player dashboard data.
- `modules/dsi/dsi_admin.php`, `modules/dsi/dsi_user.php`, `modules/dsi/image.php`: use XML protocol tags and LGSL/GameQ/TeamSpeak query data for dynamic images/status.
- `modules/lgsl_with_img_mod/`: legacy LGSL module, feed, admin, image, list, details, module registration, navigation, and database table creation.
- `modules/settings/settings.php`: saves and renders `remote_query`, `feed_enable`, `feed_url`, `query_cache_life`, and `query_num_servers_stop`.

## Panel Language Strings

Representative English strings:

- `lang/English/global.php`: `LANG_lgsl`, `LANG_lgsl_admin`.
- `lang/English/modules/settings.php`: `LANG_feed_enable`, `LANG_feed_enable_info`, `LANG_feed_url_info`, `LANG_query_cache_life_info`, `LANG_old_dashboard_behavior_info`, `LANG_remote_query`, `LANG_remote_query_info`.
- `lang/English/modules/dashboard.php`: `LANG_current_map`, `LANG_players`, `LANG_server_status`.
- `lang/English/modules/gamemanager.php`: `LANG_max_players`, `LANG_available_maps`, `LANG_map_path`, `LANG_map`, `LANG_players`, `LANG_current_map`, `LANG_no_gsp_lgsl_support`, `LANG_server_status`, `LANG_upload_map_image`, `LANG_no_online_players`.

Do not remove unrelated language for billing/reseller max players, slots, map selection, SourceMod permissions, or startup params.

## XML Schema and Game Definitions

- `modules/config_games/schema_server_config.xml`: `protocol`, `lgsl_query_name`, `gameq_query_name`, and `query_port` are already optional (`minOccurs="0"`).
- Current config count from read-only search:
  - files with `<protocol>`: 4
  - files with `<lgsl_query_name>`: 4
  - files with `<gameq_query_name>`: 37
  - files with `<query_port>`: 68
- Representative LGSL XML: `garrysmod_linux.xml`, `dayz_win64.xml`, `rust_linux64.xml`, `urban_terror_4_linux64.xml`.
- Representative GameQ XML: `minecraft_linux64.xml`, `minecraft_win64.xml`, `feedthebeast.xml`, `spigotmc_linux.xml`, `bf2.xml`, `bfbc2.xml`, `jcmp_linux.xml`, `eco_win64.xml`, `terraria_win64.xml`, `starbound_linux64.xml`.
- Query port tags are also used for startup/runtime port math and must not be removed blindly.

## Protocol Folder

Retain `protocol/`.

Representative contents:

- `protocol/lgsl/`: LGSL query implementation, monitor helper, maps/icons.
- `protocol/GameQ/`: GameQ implementation, monitor helper, protocol classes, player-list helper.
- `protocol/TeamSpeak3/`: TeamSpeak ServerQuery support. This is not LGSL/GameQ removal.
- `protocol/gsp_query.php`: prior normalized query wrapper.
- `protocol/PROTOCOL_UPGRADE_REVIEW.md`: prior protocol migration review.

## Linux Agent

- `gsp_agent.pl`: exposes `server_status` in the XML-RPC dispatcher and returns structured process/session/port readiness fields.
- `gsp_agent.pl`: also exposes `remote_query`, which shells through bundled `php-query/lgsl/lgsl_feed.php` or `php-query/gameq/gameq_feed.php`.
- `php-query/lgsl/` and `php-query/gameq/`: bundled LGSL/GameQ query code for remote query support.
- `docs/PANEL_INTEGRATION.md` and `documentation/agent-guide.md`: document `server_status` as the shared readiness contract.
- `KKrcon/`: contains RCON player helpers; keep unrelated to LGSL/GameQ removal.

## Windows Agent

- `AgentScripts/gse_agent.pl`: exposes `server_status` in the XML-RPC dispatcher and returns structured process/session/port readiness fields.
- `AgentScripts/gse_agent.pl`: also exposes `remote_query`, which shells through bundled `AgentScripts/php-query/lgsl/lgsl_feed.php` or `AgentScripts/php-query/gameq/gameq_feed.php`.
- `AgentScripts/php-query/lgsl/` and `AgentScripts/php-query/gameq/`: bundled LGSL/GameQ query code for remote query support.
- `docs/GSE_WINDOWS_AGENT_PORT_VALIDATION.md`, `docs/PANEL_INTEGRATION.md`, and `AgentScripts/documentation/agent-guide.md`: document `server_status` and expected/listening/missing port behavior.
