# GameServer Engine (GSE) Panel

The GSE Panel is the management console and control plane for hosted game servers. It talks to Linux and Windows agents, which run the game servers on the host.

This README is the only documentation for the panel. The old `docs/` folder has been folded into this file and removed.

Status: the `working` branch is in development. `testing` is for verification. Live is updated only by pulling from git, never by editing files on the server.

## What the panel does

Customers manage hosted game servers with the same practical control they would have on their own machine:

- start, stop, restart and monitor servers
- edit config files and startup parameters
- use FTP and file management tools
- install mods, add-ons and Workshop content
- schedule safe automation tasks
- read logs
- get accurate server status
- keep billing, support and provisioning tied to the server lifecycle

## Components

| Component | Where | Notes |
| --- | --- | --- |
| Panel | this repo | PHP 8.3. Loads modules from `modules/`, stores state in the database, talks to agents through `includes/lib_remote.php`. |
| Linux agent | `gse-agent-linux` | Bash and Perl. Runs game servers on Linux hosts. |
| Windows agent | `gse-agent-windows` | Perl and bash under a bundled Cygwin root. See that repo's README. |
| Storefront | `modules/billing` | Customer storefront for Gameservers.World. Lives in this repo, not a separate one. |
| Query libraries | `protocol/lgsl`, `protocol/GameQ` | Game server status queries. See "Game query protocols" below. |

## Features

### Server control
- Start, stop and restart game servers from the Game Monitor, with status `STOPPED`, `STARTING`, `RUNNING` or `CRASHED`.
- Status comes only from the agent's status file for each server. The panel does not check processes or screens itself.
- A game is `RUNNING` only when its process is alive and its game port answers.
- Crashes are reported with a reason, restarted automatically, and stop after a restart limit.
- Bots (for example BattlEye/BEC) start once the game is running and stop when it stops.

### Configuration
- **Expert mode** (per server, on by default). When on, configuration is done in the game's own config files and the panel option fields are hidden. When off, the game's XML options appear as checkboxes, text boxes and dropdowns, and their values are written to the config files at startup.
- Edit configuration files and startup parameters from the panel.
- Extra startup parameters, CPU priority, and pre-start, post-start, pre-install and post-install commands.
- The control password is hidden. It is set in `server.cfg`.

### Files and FTP
- File manager limited to each server's game folder. Paths show relative to that folder.
- FTP over explicit TLS (FTPES), one account per game server, jailed to that server's folder.
- Admin accounts are not tied to one folder.
- The Game Monitor shows the FTP login under each server, next to its IP and port.

### Content
- Server content: install mods, add-ons and DLC from Steam, and configure them after install.
- Mods are install variants set in the XML.
- Installs and updates run in a screen session so they survive failures.

### Scheduling
- Scheduled tasks (cron) for start, stop, restart and update actions.

### Updates
- The Update page has three source rows: Panel, Windows agent and Linux agent. Each row has a git repo URL and a release dropdown, newest first.
- The Panel row shows the installed version, date and branch, and an "update available" indicator. Update Panel installs the selected release.
- Agent lists have per-row checkboxes, check-all, Update Selected and Update All. Agents download the release themselves through an on-demand helper.
- Updates never run at startup. An agent updates only when you ask it to.
- Rollback keeps only the current install. The previous version is the backup, not a history.
- Panel and agent report a protocol version, so the panel can refuse to update an agent it cannot talk to safely.
- The default repo is `git.isaserver.net/workspace/gse-panel`. The URL is a default; you can enter any git repo.

### Game query protocols
- Live status uses LGSL (154 game types locally) and GameQ (114 protocol classes locally).
- Several games use query protocols that are out of date. This is tracked in issue #19.

## Installation and reinstalling

- A completed install blocks anonymous access to `install.php`.
- Reinstalling requires an admin session and the maintenance gate.
- A fresh server with no completed install lets the first visitor run the installer.

## Repository layout

```text
/
  index.php
  home.php
  install.php
  includes/      shared libraries, including lib_remote.php
  modules/       panel modules (administration, server, update, billing, ftp, ...)
  protocol/      LGSL and GameQ query libraries
  sql/           schema and migrations
  themes/
  cron/
  lang/
```

## Development workflow

1. Work on the `working` branch in the dev repo.
2. Keep each change scoped to one module.
3. Run `php -l` on changed files and the module's tests before committing.
4. Push to `working`. Merge to `testing` only when a version works well enough to build.
5. Live is updated by git pull from `working` or `testing`, never by committing on the server.

Stable means customer-ready. Unstable means in progress.

## Test environment

- Test panel: `https://dev.isaserver.net/panel/` (dev host, database `panel-testing`).
- Database credentials are kept in the test copy's `includes/config.inc.php` and are not stored in this repository.

## Planned work

From the feature backlog, grouped by area. Nothing here is a committed date.

**Server content**
- Branch switching for 7 Days to Die Stable and Unstable (`betaname`). Choose per server; switching applies on the next update.
- Steam DLC installed from Steam as an option on the same install.
- Uninstall, with the server's files. Mods do not have this yet.
- Pre-start and post-start commands run by server content, for example backing up the last log and deleting older logs.

**Game manager and start path**
- CPU priority (nice level, per server). The panel saves it, but the game manager does not apply it yet.
- Pre-start and post-start commands in the game manager. The old start path ran them; the current one does not.
- Extra start parameters, shown only in Expert mode.
- Expert mode: write option values into config files at startup when Expert mode is off, beyond the control password.

**FTP**
- An `ftp_users` table with one row per account, for server and admin accounts. Server rows link to it. Remove the old columns from `server_homes` once the links work.
- FTP users page: add, edit, disable and delete, reading from that table.
- Agent FTP account management: the Windows agent's `ftp_mgr` is not fully working. It needs redeploying to the KCWin agent and testing.
- Per-server Windows accounts (for example `server49`), so each game runs as its own user and cannot reach admin files. Needs a permissions design and its own branch.

**Updates and protocols**
- Scheduled updates: issue #16.
- Protocol design for the panel-agent API, including the version handshake: issue #17.
- Game query protocol updates for newer games and out-of-date RCON protocols: issue #19.

**Database**
- Clean install migration: the `expert_mode` column and the FTP changes need to be in the module install step, so a new install gets the same schema as the live panel.

**Later**
- Log rotation as a server-content post-start example.
- Preset profiles of startup parameters, saved per server, once Expert mode works.
- Per-customer ownership of server folders and FTP accounts.
- Customer documentation: which settings are panel options and which are config-file edits.

## Known issues

- File manager heading shows the full path after a full sync. It was reapplied on core and needs a test.
- Deprecation warnings from `str_replace()` on null values (`includes/functions.php`, and similar in the edit page). Cast to string.
- The Monitor FTP row should be checked on every server after each deploy.
- The agent FTP list on the FTP users page only shows while the KCWin agent answers.
- Bots on some servers can exit and restart repeatedly. Under investigation.
- The DayZ mod needs its own key files and the correct Steam app ID on each server.
- Restarting a server from the panel can show "not running" while it restarts.

## Changelog

The running changelog is `CHANGELOG.md` at the repo root.
