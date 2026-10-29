# Server Manager

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

Remote server/node management in the Panel.

## Current Status

- Production
- Administrative

## Dependencies

- remote server records
- port arrangements
- agent connection settings

## Database Tables

- `remote_server_ips`
- `remote_servers`
- `arrange_ports`
- `port_pools`
- `port_pool_games`
- `game_port_offsets`
- `static_port_reservations`
- `home_port_blocks`
- `port_allocation_events`

## Global Port Allocation

Current automatic allocation keeps `home_ip_ports` compatible while adding whole-block tracking.

- New allocations use `includes/port_allocator.php` when the Phase 1 schema is installed.
- Normal blocks reserve `settings.port_block_size` consecutive ports. Fresh installs seed `10`; admins can set values from `1` to `1000`.
- The allocator starts at each game's default base port, using local game metadata/protocol metadata where available, then scans upward by one port until it finds the first free contiguous block.
- Static reservations, existing `home_port_blocks`, and legacy `home_ip_ports` assignments block overlapping automatic allocations.
- `home_port_blocks` stores whole-block ownership per `ip_id`.
- `home_ip_ports` still receives the selected primary/base port for lifecycle and monitor compatibility.
- `arrange_ports`, port pools, game mappings, and game offsets remain in the database for compatibility/history, but admins do not need to manage them for normal allocation.
- `?m=server&p=port_blocks` manages global block size, static reservations, allocated blocks, and recent allocation events. It does not run production backfill or destructive migration.

## Agent Interaction

- manages the agent endpoints and node-level data

## User Workflow

- not usually customer-facing

## Admin Workflow

- add/remove remote servers
- manage IPs, ports, NAT, firewall settings
- review node configuration

## Security Concerns

- encryption keys
- timeout settings
- firewall/IP exposure

## Known Issues

- admin UX is older and could be clearer

## Missing Functionality

- richer node health and capacity dashboard

## Suggested Future Improvements

- improve node monitoring and provisioning diagnostics

## Recommendation

- Keep / Improve
