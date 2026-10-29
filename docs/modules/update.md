# Update

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Panel update and patch management.

## Current Status

- Production
- Admin-only

## Dependencies

- patch and update logs
- admin access

## Database Tables

- `update_blacklist`
- `panel_update_log`
- `update_patches`

## Agent Interaction

- indirect if patch operations touch remote systems

## User Workflow

- none, admin-only

## Admin Workflow

- inspect and apply updates
- review patch logs

## Security Concerns

- updates modify the control plane itself

## Known Issues

- can be confusing if not clearly separated from game-server updates

## Missing Functionality

- richer update history and rollback guidance
- live progress polling for asynchronous remote agent updates

## Suggested Future Improvements

- keep admin-only and document carefully
- add status polling for `component_update` logs after the agent has restarted

## Recommendation

- Keep

## Current GSP Additions

The primary update implementation lives in `modules/administration/panel_update.php` and is exposed by `modules/update/update.php`.

Current update targets:

- Panel files
- Website files
- Linux agents
- Windows/Cygwin agents

The updater uses a single configured Git repository with component source folders such as `Panel`, `Website`, `Agent_Linux`, and `Agent-Windows`. Remote agents are updated through the encrypted `component_update` XML-RPC method and preserve hosted game data and agent configuration folders.
