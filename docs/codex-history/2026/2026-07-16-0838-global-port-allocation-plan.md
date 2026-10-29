# Task

## User Request

Create durable planning/todo documents for the GSE global port-pool allocation redesign under `docs/plans/global-port-allocation/`, update active/backlog planning indexes, add a permanent history record, validate the documentation-only change, commit it, and push to `origin/unstable`. Do not change runtime code, database migrations, schemas, XML game configs, language files, services, or deployment behavior.

## Problem

The current Panel documentation did not contain a durable plan for replacing per-game/per-dedicated-server manual port ranges with reusable per-IP port pools, 10-port allocation blocks, overflow behavior, static service reservations, and per-game offset review.

## Root Cause

The current Panel inherits the OGP-style allocation model: `arrange_ports` stores manually arranged ranges by `ip_id` and `home_cfg_id`, while `home_ip_ports` stores only one primary assigned port per home/IP. That model cannot represent owned 10-port blocks, default-port pools, static service reservations, or controlled overflow allocation.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`
- `/home/dev/Projects/GSE-Agent-Linux` (read-only documentation/status review)
- `/home/dev/Projects/GSE-Agent-Windows` (read-only documentation/status review)

## Files Read

- `AGENTS.md`
- `README.md`
- `CHANGELOG.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/plans/README.md`
- `docs/plans/ACTIVE.md`
- `docs/plans/BACKLOG.md`
- `docs/plans/lgsl-gameq-protocol-removal/PLAN.md`
- `docs/plans/lgsl-gameq-protocol-removal/INVENTORY.md`
- `docs/plans/lgsl-gameq-protocol-removal/IMPACT_ANALYSIS.md`
- `docs/plans/lgsl-gameq-protocol-removal/IMPLEMENTATION.md`
- `docs/plans/lgsl-gameq-protocol-removal/VALIDATION.md`
- `docs/codex-history/2026/2026-07-09-0911-panel-monitor-query-ports.md`
- `docs/codex-history/2026/2026-07-09-1000-panel-fast-status-lights.md`
- `docs/codex-history/2026/2026-07-12-1258-monitor-single-controls.md`
- `modules/server/arrange_ports.php`
- `includes/database_mysqli.php`
- `modules/billing/create_servers.php`
- `modules/user_games/add_home.php`
- `modules/user_games/edit_home.php`
- `modules/gamemanager/module.php`
- `modules/server/module.php`
- `modules/config_games/schema_server_config.xml`
- `modules/config_games/server_configs/*.xml`
- `protocol/lgsl/lgsl_protocol.php`
- `protocol/GameQ/README.md`
- `protocol/GameQ/Protocol.php`
- `protocol/GameQ/Protocols/*.php`
- `protocol/GameQ/gameq/protocols/*.php`
- relevant docs in `docs/features`, `docs/modules`, `docs/deployment`, and `docs/architecture` found through targeted port/service searches
- `/home/dev/Projects/GSE-Agent-Linux/README.md`
- `/home/dev/Projects/GSE-Agent-Linux/docs/PANEL_INTEGRATION.md`
- `/home/dev/Projects/GSE-Agent-Windows/README.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/AGENT_ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Agent-Windows/docs/PANEL_INTEGRATION.md`

## Files Changed

- `docs/plans/ACTIVE.md`
- `docs/plans/BACKLOG.md`
- `docs/plans/global-port-allocation/PLAN.md`
- `docs/plans/global-port-allocation/CURRENT_SYSTEM.md`
- `docs/plans/global-port-allocation/GAME_PORT_REVIEW_TABLE.md`
- `docs/plans/global-port-allocation/PORT_POOLS.md`
- `docs/plans/global-port-allocation/STATIC_SERVICE_RESERVATIONS.md`
- `docs/plans/global-port-allocation/IMPLEMENTATION.md`
- `docs/plans/global-port-allocation/VALIDATION.md`
- `docs/codex-history/2026/2026-07-16-0838-global-port-allocation-plan.md`

## Architecture Decisions

- Keep the Panel as owner of allocation policy and provisioning decisions.
- Plan a pool-first model keyed per IP, with 10-port game blocks by default.
- Preserve `gsp_home_ip_ports` compatibility for existing lifecycle and monitor paths.
- Treat static service reservations as allocation records that block game allocation on the same IP/host.
- Use `32000-32999` only as controlled overflow, giving 100 10-port blocks per IP.
- Mark unverified game defaults as `needs external verification` instead of inventing certainty.

## Validation Performed

- Confirmed all requested `docs/plans/global-port-allocation/` files exist.
- Confirmed `docs/plans/ACTIVE.md` references `docs/plans/global-port-allocation/PLAN.md`.
- Parsed all 230 top-level `modules/config_games/server_configs/*.xml` files with zero parse failures.
- Confirmed `GAME_PORT_REVIEW_TABLE.md` has 230 table rows and no missing top-level XML configs.
- Confirmed `PORT_POOLS.md` includes capacities, 26900-to-27015 collision notes, and controlled `32000-32999` overflow policy.
- Confirmed `STATIC_SERVICE_RESERVATIONS.md` includes SSH, HTTP, HTTPS, FTP, MySQL/MariaDB, RDP, Panel web/API, agent listener/FTP, SMTP/mail, VPN, TeamSpeak, and passive FTP review rows with uncertain bindings marked verify per deployment.
- Confirmed changed-file scope is limited to `docs/plans`, the new global port allocation plan directory, and this history record.
- Ran `git diff --check` successfully.
- Ran Markdown table shape and literal path sanity checks successfully.

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

Pending

Hash

Pending

## Remaining Work

- Externally verify game default ports marked `needs external verification`.
- Review non-standard game offsets before implementation.
- Design and implement migrations/UI/allocator in a future code task only after product approval.
- If implementation changes runtime assumptions, update the Linux and Windows agent documentation in their own repositories as part of that future work.
