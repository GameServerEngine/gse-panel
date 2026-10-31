# GameServer Engine Architecture Overview

Workspace reference: [`../../AGENTS.md`](../../AGENTS.md)

This repository is the GameServer Engine (GSE) management console and control-plane repository. It owns the Panel, shared documentation, admin tooling, and modules that decide what actions should be taken for each hosted server.

The local workspace container is `/home/dev/Projects`, but that folder is not a Git repository. Git operations for this repository must run in `/home/dev/Projects/GSE-Panel`.

Gameservers.World is the customer-facing hosting storefront at `modules/billing`. It is part of this repository, but it is not the whole Panel and it is not a separate top-level repository.

## How to read this repo

Start here:

1. `README.md`
2. `docs/ARCHITECTURE.md`
3. `docs/PANEL_ARCHITECTURE.md`
4. `docs/AGENT_COMMUNICATION.md`
5. `docs/REPOSITORY_STRUCTURE.md`
6. `docs/COMMAND_FLOW.md`
7. `docs/UPDATE_POLICY.md`

Then use the deeper docs under `docs/architecture/`, `docs/features/`, `docs/modules/`, and `docs/development/` for implementation details.

## System boundary

The Panel owns:

- user and admin-facing management-console UI
- the Gameservers.World storefront module at `modules/billing`
- server lifecycle orchestration
- module routing and shared state
- XML/configuration authoring
- update policy and backup policy
- documentation for the full ecosystem

The Panel does not own the OS-level execution engine. That responsibility belongs to the Linux and Windows agent repositories.

## Canonical runtime model

User action
→ Panel module
→ remote command generation
→ agent communication
→ Linux or Windows agent
→ GSP Perl agent
→ operating system
→ hosted game server

`GSP Perl agent` is a compatibility/runtime name for the existing agent implementation. It is not the current product name.

The Panel is authoritative for business logic. Agents should execute requests and report results.
