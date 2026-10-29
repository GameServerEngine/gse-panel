# Companion Programs

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

Companion programs now have database-backed Server Content infrastructure for
generic Server Bot entries plus initial BEC and DZSA sidecar catalog packages.
Current behavior outside Server Bot content remains mostly script-driven and
game-specific.

Important references:

- `docs/decisions/0003-companion-programs.md`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`
- `modules/config_games/schema_server_config.xml`

Current lifecycle hardening:

- Linux and Windows both prepare `_gsp_content/runtime/server_content.pids` under each server home.
- Stop and restart clean server-owned content PIDs before main server process escalation.
- Main game server PIDs stay in agent runtime metadata, not in the server-content PID registry.
- Windows imports legacy `_alsoRun.pid` entries into the server-content cleanup path for compatibility.
- Server Bot content is stored per server in `server_content_manifest` and generates the existing `_alsoRun.cmd` parser file from Panel-owned state.
- Installed Server Bot state is authoritative in the Panel database; the Panel does not query agents to discover installed content.
- BEC is represented as a Windows Server Bot template using the existing `bec.exe --dsc --dec -f config.cfg` behavior.
- DZSA Launcher support is represented as an optional `node-mod-server.exe` sidecar while `DayZServer_x64.exe` remains the DayZ game executable.
- New sidecars, bots, and hooks should use server-owned content runtime or Server Bot content, not agent-global or customer-editable helper processes.

## What The System Needs To Do

- start companion apps with the server
- stop companion apps when the server stops
- restart companion apps when the server restarts
- track PIDs or handles
- log stdout/stderr
- avoid customer-editable privileged startup scripts

## Good Companion Examples

- BEC for Arma/DayZ
- B3
- Discord bots or bridges
- log watchers
- stats collectors
- anti-cheat helpers

## Recommended Shape

The system should be XML/admin-defined and agent-managed.

Server Bot content uses admin-defined templates with install folder, working
directory, executable/command, arguments, OS support, enable-by-default behavior,
optional checksum/provenance, and optional uninstall behavior. The Panel writes
`_alsoRun.cmd` in the location the current agent startup parser already reads
and does not redesign agent startup behavior.

## Recommendation

Keep the design centralized and game-aware. Do not rely on one-off helper files as the source of truth.
