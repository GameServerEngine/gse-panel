# GameManager

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Role

`modules/gamemanager` is the core customer server control module. It owns:

- start
- stop
- restart
- logs
- server monitor
- update flow hooks
- RCON integration
- status display

## Important Files

- `modules/gamemanager/module.php`
- `modules/gamemanager/home_handling_functions.php`
- `modules/gamemanager/server_monitor.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/log.php`
- `modules/gamemanager/view_server_log.php`
- `modules/gamemanager/get_server_log.php`
- `modules/gamemanager/update_server.php`

## Start / Stop / Restart

The module calls the agent through `includes/lib_remote.php`.

Current shape:

- start -> `universal_start`
- stop -> `remote_stop_server`
- restart -> `remote_restart_server`

The right behavior is:

- start shows `STARTING` as soon as the managed session exists
- online status requires the process/session plus the game port
- restart should be stop, wait, start
- stop should not be treated as complete until the session/process is actually gone

## Status Reporting

The current flow now points toward agent-truth status reporting via:

- `remote_server_status`
- `get_agent_server_status` in `home_handling_functions.php`
- `server_status_without_decrypt` in both agents
- fallback `is_screen_running` checks when structured status is unavailable or ambiguous
- fallback agent-side `ss`/`netstat` port listening checks through `exec`

Useful state labels:

- `OFFLINE`
- `STARTING`
- `ONLINE`
- `STOPPING`
- `UNRESPONSIVE`
- `UNKNOWN`

Query checks should remain optional metadata only.

Current Panel fallback behavior:

1. Try the structured `remote_server_status` response.
2. Check the managed screen/session with `is_screen_running`.
3. Check whether the configured game port is listening from the agent host using `ss` or `netstat`.
4. Treat a confirmed session, process, or game port as `ONLINE`.
5. Treat query failure as missing metadata only, not as offline or unknown.
6. Show `UNKNOWN` only when the Panel cannot complete a reliable agent status check.

Repair note:

- `modules/gamemanager/home_handling_functions.php` contains the status fallback.
- The port fallback intentionally parses full `ss`/`netstat` output instead of relying on one fixed field, because Cygwin and Linux output differ.
- The port fallback sends a generated command through the existing agent `exec` RPC. The grep expression must contain the numeric port directly, for example `[:.]2302([[:space:]]|$)`, not a shell-quoted value. If the regex contains quotes around the port, running servers can remain `UNKNOWN` after `server_status` is unavailable.
- The Windows and Linux agents report `ONLINE` when the configured game port is listening even if the managed screen/session is missing, with a warning in `last_error`.

## Game Monitor Display Rules

`modules/gamemanager/server_monitor.php` should display state from the agent status response, not from LGSL/GameQ query success alone.

Current display mapping:

- `ONLINE` -> green online indicator
- `STARTING`, `STOPPING`, `UNRESPONSIVE` -> yellow active/starting indicator
- `OFFLINE` -> red offline indicator
- `UNKNOWN` or agent unavailable -> gray unknown indicator

Query metadata remains optional. A running process/session or listening game port must not be shown as red/offline only because query details are unavailable.

If the monitor says `Agent status RPC unavailable`, treat it as an agent reachability/startup problem before changing query logic. For Windows/Cygwin nodes, validate `/GSP/gsp_agent.pl` with `perl -c`, check `/GSP/Cfg/bash_prefs.cfg` for CRLF or leading whitespace before assignments, and start production mode through the `GSPAgent` Windows service. Use `Agent-Windows/agent_start.bat` only for foreground troubleshooting.

## Log Viewer

Relevant files:

- `modules/gamemanager/log.php`
- `modules/gamemanager/view_server_log.php`
- `modules/gamemanager/get_server_log.php`

The log view should be treated as live, AJAX-updated output rather than a full page reload workflow.

`log.php` and `view_server_log.php` now render the live log as a dedicated `<pre id="live-server-log" class="gsp-live-log-panel">` block. They intentionally do not use the generic `log` class or a `textarea`, because theme styles can collapse textarea/log elements into one-line controls.

The live panel uses viewport-based height, preserved line breaks, vertical scrolling, long-line overflow handling, and mobile sizing.

### Startup Log AJAX Endpoint

`modules/gamemanager/start_server.php` renders `#server-log-output` while a server is in `STARTING` state and polls `modules/gamemanager/get_server_log.php` about every two seconds for current console output.

`get_server_log.php` is a standalone AJAX endpoint. It must bootstrap like the other direct Panel entry points by loading `includes/functions.php`, `includes/helpers.php`, the installed `includes/config.inc.php`, and the game XML parser before it creates or reuses the Panel database connection. `createDatabaseConnection()` lives in `includes/helpers.php`; omitting that helper causes a fatal `Call to undefined function createDatabaseConnection()` and breaks only the live startup log pane, not the server start request itself.

The endpoint uses the authenticated Panel session. Admin users may read logs through `getGameHome()`. Non-admin users may read logs only when `getUserGameHome()` says the selected home is assigned to them. The requested `mod_id` must exist under the selected home before any agent log request is sent. Missing or invalid input, inaccessible homes, missing mods, bad XML, unavailable database bootstrap, unavailable remote support, and agent log failures are returned as safe `<pre>` output for the existing startup pane and logged server-side when diagnostic detail is useful.

The endpoint preserves the current agent log contract: `console_log` path substitution, `%mod%` replacement, home-path based `clean_path()` handling, wildcard no-match messaging, and last-40-line display. Startup status text such as `Server status: STARTING. Waiting for the game port to become ready.` remains separate from log retrieval errors.

## What This Module Depends On

- `config_games` for startup parameters and protocol definitions
- `lib_remote.php` for agent calls
- `user_games` for server home records
- `rcon` for command support where available
- `addonsmanager` for content/mod interactions
