# Task

## User Request

Run the verification gate in `/home/dev/Projects/GSP/Panel` on branch `unstable`, protect the dirty interrupted work, verify the new GSE repository identity and branch layout, complete the Panel GSE migration, review and complete billing lifecycle work, verify schema documentation, normalize safe branch tracking/remote state, validate, commit logical changes, and push only if verification passes.

## Problem

The Panel repository had uncommitted interrupted migration work mixed with billing lifecycle edits, schema documentation, and history files. The local `origin` still pointed at the obsolete `GSP/Panel` repository, while the new canonical repository is `GSE/Panel`. The remote branch layout still included the legacy `Panel-unstable` branch name.

## Root Cause

The GSE migration was interrupted before commit and before local remote normalization. Billing and schema verification work had also been left in the same dirty tree. The old `GSP/Panel` origin is unavailable, and branch naming had not yet been fully migrated to the canonical `unstable` and `stable` branches.

## Repositories Inspected

- `Panel`

The user explicitly limited implementation to `Panel`. The parent workspace instructions were read from `/home/dev/Projects/GSP/AGENTS.md`. `/home/dev/Projects/GSP-WORKSPACE.md` was referenced by the instructions but was not present.

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/modules/billing.md`
- `docs/deployment/billing-cron.md`
- `docs/modules/PANEL_UPDATE.md`
- `docs/modules/UPDATE.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `panel.sql`
- `/home/dev/Projects/GSP/panel.sql`
- `.github/agent.md`
- `.github/copilot-instructions.md`
- `.github/workflows/create-release.yml`
- `.github/workflows/promote-unstable-to-stable.yml`
- `INSTALL.BAT`
- `scripts/install-panel.sh`
- `modules/administration/panel_update.php`
- `modules/server/agent_helpers.php`
- `modules/update/tests/update_config_smoke.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/checkout_free.php`
- `modules/billing/renew_server.php`
- `modules/billing/create_servers.php`
- `modules/billing/cron-shop.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/module.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `docs/codex-history/2026/2026-07-11-1832-database-schema-audit.md`
- `docs/codex-history/2026/2026-07-11-1856-panel-billing-lifecycle-service.md`

## Files Changed

- `.github/agent.md`
- `.github/copilot-instructions.md`
- `.github/workflows/create-release.yml`
- `.github/workflows/promote-unstable-to-stable.yml`
- `INSTALL.BAT`
- `check.php`
- `docs/ADMINISTRATOR_GUIDE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/ARCHITECTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/DATABASE_SCHEMA_AUDIT.md`
- `docs/DATABASE_SCHEMA_REFERENCE.md`
- `docs/DEPLOYMENT.md`
- `docs/DEVELOPER_GUIDE.md`
- `docs/INSTALLATION.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/PANEL_TO_AGENT_COMMANDS.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/UPDATE_POLICY.md`
- `docs/USER_GUIDE.md`
- `docs/codex-history/2026/2026-07-11-1832-database-schema-audit.md`
- `docs/codex-history/2026/2026-07-11-1856-panel-billing-lifecycle-service.md`
- `docs/codex-history/2026/2026-07-12-0834-panel-migration-verification.md`
- `docs/deployment/README.md`
- `docs/modules/PANEL_UPDATE.md`
- `docs/modules/UPDATE.md`
- `includes/functions.php`
- `includes/install_engine.php`
- `includes/view.php`
- `install.php`
- `modules/administration/panel_update.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/cart.php`
- `modules/billing/checkout_free.php`
- `modules/billing/classes/BillingLifecycleService.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/create_servers.php`
- `modules/billing/cron-shop.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/module.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/renew_server.php`
- `modules/billing/tests/BillingLifecycleServiceTest.php`
- `modules/server/agent_helpers.php`
- `modules/update/tests/update_config_smoke.php`
- `payment.php`
- `payment_failed.php`
- `payment_success.php`
- `push_to_github.sh`
- `scripts/install-panel.sh`

## Architecture Decisions

- Preserve Panel as the control plane and agents as execution engines.
- Make `GSE/Panel` the active repository identity while preserving compatibility repair handling for already-configured old `GSP/Panel` URLs.
- Use `https://git.runlevelsystems.com/GSE/Panel.git` for HTTPS defaults and `ssh://git@173.208.136.14/GSE/Panel.git` for the canonical SSH origin.
- Use active GSE agent links for Windows and Linux agent installers while preserving compatibility-sensitive runtime identifiers, database keys, config keys, service names, environment variables, and filesystem paths.
- Keep only `unstable` and `stable` as canonical long-lived branches. Delete legacy branches only after ancestry proof shows no commits unique outside `unstable` or `stable`.
- Centralize billing transitions in `BillingLifecycleService` instead of keeping separate payment, cron, provisioning, and admin lifecycle systems.
- Keep invoice payment status, invoice lifecycle status, order/provisioning status, server-home lifecycle status, and transaction audit rows as distinct concepts.
- Correct schema documentation only where verified against the live dump, module migrations, and code usage.

## Validation Performed

- Read initial `git status`, complete tracked/untracked diff, recent commits, local branches, current branch, remotes, and tracking state.
- Confirmed old origin `ssh://git@173.208.136.14/GSP/Panel.git` is obsolete/unavailable.
- Confirmed new canonical origin target `ssh://git@173.208.136.14/GSE/Panel.git`.
- Confirmed `git ls-remote --symref ssh://git@173.208.136.14/GSE/Panel.git HEAD refs/heads/*` reports default branch `unstable`.
- Confirmed remote branches were `Panel-unstable`, `stable`, and `unstable`; no remote `main` or `master` existed.
- Confirmed `stable` is an ancestor of `unstable` with `git rev-list --left-right --count origin/stable...origin/unstable` returning `0 33`.
- Confirmed `Panel-unstable` is an ancestor of `unstable` with `git rev-list --left-right --count origin/Panel-unstable...origin/unstable` returning `0 10`.
- After the local commits, confirmed `stable` remained an ancestor of `unstable` with `git rev-list --left-right --count origin/stable...unstable` returning `0 37`.
- After the local commits, confirmed `Panel-unstable` remained an ancestor of `unstable` with `git rev-list --left-right --count origin/Panel-unstable...unstable` returning `0 14`.
- Confirmed no authenticated Forgejo client or token was available for changing the default branch through an API. No change was needed because the remote default branch was already `unstable`.
- Ran PHP syntax checks for every changed PHP file.
- Ran `php modules/billing/tests/BillingLifecycleServiceTest.php`.
- Ran `php modules/update/tests/update_config_smoke.php`.
- Ran `php modules/addonsmanager/tests/workshop_helpers_test.php`.
- Ran `php modules/config_games/tests/validate_server_configs.php`.
- Ran `bash -n scripts/install-panel.sh`.
- Ran targeted stale-reference searches for `GSP/Panel`, `GSP-Agent-Windows`, `GSP-Agent-Linux`, and `Install-GSE-WindowsAgent`.
- Reviewed installer and update URLs.
- Ran `git diff --check`.
- Pushed verified commits to `origin/unstable`.
- Deleted remote branch `Panel-unstable` after final ancestry proof.
- Refreshed `origin/HEAD` so it points to `origin/unstable`.
- Confirmed the remote branch list is exactly `stable` and `unstable`.

## Git Commits

Repository

Panel

Commit

Migrate Panel repository identity to GSE

Hash

`b320b2cdbe810ecf3b32b529ae40a16c0f9242cb`

Repository

Panel

Commit

Complete billing lifecycle integration

Hash

`ffd6cb1e6ad4dc49db85ba57797716ab9ac82599`

Repository

Panel

Commit

Document Panel database schema

Hash

`4a6d7fe713d98907c0b32663f16a7c76c5bf174d`

Repository

Panel

Commit

Record Panel development history

Hash

`e68a8288cfc63b211c0b57c2ec800f95d1455e5b`

## Remaining Work

- No remaining migration, billing, schema documentation, or branch cleanup work was identified for this verification gate.
- Live DB/web billing tests requiring configured production-like database and browser/session context were not run in this environment.
