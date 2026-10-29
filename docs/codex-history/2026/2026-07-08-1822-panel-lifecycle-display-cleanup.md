# Task

## User Request

Workflow ID: gsp-lifecycle-cleanup
Step ID: panel-lifecycle-display-cleanup
Step: 3 of 3
Project: GSP

Complete only the current workflow step in `/home/dev/Projects/GSP/Panel`: inspect `Panel/Panel/modules/gamemanager/home_handling_functions.php`, `server_monitor.php`, `start_server.php`, and directly related helpers. Implement focused UI/lifecycle cleanup after the agent screen/watchdog ownership fix: hide internal `status_source` from normal customer-facing Game Monitor output; show neutral STARTING/active state during slow startup when active evidence or a fresh startup hint exists; do not show offline/start form immediately after successful start while STARTING or a fresh hint exists; collapse stale STOPPING hints without runtime evidence to stopped/offline; avoid red stop failures when stop succeeds or only stale/dead screen state remains; use transient monitor refresh cadence around 30 seconds. Validate with PHP lint, static source text check, `git diff --check`, and `git status --short`. Commit and push immediately if validation passes. Do not stage `.devplus` artifacts. End with `CODEX_RESULT: SUCCESS` and `VERIFICATION_STATUS: PASSED`.

## Problem

Panel lifecycle screens could still expose agent-internal status source diagnostics, allow fresh startup hints to fall through to the offline/start path, and render stale STOPPING cleanup as a customer-facing stop failure.

## Root Cause

The Panel had partial helper coverage for transient status, but normalization was split across monitor, start, and stop paths. `OFFLINE` or `UNRESPONSIVE` responses with fresh startup evidence were not consistently converted into an active display state, and `STOPPING` without runtime evidence was not treated as cleared for stop result handling.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/features/STATUS_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/GAMEMANAGER.md`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/start_server.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Linux/documentation/agent-guide.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/README.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/CYGWIN_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSP/GSP-Agent-Windows/docs/GSP_WINDOWS_AGENT_PORT_VALIDATION.md`

## Files Changed

- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-08-1822-panel-lifecycle-display-cleanup.md`

## Architecture Decisions

- Keep the agent response shape unchanged and normalize customer-facing lifecycle state in the Panel.
- Treat live port evidence as online, and session/process/PID evidence or fresh STARTING/AUTORESTARTING hints as active STARTING rather than failure/offline.
- Treat STOPPING with no runtime evidence as stopped/offline so stale hints do not keep Stop/Restart visible forever.
- Keep `status_source` available internally, but sanitize customer-facing error text so normal Game Monitor output does not show source labels.
- Use a 30-second monitor refresh cadence while transient start/stop states are visible.

## Validation Performed

- `php -l Panel/modules/gamemanager/home_handling_functions.php`
- `php -l Panel/modules/gamemanager/server_monitor.php`
- `php -l Panel/modules/gamemanager/start_server.php`
- `php -l Panel/modules/gamemanager/stop_server.php`
- `git diff --check`
- Static source text check for visible `source:` text in normal `server_monitor.php` output
- `git status --short`

## Git Commits

Repository: `/home/dev/Projects/GSP/Panel`

Commit: Pending final validation

Hash: Pending final validation

## Remaining Work

- None identified for this workflow step after validation.
