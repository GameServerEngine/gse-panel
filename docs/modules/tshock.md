# TShock

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Terraria / TShock-specific support module.

## Current Status

- Alpha
- Partial
- Disabled by default

## Dependencies

- Terraria/TShock server support
- token management

## Database Tables

- `tshock`

## Agent Interaction

- token and server data used for TShock support

## User Workflow

- only for compatible Terraria servers

## Admin Workflow

- configure and manage TShock-specific settings

## Security Concerns

- token storage
- compatibility gating

## Known Issues

- alpha status
- should only appear for the right game types
- the current top-level route assumes a Terraria server context
  (`home_id-mod_id-ip-port`) and can emit PHP errors when opened from global
  navigation without that context

## Missing Functionality

- complete docs and tighter game capability gating

## Suggested Future Improvements

- expose only when XML/game capability says Terraria/TShock
- move entry points into Game Monitor or server actions instead of global
  navigation

## Recommendation

- Keep conditional / Improve; do not default-install or show as a top-level menu
  item.
