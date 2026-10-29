# GameServer Engine Panel

The GameServer Engine (GSE) Panel is the management console and web control plane for hosted game servers.

Workspace reference: [`AGENTS.md`](../AGENTS.md)

The local workspace path still contains `GSP` for historical compatibility. Do not treat that directory name as the current public product name.

The goal of this repository is to let customers manage hosted game servers with the same practical control they would expect on their own machine:

- start, stop, restart, and monitor servers
- edit config files and startup parameters
- use FTP and file management tools
- install mods, add-ons, and Workshop content
- schedule safe automation tasks
- read logs and troubleshooting docs
- get accurate server status reporting
- keep billing, support, and provisioning tied to the server lifecycle

## Repository Layout

```text
/
  index.php
  home.php
  install.php
  includes/
  modules/
  protocol/
  sql/
  themes/
  docs/
```

## Component Overview

### Panel

The Panel is the control layer. It loads modules from `modules/`, stores panel-side state in the database, and talks to the agents through `includes/lib_remote.php`.

### Agents And Storefront

The Linux and Windows agents live in separate repositories. The canonical Gameservers.World customer storefront lives inside this repository at `modules/billing`; it is not a separate top-level repository and is not the whole Panel.

### Container Platform Proof Of Concept

The current container proof of concept is implemented in the Gameservers.World storefront:

- Customer catalog and provisioning wizard: `modules/billing/container_poc.php`
- Customer lifecycle actions: `modules/billing/container_action.php`
- Admin management: `modules/billing/admin_container_poc.php`
- Shared implementation: `modules/billing/includes/container_poc.php`
- Database migration: `sql/migrations/0004_container_platform_poc.sql`

The catalog is database-backed. The seeded default application is `None / Empty Container`, selected first, and application rows can describe icons, descriptions, versions, platforms, recommended/minimum resources, required ports, compatibility notes, container images, and optional Server Content install scripts. Provisioning creates a panel job, reserves ports, calls the authenticated Container Engine Agent `/v1/containers` API, queues a content job when selected, starts the container, and records the running container for the customer.

## Documentation Overview

Start with:

- [`../AGENTS.md`](../AGENTS.md) - workspace-wide source of truth and repository routing
- [docs/development/CODEX_GUIDE.md](docs/development/CODEX_GUIDE.md)
- [docs/architecture/REPOSITORY_OVERVIEW.md](docs/architecture/REPOSITORY_OVERVIEW.md)
- [docs/architecture/PANEL_AGENT_FLOW.md](docs/architecture/PANEL_AGENT_FLOW.md)
- [docs/architecture/API_REFERENCE.md](docs/architecture/API_REFERENCE.md)
- [docs/architecture/PANEL_AGENT_COMMANDS.md](docs/architecture/PANEL_AGENT_COMMANDS.md)
- [docs/architecture/MODULE_DEPENDENCIES.md](docs/architecture/MODULE_DEPENDENCIES.md)
- [docs/architecture/LIBRARY_REFERENCE.md](docs/architecture/LIBRARY_REFERENCE.md)
- [docs/modules/MODULE_INDEX.md](docs/modules/MODULE_INDEX.md)
- [docs/features/STATUS_SYSTEM.md](docs/features/STATUS_SYSTEM.md)
- [docs/features/XML_SYSTEM.md](docs/features/XML_SYSTEM.md)
- [docs/features/WORKSHOP_SYSTEM.md](docs/features/WORKSHOP_SYSTEM.md)
- [docs/features/SCHEDULER_SYSTEM.md](docs/features/SCHEDULER_SYSTEM.md)
- [docs/features/SCHEDULER_ACTIONS.md](docs/features/SCHEDULER_ACTIONS.md)
- [docs/features/USER_API.md](docs/features/USER_API.md)
- [docs/features/CONTAINER_PLATFORM_POC.md](docs/features/CONTAINER_PLATFORM_POC.md)

Additional repository entry points:

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- [docs/PANEL_ARCHITECTURE.md](docs/PANEL_ARCHITECTURE.md)
- [docs/AGENT_COMMUNICATION.md](docs/AGENT_COMMUNICATION.md)
- [docs/REPOSITORY_STRUCTURE.md](docs/REPOSITORY_STRUCTURE.md)
- [docs/COMMAND_FLOW.md](docs/COMMAND_FLOW.md)
- [docs/UPDATE_POLICY.md](docs/UPDATE_POLICY.md)

Additional architecture and decision records:

- [docs/architecture/AI_GSP_ARCHITECTURE.md](docs/architecture/AI_GSP_ARCHITECTURE.md) - legacy filename retained for compatibility
- [docs/decisions/](docs/decisions/)
- [docs/development/GSP_PLATFORM_IMPROVEMENT_REPORT.md](docs/development/GSP_PLATFORM_IMPROVEMENT_REPORT.md)

## Development Workflow

1. Read the docs first.
2. Find the relevant module, agent, or feature page.
3. Inspect code only where the docs point you.
4. Keep changes scoped to the module you are working on.
5. Prefer safe, structured interfaces over ad hoc shell commands.
6. Verify behavior in both Panel and agent paths when lifecycle or content flow is affected.

## Branch Strategy

Use a simple stable vs unstable model:

- `stable` or release branches should only contain verified customer-facing behavior.
- `unstable` or feature branches should hold active work, investigations, and partially complete features.

If the repo uses different branch names in practice, keep the same concept:

- stable = customer-ready
- unstable = in progress

## Stable vs Unstable

Stable code should have:

- accurate lifecycle and status reporting
- working logs
- safe file and FTP access
- working scheduler actions
- working backups and restore paths
- documented Workshop/content flows

Unstable code may exist for:

- experimental features
- partial module migrations
- new XML capabilities
- future improvements that are not yet customer-safe

## Documentation Map

- Architecture: `docs/architecture/`
- Agents: `docs/agents/`
- Games: `docs/games/`
- Modules: `docs/modules/`
- Features: `docs/features/`
- Decisions: `docs/decisions/`
- Development notes: `docs/development/`

## Project Status and Where to Look

- **To-do list (what's next):** [`TODO.md`](TODO.md)
- **Change log (what's done):** [`CHANGELOG.md`](CHANGELOG.md)
- **Active plans and decisions:** [`docs/plans/ACTIVE.md`](docs/plans/ACTIVE.md)
- **Plan index:** [`docs/plans/README.md`](docs/plans/README.md)

### Test environment

- Test panel: `https://dev.isaserver.net/panel/` (dev host, database `panel-testing`).
- Database credentials are kept in the test copy's `includes/config.inc.php` and are not stored in this repository.

### Reinstalling

A completed install blocks anonymous access to `install.php`. Reinstalling requires an admin session and the maintenance gate. A fresh server with no completed install lets the first visitor run the installer.

## Features

**Server control**
- Start, stop and restart game servers from the Game Monitor, with accurate status: `STOPPED`, `STARTING`, `RUNNING`, `CRASHED`.
- Status comes only from the agent's status file for each server. The panel does not check processes or screens itself.
- Game and bot are monitored by the agent scheduler. The game is `RUNNING` only when its process is alive and its game port is answering.
- Crashes are reported with a reason, restarted automatically, and stop after a restart limit.
- Bots (for example BattlEye/BEC) start once the game is running and stop when it stops.

**Configuration**
- Expert mode (per server, on by default): configure the server in its config files. When it is off, the game's options appear in the panel as checkboxes, text boxes and dropdowns, and are written to the config files at start.
- Edit configuration files and startup parameters from the panel.
- Extra startup parameters, CPU priority, and pre-start, post-start, pre-install and post-install commands.

**Files and FTP**
- File manager limited to each server's game folder. Paths are shown relative to that folder.
- FTP over explicit TLS (FTPES), one account per game server, restricted to that server's folder.
- The Game Monitor shows the FTP login under each server, next to its IP and port.

**Content**
- Server content: install mods, add-ons and DLC from Steam, and configure them after install.
- Installs and updates run in a screen session so they survive failures.

**Scheduling**
- Scheduled tasks (cron) for start, stop, restart and update actions.

## Changelog

### Unreleased (2026-10-22 to 2026-10-29)

**Added**
- Game manager on the Windows agent: states, crash restarts with a restart limit, and bot lifecycle.
- Expert mode per server, with the control password hidden unless expert mode is off.
- FTP login and password shown in the Game Monitor, and an FTP users page reading from the panel database.
- FTP over TLS on the Windows agent, with one account per game server.
- File manager jailed to the game folder, with relative paths.
- A feature and todo list in `docs/FEATURES_TODO.md`.

**Changed**
- Games no longer run in screen. Installs and updates still do.
- Game status now comes only from the agent's status file.
- Arma 2 OA log path and config paths are relative to the game folder.
- The control password is no longer shown in the server settings page.

**Fixed**
- Start failing with "Server already running" because of stale screen sessions.
- Stopped servers showing as running after a stop.
- Log view saying "not running" for a running server.
- Scheduler dropping the game monitor when a task was changed, and running it every 5 minutes instead of every 5 seconds.
- Null-value deprecation warnings on the edit page and the control password field.

**Known issues**
- Bots on some servers can exit and restart repeatedly; under investigation.
- The DayZ mod needs its own key files and the correct Steam app ID on each server.
- Restarting a server from the panel can show "not running" while it restarts.
