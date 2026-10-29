# Config Games

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Game XML definitions, CLI parameter generation, mod definitions, and custom field mapping.

## Current Status

- Production
- Functional

## Dependencies

- XML schema
- XML parser
- gamemanager
- user_games
- file/config editing tools

## Database Tables

- `config_homes`
- `config_mods`

## Agent Interaction

- drives startup command construction
- influences query and control protocol handling

## User Workflow

- select a game definition
- configure startup parameters
- view/edit common CLI values
- work with custom fields

## Admin Workflow

- create/update XML game configs
- define mods, CLI params, ports, and custom fields
- validate XML structure

## Security Concerns

- parameter injection
- shell escaping
- path validation

## Known Issues

- schema is powerful but broad
- some capabilities are encoded implicitly instead of declaratively

## Missing Functionality

- first-class scheduler capability declarations
- richer docs metadata

## Workshop Capability Declarations

The XML schema now supports a first-class `workshop_support` block for Steam Workshop / Server Content Manager metadata. This is the only canonical XML format for Workshop-enabled game configs.

Key fields:

- `enabled`
- `provider`
- `steam_app_id`
- `workshop_app_id`
- `download_method`
- `install_strategy`
- `install_path`
- `startup_param_format`
- `mod_separator`
- `mod_prefix`
- `mod_folder_format`
- `copy_keys`
- `post_install_action`

Validate changes with:

```bash
php modules/config_games/tests/validate_server_configs.php
```

Important rules:

- Place `workshop_support` after `game_name` and before `server_exec_name`.
- Do not add loose top-level tags such as `workshop_app_id`; schema-valid XML must use the canonical block.
- Do not add static Workshop script tags. Server Content Manager generates per-job scripts and uses generic agent execution.
- Do not configure Workshop App ID, target path, mod folder, or launch parameter fields in Server Content admin rows. These values come from this XML block.
- XML declares capability only. Server Content Manager owns the Panel-side install orchestration and uses agents only for generic file/command execution.

## Suggested Future Improvements

- extend XML capability model
- document supported variables and examples more clearly
- tie docs generation into XML

## Recommendation

- Keep / Improve
