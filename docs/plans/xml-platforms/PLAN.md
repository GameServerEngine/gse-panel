# Game XML: one definition for Windows and Linux (plan, 2026-10-22)

## Problem
Each game has a separate XML per platform and bitness (`_win32`, `_win64`, `_linux`, `_linux64`).
232 files exist: 111 Windows, 82 Linux, the rest 32-bit. The catalog therefore lists the same game
more than once, and shared settings are copied and can drift apart.

## Findings (7 Days to Die, Windows vs Linux)
- Shared: installer (steamcmd), game name, most setup.
- Different: server executable, startup arguments, log file path, post-install step.
- Windows-only blocks: UI options (`custom_fields`, `field`, `option`, `default_value`, `access`, `desc`).
- Linux-only blocks: `environment_variables`, `lock_files`, `post_install`.

## Proposed schema
- One file per game, named by game key (no platform suffix).
- Shared settings stay at the top level (game_key, game_name, installer, mods, custom_fields, ports).
- A `<platforms>` block holds one `<platform id="...">` per supported platform (linux64, win64, win32).
  Each platform block holds only what differs: server_exec_name, cli_template, exe_location,
  pre_install, post_install, environment_variables, lock_files.
- Platform choice comes from the remote server's OS (the `server_os` column). A game with one platform
  keeps a single platform block.

## Migration
1. Parser reads both the old single-platform files and the new format. No data changes yet.
2. Migration tool groups files by base name (dropping the platform suffix), checks that shared fields
   match, and writes one merged file per game. Differences go into platform blocks. A diff report is
   written for each game for review.
3. Homes that reference old filenames are mapped to the merged file through a lookup table, so existing
   servers keep working.
4. The catalog lists one entry per game. The platform is taken from the server the game is installed on.
5. Old per-platform files are removed after the merged files are verified.

## Test plan
- For each merged game, the start command built for each platform equals the command from the old file.
- Catalog shows one entry per game; no duplicate names.
- Existing homes still start with the same command line.
