# Panel Game Coupling Audit for Container Product Refactor

Date: 2026-08-01

Workflow step: Step 01 - Audit current panel architecture and game-specific coupling

## Scope

This audit covers the Panel repository only. It documents the current product model from source evidence and classifies every major place where the Panel assumes that the sold product is a game server rather than a private isolated container with an independent resource plan, location, assigned TCP/UDP port block, persistent storage, and installable application templates.

The current architecture remains a control plane plus execution engines:

1. User action in the Panel.
2. Panel module builds a request.
3. Panel remote communication layer sends it to an agent.
4. Agent executes the request and returns status or output.
5. Panel updates UI and database state.

Source: `docs/ARCHITECTURE.md`, `docs/PANEL_ARCHITECTURE.md`, `docs/AGENT_COMMUNICATION.md`, and `docs/COMMAND_FLOW.md`.

## Current Deployment Flow

Customer storefront provisioning is game-server first:

- `modules/billing/order.php:62` describes the page as an order flow for a gameserver.
- `modules/billing/order.php:144` loads a service from `billing_services` joined to `config_homes`.
- `modules/billing/order.php:232` derives the selected service OS from game XML metadata.
- `modules/billing/order.php:287` labels the deployable as `Game Server Name`.
- `modules/billing/order.php:292` lists locations from `billing_services.remote_server_id`.
- `modules/billing/order.php:341` configures the purchase with a `Player Slots` slider and months.
- `modules/billing/add_to_cart.php:205` validates the selected service by joining `billing_services` to `config_homes`.
- `modules/billing/add_to_cart.php:216` clamps `max_players` to service slot bounds.
- `modules/billing/add_to_cart.php:232` treats `billing_orders.ip` as the selected `remote_server_id`.
- `modules/billing/add_to_cart.php:297` prices the invoice as rate per player times players times quantity.
- `modules/billing/api/capture_order.php:348` invokes `modules/billing/create_servers.php` after payment capture.
- `modules/billing/create_servers.php:733` reads active `billing_orders` rows as the provisioning queue.
- `modules/billing/create_servers.php:829` loads service, game config, and mod config.
- `modules/billing/create_servers.php:844` resolves `home_cfg_id`, `mod_cfg_id`, `home_cfg_file`, game key, node OS, FTP flag, install method, manual URL, and access rights.
- `modules/billing/create_servers.php:1037` hard-codes new provisioned homes under `/home/gameserver/`.
- `modules/billing/create_servers.php:1048` creates the deployable with `addGameHome`.
- `modules/billing/create_servers.php:1076` allocates an IP and port.
- `modules/billing/create_servers.php:1095` adds a game mod and writes `max_players`.
- `modules/billing/create_servers.php:1171` triggers game file installation through `gamemanager_trigger_update_install`.

Admin-side manual creation follows the same model:

- `modules/user_games/add_home.php:36` labels the workflow as adding a new game home.
- `modules/user_games/add_home.php:65` requires `home_cfg_id`, `remote_server_id`, and a user.
- `modules/user_games/add_home.php:89` builds a path from `{USERNAME}`, `{GAMEKEY}`, and the game config.
- `modules/user_games/add_home.php:139` creates remote management and game-root directories.
- `modules/user_games/add_home.php:172` initializes `max_players`.
- `modules/user_games/add_home.php:202` renders remote-server and game-type selection UI.

## Current Billing and Resource Model

The commercial model is invoice-first, but the resource being sold is a game service with player slots:

- `panel.sql:372` defines `gsp_billing_invoices` with `service_id`, `home_name`, `ip`, `max_players`, passwords, amount, duration, and quantity.
- `panel.sql:405` defines `gsp_billing_orders` with `service_id`, `home_name`, `ip`, `max_players`, `home_id`, and order status.
- `panel.sql:434` defines `gsp_billing_services` with `home_cfg_id`, `mod_cfg_id`, service name, comma-separated `remote_server_id`, slot minimum/maximum, slot price, FTP, install method, manual URL, and access rights.
- `modules/user_games/billing_integration.php:32` documents `max_players` as the slot count for admin-created billing orders.
- `docs/modules/billing.md` describes billing as commercial billing, provisioning, invoices, orders, transactions, coupons, and gateway integration, with an explicit known need for stronger provisioning lifecycle testing.

There is no source-backed schema for independent plan RAM, burst memory, storage quota, storage add-ons, RAM add-ons, port add-ons, container image/template selection, or customer-selected port blocks.

## Current Database Model

Primary game/catalog tables:

- `panel.sql:486` `gsp_config_homes`: `home_cfg_id`, `game_key`, `game_name`, and `home_cfg_file`.
- `panel.sql:499` `gsp_config_mods`: game config to mod config mapping.
- `panel.sql:514` `gsp_game_mods`: per-home mod install parameters including `max_players`, extra params, CPU affinity, and nice level.
- `includes/database_mysqli.php:894` imports XML game configs into `config_homes` and `config_mods`.

Primary instance tables:

- `panel.sql:819` `gsp_server_homes`: `home_id`, `remote_server_id`, `user_id_main`, `home_path`, `home_cfg_id`, `home_name`, control password, FTP fields, last params, custom fields, and expiration.
- `includes/database_mysqli.php:2576` creates a home through `addGameHome` and appends the generated `home_id` to `home_path`.
- `includes/database_mysqli.php:2598` reads a home through `getGameHome` by joining `server_homes`, `remote_servers`, `config_homes`, `game_mods`, and `config_mods`.
- `includes/database_mysqli.php:2847` deletes a game home and dependent game tables, IP ports, MySQL rows, and status cache.

Primary node and network tables:

- `panel.sql:703` `gsp_remote_servers`: agent endpoint, FTP endpoint, encryption key, agent user, and display IP.
- `panel.sql:725` `gsp_remote_server_ips`: public IPs per remote server.
- `panel.sql:532` `gsp_home_ip_ports`: per-home IP and primary port mappings.

## Current Port Allocation

The newer global allocator already introduces reusable ideas, but its selection model is still game-config based:

- `panel.sql:178` `gsp_arrange_ports`: legacy ranges by `ip_id` and `home_cfg_id`.
- `panel.sql:193` `gsp_port_pools`: reusable pools with range, block size, purpose, and priority.
- `panel.sql:216` `gsp_port_pool_games`: maps port pools to `home_cfg_id`.
- `panel.sql:236` `gsp_game_port_offsets`: maps game config to additional port roles and offsets.
- `panel.sql:258` `gsp_static_port_reservations`: reserves host/service ports.
- `panel.sql:281` `gsp_home_port_blocks`: assigns a whole block to a home, with primary port and allocation status.
- `includes/port_allocator.php:80` loads game context from `config_homes`.
- `includes/port_allocator.php:161` chooses default primary ports from hard-coded game names and protocols.
- `includes/port_allocator.php:295` selects pool candidates through `port_pool_games.home_cfg_id`.
- `includes/port_allocator.php:327` falls back to legacy `arrange_ports`.
- `includes/port_allocator.php:391` checks conflicts against assigned blocks, legacy home ports, and static reservations.
- `includes/port_allocator.php:445` checks required game port offsets.
- `includes/port_allocator.php:484` finds a candidate block for `remote_server_id` and `home_cfg_id`.
- `modules/billing/create_servers.php:410` calls the global allocator with `home_id`, `remote_server_id`, and `home_cfg_id`.
- `modules/billing/create_servers.php:451` contains the legacy allocator that queries `arrange_ports` by `ip_id` and `home_cfg_id`.

Reusable capability: the `home_port_blocks` ledger, static reservations, conflict checking, event history, and block-size concepts are directly useful for containers.

Gap: the customer flow does not expose available block selection, and allocator inputs are game config IDs instead of location, plan, app template requirements, and requested TCP/UDP block size.

## Current Agent and Lifecycle Integration

The remote layer is generic transport wrapped around game-server commands:

- `includes/lib_remote.php:249` retrieves logs by `home_id` and `home_path`.
- `includes/lib_remote.php:283` sends `stop_server`.
- `includes/lib_remote.php:415` runs Steam update flows.
- `includes/lib_remote.php:652` sends `universal_start` with game home, game binary, run dir, startup command, IP, port, CPU affinity, nice level, game key, and console-log settings.
- `includes/lib_remote.php:737` sends `server_status` with IP, port, query port, RCON port, and control metadata.
- `includes/lib_remote.php:795` clones and removes home paths.
- `includes/lib_remote.php:836` sends `restart_server`.
- `includes/lib_remote.php:880` sends force stop.
- `includes/lib_remote.php:1008` compresses files.
- `includes/lib_remote.php:1344` calls Steam Workshop integration.
- `modules/gamemanager/home_handling_functions.php:41` computes query and control ports from XML before asking the agent for status.
- `modules/gamemanager/home_handling_functions.php:231` builds startup command lines from XML variables.
- `modules/gamemanager/home_handling_functions.php:407` executes start, stop, restart, and firewall behavior for a game home.
- `modules/gamemanager/update_actions.php:18` installs or updates server files using game XML, mod installer definitions, SteamCMD, and optional pre/post commands.

Reusable capability: encrypted agent RPC transport, node registry, log retrieval, file operations, status cache, lifecycle result wrapping, and background operation tracking.

Gap: there is no Panel-side Docker/container lifecycle API, no container resource limit model, no volume/storage model, no image/template deployment contract, and no capacity-aware scheduler for RAM/storage/ports.

## Current Application and Game Catalog Behavior

The catalog is XML game config, not application template metadata:

- `docs/features/XML_SYSTEM.md` states XML controls game installer names, startup command templates, CLI parameter substitution, reserved ports, query calculation, control protocol, mods, Workshop capabilities, custom fields, server params, and text replacement helpers.
- `modules/config_games/server_config_parser.php` parses those XML files for runtime behavior.
- `modules/gamemanager/home_handling_functions.php:231` substitutes variables such as `IP`, `PORT`, `PLAYERS`, `HOME_PATH`, `SAVE_PATH`, and `CONTROL_PASSWORD`.
- `modules/gamemanager/update_actions.php:87` resolves mod XML and installer names before installation.
- `modules/gamemanager/mini_start.php:79` checks for the configured game executable before rendering startup controls.
- `modules/gamemanager/rcon.php:28` sends RCON commands based on XML protocol rules and special cases.
- `modules/billing/docs/` contains per-game customer documentation pages.

Reusable capability: a catalog-driven capability definition layer is useful for installable templates.

Required product change: application templates must not generate game startup scripts or rewrite application configuration. XML startup parameter builders, `replace_texts`, game query metadata, player commands, and game docs links should be legacy-game compatibility, not the new template contract.

## Current UI Flows

Game-specific UI surfaces are broad:

- `modules/billing/order.php` storefront service page: game server name, location, player slots, months.
- `modules/gamemanager/server_monitor.php:175` lists game homes and filters by `home_cfg_id`.
- `modules/gamemanager/server_monitor.php:302` renders server name, IP and port, owner, and expiration.
- `modules/gamemanager/server_monitor.php:408` renders game icons.
- `modules/gamemanager/server_monitor.php:513` renders game connect URLs through LGSL, TeamSpeak, or Steam protocol behavior.
- `modules/gamemanager/mini_start.php:118` renders max-player controls and game map options.
- `modules/user_games/edit_home.php:50` allows changing the game type by changing `home_cfg_id` and deleting mod rows.
- `modules/user_games/home_mods.php:112` displays max-player fields for mods.

Gap: there is no first-class UI for choosing a resource plan, location capacity, customer-selected available TCP/UDP port block, persistent storage size, or post-deploy application templates.

## File Manager, FTP, Content, Backups, and Console

File manager and FTP are mostly reusable after root semantics change:

- `includes/game_path_helpers.php:96` derives `game_root` as management home plus validated `game_key`.
- `includes/game_path_helpers.php:148` restricts payload paths to the game root unless an absolute path is still inside the management home.
- `modules/litefm/litefm.php:83` attaches a game root from XML and uses it as the payload root.
- `modules/user_games/add_home.php:139` creates remote management and game-root directories, then points FTP at the game root.
- `modules/user_games/edit_home.php:113` manages FTP login/password/status and home path changes.

Server Content and Workshop are game-specific but contain reusable manifest and background-job patterns:

- `modules/addonsmanager/addons_manager.php:5` describes Server Content for game servers.
- `modules/addonsmanager/addons_manager.php:51` creates add-ons tied to `home_cfg_id`.
- `modules/addonsmanager/addons_manager.php:100` requires a selected game type and Workshop support from game XML.
- `modules/addonsmanager/addons_manager.php:235` documents install paths relative to the game server home and variables such as `%home_path%`, `%home_name%`, `%control_password%`, and `%max_players%`.
- `modules/addonsmanager/server_content_helpers.php:23` creates Workshop/content tables with `home_id`, `home_cfg_id`, `remote_server_id`, and catalog `game_key`.
- `modules/addonsmanager/server_content_actions.php:109` writes per-home manifests with `home_id`, `home_cfg_id`, and `remote_server_id`.

Backup is not safe as a reusable container backup implementation:

- `modules/backup-restore/backup-restore.php:18` marks the feature as not working/test.
- The module uses hard-coded backup roots and external commands rather than a plan-aware persistent storage or snapshot contract.

Console behavior is game-process oriented:

- `includes/lib_remote.php:249` fetches logs by screen/log context.
- `modules/gamemanager/home_handling_functions.php:407` uses game process lifecycle and console-log settings.

## Tests and Validation Surface

Existing tests cover selected compatibility helpers rather than the future container model:

- `includes/tests/game_path_helpers_test.php` covers game-root derivation and path containment.
- `includes/tests/lifecycle_contract_helpers_test.php` covers lifecycle payload shape including game root and game ports.
- `includes/tests/lib_remote_lifecycle_wrapper_test.php` covers remote lifecycle wrapper behavior.
- `modules/config_games/tests/validate_server_configs.php` validates XML game configuration files.

Missing tests:

- Billing plan and add-on price calculation.
- Customer port-block selection and conflict locking.
- Container instance creation and state transitions.
- Application template install lifecycle.
- Persistent storage quota and backup/snapshot behavior.
- Migration compatibility between legacy game homes and new container instances.

## Coupling Classification

| Area | Current evidence | Classification | Required action |
| --- | --- | --- | --- |
| Control-plane architecture | `docs/ARCHITECTURE.md`, `docs/COMMAND_FLOW.md` | Keep | Preserve Panel as business-logic authority and agents as executors. |
| Remote server/node registry | `panel.sql:703`, `panel.sql:725` | Refactor | Rename conceptual role to location/node capacity while preserving agent endpoint data. |
| Agent RPC transport | `includes/lib_remote.php` | Keep | Reuse transport and auth, add container-specific commands. |
| Game lifecycle commands | `includes/lib_remote.php:652`, `modules/gamemanager/home_handling_functions.php:407` | Replace | Introduce container lifecycle operations and leave game process lifecycle as legacy. |
| `server_homes` instance table | `panel.sql:819`, `includes/database_mysqli.php:2576` | Refactor | Migrate toward container instances or a compatibility view; keep `home_id` only as transition identity. |
| Game catalog XML | `docs/features/XML_SYSTEM.md`, `panel.sql:486` | Replace | Introduce application template metadata. Keep XML only for legacy game support. |
| Game mods | `panel.sql:499`, `panel.sql:514` | Replace | Model application/template installations instead of mods and max-player params. |
| Billing invoices and transactions | `panel.sql:372`, `docs/modules/billing.md` | Keep | Reuse payment lifecycle, change line-item semantics. |
| Billing services and orders | `panel.sql:405`, `panel.sql:434` | Refactor | Split plans, locations, app templates, add-ons, and provisioning requests. |
| Player-slot pricing | `modules/billing/order.php:341`, `modules/billing/add_to_cart.php:297` | Replace | Price by plan, duration, resource add-ons, storage add-ons, and port add-ons. |
| Location selection | `modules/billing/order.php:292` | Refactor | Use explicit location/node capacity, not CSV `remote_server_id` on a game service. |
| Port block ledger | `panel.sql:281`, `includes/port_allocator.php:391` | Keep | Reuse assigned block ledger and static reservation concepts. |
| Game-tied port policies | `panel.sql:216`, `panel.sql:236`, `includes/port_allocator.php:295` | Refactor | Drive required ports from selected app template and requested port-block plan. |
| Legacy arrange ports | `panel.sql:178`, `modules/billing/create_servers.php:451` | Replace | Do not use as primary allocator for containers. |
| File manager root safety | `includes/game_path_helpers.php`, `modules/litefm/litefm.php:83` | Refactor | Change game root to container persistent storage root and app roots. |
| FTP management | `modules/user_games/edit_home.php:113` | Refactor | Keep as optional access method to persistent storage, not game root. |
| Server Content manifests | `modules/addonsmanager/server_content_actions.php:109` | Refactor | Reuse manifest/job pattern for application templates. |
| Steam Workshop | `modules/steam_workshop/functions.php`, `modules/addonsmanager` | Remove from primary model | Leave as app-specific legacy capability only. |
| RCON/player commands | `modules/gamemanager/rcon.php` | Remove from primary model | Keep only for legacy game templates. |
| Game connect/query UI | `modules/gamemanager/server_monitor.php:513` | Remove from primary model | Container UI should show exposed ports, app status, and logs. |
| Backup module | `modules/backup-restore/backup-restore.php:18` | Replace | Build plan-aware persistent storage snapshots/backups. |
| Hard-coded game path | `modules/billing/create_servers.php:1037` | Remove | Use container storage allocation and node policy. |

## Reusable Components

- Payment, invoice, transaction, coupon, and renewal plumbing in `modules/billing`.
- Panel-owned provisioning queue concept from `billing_orders`, after schema semantics are changed.
- Agent registry and encrypted RPC transport in `remote_servers`, `remote_server_ips`, and `includes/lib_remote.php`.
- User/group access-right mapping through `user_homes` and `user_group_homes`, after naming and instance identity are updated.
- Port block assignment ledger, static reservations, conflict checks, and event table from global port allocation.
- File-manager path containment concepts from `includes/game_path_helpers.php`.
- Server Content manifest/job orchestration pattern, after decoupling from game XML and Workshop.
- Status cache and operation result wrappers, after introducing container-specific state.

## First-Slice Gaps to Close Before Implementation

1. Define new schema for plans, add-ons, locations, container instances, persistent volumes, app templates, app installations, and port-block requests.
2. Create compatibility mapping from legacy `home_id`/`server_homes` to future container instance identity.
3. Split billing service catalog from provisioned instance state.
4. Replace slot-price calculation with plan/add-on pricing.
5. Replace `billing_orders.ip` overload with explicit location or node identifiers.
6. Replace `home_cfg_id` as allocator input with location, app template requirements, and requested TCP/UDP block.
7. Add customer UI for plan, location, available port block, storage, and application template installation.
8. Add Panel-side container lifecycle service boundaries before touching agent protocol.
9. Keep legacy game-server flows operational until migration tooling exists.
10. Add tests around billing price calculation, port-block reservation, and instance provisioning state transitions.

## Architecture Decision for the Next Step

The safe first implementation slice should not rename every legacy table or route. It should introduce explicit container product tables and services beside the legacy game-server model, then bridge only the minimum necessary legacy identifiers for Panel compatibility. This preserves existing game-server customers while allowing new container deployments to use independent resource plans, selected locations, selected port blocks, persistent storage, and app templates.
