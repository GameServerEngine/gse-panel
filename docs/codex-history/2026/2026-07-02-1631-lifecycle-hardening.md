# Task

## User Request

Review, verify, and production-harden the integrated GSP workspace with emphasis on reliable remote server lifecycle control across Panel, Linux Agent, and Windows Agent. Review Server Content sidecars, bots, and hooks; bring Windows lifecycle behavior into parity with the current Linux architecture where appropriate; update permanent Codex workflow policy; validate modified PHP and Perl; create focused commits where safe.

## Problem

Panel lifecycle commands depend on the agents to translate Start, Stop, Restart, and Status into process/session control. Recent Linux-side lifecycle work had newer Server Content runtime handling than Windows. Windows still relied on legacy `_alsoRun.bat` and `_alsoRun.pid` patterns for sidecars, which made sidecar cleanup less explicit and increased the chance that restart/stop would leave auxiliary processes running after the main game server stopped.

## Root Cause

The product had two lifecycle models:

- Linux had server-owned Server Content runtime state under `_gsp_content/runtime/server_content.pids`.
- Windows still treated companion processes primarily as legacy `_alsoRun` processes emitted by game XML hooks.

The Linux cleanup helper also had a bug: a cleanup pass for one PID group could discard lines for other PID groups, so watchdog cleanup could remove app PID entries before app cleanup killed them.

## Repositories Inspected

- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`
- `/home/dev/Projects/GSP/Panel.wiki`
- `/home/dev/Projects/GSP/references/OGP/OGP-Website.wiki` as read-only upstream reference

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `Panel/README.md`
- `Panel/docs/ARCHITECTURE.md`
- `Panel/docs/PANEL_ARCHITECTURE.md`
- `Panel/docs/AGENT_COMMUNICATION.md`
- `Panel/docs/COMMAND_FLOW.md`
- `Panel/docs/architecture/PANEL_AGENT_FLOW.md`
- `Panel/docs/architecture/PANEL_AGENT_COMMANDS.md`
- `Panel/docs/features/STATUS_SYSTEM.md`
- `Panel/docs/modules/SERVER_CONTENT_MANAGER.md`
- `Panel/docs/modules/GAMEMANAGER.md`
- `Panel/docs/features/COMPANION_PROGRAMS.md`
- `Panel/docs/decisions/COMPANION_PROGRAMS_DESIGN.md`
- `Panel/docs/agents/LINUX_AGENT.md`
- `Panel/docs/agents/WINDOWS_AGENT.md`
- `Panel/includes/lib_remote.php`
- `Panel/modules/gamemanager/home_handling_functions.php`
- `Panel/modules/gamemanager/start_server.php`
- `Panel/modules/gamemanager/stop_server.php`
- `Panel/modules/gamemanager/restart_server.php`
- `Panel/modules/gamemanager/server_monitor.php`
- `Panel/modules/gamemanager/mini_start.php`
- `Panel/modules/gamemanager/update_actions.php`
- `Panel/modules/addonsmanager/server_content_actions.php`
- `Panel/modules/addonsmanager/server_content_helpers.php`
- `GSP-Agent-Linux/README.md`
- `GSP-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `GSP-Agent-Linux/gsp_agent.pl`
- `GSP-Agent-Windows/README.md`
- `GSP-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`
- `GSP-Agent-Windows/docs/COMMAND_EXECUTION.md`
- `GSP-Agent-Windows/docs/GSP_WINDOWS_AGENT_PORT_VALIDATION.md`
- `GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`
- `GSP-Agent-Windows/GSP64/GSP/Cfg/Preferences.pm.default`
- `GSP-Agent-Windows/GSP64/GSP/Cfg/bash_prefs.cfg.default`
- `Panel.wiki/Home.md`
- `Panel.wiki/Remote-Servers-and-Agents.md`
- `Panel.wiki/Game-Server-Management.md`
- `Panel.wiki/OGP-Fork-Notes.md`
- `Panel.wiki/Development.md`

## Files Changed

- `/home/dev/Projects/GSP/AGENTS.md`
- `Panel/docs/agents/WINDOWS_AGENT.md`
- `Panel/docs/features/COMPANION_PROGRAMS.md`
- `Panel/docs/codex-history/2026/2026-07-02-1631-lifecycle-hardening.md`
- `GSP-Agent-Linux/gsp_agent.pl`
- `GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`
- `GSP-Agent-Windows/docs/PROCESS_MANAGEMENT.md`
- `GSP-Agent-Windows/docs/PANEL_INTEGRATION.md`

## Architecture Decisions

- Panel remains authoritative for lifecycle intent; agents execute process/session operations.
- `screen` remains the shared lifecycle backend.
- Main game server PIDs remain distinct from Server Content sidecar/hook PIDs.
- Server Content runtime state is server-owned under `_gsp_content/runtime/server_content.pids`.
- Windows now mirrors the Linux Server Content runtime model while retaining `_alsoRun.bat` compatibility for existing XML-generated integrations.
- Stop/restart cleanup must kill server-owned sidecars before main process fallback cleanup completes.
- PID cleanup must preserve non-target PID lines across cleanup passes.

## Validation Performed

- `php -l Panel/includes/lib_remote.php`
- `php -l Panel/modules/gamemanager/home_handling_functions.php`
- `php -l Panel/modules/addonsmanager/server_content_actions.php`
- `php -l Panel/modules/addonsmanager/server_content_helpers.php`
- `perl -I/tmp/gsp-perl-stubs.SqnOSl -c GSP-Agent-Linux/gsp_agent.pl`
- `perl -I/tmp/gsp-perl-stubs.SqnOSl -c GSP-Agent-Windows/GSP64/GSP/gsp_agent.pl`

Direct Perl validation required temporary stubs outside the repositories for missing local validation dependencies/configuration. Linux direct validation without stubs failed because `Archive::Extract` is not installed in this environment. Windows direct validation without stubs failed because `GSP64/GSP/Cfg/Config.pm` is not present in the checkout.

Live Start, Stop, Restart, Status, and Server Content sidecar cleanup validation was not performed because this environment does not provide configured live Panel, Linux Agent, Windows Agent, or game server hosts.

## Git Commits

Repository: `/home/dev/Projects/GSP/Panel`

Commit: Not created during this pass.

Hash: TODO

Repository: `/home/dev/Projects/GSP/GSP-Agent-Linux`

Commit: Not created during this pass.

Hash: TODO

Repository: `/home/dev/Projects/GSP/GSP-Agent-Windows`

Commit: Not created during this pass.

Hash: TODO

Reason: the modified repositories already contained large pre-existing staged and unstaged rename/rebrand/package changes. Creating commits without first separating that owner work would require staging unrelated files or committing on top of unrelated staged state, violating the task rule to stage only task-related files.

## Remaining Work

- Run live lifecycle validation against real Linux and Windows agents: Start, Stop, Restart, Status.
- Verify Windows `_alsoRun.bat` sidecar imports and cleanup against a real server that starts companion processes.
- Move game XML sidecars from legacy `_alsoRun` generation into first-class Server Content sidecar definitions where product scope allows.
- Resolve the pre-existing staged rename/rebrand changes so future Codex work can produce focused commits safely.
