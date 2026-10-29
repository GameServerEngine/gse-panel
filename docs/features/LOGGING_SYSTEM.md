# Logging System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

Logging comes from multiple places:

- agent screen logs
- console logs
- update logs
- scheduler logs
- admin/logger history

Important references:

- `modules/gamemanager/log.php`
- `modules/gamemanager/view_server_log.php`
- `modules/gamemanager/get_server_log.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

## What Works

- live log retrieval exists
- logs can be fetched through the Panel
- the viewer can update via AJAX
- the main game log viewer uses a large monospace output panel

## What Still Needs Cleanup

- better startup failure diagnostics
- clearer newest-log-file selection
- better error highlighting
- better downloadable log history

## Log Viewer Layout

`modules/gamemanager/log.php` and `modules/gamemanager/view_server_log.php` keep the AJAX refresh behavior and render the log in a large dedicated `<pre>` block:

- element: `#live-server-log.gsp-live-log-panel`
- desktop minimum height around `500px`, with `55vh` viewport height
- mobile height around `45vh`
- monospace font
- preserved line breaks
- vertical scrolling inside the log panel
- long-line overflow remains usable without shrinking the panel to one line
- AJAX refresh updates `textContent` only; it does not reload the whole page

The live viewer intentionally avoids `<textarea>` and the generic `.log` class, because inherited theme styles can make the output appear like a one-line input.

If a future route shows a one-line log again, inspect the actual rendered element first. The fix should be applied to the route that emits the element, not as a broad theme override.

## Startup Log Endpoint Safety

The startup page has a separate live log pane at `#server-log-output`. Its AJAX source is `modules/gamemanager/get_server_log.php`, which is a standalone Panel endpoint rather than a module page under `home.php`.

Standalone log endpoints must load the same bootstrap helpers as other direct Panel entry points. In particular, database creation requires `includes/helpers.php`, where `createDatabaseConnection()` is defined. A prior startup-log failure was caused by `get_server_log.php` including `includes/functions.php` but not `includes/helpers.php`.

The startup log endpoint requires the existing authenticated Panel session and checks home ownership before reading logs from an agent. Admins use the standard admin home lookup; regular users use the assigned-home lookup. The endpoint also validates `home_id`, `mod_id`, home existence, mod ownership, XML availability, and remote log failures before producing escaped `<pre>` output suitable for insertion into the startup pane.

Follow-up recommendation: other direct PHP endpoints that create their own database connection should be reviewed for the same helper include and authenticated-session pattern, but unrelated endpoints should not be changed without focused validation.
