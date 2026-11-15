# LinuxGSM game XML tools

LinuxGSM (https://linuxgsm.com, repo `GameServerManagers/LinuxGSM`) is the source of truth
for how Linux game servers are installed and started. These tools turn its game configs
into GSE game XML files and check the result the way the panel reads it.

| File | What it does |
| --- | --- |
| `game_list.py` | Which LinuxGSM games we already have (`EXISTING`) and which are new (`NEW`, with our game_key). |
| `install_steps.py` | Install work LinuxGSM does in its modules: non-Steam downloads, fixes, environment lines, start-line additions. |
| `extra_games.py` | Games other hosts sell that LinuxGSM does not cover (hand-written, Windows). |
| `generate.py` | Writes an XML for every game in `NEW` and `extra_games.py`. Never overwrites a file. |
| `steam_info.py` | Looks up Steam app info (name, OS, launch executable) for the Windows blocks. |
| `compare.py` | Prints how our existing XMLs differ from LinuxGSM (app id, executable, start options). |
| `check_xml.php` | Loads every XML with the panel's `read_server_config()` and builds the start line; fails on leftover placeholders. |
| `check_merged.php` | Proves an edit did not change what one OS sees after the panel's OS merge. |
| `update_existing.py`, `add_linux_blocks.py` | One-time fixes made on 2026-11-14 (see CHANGELOG 2.9.2). Kept as a record. |

## Add or refresh games

```bash
git clone --depth 1 https://github.com/GameServerManagers/LinuxGSM.git /tmp/LinuxGSM
# Steam ids used by the new games, one per line, then:
python3 tools/lgsm_xml/steam_info.py ids.txt /tmp/steaminfo.json
python3 tools/lgsm_xml/generate.py /tmp/LinuxGSM /tmp/steaminfo.json modules/config_games/server_configs
php tools/lgsm_xml/check_xml.php
python3 tools/lgsm_xml/compare.py /tmp/LinuxGSM modules/config_games/server_configs
```

## How a LinuxGSM start line is converted

- `${port}`, `${ip}`, `${maxplayers}`, `${servername}` become the panel values `%PORT%`, `%IP%`, `%PLAYERS%`, `%HOSTNAME%`.
- Other `${...port}` values become reserved ports, offset from the game port.
- Passwords other than the join password become `%CONTROL_PASSWORD%`.
- Folders become `%HOME_PATH%` (the game folder).
- `-flag ${setting}` and `-flag=${setting}` become settings the customer can change, with LinuxGSM's default.
- Everything else is written in with LinuxGSM's default value.

Generated files say where they came from and list anything left to do in comments at the top.
