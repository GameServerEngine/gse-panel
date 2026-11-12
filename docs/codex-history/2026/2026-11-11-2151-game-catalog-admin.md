# Task

## User Request
The billing_setup page is hard to read. The storefront Game Catalog shows "No Published Services". The game tables are in the database but there is no admin page to enable a game, set its prices, and choose which servers (agents) it is sold on. Step one: edit the main details of the games and show and rent them on the billing module website. Edit the gse-panel repo, commit and push when done.

## Problem
- The storefront reads `catalog_offer`, which was empty. Nothing could fill it: the Game Catalog editor was left out of the menu when the billing menu was consolidated (module db_version 13), and the page that still existed (`admin_catalog.php`) wrote the old `billing_services` table, which the storefront no longer reads.
- Billing Setup used dark grey text colours written for a light page, on the dark panel.

## Root Cause
Catalog moved to `catalog_offer` / `catalog_tier` (migration 0007) without an admin page that writes them. The old `billing_services` rows have no agent id that still exists, so they cannot be turned into offers by themselves.

## Repositories Inspected
gse-panel (branch working)

## Files Read
AGENTS.md, docs/TODO.md, CHANGELOG.md, modules/billing/{panel_admin,admin_catalog,admin_config,serverlist,order}.php, includes/{catalog_storefront,catalog_pricing,storefront_locations}.php, tests/CatalogPricingTest.php, css/header.css, sql/migrations/0007_game_catalog.sql

## Files Changed
- sql/migrations/0008_catalog_game.sql (new table `catalog_game`: display name, description, picture per game)
- modules/billing/includes/catalog_games.php (new, no database: clean text, pick picture, read the price form, price periods, import plan)
- modules/billing/includes/catalog_admin.php (new, database: agent/game lists, save game, import)
- modules/billing/admin_catalog.php (rewritten: list, edit page, import tool, CSRF, admin check)
- modules/billing/panel_admin.php (Game Catalog tab in Billing Setup)
- modules/billing/includes/catalog_storefront.php, serverlist.php, order.php, css/header.css (show display name, description, picture; tier games get a "from" price; works before migration 0008)
- modules/billing/admin_config.php (readable colours)
- modules/billing/tests/CatalogGamesTest.php (new)
- docs/TODO.md, CHANGELOG.md, .github/module-map.md

## Architecture Decisions
- Build on `catalog_offer` (one row per game and agent). One price is set per game and applied to every ticked agent. Only monthly prices are typed; daily is monthly / 30 and yearly is monthly x 12.
- A game is sold on an agent when it is ticked on the game's page. Unticking disables the offer, it does not delete it. Agents with installs off or no city are shown with a note; the storefront already hides them.
- Legacy import is opt-in, previews first, never changes existing rows, and the admin picks the agents because the old server ids are gone.
- Storefront checks whether `catalog_game` exists, so it keeps working before the migration runs.
- Readability fix uses `inherit` and translucent borders so it follows the panel theme.

## Validation Performed
- php -l on every changed file.
- tests/CatalogGamesTest.php and the other billing tests pass; GameReferenceCoverageTest.php already failed before this work (arma2 missing from games.json).
- Scratch MySQL 8 database (127.0.0.1:3307, scratch_catalog, loaded from install/gse_panel.sql plus migrations 0007 and 0008, synthetic rows): 39 checks of save, tiers, unpublish, import, storefront queries, and the pre-0008 fallback; all passed. The admin page was rendered in CLI: list, edit form, bad CSRF refused, good save, picture path and HTML tags cleaned, non-admin refused.
- Not run in a browser. Nothing deployed and no live database touched.

## Git Commits
Repository: gse-panel, branch working. Hash: see git log.
