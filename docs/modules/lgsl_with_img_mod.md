# LGSL With Image Mod

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Legacy LGSL status listing and image-based server display.

## Current Status

- Legacy
- Functional for query display
- Disabled by default

## Dependencies

- LGSL query data
- cached image assets

## Database Tables

- `lgsl`

## Agent Interaction

- query-based display only

## User Workflow

- view public-facing server status images

## Admin Workflow

- manage LGSL entries

## Security Concerns

- query trust should not be treated as server truth

## Known Issues

- legacy status approach
- overlaps with modern agent status reporting

## Missing Functionality

- clearer integration with the current status model

## Suggested Future Improvements

- keep only where the image-based listing is still desired
- move related query/image functionality into Game Monitor or contextual server
  actions instead of global navigation

## Recommendation

- Deprecate / Keep optional as legacy; do not default-install.
