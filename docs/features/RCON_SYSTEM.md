# RCON System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

RCON support is part of the gamemanager and a dedicated admin RCON module.

Important references:

- `modules/gamemanager/module.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/rcon.php`
- `modules/rcon/module.php`

## Current Uses

- admin console commands
- warning messages before restart
- diagnostic control for supported games
- scheduler-friendly command execution

## Recommended Direction

- keep presets
- validate commands where possible
- do not expose arbitrary commands to customers by default
- log every command with user and server context

