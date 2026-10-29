# Decision 0003: First-Class Companion Programs

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Status

Accepted

## Decision

Companion applications such as BEC, B3, Discord bridges, log watchers, and stats collectors should be modeled as managed companion programs rather than ad hoc customer-editable startup scripts.

## Reasoning

- Customers should not control privileged helper commands through editable startup files.
- Companion processes need to be started, stopped, and restarted alongside the game server.
- PID and log handling should be centralized.
- The same design should work across Linux and Windows/Cygwin.

## Alternatives Considered

- keep `_alsoRun.bat` style helper files
- use only `pre_start` scripts
- rely on manual customer scripts

## Why Those Were Not Chosen

- They are not centrally managed.
- They are hard to secure.
- They are difficult to cleanly stop on server shutdown.

