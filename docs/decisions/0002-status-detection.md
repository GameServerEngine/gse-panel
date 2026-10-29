# Decision 0002: Agent-Truthed Status Detection

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Status

Accepted

## Decision

Server status should be derived from agent truth:

1. managed process/session existence
2. required game port listening
3. optional query and metadata lookup

## Reasoning

- Marker files become stale after crashes, failed starts, and power loss.
- Query systems like LGSL and GameQ are useful but unreliable as the sole online signal.
- The agent can check the actual runtime state locally.

## Alternatives Considered

- query-only status
- marker-file status
- process-only status

## Why Those Were Not Chosen

- Query-only status can lie for supported games.
- Marker files are not authoritative.
- Process-only status misses the important readiness condition: the port must actually listen.

