# Task

## User Request

Implement a focused mobile-first cleanup of the Gameservers.World storefront in `/home/dev/Projects/GSP/Panel`. Verify instructions, docs, current status/diff, affected files, screenshots or supplied evidence, and logo assets before editing. Preserve all billing, authentication, catalog, cart, account, order, provisioning, support, documentation, and panel behavior. Show the Gameservers.World logo exactly once in the normal page experience, in the shared header. Remove repeated homepage, trust, and footer logo imagery. Simplify mobile header/navigation, homepage copy/layout, catalog cards, and footer. Validate PHP syntax, billing tests, local HTTP rendering, asset/link paths, responsive widths, accessibility, duplicate logo references, final diff, and `git diff --check`. Commit on `unstable` with `Simplify Gameservers.World mobile storefront` and push to `origin/unstable` if validation passes.

## Problem

The refined storefront still rendered too much visible brand imagery and used heavy mobile presentation. The mobile header was visually large, the navigation opened as a stacked full-width block, repeated logos appeared in the hero, powered/trust area, and footer, homepage copy had dense explanatory phrasing, and catalog cards used the primary CTA style for every Configure Server link.

## Root Cause

The prior redesign and refinement wired the new Gameservers.World brand into multiple visible page regions and reused broad CTA/button styles across header, homepage, and catalog surfaces. The responsive breakpoint also converted all major buttons to full width on narrow screens, which made catalog cards resemble a stack of oversized purple buttons.

## Repositories Inspected

- `/home/dev/Projects/GSP`
- `/home/dev/Projects/GSP/Panel`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/development/CODEX_GUIDE.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/gameservers-world-storefront.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/billing.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-14-2119-gameservers-world-redesign.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-15-0908-gameservers-world-refinement.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/docs/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/top.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/menu.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/footer.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/billing_bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/index.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/serverlist.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/css/header.css`
- `/home/dev/Projects/GSP/Panel/modules/billing/tests/BillingActionSecurityTest.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/tests/BillingLifecycleServiceTest.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/tests/BillingRequestPathSourceTest.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/images/brand/gsw-globe-icon.png`
- `/home/dev/Projects/GSP/Panel/modules/billing/images/brand/gsw-globe-logo.png`
- `/home/dev/Projects/GSP/Panel/modules/billing/images/brand/gsw-text-logo.png`

## Files Changed

- `docs/deployment/gameservers-world-storefront.md`
- `docs/codex-history/2026/2026-07-15-1010-gameservers-world-mobile-cleanup.md`
- `modules/billing/css/header.css`
- `modules/billing/docs.php`
- `modules/billing/includes/footer.php`
- `modules/billing/includes/menu.php`
- `modules/billing/index.php`
- `modules/billing/login.php`
- `modules/billing/serverlist.php`

## Architecture Decisions

- Kept `modules/billing` as the canonical Gameservers.World storefront inside the Panel repository.
- Preserved all server-side billing, auth, account, cart, order, provisioning, support, documentation, and panel routes.
- Chose `gsw-globe-icon.png` plus `gsw-text-logo.png` as the single shared-header brand treatment; the larger `gsw-globe-logo.png` is not used as visible page decoration.
- Removed visible Gameservers.World logo imagery from homepage hero, powered/trust statement, and footer while leaving favicon and touch icon use intact.
- Kept mobile navigation in the shared header as a compact dropdown with accessible button state and link-close behavior.
- Split catalog actions from primary hero CTAs so Configure Server remains touch-friendly without dominating each card.

## Validation Performed

- `git status --short --branch`
- `git diff`
- `file modules/billing/images/brand/*.png`
- `identify modules/billing/images/brand/*.png`
- Visual inspection of active Gameservers.World brand assets with image viewer.
- PHP syntax checks:
  - `php -l modules/billing/includes/menu.php`
  - `php -l modules/billing/includes/footer.php`
  - `php -l modules/billing/index.php`
  - `php -l modules/billing/serverlist.php`
  - `php -l modules/billing/login.php`
  - `php -l modules/billing/docs.php`
- Existing billing tests:
  - `php modules/billing/tests/BillingActionSecurityTest.php`
  - `php modules/billing/tests/BillingLifecycleServiceTest.php`
  - `php modules/billing/tests/BillingRequestPathSourceTest.php`
- Local HTTP render checks through `php -S 127.0.0.1:8077 -t modules/billing`:
  - `/index.php`
  - `/serverlist.php`
  - `/login.php`
  - `/company.php`
  - `/docs.php?action=view&doc=minecraft`
- Local HTTP asset checks:
  - `/css/header.css`
  - `/images/brand/gsw-globe-icon.png`
  - `/images/brand/gsw-text-logo.png`
  - `/images/brand/favicon-32x32.png`
- Internal link/path checks from the required rendered pages; all local links checked returned HTTP 200 with non-empty output.
- Static responsive review for 360px, 390px, 430px, 480px, 1366px, 1440px, 1600px, and 1920px using the implemented CSS constraints and header/dropdown width calculations.
- Static accessibility review confirmed main landmarks on checked pages, primary and footer nav labels, accessible hamburger state and label, meaningful brand text alternative, focus-visible styling, preserved reduced-motion transition gating, readable heading order, and touch-target sizing.
- Active storefront duplicate logo scan confirmed visible Gameservers.World brand image references are limited to the shared header brand group.
- Static scan confirmed homepage redesign inline styles were removed.
- Final diff review confirmed no billing/payment/auth/order/provisioning logic changed; catalog SQL and order URLs remain intact.
- `git diff --check`

Headless screenshot validation with `/usr/bin/google-chrome --headless --screenshot` was attempted for representative mobile and desktop widths. Chrome did not complete within 60 seconds in this environment and was stopped, matching the previous storefront history's headless Chrome limitation. Playwright and Puppeteer were not installed.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

`Simplify Gameservers.World mobile storefront`

Hash

Final immutable hash reported in the task response. The hash cannot be embedded in this committed file without changing the commit hash itself.

## Remaining Work

- Run a visual browser QA pass in a normal workstation browser because local Chrome headless screenshot capture hung in this environment.
