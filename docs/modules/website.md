# Website

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

## Purpose

This document is retained as a compatibility pointer for older references. The canonical public Gameservers.World storefront no longer lives in `modules/website`.

## Canonical Runtime Location

- Public storefront, catalog, account entry, cart, support, documentation, and legal pages: `modules/billing/`
- Removed duplicate legacy public site: `modules/website/`

Do not recreate `modules/website` as a second active storefront. Public storefront work belongs in `modules/billing` unless a future architecture decision explicitly replaces the current deployment model.

## Storefront Visual System

The shared storefront shell is implemented by:

- `modules/billing/includes/menu.php`
- `modules/billing/includes/footer.php`
- `modules/billing/css/header.css`

Public pages should reuse the shared shell and billing helpers so root and non-root deployments keep working. Preserve login/logout behavior, account links, cart badge behavior, admin visibility, support/contact/docs routes, and control-panel behavior.

## GameServers.World Branding

GameServers.World is the official production hosting storefront and demonstration environment for the GameServer Engine (GSE) product family. It lives at `modules/billing` inside the Panel repository.

The active storefront brand uses the uploaded GameServers.World PNG assets:

- Globe source: `/home/dev/Projects/gse-globe-logo.png`
- Text source: `/home/dev/Projects/gse-text-logo.png`
- Optimized production assets are copied into `modules/billing/images/brand/`
- The storefront must not reference source assets outside the repository directly

## Design Rules

- Public pages must load without fatal errors when the storefront database is unavailable.
- Path and URL handling must use billing helpers where available.
- Catalog pages may degrade gracefully when billing data is unavailable.
- Marketing presentation must avoid unlicensed third-party game art; use GameServers.World branding, platform visuals, geometric treatments, and runtime/catalog data.
- Messaging should sell hosted game servers first. GameServer Engine should appear as a concise credibility statement on the homepage and be explained on the Company page.
- Do not invent unsupported guarantees, locations, prices, statistics, SLAs, customer counts, or open-source claims.
