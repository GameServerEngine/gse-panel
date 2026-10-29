# Subusers

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Subuser permissions and delegated access management.

## Current Status

- Functional

## Dependencies

- user/group permission model
- server access rights

## Database Tables

- not declared in the module metadata file itself

## Agent Interaction

- None directly

## User Workflow

- grant access to another user
- share server management rights

## Admin Workflow

- manage permission scope and delegated access

## Security Concerns

- permissions must be scoped tightly to avoid over-sharing

## Known Issues

- module documentation is sparse in code

## Missing Functionality

- clearer UI around per-server and per-feature permissions

## Suggested Future Improvements

- tie subusers into files, scheduler, content, and support permissions more explicitly

## Recommendation

- Keep / Improve

