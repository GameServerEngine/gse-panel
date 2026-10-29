# Task

## User Request

Create a minimal Testing App XML game/server config for the generic server-port-test utility, with Linux and Windows startup/install behavior, validate XML, commit on branch testing with message `feat: add testing app server config`, and push to `origin/testing`.

## Problem

The Panel did not have a minimal fake server XML config for lifecycle and port-listening validation with the new server-port-test utility.

## Root Cause

No existing active XML under `modules/config_games/server_configs/` represented server-port-test. Existing configs are OS-specific when server executables differ, and the schema has no single XML field for alternate Linux and Windows executables.

## Repositories Inspected

- Panel (`/home/dev/Projects/GSE-Panel`)

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/features/XML_SYSTEM.md`
- `docs/modules/CONFIG_GAMES.md`
- `docs/modules/config_games.md`
- `docs/deployment/xml-notes.md`
- `docs/architecture/REPOSITORY_OVERVIEW.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/plans/global-port-allocation/GAME_PORT_REVIEW_TABLE.md`
- `modules/config_games/schema_server_config.xml`
- `modules/config_games/server_config_parser.php`
- `modules/config_games/tests/validate_server_configs.php`
- `modules/config_games/server_configs/teamspeak3_linux64.xml`
- `modules/config_games/server_configs/murmur.xml`
- `modules/config_games/server_configs/murmur_win.xml`
- `modules/config_games/server_configs/minecraft_linux64.xml`
- `modules/config_games/server_configs/spunkybot_linux64.xml`
- `modules/config_games/server_configs/arma-reforger_linux64.xml`
- `modules/config_games/server_configs/arma-reforger_win64.xml`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/update_actions.php`
- `includes/api_functions.php`
- `includes/functions.php`
- `includes/port_allocator.php`
- `includes/database_mysqli.php`
- `includes/lib_remote.php`

## Files Changed

- `modules/config_games/server_configs/testing_app_linux64.xml`
- `modules/config_games/server_configs/testing_app_win64.xml`
- `docs/codex-history/2026/2026-07-18-0822-testing-app-server-config.md`

## Architecture Decisions

- Added separate Linux and Windows XML files because the current Panel XML convention uses OS-suffixed game keys/files for different executables.
- Kept the display name as `Testing App` in both configs.
- Used a single `server` mod key rendered as `--server` through `%GAME_TYPE%` so the startup command stays minimal.
- Used `pre_install` scripts without `<installer>steamcmd</installer>` so install/update runs only the artifact download script.
- Used placeholder release URL base `https://example.invalid/runlevel/server-port-test/releases/latest/download` and final-looking artifact names until release URLs exist.
- Did not modify allocator code because the XML schema has no per-config default port element and the task requested allocator changes only if required.

## Validation Performed

- Confirmed branch was `testing`.
- Confirmed there were no unrelated staged changes before editing.
- Confirmed the active server XML folder is `modules/config_games/server_configs/`.
- Ran PHP SimpleXML well-formedness check for both new XML files: passed.
- Ran `xmllint --noout modules/config_games/server_configs/testing_app_linux64.xml modules/config_games/server_configs/testing_app_win64.xml`: passed.
- Ran `php modules/config_games/tests/validate_server_configs.php`: passed for all active server config XML files.
- Ran a field-presence and startup-render check for both new XML files: required fields present and rendered commands match the expected server mode/IP/port shape.
- Ran `git diff --check`: passed.

## Git Commits

Repository

Panel

Commit

`feat: add testing app server config`

Hash

Pending.

## Remaining Work

- Replace placeholder release URL base with the final server-port-test release endpoint.
- If Testing App must allocate from 2302 instead of allocator fallback behavior, add supported default-port metadata in a follow-up change.
