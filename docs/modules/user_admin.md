# User Admin

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

User, role, and group administration.

## Current Status

- Production

## Dependencies

- auth system
- role and group tables

## Database Tables

- `users`
- `user_groups`
- `user_role_info`
- `user_group_info`

## Agent Interaction

- none directly

## User Workflow

- account management

## Admin Workflow

- create/edit users
- manage groups and roles
- set page limits and email preferences

## Security Concerns

- password handling
- email uniqueness
- group permissions

## Known Issues

- older role/group model

## Missing Functionality

- more explicit per-feature permissions

## Suggested Future Improvements

- tie roles into server content, scheduler, files, and billing more clearly

## Recommendation

- Keep / Improve

