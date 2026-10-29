# Task

## User Request

Resume the Gameservers.World storefront with a refinement pass. Read required instructions, architecture docs, previous redesign history, git status/diff, and affected files. Confirm `modules/billing` is canonical. Use the newly uploaded `/home/dev/Projects/gse-globe-logo.png` and `/home/dev/Projects/gse-text-logo.png` as the new GameServers.World branding. Preserve billing, auth, catalog, cart, account, and provisioning behavior. Refocus the homepage toward customers shopping for game server hosting, reduce repeated GameServer Engine references, move relationship explanation to the Company/About page, review competitor public information architecture, validate, commit with message `Refine Gameservers.World branding and customer focus`, and push to `origin/unstable`.

## Problem

The previous redesign made GameServer Engine branding dominate the storefront. The header used the old GSE logo plus an additional official-host badge, the mobile hamburger displayed a visible `Menu` label, and the homepage led with platform/demo positioning instead of helping customers shop for game server hosting.

## Root Cause

The storefront was already consolidated under `modules/billing`, but the prior brand pass used GameServer Engine assets as the primary identity. The newly supplied GameServers.World globe and text PNGs had not yet been optimized into the billing storefront asset set or wired through the shared shell.

## Repositories Inspected

- `/home/dev/Projects/GSP`
- `/home/dev/Projects/GSP/Panel`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/MODULE_INDEX.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/billing.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/website.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/gameservers-world-storefront.md`
- `/home/dev/Projects/GSP/Panel/docs/features/USER_API.md`
- `/home/dev/Projects/GSP/Panel/docs/features/PROVISIONING.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-09-canonical-billing-storefront.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-14-2119-gameservers-world-redesign.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/docs/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/menu.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/css/header.css`
- `/home/dev/Projects/GSP/Panel/modules/billing/index.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/company.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/footer.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/support.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/cart.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/payment_cancel.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/payment_success.php`
- `/home/dev/Projects/gse-globe-logo.png`
- `/home/dev/Projects/gse-text-logo.png`

Competitor public IA reviewed:

- Nitrado
- GameServers.com
- Pingperfect
- GTXGaming
- Survival Servers

## Files Changed

- `docs/codex-history/2026/2026-07-15-0908-gameservers-world-refinement.md`
- `docs/deployment/gameservers-world-storefront.md`
- `docs/modules/website.md`
- `modules/billing/cart.php`
- `modules/billing/company.php`
- `modules/billing/css/header.css`
- `modules/billing/images/brand/apple-touch-icon.png`
- `modules/billing/images/brand/favicon-16x16.png`
- `modules/billing/images/brand/favicon-32x32.png`
- `modules/billing/images/brand/favicon-48x48.png`
- `modules/billing/images/brand/gsw-globe-icon.png`
- `modules/billing/images/brand/gsw-globe-logo.png`
- `modules/billing/images/brand/gsw-text-logo.png`
- `modules/billing/includes/footer.php`
- `modules/billing/includes/menu.php`
- `modules/billing/index.php`
- `modules/billing/payment_cancel.php`
- `modules/billing/payment_success.php`
- `modules/billing/support.php`

## Architecture Decisions

- Kept `modules/billing` as the canonical Gameservers.World storefront.
- Preserved server-side billing, auth, catalog, cart, account, and provisioning flows; edits are branding, presentation copy, public shell, and graceful local DB fallback for `payment_success.php`.
- Used optimized copied assets under `modules/billing/images/brand/` derived from the uploaded GameServers.World PNG sources.
- Desktop header uses globe logo immediately followed by text logo as one brand. Mobile header hides the text logo and keeps the globe mark only.
- Removed the old visible GSE header logo, official-host badge, and visible hamburger `Menu` label while preserving an accessible `aria-label`.
- Homepage now sells game server hosting first. GameServer Engine is reduced to a concise platform trust statement and the Company page explains the relationship between GameServers.World, Run Level Systems, and GameServer Engine.
- Competitor review informed hierarchy only: game/catalog entry first, then ordering, management, mods, reliability, support, and documentation.

## Validation Performed

- `git status --short --branch`
- `git diff`
- Public competitor IA review using Nitrado, GameServers.com, Pingperfect, GTXGaming, and Survival Servers.
- Asset verification with `file`, `identify`, and `sha256sum`.
- PHP syntax checks across active `modules/billing` PHP files.
- Existing billing tests:
  - `php modules/billing/tests/BillingActionSecurityTest.php`
  - `php modules/billing/tests/BillingLifecycleServiceTest.php`
  - `php modules/billing/tests/BillingRequestPathSourceTest.php`
- `git diff --check`
- Local HTTP render checks through `php -S 127.0.0.1:8077 -t modules/billing`.
- Local HTTP asset checks for `css/header.css` and new brand assets.
- Link/path validation for changed public pages.
- Static accessibility and responsive checks for landmarks, logo alt text, hamburger `aria-controls`/`aria-expanded`/`aria-label`, mobile logo hiding, focus-visible styling, overflow containment, and reduced-motion handling.
- Headless Chrome screenshot validation was attempted but Chrome hung and then trapped in this environment, so responsive validation used HTTP/DOM/CSS inspection.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

`Refine Gameservers.World branding and customer focus`

Hash

Final immutable hash reported in the task response after commit creation.

## Remaining Work

- Run a visual browser QA pass in a normal workstation browser because local headless Chrome did not complete in this environment.
