# Scheduler System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

The scheduler is implemented through the `cron` Panel module and agent-side `Schedule::Cron` execution.

Important references:

- `docs/modules/SCHEDULER.md`
- `modules/cron/cron.php`
- `modules/cron/shared_cron_functions.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

## Current Strengths

- It can schedule server actions.
- It can schedule Steam updates and server content actions.
- It already has a visible UI and agent execution path.

## Current Weaknesses

- It is too command-string oriented.
- Customer-safe and admin-only actions are not separated cleanly enough.
- Task run history is not rich enough.
- Error reporting and conflict handling need work.

## Recommended Direction

- typed actions
- explicit permissions
- stored task history
- clear logs and results
- no customer raw shell commands by default

## Server Content / Workshop Actions

The Scheduler must use Server Content Manager actions for Workshop automation. Do not build a separate Workshop scheduler.

Current Server Content actions:

| Action Key | Purpose |
|---|---|
| `server_content_check_updates` | Check content update state. |
| `server_content_check_workshop_updates` | Check Workshop update state. |
| `server_content_install_updates_if_stopped` | Install only when the server is stopped. |
| `server_content_install_updates_next_restart` | Queue updates for the next restart. |
| `server_content_install_updates_now` | Install available updates immediately. |
| `server_content_install_updates_and_restart` | Install updates, wait, then restart. |
| `server_content_update_all` | Update all server content records. |
| `server_content_validate_files` | Validate server content files. |
| `server_content_backup_before_update` | Backup before applying updates. |

Workshop-specific aliases:

| Action Key | Purpose |
|---|---|
| `workshop_update` | Update installed Workshop items through Server Content Manager. |
| `workshop_update_and_restart` | Update Workshop items and request a safe restart. |
| `workshop_download_only` | Download/cache Workshop items without installing into live folders. |
| `workshop_install_pending_on_restart` | Install items marked as pending during the restart/maintenance window. |

Implementation references:

- `modules/cron/shared_cron_functions.php`
- `modules/addonsmanager/server_content_actions.php`
- `modules/addonsmanager/workshop_action.php`
