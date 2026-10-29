# TS3Admin

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Teamspeak 3 admin interface module.

## Current Status

- Functional
- Niche
- Disabled by default

## Dependencies

- TS3 server administration data
- Teamspeak 3 service access

## Database Tables

- `ts3_homes`

## Agent Interaction

- indirect through TS3 service administration

## User Workflow

- manage TS3 service associations

## Admin Workflow

- configure TS3 homes and credentials

## Security Concerns

- Teamspeak credentials and host access

## Known Issues

- separate niche module from the game-server core
- should not be registered as a fresh-install top-level module unless a
  deployment explicitly sells or manages TeamSpeak 3 services

## Missing Functionality

- clearer docs and product positioning

## Suggested Future Improvements

- keep only if TS3 hosting is part of the offering

## Recommendation

- Keep optional; do not default-install.
