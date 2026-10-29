# Gameserver Engine Panel Admin Guide

This document can be viewed directly from the repository, packaged with releases, or imported into another wiki. It explains how to deploy, operate, and extend the Gameserver Engine Panel.

## Overview

- **Project scope** – PHP web panel + billing, Linux and Windows agents, 100+ XML driven game templates.
- **Audience** – Administrators and integrators who maintain commercial hosting fleets.
- **Companion repos** - [`Agent-Linux`](https://git.runlevelsystems.com/GSE/Agent-Linux) and [`Agent-Windows`](https://git.runlevelsystems.com/GSE/Agent-Windows).
- **Color coding** – We keep the “rust + charcoal” palette across documentation so screenshots match the UX.

## Quick Install

The repository ships `scripts/install-panel.sh`, a Debian/Ubuntu-first installer for new Panel deployments.

```bash
curl -fsSL https://git.runlevelsystems.com/GSE/Panel/raw/branch/unstable/scripts/install-panel.sh \
    -o /tmp/install-panel.sh
sudo bash /tmp/install-panel.sh
```

Key behaviors:

1. Installs OS packages for the selected web server and database server.
2. Downloads and extracts the selected Panel release ZIP archive into the selected install path.
3. Creates the Panel database/user and invokes the shared PHP install engine.
4. Writes `includes/config.inc.php`, installs modules, creates the initial admin account, and repairs permissions.

## Production Updates

For an installed Panel path, use the stable release ZIP plus the CLI post-update runner:

```bash
sudo bash scripts/install-panel.sh --channel stable --path /var/www/html/panel
php tools/post_update_cli.php
sudo bash scripts/install-panel.sh --repair-permissions --path /var/www/html/panel --owner "$USER" --group www-data
```

Use `--channel unstable` for development/test installs, or `--release-zip-url URL` for a controlled custom archive test. For `/var/www/html/panel-test`, repair permissions with `--owner www-data --group www-data`, directories mode `2775`, files mode `664`, and deploy user `dev` in the `www-data` group. The archive update preserves `includes/config.inc.php` and runtime directories such as `upload/`, `cache/`, `log/`, and `temp/`, avoiding Git ownership and `safe.directory` issues in web roots. The post-update runner applies module `db_version` migrations and existing cleanup hooks. Reload PHP or the web server if the host uses opcode caching or long-running PHP workers.

## Architecture

GSP is still built around the classic GSP topology:

1. **Web Panel** – PHP application with billing, coupons, invoicing, and customer UI enhancements.
2. **Agents** – Lightweight Perl daemons (`gsp_agent.pl`) installed on every game host. Default port 12679/TCP.
3. **Game Servers** – Processes defined in `modules/config_games/server_configs/*.xml`. Agents launch them inside detached GNU screen sessions, capture PIDs, and stream console logs back to the panel.

All provisioning logic flows through XML definition files, so keep IDs, attributes, and order compliant with `modules/config_games/schema_server_config.xml`.

## Agent Management

### Linux Agent

1. Install dependencies: `sudo apt install git curl rsync perl libxml2-utils screen` (Ubuntu 24.04+).
2. Clone and install:
   ```bash
   sudo git clone https://git.runlevelsystems.com/GSE/Agent-Linux.git /opt/gsp-agent
   cd /opt/gsp-agent
   sudo bash install.sh
   sudo bash agent_conf.sh -s "yourRootPassword" -u gsp_agent
   ```
3. Edit `/home/gsp_agent/Cfg/Config.pm` to match the panel entry (listen ip/port, `key`, `web_api_url`).
4. Enable the service: `sudo systemctl enable --now gsp_agent` (installs from `systemd/gsp_agent.service`).
5. Confirm heartbeats from the panel → Administration → Game Servers.

### Windows Agent (Cygwin bundle)

1. `git clone https://git.runlevelsystems.com/GSE/Agent-Windows.git C:\\gsp-agent`.
2. Run `Install\onceinstall_agent.bat` as Administrator to install Cygwin, create the `gameserver` service user, and copy files.
3. Launch the bundled Cygwin terminal and execute:
   ```bash
   cd /GSP
   bash agent_conf.sh -p "gameserverPassword"
   ```
4. Update `C:\\GSP\\Cfg\\Config.pm` (same structure as Linux) with the panel’s key, API URL, and listen port.
5. Ensure the “GSP Agent” Windows service is set to Automatic (Delayed Start) and the firewall allows the agent port plus expected game ports.

## XML Deep Dive

Game definitions live under `modules/config_games/server_configs`. They must respect the schema order and content. Keep one tag per line so diffs remain clean.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<game_config>
    <game_key>valheim_linux64</game_key>
    <protocol>lgsl</protocol>
    <lgsl_query_name>valheim</lgsl_query_name>
    <installer>steamcmd</installer>
    <game_name>Valheim</game_name>
    <server_exec_name>start_server.sh</server_exec_name>
    <query_port type="add">1</query_port>
    <cli_template>%HOME_PATH%/start_server.sh -name "%HOSTNAME%" -port %PORT% -world %MAP% %VAR_ALL%</cli_template>
    <cli_params>
        <cli_param id="HOSTNAME" cli_string="-name=" options="q" />
        <cli_param id="PORT" cli_string="-port=" options="sq" />
        <cli_param id="MAP" cli_string="-world=" options="q" />
    </cli_params>
    <reserve_ports>
        <port type="add" id="QUERY_PORT">1</port>
        <port type="add" id="RCON_PORT" cli_string="+rcon.port" options="sq">10</port>
    </reserve_ports>
    <cli_allow_chars>:-_\</cli_allow_chars>
    <maps_location>saves/worlds</maps_location>
    <max_user_amount>10</max_user_amount>
    <control_protocol>rcon2</control_protocol>
    <mods>
        <mod key="default">
            <name>Dedicated</name>
            <installer_name>896660</installer_name>
        </mod>
    </mods>
    <server_params>
        <param key="+server.identity" type="text" id="IDENTITY">
            <default>my_server_identity</default>
            <desc>Sets the Rust identity folder.</desc>
        </param>
    </server_params>
    <commands>
        <command>
            <name>Start</name>
            <execute>./RustDedicated -port {PORT} -ip {IP} {VAR_ALL}</execute>
        </command>
    </commands>
    <environment_variables>
        export LD_LIBRARY_PATH=$LD_LIBRARY_PATH:{GSP_HOME_DIR}/RustDedicated_Data/Plugins/x86_64
    </environment_variables>
</game_config>
```

### Schema Tag Reference (abridged)

The full human-readable version is provided in [`documentation/xml-notes.md`](./xml-notes.md). Highlights:

- `game_config` – Root node, one per file.
- `game_key` – Unique identifier plus OS suffix (`_linux64`, `_win32`, etc.).
- `protocol`, `lgsl_query_name`, `gameq_query_name` – Control how the panel queries live status.
- `installer` – `steamcmd`, `rsync`, `manual`, or custom.
- `cli_template` – Command string appended after the executable; supports `%VARIABLE%` placeholders and `{VAR_ALL}` macros.
- `cli_param` – Defines formatting rules for each variable (space/quote behaviors via the `options` attribute).
- `reserve_ports` – Offsets additional ports relative to `%PORT%` (query, Steam, RCON, etc.).
- `server_params` / `param` – Form inputs shown in the panel UI.
- `replace_texts` – Declarative config edits (search/replace values inside cfg files).
- `commands` – Start/stop/console actions executed by the agent.
- `environment_variables` – Prepend exports before the command runs.

## Adding a New Game

1. **Research** – Identify the executable, required CLI flags, optional parameters, config paths, and ports.
2. **Copy a template** – Duplicate a similar XML file from `server_configs/` and modify it in-place.
3. **Wire up parameters** – Use `server_params` for every knob you want exposed, and map those IDs inside `cli_params`.
4. **Validate** – Run `xmllint --schema modules/config_games/schema_server_config.xml mygame.xml --noout`.
5. **Test** – Upload through the panel, click “Update Games List,” provision a server, and watch `gsp_agent.log` for errors.
6. **Document** – Update this guide or the wiki with any quirks, Steam app IDs, or non-standard install notes.

## Operational Notes

- **No panelStart wrapper** – The agent handles PID tracking, console logs, and restart logic. Keep commands clean.
- **Logging** – Agents write `gsp_agent.log`, `gsp_agent.pid`, `gsp_agent_run.pid`, and individual `console.log` files.
- **Stats database** – Optional MySQL credentials in `Cfg/Config.pm` feed resource monitoring cron jobs.
- **Password rotation** – Update credentials in `content/staff-passwords.txt` on the WDS website and regenerate secrets in the panel/agents at the same time.

For deeper schema commentary, consult [`documentation/xml-notes.md`](./xml-notes.md) or import the Markdown into your preferred wiki.
