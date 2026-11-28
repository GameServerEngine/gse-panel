# Task
Get game queries (hostname, map, players) working on every game that supports them, and show them on the game monitor again.

## User Request
Prefer LGSL / GameQ (or GameDig); the image module should work on games that support it; fix the game monitor so it shows that info again, using the original OGP layout as reference.

## Findings
- 212 of 236 game XMLs had no <protocol>; the first commit of this repository already had only 4. Upstream OGP (OpenGamePanel/OGP-Website, cloned to /tmp/ogp-ref for reference) has a protocol on 230 of 260.
- The monitor layout (server_monitor list loading ref_servermonitor per server, refreshed while shown) is the same as upstream; it showed "Current Map: online" because of the no-protocol fallback.
- GameQ Source rejected the 2020 A2S_INFO challenge reply ('A'); LGSL resent A2S_INFO without the challenge; LGSL had a non-upstream "source" port entry (query = port + 1).
- server_monitor.php wrote the agent status into status_cache, which LGSLMonitor/GameQMonitor read as query results (30 s cache), so pages showed "Server is starting".
- Arma 2 OA servers answer the Steam query on port + 1, not LGSL's GameSpy arma2oa type; dayz.xml said query port +6 while Nasdara answers on +1 (the panel writes that value into serverDZ.cfg at start).

## Files Changed
196 game XMLs (protocol and query name at the top level, after game_key), arma2/arma2oa/dayz_arma2co (lgsl source), dayz.xml (query_port +1), jcmp.xml (justcause2), protocol/GameQ/Protocols/Source.php, protocol/lgsl/lgsl_protocol.php, modules/gamemanager/ref_servermonitor.php, modules/gamemanager/server_monitor.php, tests/game_query_port_test.php, CHANGELOG.md, docs/TODO.md.

## Validation Performed
All 236 XMLs validate against the schema. Server page rendered from the staged tree against the live database for 953 (CS:S), 954 (Gmod), 952 (Urban Terror), 49, 50, 51, 951 (Arma 2 OA) and 52 (DayZ): all show hostname and map; 951 shows its player list (1/20). Tests pass (game_query_port incl. new guards, port_allocator, start_status_answer, game_param_options, game_usage, agent_inventory, status_sync); lifecycle_contract_helpers_test fails as before (logged in TODO).

## Git Commits
See the commit that adds this file.
