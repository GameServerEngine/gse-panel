# Lifecycle Agent Handoff

This is the Panel contract that `GSE-Agent-Linux` and `GSE-Agent-Windows` must
implement for the game-key payload layout.

## Path Contract

`server_homes.home_path` is the server-ID management home. The Panel validates
the XML `game_key` as one safe directory component and derives:

```text
management_home = /srv/gse/42
game_key = dayz_win64
game_root = /srv/gse/42/dayz_win64
```

Windows/Cygwin uses the same logical contract:

```text
management_home = /cygdrive/c/GSE/Servers/42
game_key = dayz_win64
game_root = /cygdrive/c/GSE/Servers/42/dayz_win64
```

Game payload belongs under `game_root`. GSE management files stay directly under
`management_home` or management-only children such as `gsp_server_content`.
Agents must not create PID files, status markers, generated watchdog inputs,
bot definitions, or Server Content management jobs beneath `game_root`.

## Start Payload

The Panel calls `universal_start` with the legacy positional arguments plus a
final JSON argument. The final argument has `contract=gse.lifecycle.v2` and
contains these fields:

| Field | Meaning |
| --- | --- |
| `operation` | `start` or `restart_start` |
| `home_id` | Numeric server home ID |
| `management_home` | Server-ID management directory |
| `game_key` | Validated XML game key |
| `game_root` | Game payload root, `management_home/game_key` |
| `executable` | XML server executable name |
| `run_directory` | Relative run directory beginning with `game_key` |
| `absolute_run_directory` | Absolute run directory under `game_root` |
| `arguments` | Current resolved startup arguments from saved user selections |
| `environment` | Current XML environment variable string |
| `pre_start` | Current XML pre-start command string |
| `port_context` | Assigned IP, game/query/RCON ports, protocol, control protocol, and query identifiers |
| `user_options` | Current decoded `server_homes.last_param` values |
| `mod` | Selected mod row for the Start press |
| `replace_text_results` | Files written by Panel replace-text preparation |
| `bot` | Null or one bot `{working_directory, command, executable, arguments}` |
| `restart_reason` | Empty for Start, `panel_restart` for Panel Restart |

The agent must regenerate watchdog state and optional bot launch state from this
payload on every Start. It must not reuse stale watchdog or bot command files
from a previous Start.

## Stop And Restart

`stop_server` and `force_stop_server` receive the legacy arguments plus a final
`gse.lifecycle.v2` JSON payload with `operation`, `home_id`, `management_home`,
`game_key`, `game_root`, and `port_context`.

Stop success requires verified absence of managed screen/session evidence,
game/watchdog/bot PIDs, surviving process trees, and assigned game-port owners.
Protected or ambiguous port owners must be returned as structured failures.

Restart is a verified Stop followed by a fresh Start generated from current
Panel selections. A successful Restart response must not be returned while
tracked PIDs or assigned port owners survive the stop phase.

## Desired And Observed State

Desired state is exactly one of:

```text
status.running
status.stopped
```

Desired-state files express intent only. Observed runtime state comes from
screen/session, PID/process-tree, and assigned-port evidence. The Panel treats
`status.running` without observed runtime evidence as not active on current
agents.

## Compatibility Boundary

Older agents may ignore the appended JSON payload and return legacy scalar
codes. The Panel keeps narrow compatibility for those agents:

- legacy Start `1` means accepted, negative codes remain start failures
- legacy Stop success is provisional until follow-up `server_status` verifies stopped
- legacy Restart `1`, `-1`, and `-2` keep their historic meanings
- status-only compatibility applies only when no desired-state fields are
  present in the agent response

Current agents should return structured hashes with `success`, `status`,
`verified`, `message`, optional `last_error`, and evidence fields for screen,
PIDs, and ports.

## Linux Phase Notes

Linux validation should verify that `screen`, watchdog PID, game PID, optional
bot PID, and TCP/UDP port evidence match the assigned home/IP/port. Start
preflight should fail before launch when live evidence already exists.

Server Content management files are expected at:

```text
/srv/gse/42/gsp_server_content/workshop_manifest.json
/srv/gse/42/gsp_server_content/jobs/workshop/workshop_job_*.sh
/srv/gse/42/bot.def
```

Payload examples:

```text
/srv/gse/42/dayz_win64/@CF
/srv/gse/42/dayz_win64/keys
/srv/gse/42/dayz_win64/dzsa/node-mod-server.exe
```

An optional bot payload may live under `game_root` or another validated
management-home child. Its lifecycle definition remains
`management_home/bot.def`.
