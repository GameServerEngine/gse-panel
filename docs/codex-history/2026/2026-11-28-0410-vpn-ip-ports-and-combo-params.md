# Task
CS:Source on game6 did not start, Garry's Mod on game5 was not reachable, the port assignment per agent needed a review, game servers must never run on the VPN address, and map and gamemode should be dropdowns you can type into, learning new values that work.

## User Request
Check the port manager per remote agent (CS:Source does not start because of the port), Garry's Mod issues, maps and gamemodes as dropdowns with presets that you can type into and that learn values that start; make sure game servers are not run on the VPN IP.

## Problem
- CS:Source exited at once: srcds_linux could not load libstdc++.so.6 (32-bit) on game6.
- Both servers were bound to the WireGuard address (10.255.0.130 / 10.255.0.140).
- Port blocks did not hold the Source client port (port - 10).
- Garry's Mod stock maps live in VPK files, so a maps-folder listing never shows them.

## Root Cause
- install_agent_prereqs.sh installs libc6-i386 and lib32gcc-s1 but not lib32stdc++6.
- discover_ips in both agents returned every address including wg0 (and docker bridges on Linux); add_server saved them all; port_allocator_remote_ip_ids took the lowest ip_id, the VPN address.
- port_allocator_offsets_fit was never called and its table game_port_offsets is empty; blocks started at the game port.

## Repositories Inspected
gse-panel, gse-agent-linux, gse-agent-windows.

## Files Changed
Panel: includes/game_ip.php (new), includes/game_param_options.php (new), includes/port_allocator.php, includes/lifecycle_contract_helpers.php, modules/server/add_server.php, modules/server/edit_server.php, modules/gamemanager/server_monitor.php, modules/status_history/poller.php, modules/config_games/server_configs/garrysmod.xml, sql/migrations/0014_game_param_options.sql (new), modules/server/tests/port_allocator_test.php, tests/game_param_options_test.php (new), CHANGELOG.md, docs/TODO.md.

## Architecture Decisions
- One rule (game_ip_allowed): a private address only on an agent with use_nat. Enforced in the allocator, at start (lifecycle_start_payload, used by every start and restart) and on the add/edit server pages.
- Per-IP port allocation is kept on purpose (an existing test covers several public IPs on one host).
- Blocks are widened to the game's near extra ports (within 100) from <reserve_ports>; far ports are a TODO.
- Learned combo values go to the database, not the XML, because the XML files are replaced from git on every deploy.

## Live changes (core database, backups in /home/leroy/panel-db-backups)
home_ip_ports and home_port_blocks for 953 and 954 moved to the public IPs (ip_id 10 and 8) and widened to 27005-27024; VPN IPs 5, 7, 9 removed from remote_server_ips; their status_cache rows dropped; blocks added for 49-52; demo block moved to 27960-27969; block of deleted ip_id 2 released.
game6: lib32stdc++6 and libsdl2-2.0-0:i386 installed.

## Validation Performed
port_allocator_test (23), game_param_options_test (10), game_usage_test (21), agent_inventory_test, status_sync_test pass; php -l on all changed files; garrysmod.xml validated against schema_server_config.xml.
CS:Source started by hand on 75.127.4.194:27015 after the library install and bound the port. Garry's Mod started by hand on 155.94.173.138:27015 with gm_construct and terrortown and loaded TTT.

## Git Commits
See the commit that adds this file.
