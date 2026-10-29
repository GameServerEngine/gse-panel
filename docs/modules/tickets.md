# Tickets

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Support ticket system with messages, attachments, and settings.

## Current Status

- Disabled by default
- Not currently wanted in the default Panel

## Dependencies

- auth/session
- support workflows
- attachment storage

## Database Tables

- `tickets`
- `ticket_messages`
- `ticket_attachments`
- `ticket_settings`

## Agent Interaction

- indirect via server-context support requests

## User Workflow

- create ticket
- reply
- upload attachments
- track status

## Admin Workflow

- respond to tickets
- manage settings
- moderate attachments and ratings

## Security Concerns

- attachment size and file type limits
- privacy of server and billing context

## Known Issues

- removed from fresh installer registration and default top-level navigation
- should be more tightly linked to server monitor and logs

## Missing Functionality

- automatic server context attachment
- support escalation flow

## Suggested Future Improvements

- integrate server state, recent logs, and account context

## Recommendation

- Do not default-install. Keep the general `support` module as the default
  support entry point unless a future replacement ticket workflow is selected.
