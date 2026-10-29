# Administration

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Admin utilities and logging helpers, including the watch logger and external link storage.

## Current Status

- Functional
- Legacy-leaning

## Dependencies

- Panel admin pages
- `adminExternalLinks` table
- `logger` table

## Database Tables

- `adminExternalLinks`
- `logger`

## Agent Interaction

- None directly

## User Workflow

- Not customer-facing

## Admin Workflow

- manage external admin links
- inspect logger records
- use watch logger tools

## Security Concerns

- admin-only access
- log visibility should be role-gated

## Known Issues

- legacy UI patterns
- limited product value compared to newer notification/support systems

## Missing Functionality

- modern audit/log search
- tighter integration with support and notifications

## Suggested Future Improvements

- merge logger views into a unified audit area
- replace old link utilities with clearer admin widgets

## Recommendation

- Keep

