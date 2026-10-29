# Task

## User Request

Resume and complete the previously blocked Gameservers.World storefront redesign in `/home/dev/Projects/GSP/Panel`.

Modernize Gameservers.World so it is exciting, polished, and aligned with the Gameserver Engine product family. It is the official production host and demonstration environment for Gameserver Engine. The site must clearly state that it is managed by developers and gamers and that it is the official host of the Gameserver Engine project. Use the GSE cubes logo sourced from the `gameserverengine.com` production logo sheet/assets.

Preserve the existing PHP architecture, business logic, routes, security, billing, auth, catalog, cart, account, support, documentation, and panel behavior. Use optimized copied GSE assets rather than referencing the full source sheet. Validate, document, commit, and push if validation passes.

## Problem

The previous redesign attempt stopped because no verified Gameserver Engine cube/cubes brand asset was available inside the Panel repository. The active storefront also still used a drab legacy presentation, stale documentation still described `modules/website` as canonical, the shared header/footer lacked accessible mobile navigation, and several public pages could fatal when the billing database was unavailable.

## Root Cause

The canonical public storefront had been consolidated into `modules/billing`, but its presentation and some docs had not been updated after the duplicate `modules/website` site was removed. The verified Gameserver Engine brand source exists in the separate `/home/dev/Projects/gameserverengine.com` project, where `README.md` identifies `gsp-logo-sheet.png` as the production source sheet and `assets/brand/` as the optimized production asset output.

## Repositories Inspected

- `/home/dev/Projects/GSP`
- `/home/dev/Projects/GSP/Panel`
- `/home/dev/Projects/gameserverengine.com`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/development/CODEX_GUIDE.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/gameservers-world-storefront.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/APACHE_SETUP.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/MODULE_INDEX.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/billing.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/website.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-09-canonical-billing-storefront.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-13-0825-panel-billing-phase3-buttons.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-14-2045-gameservers-world-redesign.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/README.md`
- `/home/dev/Projects/GSP/Panel/modules/billing/bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/billing_bootstrap.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/site_config.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/config_loader.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/top.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/menu.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/includes/footer.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/css/header.css`
- `/home/dev/Projects/GSP/Panel/modules/billing/index.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/serverlist.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/docs.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/support.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/contact.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/company.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/careers.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/privacy.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/tos.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/server_status.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/login.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/register.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/forgot_password.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/reset_password.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/cart.php`
- `/home/dev/Projects/gameserverengine.com/README.md`
- `/home/dev/Projects/gameserverengine.com/gsp-logo-sheet.png`
- `/home/dev/Projects/gameserverengine.com/assets/brand/gse-icon-mark.png`
- `/home/dev/Projects/gameserverengine.com/assets/brand/gse-logo-header.png`
- `/home/dev/Projects/gameserverengine.com/assets/brand/gse-logo-full.png`
- `/home/dev/Projects/gameserverengine.com/assets/brand/gse-logo-compact.png`
- `/home/dev/Projects/gameserverengine.com/docs/codex-history/2026/2026-07-10-1123-rebrand-customer-website.md`
- `/home/dev/Projects/gameserverengine.com/docs/codex-history/2026/2026-07-10-1700-branding-install-guide.md`

## Files Changed

- `docs/deployment/APACHE_SETUP.md`
- `docs/deployment/gameservers-world-storefront.md`
- `docs/modules/MODULE_INDEX.md`
- `docs/modules/website.md`
- `docs/codex-history/2026/2026-07-14-2119-gameservers-world-redesign.md`
- `modules/billing/bootstrap.php`
- `modules/billing/careers.php`
- `modules/billing/cart.php`
- `modules/billing/company.php`
- `modules/billing/contact.php`
- `modules/billing/css/header.css`
- `modules/billing/docs.php`
- `modules/billing/forgot_password.php`
- `modules/billing/includes/config_loader.php`
- `modules/billing/includes/footer.php`
- `modules/billing/includes/menu.php`
- `modules/billing/index.php`
- `modules/billing/login.php`
- `modules/billing/privacy.php`
- `modules/billing/register.php`
- `modules/billing/reset_password.php`
- `modules/billing/server_status.php`
- `modules/billing/serverlist.php`
- `modules/billing/support.php`
- `modules/billing/tos.php`
- `modules/billing/images/brand/apple-touch-icon.png`
- `modules/billing/images/brand/favicon-16x16.png`
- `modules/billing/images/brand/favicon-32x32.png`
- `modules/billing/images/brand/favicon-48x48.png`
- `modules/billing/images/brand/gse-icon-mark.png`
- `modules/billing/images/brand/gse-logo-compact.png`
- `modules/billing/images/brand/gse-logo-full.png`
- `modules/billing/images/brand/gse-logo-header.png`

Removed untracked superseded report:

- `docs/codex-history/2026/2026-07-14-2045-gameservers-world-redesign.md`

## Architecture Decisions

- Kept `modules/billing` as the sole canonical Gameservers.World storefront.
- Preserved billing/auth/cart/order/payment/provisioning server-side behavior; changes are presentation, public fallback handling, shared shell, and documentation.
- Used optimized Gameserver Engine assets copied from `/home/dev/Projects/gameserverengine.com/assets/brand/`; did not reference the full production source sheet from storefront HTML/CSS.
- Header uses `gse-logo-header.png`, hero uses `gse-icon-mark.png`, powered-by section uses `gse-logo-full.png`, footer uses `gse-icon-mark.png`, and favicons/apple touch icon use the optimized favicon set.
- Removed the legacy homepage mixed-game collage/banner from the hero and replaced it with GSE cube branding and platform visuals.
- Catalog and infrastructure homepage areas read existing runtime data when the billing DB is available and degrade to nonfatal explanatory panels when unavailable.
- Public server status no longer displays agent IP addresses; it uses configured runtime server names and status data only.
- Shared header/navigation/footer preserve helper-driven billing URLs, login/logout, account, cart badge, admin visibility, support, contact, docs, and control-panel behavior.
- `includes/config_loader.php` and `bootstrap.php` now support public degraded rendering when `includes/config.inc.php` or DB access is unavailable.
- Corrected stale docs that described `modules/website` or `_website` as the active public storefront.

## Validation Performed

- `git status --short --branch`
- `git diff`
- `git log --oneline -n 12 --decorate`
- `file` and `sha256sum` checks for source and copied GSE assets.
- Visual inspection of `gse-logo-header.png`, `gse-logo-full.png`, and `gse-icon-mark.png`.
- PHP syntax checks:
  - `php -l modules/billing/bootstrap.php`
  - `php -l` for changed storefront PHP files and shared includes.
- Existing billing tests:
  - `php modules/billing/tests/BillingActionSecurityTest.php`
  - `php modules/billing/tests/BillingLifecycleServiceTest.php`
  - `php modules/billing/tests/BillingRequestPathSourceTest.php`
- Missing-config/degraded public render checks with CLI PHP for:
  - `index.php`, `serverlist.php`, `docs.php`, `support.php`, `contact.php`, `company.php`, `careers.php`, `privacy.php`, `tos.php`, `server_status.php`, `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`
- Local HTTP render checks through `php -S 127.0.0.1:8077 -t modules/billing`; all checked public pages returned HTTP 200 and non-empty output.
- Local HTTP asset checks for `css/header.css` and copied brand assets; all returned HTTP 200.
- `git diff --check`
- Static scan for stale storefront CDN/logo/banner/source-sheet references in changed active public files.
- Static accessibility/responsive inspection confirmed landmarks, labels on changed auth forms, meaningful logo alt text, focus-visible styling, accessible mobile nav toggle with `aria-expanded`, responsive breakpoints, overflow containment, and `prefers-reduced-motion` handling.

Browser screenshot validation was attempted with Google Chrome headless and Firefox. Chrome did not complete within the timeout in this environment; Firefox is a snap stub and is not installed. Responsive validation therefore used static CSS inspection plus CLI/HTTP render checks.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

`Redesign Gameservers.World storefront`

Hash

Pending until commit creation; final immutable hash is reported in the task response.

## Remaining Work

- Run a visual browser QA pass in a workstation/browser environment because local headless Chrome and Firefox screenshot tooling was unavailable.
- Review any older diagnostic/tooling files that still mention `_website` if those tools are still used; they were not part of the public storefront redesign.
