# Status System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current Goal

The status system should tell the truth about a server's state without depending on stale marker files or query success alone.

Lifecycle decisions must not use `SERVER_STOPPED`/`Server_Stopped` marker files in game homes.
Stop intent is agent-owned runtime state, and runtime validation is based on process/session/port checks.

## Current Direction

The current codebase is moving toward agent-owned structured status.

Important files:

- `includes/lib_remote.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/server_monitor.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

## Panel Display Behavior

Game Monitor should not convert ambiguous status into false offline.

Panel display mapping:

- `ONLINE` displays green.
- `STARTING`, `STOPPING`, and `UNRESPONSIVE` display yellow/active.
- `OFFLINE` displays red only when the agent confirms offline.
- `UNKNOWN` displays gray.

LGSL/GameQ query failure is not enough to mark a server offline. Query success may add player/map/hostname metadata, but query failure should only show a small unavailable note when the agent says the server is otherwise active.

Current repaired Panel flow:

1. `modules/gamemanager/server_monitor.php` calls `get_agent_server_status`.
2. `get_agent_server_status` first asks for structured `remote_server_status` data when available.
3. If the structured response is missing or ambiguous, the Panel uses existing agent calls to check `is_screen_running`.
4. The Panel also asks the agent to test the configured game port with `ss` or `netstat`.
5. A confirmed managed session, process flag, or listening game port is enough to display `ONLINE`.
6. `UNKNOWN` is reserved for an unavailable or inconclusive agent status check.

When an agent returns desired-state fields such as `desired_state`,
`desired_lifecycle_state`, `desired_state_file`, or `status_marker`, the Panel
displays that value only as intent. It does not treat `status.running` as proof
that the server is active. `ONLINE`, `STARTING`, `STOPPING`, and
`UNRESPONSIVE` require observed runtime evidence such as a managed screen,
process/PID, or assigned port listener. Status-only compatibility is kept only
for older agents that do not expose desired-state fields.

The port fallback is deliberately cross-platform:

- Linux normally uses `ss -lntu`, then `netstat`.
- Cygwin/Windows normally uses `netstat -an`.
- The Panel-side fallback parses complete command output instead of assuming the listening address is always in the same column.
- The port fallback must build a literal numeric grep pattern such as `[:.]2302([[:space:]]|$)`. A previous regression shell-quoted the port before interpolating it into the regex, producing a pattern like `[:.]'2302'...` that could never match normal `ss`/`netstat` output.

Agent behavior now treats a listening game port as `ONLINE` even if the managed screen/session cannot be found. The UI may still show the warning from `last_error`, but it must not show a false red/offline or gray/unknown state when the game port proves the server is running.

## Recommended State Model

| State | Meaning |
|---|---|
| `OFFLINE` | No managed session/process and no required port listening. |
| `STARTING` | Managed session/process exists but the required port is not ready yet. |
| `ONLINE` | Managed session/process exists and the required port is listening. |
| `STOPPING` | Stop is in progress and the session/process still exists. |
| `UNRESPONSIVE` | The server did not become ready in time or stop did not complete cleanly. |
| `UNKNOWN` | The agent cannot be reached or cannot determine state. |

## What Should Be Checked

The agent should check, in this order:

1. managed session or screen name
2. process or PID tree when available
3. required game port listening
4. optional query/RCON port listening
5. optional query metadata

The Panel fallback does not replace a full agent-owned status model. It exists so existing running servers do not show false unknown/offline when the structured status RPC is missing, partial, or query metadata fails.

## Known Problems To Remember

- LGSL/GameQ may fail for supported games, blocked ports, or slow startups.
- Marker files can become stale after crashes or power loss.
- A game can be online even if query metadata is temporarily unavailable.
- Some games need long startup windows, so timeouts must be configurable per game.
- `Agent status RPC unavailable` usually means the Panel could not complete the `server_status` call. On Windows/Cygwin nodes, first verify `/GSP/gsp_agent.pl` is valid Perl, `/GSP/Cfg/bash_prefs.cfg` has LF line endings and no leading spaces before assignments, and the `GSPAgent` Windows service starts cleanly. Use `Agent-Windows/agent_start.bat` only for foreground troubleshooting.

## Planned Improvement Shape

- make the agent the source of truth
- preserve optional query metadata
- use state hints for start/stop transitions
- expose clear messages for `STARTING` and `UNRESPONSIVE`
- add precise log excerpts when startup fails

## Lifecycle action behavior

Panel gamemanager stop/restart now prioritizes executing lifecycle actions over status-only gating:

- Stop action always attempts remote stop when requested.
- `UNKNOWN` status or temporary RPC status ambiguity does not block stop execution.
- Restart UI shows explicit wait messaging while agent-enforced restart delay is in progress.
- Restart polling tolerates temporary `UNKNOWN`/`STOPPING`/`OFFLINE` states during the restart window before final start status is confirmed.

This prevents dead-end failures such as "Agent status RPC unavailable" from blocking user-initiated stop/restart actions.
