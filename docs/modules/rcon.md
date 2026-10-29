# Rcon

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Admin RCON interface for issuing console commands to supported game servers.

## Current Status

- Functional

## Dependencies

- server control protocol support
- gamemanager

## Database Tables

- none declared in module metadata

## Agent Interaction

- sends RCON/console commands through the agent

## User Workflow

- usually not customer-facing

## Admin Workflow

- run commands
- support warning messages and diagnostics

## Security Concerns

- command validation
- log every command

## Known Issues

- command safety depends heavily on module-specific restrictions

## Missing Functionality

- richer presets and command history

## Suggested Future Improvements

- integrate more tightly with scheduler warnings and support workflows

## Recommendation

- Keep / Improve

