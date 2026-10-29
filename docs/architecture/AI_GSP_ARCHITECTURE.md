# GameServer Engine Component Architecture

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

This legacy-named document describes the current GameServer Engine (GSE) component boundary. The local workspace root is `/home/dev/Projects/GSP`, but that folder is a multi-repository container, not a Git repository.

- `/home/dev/Projects/GSP/Panel` - GameServer Engine Panel management console and control plane.
- `/home/dev/Projects/GSP/Panel/modules/billing` - canonical Gameservers.World customer storefront.
- `/home/dev/Projects/GSP/GSP-Agent-Linux` - Linux game server execution engine.
- `/home/dev/Projects/GSP/GSP-Agent-Windows` - Windows/Cygwin game server execution engine.

`GSP` remains in some filenames, paths, service names, and protocol identifiers for compatibility. Do not rename those identifiers without a coordinated migration.

## Panel And Agent Communication

The Panel talks to agents through XML-RPC using the encrypted parameter helpers in `includes/lib_remote.php`. The Panel should request actions from the agent and treat the agent as the source of truth for server state.

The shared server control backend is still `screen` for both Linux and Cygwin/Windows agents. The Panel should not decide that a game server is online only because a web request, LGSL query, or GameQ query succeeded.

## Status Source Of Truth

The agent is the source of truth for start, stop, restart, and status. Marker files such as `SERVER_STOPPED` or startup flag files may still exist for legacy autorestart and startup bookkeeping, but they are not trusted as the current server state.

The minimum readiness check is:

1. The configured process/session exists.
2. The configured game port is listening.

LGSL/GameQ query data is optional metadata only. It can provide player counts, maps, hostname, and other query details. If query fails while the process/session exists and the game port is listening, the Panel should show the server as online with a small "query info unavailable" note.

## Status Model

- `OFFLINE`: no managed process/session and no listening game port.
- `STARTING`: managed process/session exists, but the game port is not listening yet.
- `ONLINE`: managed process/session exists and the game port is listening.
- `STOPPING`: stop was requested and the managed process/session still exists.
- `UNRESPONSIVE`: the managed process/session exists but the game port did not become ready before timeout, or stop did not complete cleanly.
- `UNKNOWN`: the agent cannot be reached or did not return structured status.

## Start

Start should launch the configured screen/session and immediately report a starting state if the session exists. Slow games, including Arma 2 and DayZ Mod, must not be marked failed just because LGSL/GameQ did not answer quickly.

Startup timeout should be configurable per game XML when possible, with a default of 180 seconds.

## Stop

Stop should issue the configured graceful control command when available. The agent then checks whether the managed screen/session is gone and whether the game port is no longer listening. If the process/session remains after the wait period, the agent escalates by closing the screen session.

The Panel should only show stopped after the agent confirms `OFFLINE`.

## Restart

Restart is intentionally simple:

1. Stop server.
2. Wait 60 seconds.
3. Start server.

Do not use a separate shortcut that skips stop verification or depends on query responses.

## Log Viewer

The gamemanager log viewer should update the log text through AJAX/live fetches only. It should not reload the full page for each refresh and should not show the old manual refresh interval dropdown or plus/update button.

The live log should preserve mobile scrolling behavior and only auto-scroll when the user is already near the bottom.

## Agent Parity

Keep Linux and Cygwin/Windows agent behavior as similar as possible. Both should continue using `screen` as the shared backend for now and expose the same status model to the Panel.
