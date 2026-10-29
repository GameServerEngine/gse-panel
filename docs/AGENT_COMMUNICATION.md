# GameServer Engine Agent Communication

Workspace reference: [`../../AGENTS.md`](../../AGENTS.md)

This document is the short-form overview of how the Panel talks to the Linux and Windows agents.

## Transport

The Panel uses its remote communication layer to send commands to an agent, which in turn forwards work to the GSP Perl agent and the host OS. `GSP` remains part of some Perl agent names, headers, paths, and service identifiers for compatibility.

## Common flow

1. Panel module builds a request.
2. `includes/lib_remote.php` sends it.
3. The agent receives the request.
4. The GSP Perl agent executes the requested action.
5. The agent returns status, logs, or command results.

## Key properties

- requests must be explicit and validated
- agents should report results, not decide policy
- status reporting should remain compatible between Linux and Windows where practical
- platform-specific differences should be isolated to the agent layer
- `agent_version` and `agent_resource_snapshot` report the agent code build version, not only the legacy config version value.
- Windows `server_status` may return `WARNING` when the assigned game port is live but no managed screen session or matching PID metadata is present. The Panel treats that as active, keeps `status_source` internal, and shows only customer-safe status text or `last_error` details instead of offering a normal Start path.
- Panel stop handling treats the agent return code as provisional. After `stop_server`, the Panel calls `server_status`; success is shown when the follow-up status is verified `OFFLINE` with no process/session/game-port activity, or when `STOPPING` is only a stale hint with no runtime evidence. `ONLINE`, `WARNING`, `STARTING`, `UNRESPONSIVE`, and unknown active states are stop verification failures.

## Security rule

Customer input must never become arbitrary shell command text. Panel-side validation and allowlists must happen before a request is sent to an agent.
