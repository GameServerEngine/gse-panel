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

## Follow-up (2026-11-28 10:02-10:15 CST): map picture and TeamSpeak 3
- Map picture: get_map_path fell back to the query protocol's logo (Source logo for an Arma 2 OA server using the Steam query); it now uses only pictures of the map, else plain black (images/map_none.png), and none when the game reports no map ("-").
- TeamSpeak 3: test server 955 "TeamSpeak 3 Test" on game6 (75.127.4.194:2100, block 2100-2199, ServerQuery 2124), installed from the official 3.13.7 Linux archive; its serveradmin password is the server's control password in the panel. Fixed: teamspeak3.xml Linux exe_location; TS3Monitor, protocol/TeamSpeak3/functions.php and get_agent_server_status read the XML query port; no map line for TS3. Verified: the server page shows the hostname and channel tree, the game monitor shows the Full Teamspeak3 Management button with query 2124, and the teamspeak3 module library logs in as serveradmin and reads the server.

## Follow-up (2026-11-28 10:17-10:25 CST): PHP warnings on the TS3 page
- Reported: the game monitor printed a block of "Deprecated: Return type of TeamSpeak3_..." notices.
- Found by loading every TS3 library class with E_ALL (27 interface methods, 2 optional-before-required parameters) and by rendering the game monitor list and the server 955 page in a staged copy with an error handler (utf8_encode, $ts3opt, $trclass).
- Fixed in the original code (attribute, parameter order, mb_convert_encoding, variables initialised instead of unset). Both pages then rendered with zero notices.
- Cause of them being visible: display_errors = On in core's Apache php.ini (logged in TODO; not changed).

## Follow-up (2026-11-28 10:22-10:26 CST): game picture instead of black
- Requested: default to the game's ordering-page picture instead of black; map uploads should be possible.
- New game_picture_path($server_home): catalog_game.img_url, else catalog_guess_image() on the XML file name, else images/map_none.png. get_map_path() takes $server_home as a 4th argument; LGSLMonitor, GameQMonitor, ref_servermonitor, mini_start and dsi/image pass it.
- Tested on the live database: all 9 servers get their ordering-page picture (Arma 2 OA, DayZ, Urban Terror, CS:S, Garry's Mod, TeamSpeak 3); a game with no catalog row gets its matching images/games picture; an unknown game gets black.
- Found while testing: gametracker answers HTTP/2, which get_first_existing_file() never accepted, so no online map picture had loaded. Fixed; CS:S de_dust2 now returns the gametracker picture.
- Upload (upload_map_image.php, admin only) already existed; its path came from unchecked form fields. Hardened.

## Git Commits
See the commit that adds this file.
