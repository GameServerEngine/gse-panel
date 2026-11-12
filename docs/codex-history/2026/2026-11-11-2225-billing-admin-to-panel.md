# Task

## User Request
All configuration pages belong in the control panel. modules/billing is only the customer-facing storefront (order, pay, invoices, history) on shared logins and data. Move the website admin pages (such as adminserverlist.php) into the panel Administration menu under one Billing page with subpages, delete the website admin pages and all container features, make games chosen in the panel show on the storefront, add a ticket form on the support page that inserts into the panel tickets module, and give modules/billing the GameServer Engine look (logo, imagery, content in the white section).

## Problem
- Admin pages existed twice: standalone on the website and inside the panel (Billing Setup / Billing Operations).
- Games enabled in the old website admin page were written to `billing_services`, which the storefront does not read (it reads `catalog_offer`), so they never appeared.
- Placement headroom could only be edited on the old website page.

## Root Cause
The billing design moved to the panel, but the website admin pages, the old pricing page and the container proof of concept were never removed, and the old page kept writing a table the storefront no longer reads.

## Repositories Inspected
gse-panel (branch working)

## Files Changed (stage 1: panel consolidation and catalog fix)
- modules/billing/panel_admin.php (one Billing page, eight tabs, old keys kept as aliases)
- modules/billing/navigation.xml, module.php (db_version 14, version 3.7, one Billing menu row), install/gse_panel.sql (menu row and module version hand-edited)
- modules/billing/includes/admin_auth.php (outside the panel, redirect to the panel Billing page)
- modules/billing/includes/menu.php (Admin link and admin lookup removed, "My Containers" renamed "My Servers"), my_servers.php (container block removed)
- modules/billing/admin_catalog.php, includes/catalog_games.php, includes/catalog_admin.php (placement headroom form, import with saved agents), tests/CatalogGamesTest.php
- modules/billing/admin_config.php, admin_orders.php, test_integration.php, PANEL_INTEGRATION.md, COUPON_SYSTEM.md (links)
- Deleted: admin.php, adminserverlist.php, admin_xml_editor.php, admin_container_poc.php, container_poc.php, container_action.php, includes/container_poc.php, tests/ContainerPocWorkflowTest.php, tools/debug_invoices_redirect.php
- docs/TODO.md, CHANGELOG.md, .github/module-map.md, .github/agent.md, .github/copilot-instructions.md

## Files Changed (stage 2: My Servers and support tickets)
- modules/billing/my_servers.php (query moved from the missing tables `home` / `game_configs` to `server_homes` / `config_homes`, newest order per server)
- modules/billing/includes/support_tickets.php (new), support.php (ticket form and list), tests/SupportTicketsTest.php (new)
- docs/TODO.md, CHANGELOG.md, .github/module-map.md

## Architecture Decisions
- The support form writes the same rows as the panel's tickets module (tickets plus the first ticket_messages row, status 3 = waiting for staff, last_updated set, sub users belong to the main account); service_id is NULL for "no server". No get_result (works without mysqlnd).
- Module-native `db_version` 14 replaces the old menu rows (idempotent, runs on update); no separate sql/migrations file.
- Old page keys billing_setup, billing_operations and game_catalog still resolve so saved links work.
- The XML editor was dropped: the config_games module already has one.
- Container database tables (migration 0004) are left untouched.
- Import with saved agents intersects each old row's `remote_server_id` list with agents that exist, plus any ticked agents.

## Validation Performed
- php -l on changed files; all billing tests pass except GameReferenceCoverageTest.php, which already failed before this work (arma2 missing from games.json).
- Scratch MySQL database (127.0.0.1:3307, gse_scratch_p2, install/gse_panel.sql plus migrations 0007 and 0008, synthetic rows): the db_version 14 queries run twice leave one Billing row; import with saved agents, second import, headroom insert/update/read: 12 checks passed.
- Support tickets on a scratch MySQL 8 database loaded with an empty table prefix (as in production): 12 checks (own servers only, with and without a server, IPv4 and IPv6 address bytes, sub user parent, list, all-or-nothing rollback); SupportTicketsTest.php passes.
- Not run in a browser. Nothing deployed and no live database touched.

## Files Changed (stage 3: GameServer Engine restyle)
- modules/billing/css/header.css (rewritten: light page, white content section, GSE header and footer, tokens from gameserverengine.com), includes/menu.php (GSE header, opens .gsw-page and .gsw-content), includes/footer.php (closes them, GSE footer), includes/theme_head.php (Inter font and header.css only)
- Pages with their own inline styles moved onto the shared theme: login.php, forgot_password.php, reset_password.php (inline style removed), cart.php, docs.php, order.php, payment_success.php (page-only rules kept after the theme link), ai.php (now a full page with header, footer and styles), payment_success.php (now shows header and footer)
- modules/billing/support.php (rewritten: the Open a Ticket form from stage 2 was missing from the tree; helper file and tests were committed but the page was not)
- modules/billing/images/brand: new favicons, apple-touch-icon, gse-hero-artwork.webp, gse-logo-footer.png; removed gsw-globe-icon, gsw-globe-logo, gsw-text-logo
- docs/TODO.md, CHANGELOG.md

## Architecture Decisions (stage 3)
- One shell: menu.php opens `#gsw-site > .gsw-page > .gsw-content`; footer.php closes it. Every page that includes the menu and footer therefore has its content in the white section; page wrappers (main, .container, .container-wide, .site-panel, .gsw-section) are neutralised inside it.
- Page-only CSS stays in the page, after the theme link, so it wins over the shared rules; shared rules (buttons, forms, tables, alerts, panels) live only in header.css.
- The storefront no longer loads the panel's SimpleBootstrap files; the pages only used `.btn`, `.btn-secondary` and `.btn-small` from them, now defined in header.css.
- Control Panel link is always shown (it points to /panel/).

## Validation Performed (stage 3)
- php -l on every changed file; CatalogGamesTest and SupportTicketsTest pass.
- Served the module with php -S: 26 storefront pages return 200 with the content section and footer present and no PHP errors (pages that need a database show their normal no-database output).
- Support page end to end on the scratch MySQL database through a copy of the module and a throwaway panel config (removed afterwards): form lists only the customer's own servers; a ticket on an own server and one with no server are saved (status 3, one first message, address bytes, parent id); another customer's server and a bad CSRF token are refused with no row; the list shows both tickets. Test rows deleted.
- Class coverage script: every class used on storefront pages is either styled or only a script hook.
- Not run in a browser (no browser on the dev host): layout, colours and the mobile menu still need a look on the test site.

## Git Commits
Repository: gse-panel, branch working. Hash: see git log.
