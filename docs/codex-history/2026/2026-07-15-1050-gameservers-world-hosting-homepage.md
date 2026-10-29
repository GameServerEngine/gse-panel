# Task

## User Request

Implement a focused customer-conversion cleanup of the Gameservers.World storefront in `/home/dev/Projects/GSP/Panel`. Confirm `modules/billing` is the canonical storefront and `/home/dev/Projects/GSP/Panel` is the Git repository. Inspect instructions, architecture docs, recent storefront history, latest commit `a80ca98d`, current status/diff, affected storefront files, and user-provided mobile defects before editing. Remove the homepage server list and related featured-service queries, keep the full catalog on `serverlist.php`, replace internal-heavy homepage copy with a concise customer-focused hosting landing page, add a static public locations block for Kansas City, Los Angeles, New York City, Dallas, and Ireland, shorten the footer, remove broad full-width purple CTA treatment except where a single primary action is useful, preserve billing/auth/catalog/cart/account/order/payment/provisioning/support/docs/panel behavior, validate, commit with `Refocus Gameservers.World hosting homepage`, and push to `origin/unstable`.

## Problem

The prior mobile cleanup improved the storefront shell, but the homepage still tried to function as a catalog page and a platform explainer. It queried and rendered featured services, queried runtime server names for public location display, repeated internal implementation language, and left the footer as a dense mix of navigation, contact, copyright, and powered-by text.

## Root Cause

The homepage inherited catalog and infrastructure preview sections from the earlier redesign. Those sections reused billing/runtime data intended for operational or catalog pages, which made the landing page text-heavy and exposed implementation concepts that customers do not need before choosing to browse servers or contact support.

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
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-15-1010-gameservers-world-mobile-cleanup.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/docs/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/billing_bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/config_loader.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/footer.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/menu.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/index.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/serverlist.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/css/header.css`
- `/home/dev/Projects/GSP/Panel/modules/billing/tests/BillingActionSecurityTest.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/tests/BillingLifecycleServiceTest.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/tests/BillingRequestPathSourceTest.php`
- `git show --format=fuller --patch --find-renames a80ca98d`

No screenshot files were present in the repository scan; the mobile screenshot defects were treated as user-provided evidence in the task prompt and verified against the current storefront source/rendered output.

## Files Changed

- `docs/deployment/gameservers-world-storefront.md`
- `docs/codex-history/2026/2026-07-15-1050-gameservers-world-hosting-homepage.md`
- `modules/billing/css/header.css`
- `modules/billing/includes/footer.php`
- `modules/billing/index.php`
- `modules/billing/serverlist.php`

## Architecture Decisions

- Kept `modules/billing` as the canonical Gameservers.World storefront inside the Panel repository.
- Removed homepage featured-service and runtime-location database queries entirely; the homepage no longer calls `billing_get_db()` or queries `billing_services`/`remote_servers`.
- Kept the full catalog, catalog SQL, service IDs, slot/price metadata, and order URLs on `serverlist.php`.
- Used a static public homepage location list with exactly Kansas City, Los Angeles, New York City, Dallas, and Ireland.
- Kept homepage CTAs to the same two customer actions: browse game servers and talk to the team.
- Left shared header navigation, login/logout behavior, account links, cart visibility, support/docs routes, and control-panel link behavior unchanged.
- Reworked the footer as structured brand, navigation, contact, legal/copyright, powered-by, and quiet last-updated areas.
- Updated only storefront presentation/documentation/history files; no payment, authentication, order, provisioning, or lifecycle logic was changed.

## Validation Performed

- `git status --short --branch`
- `git diff`
- `git show --stat --decorate --name-status a80ca98d`
- `git show --format=fuller --patch --find-renames a80ca98d`
- PHP syntax checks:
  - `php -l modules/billing/index.php`
  - `php -l modules/billing/serverlist.php`
  - `php -l modules/billing/includes/footer.php`
- Existing billing tests:
  - `php modules/billing/tests/BillingActionSecurityTest.php`
  - `php modules/billing/tests/BillingLifecycleServiceTest.php`
  - `php modules/billing/tests/BillingRequestPathSourceTest.php`
- Local HTTP render checks through `php -S 127.0.0.1:8077 -t modules/billing`:
  - `/index.php` returned 200
  - `/serverlist.php` returned 200
  - `/login.php` returned 200
  - `/company.php` returned 200
  - `/support.php` returned 200
- Local HTTP asset and link/path checks:
  - `/contact.php`, `/docs.php`, `/privacy.php`, `/tos.php`, `/careers.php`, `/server_status.php`, `/forgot_password.php`, `/register.php`
  - `/css/header.css`
  - `/images/brand/gsw-globe-icon.png`
  - `/images/brand/gsw-text-logo.png`
  - `/images/brand/favicon-16x16.png`
  - `/images/brand/favicon-32x32.png`
  - `/images/brand/apple-touch-icon.png`
- Static responsive review for:
  - 360px, 390px, 430px, 480px: compact header, dropdown nav, single-column content/footer, auto-width CTAs, compact location chips, overflow hidden at page/site shell.
  - 1366px, 1440px, 1600px, 1920px: centered sections, constrained hero, four-column trust strip, compact buttons, three-column footer plus legal row.
- Accessibility/static DOM review:
  - main landmarks on required pages
  - primary and footer nav labels
  - hamburger `aria-controls`, `aria-expanded`, and dynamic label behavior
  - heading order on homepage and catalog
  - focus-visible styling
  - touch target min-heights for nav/buttons/catalog actions
  - readable footer link groups and contact address
  - contrast intent preserved by existing black/blue/white system
- Homepage render/source scans confirmed:
  - no `gsw-catalog-card` or `Configure Server` appears on homepage
  - no `billing_services`, `remote_servers`, `featuredServices`, or `runtimeLocations` remains in homepage source
  - no internal phrases from the request remain on homepage
  - the exact five public locations appear once each
  - no hostnames, IP addresses, node IDs, or infrastructure internals are exposed in the homepage location block
  - no full-width purple catalog/footer buttons were introduced
- Final logic-sensitive diff scan confirmed no billing/payment/auth/order/provisioning code changed.
- `git diff --check`

Headless Chrome screenshot validation was attempted with `/usr/bin/google-chrome --headless=new --screenshot` at 360px. Chrome 148.0.7778.178 did not complete within a 30 second timeout and produced an empty log, so browser screenshot validation was omitted in favor of HTTP render, DOM/source, and CSS responsive verification.

The local `/panel/` control-panel link was not treated as a billing-server HTTP route because it points outside the standalone `modules/billing` PHP document root. The link behavior was preserved in `includes/menu.php`, which was not changed.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

`Refocus Gameservers.World hosting homepage`

Hash

Final immutable hash reported in the task response. The hash cannot be embedded in this committed file without changing the commit hash itself.

## Remaining Work

- Run a normal browser visual QA pass if exact pixel screenshots are required, because local headless Chrome did not complete screenshot capture in this environment.
