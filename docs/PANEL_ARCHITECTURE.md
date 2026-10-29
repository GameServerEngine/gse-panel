# GameServer Engine Panel Architecture

Workspace reference: [`../../AGENTS.md`](../../AGENTS.md)

The Panel is the GameServer Engine (GSE) management console and orchestration layer for hosted game servers. It handles authentication, routing, server lifecycle decisions, file/config management, Workshop/content workflows, status display, scheduling, and updates.

Gameservers.World storefront-only work belongs in `modules/billing`. Panel management-console work belongs elsewhere in this repository as appropriate.

## Responsibilities

- load modules from `modules/`
- read and write panel-side database state
- generate requests for remote agents
- validate server configuration and XML
- render admin and customer workflows
- coordinate backups, updates, and rollback

## Important implementation areas

- `includes/lib_remote.php` for agent communication
- `modules/config_games/` for game XML definitions
- `modules/update/` and update-related administration modules
- `modules/gamemanager/` for server lifecycle and logs
- `modules/addonsmanager/` for server content and Workshop flows
- `modules/billing/` for the canonical Gameservers.World storefront and billing module

## Design rule

The Panel decides what should happen. It does not depend on agents to make business decisions, only to execute requests and report their status.
