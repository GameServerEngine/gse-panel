# Dynamic Server Image

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Dynamic server image and artwork management for server cards.

## Current Status

- Functional
- Legacy / niche
- Disabled by default

## Dependencies

- cached server images
- game-specific artwork assets

## Database Tables

- None declared in module metadata

## Agent Interaction

- None directly

## User Workflow

- view server images in dashboard/status contexts

## Admin Workflow

- configure imagery and defaults

## Security Concerns

- image cache and file path safety

## Known Issues

- niche module with limited platform-critical value

## Missing Functionality

- clearer integration with docs and server cards

## Suggested Future Improvements

- keep only if the product uses it heavily
- move related image/status actions into Game Monitor or contextual server
  actions instead of global navigation

## Recommendation

- Keep optional / Audit; do not default-install.
