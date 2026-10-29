# User Games

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Server provisioning, assignment, cloning, expiration, and home management.

## Current Status

- Production
- Functional

## Dependencies

- billing lifecycle
- server manager
- game XML configs
- Panel agent provisioning calls

## Database Tables

- `user_homes`
- `user_group_remote_servers`
- `user_group_homes`
- `master_server_homes`

## Agent Interaction

- provisioning and clone operations
- installation commands
- expiration handling

## User Workflow

- get assigned a server
- edit home details
- clone or migrate when allowed
- check expiration

## Admin Workflow

- create servers
- assign homes
- migrate homes
- manage install commands and custom fields

## Security Concerns

- port allocation
- password generation
- ownership and permission boundaries

## Known Issues

- provisioning is a critical path and needs audit-grade reliability

## Missing Functionality

- clearer install/provisioning progress
- stronger post-create verification

## Suggested Future Improvements

- make provisioning more transparent and testable

## Recommendation

- Keep / Improve

