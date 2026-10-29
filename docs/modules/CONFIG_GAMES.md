# Config Games

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

This file is the prompt-facing entry point for the Config Games module. The historical lower-case document remains at [`config_games.md`](config_games.md).

## Purpose

Config Games owns game XML definitions, XML schema validation, startup parameter templates, mod definitions, port metadata, and game capability declarations used by Panel modules.

## Workshop XML Source Of Truth

Steam Workshop support is configured by game XML, not Server Content admin rows.

Canonical structure:

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

Rules:

- `workshop_support` belongs after `game_name` and before `server_exec_name`.
- `workshop_app_id` is required for customer Workshop installs.
- `install_path` defaults to `{SERVER_ROOT}/workshop/{MOD_FOLDER}` unless the install strategy is Arma/DayZ-style, where `{SERVER_ROOT}/{MOD_FOLDER}` is used.
- `post_install_action` is an admin-owned action/template identifier, not customer shell input.
- Static Workshop script paths are not part of the XML contract.
- Server Content Manager generates per-job scripts and calls generic agent execution.

## Validation

Validate all game XML definitions with:

```bash
php modules/config_games/tests/validate_server_configs.php
```

