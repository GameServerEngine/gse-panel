# Task

## User Request

Update authoritative workspace and architecture documentation so future DEV+1 and Codex runs use GameServer Engine (GSE) as the product name, enter the correct Git repository, edit the correct layer, preserve compatibility-sensitive `GSP` identifiers, verify the completed Gameservers.World storefront refinement commit `9f0ab537`, validate, commit, and push.

## Problem

Panel README and architecture entry-point docs still described the current product as `GSP`, linked to a missing `GSP-WORKSPACE.md`, and did not clearly distinguish the Panel management console from the Gameservers.World storefront under `modules/billing`.

## Root Cause

The workspace and product branding moved to GameServer Engine (GSE), but current docs had not been updated after the repository layout settled on `/home/dev/Projects/GSP` as a multi-repository container and `/home/dev/Projects/GSP/Panel` as the control-plane Git repository.

## Repositories Inspected

- `/home/dev/Projects/GSP`
- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/GSP/GSP-Agent-Linux`
- `/home/dev/Projects/GSP/GSP-Agent-Windows`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/.devplus/CODEX_INSTRUCTIONS.md`
- `/home/dev/Projects/GSP/.devplus/roadmaps/gse-development-roadmap.md`
- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/architecture/REPOSITORY_OVERVIEW.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/architecture/AI_GSP_ARCHITECTURE.md`
- `docs/agents/LINUX_AGENT.md`
- `docs/agents/WINDOWS_AGENT.md`
- `docs/modules/billing.md`
- `docs/modules/website.md`
- `docs/deployment/gameservers-world-storefront.md`
- `modules/billing/README.md`
- `modules/billing/includes/menu.php`
- `modules/billing/css/header.css`
- `modules/billing/index.php`
- `modules/billing/company.php`
- `docs/codex-history/2026/2026-07-09-canonical-billing-storefront.md`
- `docs/codex-history/2026/2026-07-14-2119-gameservers-world-redesign.md`
- `docs/codex-history/2026/2026-07-15-0908-gameservers-world-refinement.md`

## Files Changed

- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/architecture/REPOSITORY_OVERVIEW.md`
- `docs/architecture/PANEL_AGENT_FLOW.md`
- `docs/architecture/AI_GSP_ARCHITECTURE.md`
- `docs/agents/LINUX_AGENT.md`
- `docs/agents/WINDOWS_AGENT.md`
- `docs/modules/billing.md`
- `docs/modules/website.md`
- `modules/billing/README.md`
- `docs/codex-history/2026/2026-07-15-0936-gse-workspace-routing.md`

## Architecture Decisions

- Documented the Panel repository as the GameServer Engine management console/control plane.
- Documented Gameservers.World as the customer-facing hosting storefront under `modules/billing`, not a separate top-level repo and not the whole Panel.
- Preserved literal `GSP` runtime, path, protocol, and legacy filename identifiers.
- Replaced missing workspace-reference links in edited current docs with links to the workspace `AGENTS.md`.
- Did not change storefront PHP/CSS/assets because commit `9f0ab537` already satisfied the storefront refinement requirements.

## Validation Performed

- Verified `/home/dev/Projects/GSP` is not a Git repository.
- Verified `/home/dev/Projects/GSP/Panel` is on `unstable` tracking `origin/unstable`.
- Verified commit `9f0ab537f61241e94f14b42508706e9fb207eec2` is present on `origin/unstable`.
- Inspected commit `9f0ab537`, storefront files, and brand assets.
- Searched current non-historical docs for ambiguous `GSP` references.
- Verified changed docs contain intended repository paths and terminology.
- Ran `git diff --check`.
- Reviewed final Panel diff before commit.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

`Document GSE workspace repository boundaries`

Hash

Pending until commit creation; final immutable hash is reported in the task response.

## Remaining Work

None.
