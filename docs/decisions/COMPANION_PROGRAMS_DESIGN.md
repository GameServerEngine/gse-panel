# Companion Programs Design Investigation

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

This is an investigation-only design report for adding first-class companion/sidecar application support to GSP game servers. No implementation is included here.

Repository layout reviewed:

- `/Agent-Windows`
- `/Agent_Linux`
- `/Panel`
- `/Website`

Note: the repository currently uses `Agent_Linux` on disk, not `Agent-Linux`.

## 1. Current Flow Found

### Main Agent Files

Relevant files:

- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/gsp_agent.pl`
- `includes/lib_remote.php`
- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/config_games/schema_server_config.xml`
- `modules/config_games/server_config_parser.php`
- `modules/config_games/config_servers.php`
- `modules/config_games/cli-params.php`

### Agent Startup Directory And Autostart

Both agents define an agent run directory and a startup flag directory:

- Linux: `AGENT_RUN_DIR`, `GAME_STARTUP_DIR`, `SCREEN_LOGS_DIR`, `SCREENRC_FILE`
- Windows/Cygwin: same general constants, with Windows-specific SteamCMD executable paths

Both agents create/read the `startups` directory on agent startup. If startup files exist and `--no-startups` is not set, the agent reads each startup file and calls `universal_start_without_decrypt(...)` with the saved startup arguments.

Startup files are CSV-like records containing:

```text
home_id, home_path, server_exe, run_dir, startup_cmd, server_port, server_ip, cpu, nice, preStart, envVars, game_key, console_log
```

These files are useful for agent autostart but should not be treated as runtime status truth.

### Screen Usage

Both agents use `screen` as the shared runtime backend.

Linux:

- `create_screen_id(SCREEN_TYPE_HOME, $home_id)` creates names like `GSP_HOME_000000123`.
- `create_screen_cmd(...)` returns a `screen -d -m -t ... -S ...` command.
- `create_screen_cmd_loop(...)` creates a generated shell script named like `GSP_HOME_000000123_startup_scr.sh`, then runs that script inside `screen`.
- The generated shell script loops/restarts the server when autorestart is enabled.
- The script checks for `SERVER_STOPPED` before respawning.

Windows/Cygwin:

- Uses the same `create_screen_id(...)` naming pattern.
- `create_screen_cmd(...)` launches a command in `screen`.
- `create_screen_cmd_loop(...)` writes `_serverStart.bat`, then runs it through `cmd /Q /C` inside `screen`.
- The generated batch file loops/restarts the server when autorestart is enabled.
- The batch file checks `SERVER_STOPPED` before respawning.

### Start Flow

Panel start path:

- `modules/gamemanager/mini_start.php` builds `$start_cmd`.
- It collects XML `pre_start`, `environment_variables`, `lock_files`, executable name, executable location, port, IP, CPU affinity, nice value, game key, and console log.
- It calls `$remote->universal_start(...)`.

Linux agent start:

- `universal_start_without_decrypt(...)` validates the home path and executable.
- It may create a per-game Linux user when that preference is enabled.
- It writes/updates the startup flag file in `GAME_STARTUP_DIR`.
- It converts `pre_start` and environment variables between multiline and startup-safe formats.
- It builds the command according to executable type:
  - `.exe` / `.bat`: Wine command
  - `.jar`: startup command
  - other: `./server_exe startup_cmd`
- It creates a generated shell startup script through `create_screen_cmd_loop(...)`.
- It backs up `screenlogs/screenlog.<screen_id>`.
- It runs pre-start commands through `run_before_start_commands(...)`.
- It launches the generated command through `sudo_exec_without_decrypt($cli_bin, $owner)`.
- It renices server processes after launch.

Windows/Cygwin agent start:

- `universal_start_without_decrypt(...)` validates the home path and executable.
- It converts paths with `cygpath -wa`.
- It converts `pre_start` and environment variables.
- It builds Windows commands using `cmd /Q /C start ... /WAIT`.
- With autorestart enabled, it uses `create_screen_cmd_loop(...)` and `_serverStart.bat`.
- Without autorestart, it uses `create_screen_cmd(...)`.
- It backs up `screenlogs/screenlog.<screen_id>`.
- It runs pre-start commands through `run_before_start_commands(...)`.
- It launches the screen command with `system($cli_bin)`.
- It writes a startup file named `_serverStart.bat` under `GAME_STARTUP_DIR`, though the name is generic rather than per IP/port in the current Windows path.

### Stop Flow

Panel stop path:

- `modules/gamemanager/stop_server.php` calls `$remote->remote_stop_server(...)`.
- Stop parameters include home ID, IP, port, control protocol, control password, control type, and home path.

Linux agent stop:

- `stop_server_without_decrypt(...)` removes the startup flag for `$server_ip-$server_port`.
- If autorestart is enabled, it creates `SERVER_STOPPED` in the game home.
- It attempts a graceful stop when configured:
  - `rcon`
  - `rcon2`
  - `armabe`
  - `minecraft`
- If graceful stop does not complete, it collects PIDs from the screen session using `get_home_pids(...)`.
- It sends `kill 15`, then `kill 9` if needed.
- It runs `screen -wipe`.

Windows/Cygwin agent stop:

- `stop_server_without_decrypt(...)` removes the startup flag.
- If autorestart is enabled, it creates `SERVER_STOPPED` in the game home.
- It finds the screen PID from `screen -list`.
- It maps that to a Windows PID using `ps -W`.
- It calls `cmd /C taskkill /f /fi 'PID eq ...' /T`.
- It runs `screen -wipe`.

### Restart Flow

Both agents expose `restart_server` and `restart_server_without_decrypt(...)`.

The current intended restart flow is:

1. Stop server.
2. Wait 60 seconds.
3. Start server.

That flow is now present in the current agent files. Any companion system should hook into this sequence explicitly:

1. Stop companions.
2. Stop game server.
3. Wait 60 seconds.
4. Start game server.
5. Start companions after their configured delays.

### PID Tracking

Current PID tracking exists but is inconsistent:

- The agents write their own `gsp_agent.pid`.
- FastDownload has `fd.pid`.
- Scheduler has `scheduler.pid`.
- Linux game server child PIDs are discovered from the screen PID with `pgrep -P`.
- Windows/Cygwin maps a screen PID to a Windows PID through `ps -W`.
- Some game XML uses the `PID_FILE` CLI param, for example Source-engine servers.
- Windows `_alsoRun.bat` behavior expects `_alsoRun.pid` to contain helper PIDs.

There is no general companion PID registry today.

### Marker Files

Relevant marker files:

- `GAME_STARTUP_DIR/<ip>-<port>` startup flag on Linux.
- Windows writes a startup file under `GAME_STARTUP_DIR`, currently `_serverStart.bat`.
- `SERVER_STOPPED` in the game home when autorestart is enabled.
- `_alsoRun.pid` for the current Windows helper behavior.

`SERVER_STOPPED` is used by generated autorestart scripts to decide whether to respawn. It should not be used as the source of truth for current runtime status.

### Logs

Game screen logs are written under:

```text
screenlogs/screenlog.<screen_id>
```

The agents call `backup_home_log(...)` before starting a server. If XML defines `console_log`, Panel/agent log functions may read the game-specific console log instead.

There is no dedicated companion stdout/stderr log path today.

## 2. Current Helper Script Behavior

### `_alsoRun.bat`

The clearest current companion behavior is Windows-only.

In `Agent-Windows/gsp_agent.pl`, `create_screen_cmd_loop(...)` generates `_serverStart.bat`. That generated batch file contains:

```bat
if exist "_alsoRun.bat" call "_alsoRun.bat"
start ... /wait <game command>
for /f %%p in (_alsoRun.pid) do taskkill /PID %%p /F
```

This means:

- `_alsoRun.bat` is executed before the game server process.
- `_alsoRun.pid` is read after the game server exits.
- Each PID listed in `_alsoRun.pid` is force-killed.
- This only exists in the Windows/Cygwin agent path.
- It only works when the generated `_serverStart.bat` loop path is used.
- It relies on files in or near the game server working directory.
- It depends on the helper script correctly writing `_alsoRun.pid`.

Some DayZ/Arma XML files generate `_alsoRun.bat` in `pre_start`. Examples found include:

- `modules/config_games/server_configs/dayz_arma2co_win32.xml`
- `modules/config_games/server_configs/dayz_epoch_mod_win32.xml`

Those XML files build `_alsoRun.bat` to start BEC and use WMIC to find/write the BEC PID into `_alsoRun.pid`.

Problems:

- The helper file lives in a customer-accessible game area.
- The customer may be able to edit/delete `_alsoRun.bat` or `_alsoRun.pid`.
- It is Windows-specific.
- PID capture depends on executable path matching and WMIC.
- Cleanup happens after the game process exits, not as a first-class stop action.
- It is not centrally visible to the Panel as companion state.

### `pre_start`

The XML schema supports `pre_start`.

Panel reads `pre_start` in:

- `modules/gamemanager/mini_start.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/home_handling_functions.php`

The value is passed to the agent as `$preStart`.

Linux behavior:

- `run_before_start_commands(...)` writes `${home_path}/prestart_ogp.sh`.
- It writes each XML line into the shell file.
- It runs `bash prestart_ogp.sh` as the game owner.
- The script removes itself at the end.

Windows/Cygwin behavior:

- `run_before_start_commands(...)` writes `_prestart.bat` in the Windows-converted home path.
- It writes each XML line into the batch file.
- It runs `cmd /Q /C start /wait "<_prestart.bat>"`.
- It deletes `_prestart.bat`.

Concerns:

- `pre_start` is trusted XML/admin-defined script content, but it executes in/against the customer game home.
- It is not structured.
- It is not tracked.
- It is only "before start"; it is not a companion lifecycle model.
- It cannot reliably stop helper processes unless custom script authors implement that themselves.

### `post_start`

The schema and config editor order include `post_start`.

Found references:

- `modules/config_games/schema_server_config.xml`
- `modules/config_games/config_servers.php`
- `modules/config_games/xml_tag_descriptions.php`
- One example XML: `modules/config_games/server_configs/nexuiz_win.xml`

I did not find a start path that reads `server_xml->post_start` or sends it to the agents. As currently written, `post_start` appears schema-visible but not operational.

### `post_install`

Both agents include workshop/mod post-install script generation logic. This is install/update-related, not game-server runtime lifecycle management.

`post_install` should not be used for companion processes because it is not tied to server start/stop/restart.

### Custom Fields And Custom Scripts

Custom fields are supported in XML and stored per server. They are used by config replacement logic:

- `modules/gamemanager/cfg_text_replace.php`
- `modules/config_games/schema_server_config.xml`

Custom fields can influence config file content. They are not currently a safe or structured way to define executable companion commands.

Customers can also use extra startup parameters where permissions allow. That should not become the companion command source because it would permit arbitrary command injection into managed helper launches.

## 3. Recommended Method 1

### First-Class Companion Programs System

The best design is a first-class, structured companion program system.

Game XML should define the allowed companion programs for that game. The Panel should store per-server choices such as enabled/disabled and optional safe settings. The agent should own runtime launch, PID tracking, log paths, stop, and restart cleanup.

Example XML shape:

```xml
<companion_programs>
  <program key="bec" name="BattlEye Extended Controls" os="windows">
    <enabled_default>0</enabled_default>
    <delay_seconds_default>30</delay_seconds_default>
    <working_dir>{GAME_PATH}\BEC</working_dir>
    <command>Bec.exe</command>
    <args>-f Config.cfg</args>
    <process_name>Bec.exe</process_name>
    <stop_on_server_stop>1</stop_on_server_stop>
    <restart_on_server_restart>1</restart_on_server_restart>
    <stdout_log>companions/bec.out.log</stdout_log>
    <stderr_log>companions/bec.err.log</stderr_log>
  </program>
</companion_programs>
```

Recommended XML fields:

- `key`: stable internal ID.
- `name`: display name.
- `os`: `linux`, `windows`, or `any`.
- `enabled_default`: default panel setting.
- `delay_seconds_default`: default delay after game start.
- `working_dir`: trusted template path.
- `command`: trusted executable/script name or path template.
- `args`: trusted default arguments.
- `process_name`: optional verification aid only, not the kill target by itself.
- `stop_command`: optional graceful stop command.
- `stop_timeout_seconds`: default graceful wait.
- `kill_after_timeout`: boolean.
- `stop_on_server_stop`: boolean.
- `restart_on_server_restart`: boolean.
- `stdout_log` / `stderr_log`: relative managed log paths.
- `required_files`: optional list for Panel validation.
- `requires_game_online`: whether to delay until the game port is online.

### Where XML Defines Allowed Companion Programs

Add `companion_programs` to `modules/config_games/schema_server_config.xml` as an optional top-level element.

Only admins/editors of game XML should be able to define:

- executable path
- arguments
- working directory
- stop command
- process name
- default delay
- log paths

Customers should not be able to enter arbitrary commands.

### Where Panel Stores Per-Server Settings

Panel should store per-home companion settings in the database, not in customer-editable files.

Possible table:

```sql
server_companion_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  home_id INT NOT NULL,
  companion_key VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  delay_seconds INT NULL,
  settings_json LONGTEXT NULL,
  UNIQUE KEY uniq_home_companion (home_id, companion_key)
)
```

`settings_json` should only contain safe, schema-defined options. Do not allow free-form command or shell text. For example:

- config profile selection
- config file name from an allowed list
- delay override within min/max bounds
- environment preset key

### How The Agent Receives Settings

Extend the encrypted XML-RPC start/restart call to include a companion payload, or add a dedicated agent RPC after start:

Recommended:

- `universal_start(..., companion_payload)` for direct lifecycle coupling.
- `server_companion_start(home_id, payload)` for explicit start action.
- `server_companion_stop(home_id)` for explicit stop action.
- `server_companion_status(home_id)` for status display.

The payload should be generated by the Panel from trusted XML plus stored per-server enabled settings.

Payload should contain fully resolved, validated data:

```json
{
  "home_id": 123,
  "server_ip": "1.2.3.4",
  "server_port": 2302,
  "companions": [
    {
      "key": "bec",
      "enabled": true,
      "delay_seconds": 30,
      "working_dir": "/home/gsp_agent/GSP_User_Files/123/BEC",
      "command": "Bec.exe",
      "args": ["-f", "Config.cfg"],
      "stdout_log": "bec.out.log",
      "stderr_log": "bec.err.log"
    }
  ]
}
```

For XML-RPC compatibility, this may be passed as JSON text.

### How The Agent Starts Companions

The agent should start companions after the game screen/session has launched.

Recommended approach:

- Use separate `screen` sessions per companion.
- Session naming:
  - Game: `GSP_HOME_000000123`
  - Companion: `GSP_COMPANION_000000123_bec`
- Write companion runtime state under an agent-controlled path outside the game FTP/file-manager root.
- Start delayed companions through a small agent-owned delay wrapper:
  - Linux: `screen -d -m -S GSP_COMPANION_... bash -lc 'sleep 30; cd ...; exec ...'`
  - Windows/Cygwin: `screen -d -m -S GSP_COMPANION_... cmd /Q /C "timeout /t 30 ... && cd /d ... && ..."`

This avoids blocking game startup. The Panel can show the game as `STARTING`/`ONLINE` independently while companion statuses move through `PENDING`, `STARTING`, `RUNNING`, or `FAILED`.

### How The Agent Records PIDs

Create an agent-owned runtime directory, for example:

```text
<AGENT_RUN_DIR>/companions/<home_id>/
```

Files:

```text
companions/<home_id>/state.json
companions/<home_id>/pids/<companion_key>.pid
companions/<home_id>/logs/<companion_key>.out.log
companions/<home_id>/logs/<companion_key>.err.log
```

State should include:

- home ID
- companion key
- screen session name
- agent PID/screen PID
- child PID if known
- command hash or executable path
- start time
- last status
- last error

For Linux:

- Prefer launching through `screen`.
- Use the screen PID plus child process lookup.
- If possible, make the wrapper write `echo $$` and the exec child PID to a PID file.
- Use process group/session cleanup where possible.

For Windows/Cygwin:

- Keep `screen` for parity.
- Prefer a wrapper that starts the companion and writes the Windows PID to a PID file.
- Use Cygwin `ps -W` or PowerShell/WMIC alternatives where available.
- Avoid killing by executable name alone.

### How The Agent Stops Companions

Stop by the recorded handle, not by executable name.

Recommended stop order:

1. Load `companions/<home_id>/state.json`.
2. For each running companion:
   - If `stop_command` exists, run it in the configured working directory.
   - Wait up to `stop_timeout_seconds`.
   - If still alive and `kill_after_timeout=1`, kill only recorded PID/process tree/session.
   - Close the companion screen session.
   - Update state.
3. Clean stale PID files only after verifying the process is gone.

Avoid:

- `pkill Bec.exe`
- `taskkill /IM Bec.exe`
- broad executable-name matching

Those can kill unrelated customer processes.

### Restart Behavior

Recommended restart sequence:

1. Stop companions for the home.
2. Stop game server.
3. Wait 60 seconds.
4. Start game server.
5. Start enabled companions according to delay settings.

Do not let companions survive a restart unless explicitly marked as persistent and safe.

### Companion Logging

Companion stdout/stderr should be logged separately from game screen logs.

Recommended default:

```text
<AGENT_RUN_DIR>/companions/<home_id>/logs/<key>.out.log
<AGENT_RUN_DIR>/companions/<home_id>/logs/<key>.err.log
```

Panel can add a companion log viewer later, separate from the game server log viewer.

For customer visibility, expose logs through the agent RPC after permission checks rather than placing them inside the customer FTP root by default.

### Security

This method is secure because:

- Commands come from trusted game XML/admin configuration.
- Customer UI stores only enable/disable and bounded safe settings.
- Runtime files live outside the customer file manager/FTP root.
- The agent launches only known companion keys for the current game config.
- Stop/kill uses recorded PIDs/screen sessions, not executable names.
- Logs are controlled by the agent.

### Portability

This method is portable because:

- XML can define Linux and Windows variants with the same logical companion key.
- Agent receives normalized companion payload.
- Both agents use screen sessions.
- PID capture is platform-specific behind the same agent RPC model.

## 4. Recommended Method 2

### Agent-Managed Generated Startup/Cleanup Scripts

Alternate design: keep using scripts, but generate and store them in an agent-controlled directory outside customer-editable game files.

Example layout:

```text
<AGENT_RUN_DIR>/companions/<home_id>/
  companions.json
  start_companions.sh
  stop_companions.sh
  start_companions.bat
  stop_companions.bat
  pids/
  logs/
```

The Panel still defines companions from trusted XML and stores per-server enabled settings. The agent generates scripts from those trusted definitions.

Start flow:

1. Game server launches.
2. Agent starts an agent-owned companion startup script in its own screen session.
3. Script handles delays and PID capture.

Stop flow:

1. Agent runs an agent-owned cleanup script.
2. Script kills only PIDs listed in the agent-owned `pids` directory.
3. Agent verifies cleanup.

### Pros

- Closer to the existing `_serverStart.bat` and generated shell script model.
- Easier to debug for admins because generated scripts are readable.
- Can work with the existing screen backend.
- Avoids customer-editable `_alsoRun.bat`.
- Can be implemented incrementally.

### Cons

- Still script-heavy.
- Quoting rules remain difficult across Linux, Cygwin, and Windows.
- Risk of script injection if generation is not strict.
- PID capture still needs platform-specific care.
- State management can drift if scripts are edited manually by admins.
- Less clean than direct agent-managed process APIs.

### Comparison To Method 1

Method 1 is better long term because the agent owns lifecycle state directly. Method 2 is a reasonable migration bridge if implementation speed matters, but it should still use trusted XML/admin definitions and agent-owned control paths.

## 5. Security Considerations

### Do Not Trust Customer-Editable Startup Files

Avoid using customer-editable files such as:

- `_alsoRun.bat`
- `_alsoRun.pid`
- arbitrary `.bat` or `.sh` helper files in the game home

Those files can be edited, deleted, replaced, or used to run unintended commands.

### Managed Files Should Live Outside The FTP/File Manager Root

Recommended location:

```text
<AGENT_RUN_DIR>/companions/<home_id>/
```

This directory should be owned by the agent or game server runner user and should not be directly writable by customers.

### Commands Must Come From Trusted Configuration

Companion commands should come from:

- shipped game XML
- admin-managed XML
- a future admin-only companion catalog

They should not come from:

- customer text fields
- startup extra parameters
- uploaded files
- customer-editable config files

### Validate Commands

Validation should include:

- companion key exists in the current game XML
- OS matches the agent OS
- command path resolves under an allowed directory unless explicitly admin-approved
- working directory resolves under game home or an admin-approved path
- no unexpanded `{...}` tokens remain
- no shell metacharacters in fields that are supposed to be argv tokens
- delay is numeric and capped
- log paths are relative and cannot escape managed log directories

### Avoid Arbitrary Command Execution

Use argv-style execution where possible.

When shell/batch is unavoidable:

- generate commands from trusted fields only
- quote every path/argument consistently
- avoid concatenating customer-provided strings into shell code
- keep generated files outside customer write paths

### Avoid Killing Unrelated Processes

Do not kill by executable name alone.

Preferred kill targets:

- recorded companion PID
- recorded process group
- recorded screen session
- recorded Windows process tree for that PID

Process name should be used only as a verification hint, not as the primary kill selector.

## 6. Cross-platform Considerations

### Linux Agent

Linux can use:

- `screen`
- `bash`
- PID files
- `/proc`
- `pgrep -P`
- `kill 15`, then `kill 9`

Recommended Linux companion launch:

```text
screen -d -m -S GSP_COMPANION_<home_id>_<key> bash -lc '<agent-owned wrapper>'
```

Use an agent-owned wrapper or direct fork/exec to record PID and redirect logs.

### Windows/Cygwin Agent

Windows/Cygwin can use:

- `screen`
- `cmd /Q /C`
- `start`
- `taskkill /PID ... /T`
- `ps -W`
- possibly WMIC or PowerShell depending on environment

Current `_alsoRun.bat` depends on WMIC in some XML. WMIC is not reliable on all modern Windows installations, so future code should not require it.

Recommended Windows companion launch:

```text
screen -d -m -S GSP_COMPANION_<home_id>_<key> cmd /Q /C "<agent-owned wrapper.bat>"
```

The wrapper should record the Windows PID or enough process/session information for cleanup.

### Batch vs Shell Differences

Risks:

- quoting spaces in paths
- escaping backslashes
- environment variable syntax differences
- `start` window title behavior on Windows
- delayed expansion in batch files
- Cygwin path vs Windows path conversion
- signal semantics: Linux signals vs Windows taskkill

The Panel should not build platform command strings. It should send trusted structured definitions to the agent and let the agent perform OS-specific quoting/execution.

### Screen Behavior

Screen remains the default shared backend for now.

Use separate screen sessions for companions rather than attaching them to the game server screen. This gives:

- independent status
- independent logs
- safer cleanup
- clearer restart behavior

### Process Cleanup Risks

Companion processes may:

- fork children
- daemonize
- spawn background processes
- exit while leaving children
- fail before writing PID
- be started manually by the customer/admin

The agent should track process trees where possible and verify stop results.

## 7. Suggested Implementation Phases

### Phase 1: Inventory Current Flow And Add Report Only

This report is Phase 1.

No source code changes should be made for companion behavior in this phase.

### Phase 2: XML Schema Design

Add `companion_programs` to:

- `modules/config_games/schema_server_config.xml`
- `modules/config_games/config_servers.php`
- `modules/config_games/xml_tag_descriptions.php`

Create example XML for BEC, B3, and a generic log watcher.

### Phase 3: Panel Storage/UI Design

Add storage for per-server companion settings.

Initial UI:

- show companion list from XML
- enable/disable checkbox
- delay override
- safe predefined options only

Do not expose raw command editing to customers.

### Phase 4: Agent Start/Stop Integration

Add encrypted RPCs:

- `server_companion_start`
- `server_companion_stop`
- `server_companion_status`

Extend start/restart flow to call companion start/stop at the right time.

### Phase 5: PID Tracking And Cleanup

Implement:

- agent-owned state directory
- PID files
- screen session names
- process tree cleanup
- stale PID detection
- companion status reporting

### Phase 6: DayZ/BEC Test

Migrate one DayZ/Arma BEC case away from `_alsoRun.bat`.

Test:

- start game
- delayed BEC start
- BEC status
- stop game
- BEC cleanup
- restart flow
- crash/recovery behavior
- agent restart behavior

### Phase 7: Generalized Docs

Document:

- XML authoring
- admin setup
- supported placeholders
- security rules
- troubleshooting
- log locations
- migration path from `_alsoRun.bat`

## 8. Open Questions

1. Should companions be started after the game process exists, after the game port is listening, or after query/RCON succeeds when available?
2. Should companions be allowed to keep running if the game server crashes and autorestarts, or should they always restart with each game process cycle?
3. Should some companions be marked "persistent" across game restarts?
4. Which user should run companions on Linux when `LINUX_USER_PER_GAME_SERVER` is enabled?
5. Should companion logs be visible to customers by default, or admin-only?
6. Should companion config files be editable through the existing config file module?
7. How should Windows PID capture work on systems without WMIC?
8. Is PowerShell guaranteed available in the supported Windows/Cygwin agent environment?
9. Should the Panel support companion install/update packages, or only runtime start/stop of already-installed files?
10. Should companion definitions live only in game XML, or should there be a reusable global companion catalog?
11. How should firewall rules handle companion ports if a companion needs one?
12. What should happen if a companion fails but the game server is online?
13. Should companion failure affect billing/status/customer-facing uptime?
14. How should agent autostart restore companion state after power loss or agent restart?
15. Should the agent stop companions before or after graceful game stop for tools that send shutdown messages to the game?
16. Should companion environment variables be separate from game environment variables?
17. How should command placeholders be standardized across Linux and Windows?
18. What is the maximum acceptable startup delay for companion programs?
19. Should admins be able to override companion commands per server, or only per game XML?
20. How should existing `_alsoRun.bat` game configs be migrated without breaking current customers?

## Summary Recommendation

Build Method 1: a first-class companion programs system driven by trusted XML/admin configuration, stored per server in the Panel, and executed/owned by the agent.

Do not continue expanding `_alsoRun.bat`. It is a useful proof of need, but it is Windows-specific, customer-editable, hard to audit, and unreliable for stop/restart cleanup.

Use `screen` as the shared backend for now, with one screen session per companion and agent-owned PID/log/state files outside the customer file root.
