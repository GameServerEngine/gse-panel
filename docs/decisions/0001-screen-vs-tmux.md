# Decision 0001: Keep `screen` As The Shared Backend

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Status

Accepted

## Decision

GSP should keep `screen` as the shared process/session backend for both Linux and Windows/Cygwin agents for the current platform generation.

## Reasoning

- The existing agents already implement server lifecycle around `screen`.
- Linux and Windows/Cygwin behavior can stay aligned if both sides share the same session model.
- The Panel already expects session-based lifecycle checks.
- Moving to a different backend too early would create a large amount of compatibility work for start/stop/restart, logging, and scheduler flows.

## Alternatives Considered

- `tmux`
- direct process supervision without session wrappers
- custom daemon per server

## Why Those Were Not Chosen

- They would require broader code changes across both agents and the Panel.
- The existing runtime, log, and scheduler flows already assume `screen`.
- Cross-platform parity is easier to maintain with the current backend.

