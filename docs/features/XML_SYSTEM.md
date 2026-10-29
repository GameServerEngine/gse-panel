# XML Game Configuration System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

The XML game configuration system describes how a game server should be started, queried, and customized.

Primary files:

- `modules/config_games/schema_server_config.xml`
- `modules/config_games/server_config_parser.php`
- `modules/config_games/xml_config_creator.php`
- `modules/config_games/config_servers.php`
- `modules/config_games/cli-params.php`
- `modules/config_games/set_params.php`

## What XML Controls

The schema supports:

- game and installer names
- startup command templates
- CLI parameter substitution
- reserved ports
- query port calculation
- control protocol selection
- mod definitions
- Workshop / Server Content capability declarations
- custom fields
- server parameter groups
- text replacement helpers

## Important Variables

The schema and startup builder can work with variables such as:

- `GAME_TYPE`
- `HOSTNAME`
- `IP`
- `MAP`
- `PID_FILE`
- `PLAYERS`
- `PORT`
- `QUERY_PORT`
- `BASE_PATH`
- `HOME_PATH`
- `SAVE_PATH`
- `OUTPUT_PATH`
- `USER_PATH`
- `CONTROL_PASSWORD`

## Startup Parameters

The Panel builds startup parameters from the XML template and the stored server configuration.

Key concepts:

- `cli_template`
- `cli_params`
- `reserve_ports`
- `server_params`
- `custom_fields`
- `clean_server_param_value`

The XML file defines:

- which parameters exist
- how they are quoted or spaced
- whether the parameter is editable by the customer
- what defaults should be used

## Query Definitions

The XML schema supports query-related concepts such as:

- `gameq`
- `lgsl`
- `teamspeak3`
- query port offset calculations
- control protocol selection

These values are used by `gamemanager` and the agent status logic to calculate query metadata, not to decide online/offline by themselves.

## Installation and File Editing

XML definitions also feed:

- config file shortcuts
- install-time behavior
- docs links
- reserved ports
- mod or content behavior

For current game-server homes, `server_homes.home_path` is the server-ID
management home and the game payload is installed below
`management_home/<validated game_key>`. XML-driven executable paths, config
replacement files, map and mission paths, mod locations, and Workshop install
paths are resolved relative to that game payload root unless an absolute
admin-defined path is explicitly supplied and validated by the caller.

## Workshop / Server Content Capability

Workshop-enabled games must use the canonical `workshop_support` block. The game XML is the source of truth for Steam Workshop capability and runtime behavior. Loose top-level tags such as `workshop_app_id` must not be used in new game XML because schema validation is intentionally strict.

The `workshop_support` block is a capability declaration only. It does not install mods by itself and it does not create an agent-side Workshop subsystem. Server Content Manager reads these values, writes a per-server manifest, writes a generated per-job script, and calls the agent's existing generic execution primitives.

Example:

```xml
<workshop_support>
  <enabled>1</enabled>
  <provider>steam</provider>
  <steam_app_id>107410</steam_app_id>
  <workshop_app_id>107410</workshop_app_id>
  <download_method>steamcmd</download_method>
  <install_strategy>arma_mod_folder</install_strategy>
  <install_path>{SERVER_ROOT}/{MOD_FOLDER}</install_path>
  <startup_param_format>-mod={MOD_LIST}</startup_param_format>
  <mod_separator>;</mod_separator>
  <mod_prefix>@</mod_prefix>
  <copy_keys enabled="1">
    <source_pattern>{MOD_PATH}/keys/*.bikey</source_pattern>
    <target_path>{SERVER_ROOT}/keys</target_path>
  </copy_keys>
  <post_install_action></post_install_action>
</workshop_support>
```

Supported `install_strategy` values:

- `game_managed_workshop`
- `steamcmd_download_only`
- `copy_to_game_root`
- `copy_to_mod_folder`
- `dayz_mod_folder`
- `arma_mod_folder`
- `config_only`
- `custom_scripted_install`

`workshop_app_id` is the Steam Workshop app ID used by `steamcmd +workshop_download_item`. It is not automatically the same as a dedicated server installer app ID. For Arma 3, Workshop content uses `107410` while the dedicated server installer remains defined on the normal mod installer entry.

`post_install_action` is reserved for a safe admin-owned post-install action identifier or template name. It is not customer input and must not be treated as an arbitrary shell command.

Ordering rule:

- `workshop_support` belongs after `game_name` and before `server_exec_name` in the current schema sequence.
- New XML files should not add top-level Workshop tags.
- New XML files should not define static agent script paths. Server Content Manager stages generated per-job scripts under the server home and invokes them through generic agent execution.
- If `install_path` is omitted, Server Content Manager defaults to `{SERVER_ROOT}/workshop/{MOD_FOLDER}` or `{SERVER_ROOT}/{MOD_FOLDER}` for DayZ/Arma strategies.

The current XML schema is validated by:

```bash
php modules/config_games/tests/validate_server_configs.php
```

## Recommended Mental Model

Think of the XML system as the capability definition layer:

```text
game XML
  -> startup template
  -> parameter rules
  -> query rules
  -> content/mod hooks
  -> docs links
  -> scheduler and status hints
```
