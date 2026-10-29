# Installers

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

Installer behavior is split across game XML, gamemanager startup, addonsmanager, SteamCMD helpers, and agent scripts.

Important references:

- `modules/config_games/schema_server_config.xml`
- `modules/gamemanager/home_handling_functions.php`
- `modules/addonsmanager/module.php`
- `modules/steam_workshop/module.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

## Installer Types Seen In The Codebase

- SteamCMD-based installs
- download/extract installs
- script-driven installs
- Workshop/content installs
- profile/content copy workflows

## Recommended Model

Installer strategy should come from game capability metadata. The agent should execute trusted strategies, not arbitrary customer commands.

## Windows Agent Install Notes

The Windows agent is Cygwin-based under `Agent-Windows`.

Common installed layout:

- Cygwin root: `C:\GSP\Agent`
- Bash: `C:\GSP\Agent\bin\bash.exe`
- Agent root: `C:\GSP\Agent\GSP`
- Command installer: `C:\GSP\Agent\Installer-Agent-Windows.cmd`
- Service installer: `C:\GSP\Agent\Installer-Agent-Windows.ps1`
- Foreground launcher: `C:\GSP\Agent\agent_start.bat`
- Foreground stop helper: `C:\GSP\Agent\agent_stop.bat`

Service mode is the default production mode and is registered through bundled `cygrunsrv.exe`. Windows Services controls production start, stop, and restart. The foreground launcher checks `%~dp0bin\bash.exe` and runs only in the current desktop session for troubleshooting.

Generated config files under `/GSP/Cfg` are production state. `GSP/agent_launch.sh` may create them from `.default` templates if missing, but install and repair logic must preserve them. Restart-time auto-update may replace only `/GSP/gsp_agent.pl`; it must not overwrite `Cfg/Config.pm`, `Cfg/Preferences.pm`, `Cfg/bash_prefs.cfg`, service settings, passwords, server data, or logs.

Both service and foreground startup run `GSP/agent_launch.sh`. The guard normalizes text files, validates `GSP\gsp_agent.pl`, rejects placeholder config, refuses to launch when the configured agent port is already listening, and refuses valid PID files from another agent instance. `agent_stop.bat` stops only the foreground PID; service stop remains a Windows Services action.
