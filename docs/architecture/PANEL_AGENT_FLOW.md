# Panel Agent Flow

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

## Overview

The Panel does not directly run servers. It prepares the request, sends it to the agent, and interprets the response.

```text
User action
  -> Panel module page
  -> GSPRemoteLibrary in lib_remote.php
  -> XML-RPC request to agent /RPC2
  -> gsp_agent.pl method
  -> local screen/process/port work
  -> return status or payload
  -> Panel renders result
```

`GSPRemoteLibrary` and related agent names are compatibility identifiers in the current runtime. They do not change the public product name, which is GameServer Engine (GSE).

## Request Path

Important Panel files:

- `includes/lib_remote.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/view_server_log.php`
- `modules/gamemanager/get_server_log.php`
- `modules/gamemanager/home_handling_functions.php`

Detailed cross-platform handoff fields for the Linux and Windows agents are in
[`LIFECYCLE_AGENT_HANDOFF.md`](LIFECYCLE_AGENT_HANDOFF.md).

## Management Home And Game Root

The Panel stores `server_homes.home_path` as the server-ID management home. It
derives the game payload root from the XML `game_key`:

```text
game_root = management_home/<validated game_key>
```

The XML `game_key` is validated as one safe directory component before any
payload path is built. It must not contain traversal, separators, drive
prefixes, control characters, reserved device names, empty values, or any value
that can escape the management home.

Game payload paths such as executable locations, map lists, config replacement
files, install/update targets, mods, and Workshop targets are resolved under
`game_root`. Lifecycle and coordination paths such as desired-state markers,
PID/runtime files, generated watchdog inputs, Server Content manifests/jobs,
and bot launch definitions remain in `management_home`.

### Start

1. Panel loads the selected server home and game XML.
2. Panel composes startup parameters from stored values and XML templates.
3. Panel applies replace-text and configuration preparation before the remote
   lifecycle request.
4. Panel calls `universal_start` on the agent with the legacy positional
   arguments plus a final JSON `gse.lifecycle.v2` payload. Existing agents may
   ignore the appended payload; current agents use it to regenerate watchdog and
   optional bot state from fresh values on every Start.
5. Agent creates the desired-state markers, regenerated watchdog, optional
   `bot.def`, `screen` session, and game process.
6. Panel polls `remote_server_status`.
7. When the agent reports observed process/session/PID/port evidence, the
   server is treated as ONLINE. `status.running` alone is never runtime proof.

The appended Start payload carries:

- `management_home`
- validated `game_key`
- `game_root`
- executable name
- relative and absolute run directory
- resolved startup arguments
- environment variables and pre-start commands
- assigned IP, game port, query/RCON ports, and protocol context
- current saved user options and selected mod values
- replace-text write results from the preparation step
- optional one-bot definition as working directory, command/executable, and arguments

### Stop

1. Panel calls `remote_stop_server`.
2. Agent sends the configured graceful stop command if one exists.
3. Agent watches the session/process and required port.
4. If the session/process does not exit in time, the agent escalates to screen quit or process kill.
5. Panel treats the server as stopped only when follow-up status evidence shows
   no managed screen, process/PID, or assigned TCP/UDP game-port owner survived.
   Structured failures from protected or ambiguous port owners, surviving
   processes, invalid game keys, crash-loop shutdown, or incompatible agents are
   displayed instead of converted to success.

### Restart

Restart should be:

```text
stop
  -> wait
  -> start
```

The wait period should be explicit and visible in logs. Restart should not depend on marker files or query success.

### Status

1. Panel calls `remote_server_status`.
2. Agent checks:
   - desired state marker
   - managed screen/session
   - PID or process tree when available
   - required game port
   - optional query/RCON ports
3. Agent returns a structured status object.
4. Panel maps the result to the UI state using observed runtime evidence.
   Desired state may be displayed as intent, but it does not make a server
   active by itself. Legacy status-only fallback is limited to older agents that
   do not return desired-state fields.

### Logs

1. Panel calls `get_log`.
2. Agent returns the latest screen or console log data.
3. The log view updates via AJAX instead of full-page refresh.
4. The UI should preserve scroll position and only auto-scroll when the user is already at the bottom.

## Data Returned by Status

Recommended status fields:

- `status`
- `ready`
- `process_running`
- `session_running`
- `game_port_listening`
- `query_port_listening`
- `rcon_port_listening`
- `pid`
- `session_name`
- `ip`
- `port`
- `query_port`
- `last_error`
- optional query metadata

## Practical Notes

- The Panel should not mark a server failed only because query metadata was unavailable.
- The agent should be the source of truth.
- Marker files may exist, but they should be treated as hints only.
- The same high-level flow should work for Linux and Windows/Cygwin.
