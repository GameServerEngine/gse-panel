# Cron

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Scheduler / CRON for game server automation.

## Current Status

- Functional
- Partial

## Dependencies

- Panel action selectors
- agent scheduler tasks
- server monitor and content systems

## Database Tables

- None declared by the module metadata

## Agent Interaction

- `scheduler_add_task`
- `scheduler_edit_task`
- `scheduler_del_task`
- `scheduler_list_tasks`
- `scheduler_read_tasks`

## User Workflow

- create recurring server actions
- review task list and event output

## Admin Workflow

- create server-wide tasks
- create raw command tasks
- manage server content action automation

## Security Concerns

- raw commands can be dangerous
- API tokens in callbacks need careful handling
- customer-safe actions must be separated from admin-only actions

## Known Issues

- too command-string oriented
- limited task history
- overlap and conflict rules are weak

## Missing Functionality

- typed action registry
- task run history
- notifications
- missed-run handling

## Suggested Future Improvements

- rebuild around safe actions
- add structured logs and result states
- make customer-safe actions distinct from admin actions

## Recommendation

- Rewrite / Improve

