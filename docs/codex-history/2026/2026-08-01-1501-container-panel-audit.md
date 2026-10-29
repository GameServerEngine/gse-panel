# Task

## User Request

Execute only Step 01 of the Leroy workflow for GSE-Panel: audit the current panel architecture and identify game-specific coupling before refactoring the product model from rented game servers to private isolated containers. Produce durable documentation from source evidence. Preserve existing uncommitted work. Do not commit or push.

## Problem

The Panel currently sells and provisions game servers. The target product is private isolated containers with resource plans, locations, customer-visible port blocks, persistent storage, and installable application templates. Before implementation, the current deployment, billing, resource, agent, and application flows needed to be mapped from source evidence.

## Root Cause

Game-server assumptions are embedded across the schema, storefront, billing service definitions, provisioning queue, game XML catalog, port allocator, lifecycle commands, file paths, server monitor, content manager, and admin user-games module. The strongest coupling is the use of `home_cfg_id`, `game_key`, `max_players`, game XML, and `server_homes` as the shared contract for catalog, product, deployment, runtime, and UI state.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`

## Files Read

- `/home/dev/Projects/AGENTS.md`
- `/home/dev/Projects/GSE-Panel/AGENTS.md`
- `/home/dev/Projects/GSE-Panel/README.md`
- `/home/dev/Projects/GSE-Panel/CHANGELOG.md`
- `/home/dev/Projects/GSE-Panel/.devplus/workspaces/workspace-20260801195617-cba9edcb/WORKFLOW.md`
- `/home/dev/Projects/GSE-Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSE-Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSE-Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSE-Panel/docs/DATABASE_SCHEMA_REFERENCE.md`
- `/home/dev/Projects/GSE-Panel/docs/DATABASE_SCHEMA_AUDIT.md`
- `/home/dev/Projects/GSE-Panel/docs/plans/global-port-allocation/DATABASE_DESIGN.md`
- `/home/dev/Projects/GSE-Panel/docs/features/PROVISIONING.md`
- `/home/dev/Projects/GSE-Panel/docs/features/XML_SYSTEM.md`
- `/home/dev/Projects/GSE-Panel/docs/modules/billing.md`
- `/home/dev/Projects/GSE-Panel/panel.sql`
- `/home/dev/Projects/GSE-Panel/includes/database_mysqli.php`
- `/home/dev/Projects/GSE-Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSE-Panel/includes/port_allocator.php`
- `/home/dev/Projects/GSE-Panel/includes/game_path_helpers.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/order.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/add_to_cart.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/api/capture_order.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/create_servers.php`
- `/home/dev/Projects/GSE-Panel/modules/user_games/add_home.php`
- `/home/dev/Projects/GSE-Panel/modules/user_games/edit_home.php`
- `/home/dev/Projects/GSE-Panel/modules/user_games/del_home.php`
- `/home/dev/Projects/GSE-Panel/modules/user_games/billing_integration.php`
- `/home/dev/Projects/GSE-Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSE-Panel/modules/gamemanager/update_actions.php`
- `/home/dev/Projects/GSE-Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSE-Panel/modules/gamemanager/mini_start.php`
- `/home/dev/Projects/GSE-Panel/modules/gamemanager/rcon.php`
- `/home/dev/Projects/GSE-Panel/modules/litefm/litefm.php`
- `/home/dev/Projects/GSE-Panel/modules/addonsmanager/addons_manager.php`
- `/home/dev/Projects/GSE-Panel/modules/addonsmanager/server_content_helpers.php`
- `/home/dev/Projects/GSE-Panel/modules/addonsmanager/server_content_actions.php`
- `/home/dev/Projects/GSE-Panel/modules/steam_workshop/functions.php`
- `/home/dev/Projects/GSE-Panel/modules/backup-restore/backup-restore.php`
- `/home/dev/Projects/GSE-Panel/includes/tests/game_path_helpers_test.php`
- `/home/dev/Projects/GSE-Panel/includes/tests/lifecycle_contract_helpers_test.php`
- `/home/dev/Projects/GSE-Panel/includes/tests/lib_remote_lifecycle_wrapper_test.php`

## Files Changed

- `docs/plans/container-platform/PANEL_GAME_COUPLING_AUDIT.md`
- `docs/codex-history/2026/2026-08-01-1501-container-panel-audit.md`

## Architecture Decisions

- Preserve the existing control-plane and agent-executor split.
- Treat current game-server flows as legacy compatibility during the container refactor.
- Reuse billing payment primitives, remote node/RPC primitives, access-right primitives, file path safety concepts, and the global port block ledger.
- Refactor or replace product/catalog/provisioning semantics that currently use `home_cfg_id`, `game_key`, `max_players`, game XML, and `server_homes` as one combined contract.
- Do not implement schema or code changes in Step 01 because the selected workflow step is audit-only.

## Validation Performed

- Confirmed initial Git state with `git status`.
- Confirmed initial diff with `git diff`.
- Reviewed repository instructions and durable documentation before writing.
- Created durable audit documentation only.
- Ran Markdown/diff validation after editing.

## Git Commits

Repository: `/home/dev/Projects/GSE-Panel`

Commit: Not created.

Hash: N/A

Reason: The current workflow explicitly says "Do not commit or push."

## Remaining Work

- Step 02 should design the container product, resource, port, storage, and template model.
- Implementation must add tests for plan pricing, port-block allocation, provisioning state transitions, and template installs before replacing legacy game-server behavior.
