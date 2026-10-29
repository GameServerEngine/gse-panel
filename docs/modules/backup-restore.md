# Backup Restore

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Backup and restore UI for game server homes.

## Current Status

- Broken
- Testing-phase messaging in code

## Dependencies

- Hard-coded backup paths and external backup host details in module code
- Manual shell execution

## Database Tables

- None declared in module metadata

## Agent Interaction

- Not a clean agent-driven workflow

## User Workflow

- Customer-facing but not production-ready

## Admin Workflow

- currently used as an experimental/testing interface only

## Security Concerns

- hard-coded paths
- hard-coded credentials style behavior
- shell command construction
- not safe as a production backup path

## Known Issues

- explicit "not working" messaging
- dry-run style behavior
- restore flow is not a real product workflow

## Missing Functionality

- real backup job model
- retention policy
- restore verification
- download/export
- agent-integrated backup execution

## Suggested Future Improvements

- replace with a managed backup system
- move backup state and logs into the Panel/agent architecture

## Recommendation

- Remove / Replace

