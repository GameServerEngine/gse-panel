# Circular

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Admin notifications / circular messages for broadcasting announcements to users.

## Current Status

- Functional

## Dependencies

- Panel notification flow
- user recipient tracking

## Database Tables

- `circular`
- `circular_recipients`

## Agent Interaction

- None directly

## User Workflow

- receive announcements / notifications

## Admin Workflow

- create a circular message
- target recipients
- review delivery state

## Security Concerns

- message content should be sanitized
- recipient scoping must be correct

## Known Issues

- older notification pattern

## Missing Functionality

- richer maintenance scheduling integration
- email/Discord bridge

## Suggested Future Improvements

- integrate with support and maintenance alerts
- expose clearer read/unread state

## Recommendation

- Keep / Improve

