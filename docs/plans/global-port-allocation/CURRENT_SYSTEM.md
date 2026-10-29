# Current Port Allocation System

## Summary

The Panel already has a configured range/block allocation model. Admins arrange available port ranges per IP and game config in `gsp_arrange_ports`, and `port_increment` controls the spacing between assignable primary ports. In practice, an increment of `10` means the allocator will suggest or select base ports such as `27015`, `27025`, `27035`, and so on.

The important limitation is persistence. The current database records the selected IP and primary/base port for a home in `gsp_home_ip_ports`; it does not explicitly persist a full owned block such as `27015-27024` for that home. Adjacent ports are protected indirectly when the configured increment is large enough and all allocation paths honor that increment.

## Existing Tables And Relationships

### `gsp_remote_servers`

Defined by `modules/server/module.php` and `panel.sql`.

- Primary key: `remote_server_id`.
- Unique key: `(agent_ip, agent_port)`.
- Relevant columns: `agent_ip`, `agent_port`, `ftp_port`, `use_nat`, `ftp_ip`, `firewall_settings`, `display_public_ip`.
- Relationship: one remote server can have multiple rows in `gsp_remote_server_ips`.

### `gsp_remote_server_ips`

Defined by `modules/server/module.php` and `panel.sql`.

- Primary key: `ip_id`.
- Relevant columns: `remote_server_id`, `ip`.
- Relationship: `ip_id` is the per-address allocation scope used by both arranged ranges and assigned home ports.
- Current schema does not define a unique key on `(remote_server_id, ip)` in the repo baseline.

### `gsp_arrange_ports`

Defined by `modules/server/module.php` and `panel.sql`.

- Primary key: `range_id`.
- Unique key: `(ip_id, home_cfg_id)`.
- Relevant columns:
  - `ip_id`: the IP/address where this range is available.
  - `home_cfg_id`: the game config this range is for; `0` is the unspecified/default fallback.
  - `start_port`: first assignable primary/base port.
  - `end_port`: last port in the configured range.
  - `port_increment`: spacing between assignable primary/base ports. This is the current "ports per game" or block-size behavior.

The unique key prevents more than one row for the same `(ip_id, home_cfg_id)`. It does not by itself prevent numeric overlap between different `home_cfg_id` values on the same IP; that is handled in PHP by `addPortsRange()` and `editPortsRange()`.

### `gsp_home_ip_ports`

Defined by `modules/gamemanager/module.php` and `panel.sql`.

- Primary key: `(ip_id, port)`.
- Relevant columns:
  - `ip_id`: assigned IP/address.
  - `port`: selected primary/base port for the home.
  - `home_id`: assigned server home.
  - `force_mod_id`: optional mod override for the address.

This table prevents duplicate primary ports on the same IP. It allows the same primary port to be reused on a different `ip_id`. It does not store `block_start`, `block_end`, pool identity, allocation source, static-service reservations, or per-game offset ownership.

### Related Tables

- `gsp_server_homes.home_id` and `gsp_server_homes.home_cfg_id` identify the provisioned server and its game config.
- `gsp_config_homes.home_cfg_id` maps a game config to `game_key`, `game_name`, and `home_cfg_file`.
- `gsp_config_mods.home_cfg_id` and `gsp_game_mods.home_id` attach mod profiles and startup defaults.
- Billing rows such as `gsp_billing_services.home_cfg_id` and `gsp_billing_orders.home_id` drive provisioning, then resolve to `gsp_server_homes` and `gsp_home_ip_ports`.

## How `gsp_arrange_ports` Works Today

`modules/server/arrange_ports.php` lets an admin choose a remote server IP, game config, start port, end port, and increment. The UI label is `port_increment`; language strings describe this as "Port Increment" and show total/available assignable ports.

`includes/database_mysqli.php::addPortsRange()` validates:

- start, end, and increment are present;
- none of those values is `0`;
- `start_port <= end_port`.

It then loads all existing ranges for the same `ip_id`, regardless of game config. It expands every integer between each existing `start_port` and `end_port` and rejects the new range if any integer overlaps. This means duplicate or overlapping numeric ranges are blocked per IP, not merely per `(ip_id, home_cfg_id)`.

`editPortsRange()` performs the same overlap check while skipping the row being edited.

The stored `port_increment` is not used for overlap detection between ranges. It is used later to determine assignable primary/base ports inside the range.

Example: a range `27015-27104` with increment `10` provides primary allocations `27015`, `27025`, `27035`, `27045`, `27055`, `27065`, `27075`, `27085`, and `27095`. If every game uses offsets inside `+0..+9`, this behaves like 10-port blocks even though the database does not store each full block.

## How `gsp_home_ip_ports` Works Today

`gsp_home_ip_ports` records one assigned IP and primary/base port per row. Current code uses it as the compatibility source for:

- Game Monitor and lifecycle controls;
- Start/Stop/Restart ownership checks;
- startup command generation;
- firewall allow/deny around the primary and query ports;
- billing provisioning retry checks;
- display of IP:port in customer/admin views.

`includes/database_mysqli.php::addGameIpPort()` inserts only `(ip_id, port, home_id)`. The primary key `(ip_id, port)` rejects duplicate primary ports on the same IP.

The table does not record:

- the range or pool that was used;
- the configured increment at allocation time;
- `block_start` or `block_end`;
- secondary ports reserved by XML offsets;
- static service reservations;
- why a fallback range was chosen.

## Manual/Admin Allocation Flow

There are two admin/manual paths:

1. `modules/user_games/add_home.php` creates a `server_homes` row, assigns panel access, registers a billing order through `modules/user_games/billing_integration.php`, and invokes `modules/billing/create_servers.php` to provision the IP/port and install path. This means many admin-created homes now use the same allocation function as billing-created homes.
2. `modules/user_games/edit_home.php` still lets an admin manually add or remove IP/port rows. It calls `getNextAvailablePort($ip_id, $home_cfg_id)` to suggest one port, then writes the selected value with `addGameIpPort()`.

`getNextAvailablePort()`:

- loads the first `gsp_arrange_ports` row for the exact `home_cfg_id`;
- falls back to a `home_cfg_id=0` row on the same IP;
- if neither exists, falls back to a legacy hardcoded range `27015-39915` with increment `100`;
- loads used primary ports from `gsp_home_ip_ports` for that `ip_id`;
- returns the first range value, stepping by `port_increment`, whose primary port is not already used.

The legacy fallback is not a global-pool design and should be replaced during future implementation, but it must be documented because it exists today.

## Billing Allocation Flow

`modules/billing/create_servers.php::billing_allocate_home_port()` is the active provisioning allocator for billing-created homes and billing-backed admin-created homes.

It:

- loads all `ip_id` values for the selected `remote_server_id` from `gsp_remote_server_ips`;
- for each IP, queries `gsp_arrange_ports` for the exact `home_cfg_id`, ordered by `range_id`;
- falls back to `home_cfg_id=0` rows if no exact rows exist for that IP;
- loads used primary ports from `gsp_home_ip_ports` for that same `ip_id`;
- scans from `start_port` to `end_port` by `port_increment`;
- inserts one `(ip_id, port, home_id)` row into `gsp_home_ip_ports` using a `WHERE NOT EXISTS` guard against a concurrent duplicate primary allocation;
- verifies the inserted row before returning success.

This correctly allocates from configured per-IP ranges and honors configured increments. It still persists only the selected primary/base port.

## XML Query, RCON, And Reserve-Port Behavior

`modules/config_games/schema_server_config.xml` supports:

- `<query_port type="add|subtract">N</query_port>` for query-port calculation;
- `<reserve_ports><port type="add|subtract" id="TOKEN" cli_string="...">N</port></reserve_ports>` for secondary startup tokens;
- CLI token IDs including `PORT`, `QUERY_PORT`, `IP`, `PLAYERS`, and `CONTROL_PASSWORD`.

`modules/gamemanager/home_handling_functions.php::get_query_port()` calculates query ports by adding or subtracting the XML value from the assigned primary port. For LGSL metadata, the code may call `lgsl_port_conversion()` to derive query ports. TeamSpeak uses a hardcoded query port path in lifecycle/status helpers.

`modules/gamemanager/mini_start.php` and `home_handling_functions.php::get_start_cmd()` build startup commands from the assigned `gsp_home_ip_ports.port`:

- `%PORT%` comes from the assigned primary/base port.
- `%QUERY_PORT%` can come from XML `<query_port>`, LGSL conversion, or TeamSpeak handling.
- `<reserve_ports>` entries replace custom tokens such as `%RCON_PORT%`, `%WEB_ADMIN_PORT%`, `%STEAM_PORT%`, `%QUERY_SSH_PORT%`, and `%FILETRANSFER_PORT%` by adding or subtracting from the assigned primary/base port.

Representative examples from current XML:

- `dayz_win64.xml`: query `+6`, RCON reserve `+3`.
- `rust_linux64.xml`: query reserve `+1`, RCON reserve `+2`.
- `rust_win64.xml`: query reserve `+10`, which does not fit a normal 10-port `+0..+9` block and needs review.
- `minecraft_linux64.xml`: query `+0`, RCON reserve `+1`.
- `arkse_linux64.xml`: query reserve `+1`, RCON reserve `+2`.
- `killingfloor2_linux64.xml`: web admin `+10`, query `+19238`, Steam `+12783`, making it non-standard for a 10-port block.
- `teamspeak3_linux64.xml`: query `+24`, query SSH `+2`, file transfer `+3`, making it service-specific rather than a normal game block.

Current XML offsets influence startup and status behavior, but they are not normalized into database allocation records.

## What The Current System Already Does Correctly

- Tracks allocation per `ip_id`.
- Allows the same primary/base port to be reused on different IPs.
- Lets admins configure range start/end per IP.
- Supports game-specific arranged ranges through `home_cfg_id`.
- Supports an unspecified fallback range through `home_cfg_id=0`.
- Supports configurable per-game spacing through `port_increment`.
- Avoids duplicate primary allocations within the configured range on the same IP.
- Blocks overlapping configured numeric ranges on the same IP in PHP.
- Provides a compatibility source, `gsp_home_ip_ports`, that lifecycle, monitor, firewall, billing, and startup paths already use.

## Current Shortcomings Relative To The Desired Model

- Ranges are arranged per IP and game config instead of being reusable global pool definitions.
- Multiple games that should share a default-port pool are hard to model without repeating ranges.
- Overlapping or natural default-port neighborhoods cannot be managed globally across game configs.
- Static service reservations are not first-class allocation or reservation records.
- Overflow/default pool behavior is not formalized; one admin path still has a hardcoded `27015-39915` increment-`100` fallback.
- Per-game offsets for query, RCON, web, Steam, peer, admin, or custom ports are XML/runtime concepts, not normalized database rows.
- Full block ownership is not explicitly stored if the current system only records selected primary ports.
- Allocation records do not store source (`preferred`, `compatible`, `overflow`, `manual`, `legacy/imported`) or review status.
- Existing range overlap checks are PHP-only and do not provide a database-level no-overlap constraint for blocks or service reservations.

## Compatibility To Preserve

- Keep `gsp_home_ip_ports` as the primary compatibility write until lifecycle and display code paths migrate.
- Keep `gsp_arrange_ports` through a compatibility/migration period; do not destructively replace it.
- Preserve per-IP reuse: the same block can be used on different `ip_id` values.
- Preserve existing homes even when their assigned primary ports do not align to the future 10-port block policy; import them as `legacy/imported` or `needs-review`.
- Keep Start, Stop, Restart, Console, Logs, Scheduler, File Manager, Config, Server Content, and billing retry flows resolving an IP and primary port exactly as they do today until migrated.
