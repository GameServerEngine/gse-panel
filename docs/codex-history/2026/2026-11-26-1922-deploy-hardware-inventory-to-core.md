# Task
Deploy the Phase 2 hardware inventory (panel side) to the live panel on core, with backups and checks.

## User Request
"Yes and you update": take a backup of the live panel first, then install the new panel release on core. Earlier the operator chose to run the manual Release workflow on `working` and install `working-latest`.

## Problem
Core runs the production panel (2.28.5, not a git checkout). The panel's updater is a web page; releases are built only by a manual GitHub workflow; testing and working have diverged, so promotion by fast-forward is not possible. The inventory code was only in git.

## Root Cause
Not a defect: a deployment. One defect was found on the way (mine): the inventory hook passed the `DB_PREFIX` constant to code that uses the raw mysqli connection. `DB_PREFIX` is the literal placeholder string "DB_PREFIX" (includes/database.php), replaced only by the wrapper's query()/resultQuery(). The hook would have created tables named `DB_PREFIXagent_hardware_inventory`. My first integration test defined `DB_PREFIX` as a real prefix, which hid it.

## Repositories Inspected
gse-panel (changed). GitHub Actions run history for gse-panel and gse-agent-linux (read only).

## Files Read
AGENTS.md, CLAUDE.md, BRANCHING.md, .github/workflows/release.yml, modules/administration/panel_update.php (source, runtime_state_paths), tools/post_update_cli.php, includes/database.php, includes/database_mysqli.php, modules/server/agent_helpers.php, modules/billing/module.php; core: panel_update_log, settings, modules table, Apache error log, file modes.

## Files Changed
Repository: includes/agent_inventory.php (prefix guard), modules/server/agent_helpers.php (uses getTablePrefix()), tests (agent_inventory_test, agent_inventory_db_test, agent_refresh_inventory_test), docs/TODO.md.
Core (/var/www/html/GSE/Panel): 16 files from release working 2.28.6, then 8 files from working 2.28.7. install.php and the seven runtime files the updater keeps were not touched. Between the two, agent_helpers.php was put back to the 2.28.5 copy from the backup to switch the hook off.

## Architecture Decisions
- Used the release ZIP (the artifact the updater installs) and copied only the files a dry run showed as different. The panel's updater is a web page and has no CLI entry; tools/post_update_cli.php is the CLI post-step.
- Did not run post_update_cli.php: the billing module's database version is 17 on core and 18 in the code (migration 18 = order_options_install_sql, commit a4150477). That gap existed before this work, is not mine, and the step would apply it as a side effect.
- Excluded install.php: the live panel deliberately has none.
- Backups in /var/backups/gse-panel (root only, outside the web root): files before 2.28.6, a database dump (127 tables), files before 2.28.7.
- Fix: the hook takes the prefix from the existing getTablePrefix(); agent_inventory_prefix_ok() refuses the placeholder and any prefix that is not letters, digits and underscores (an empty prefix, as on core, is allowed).
- Did not touch the testing or stable branches.

## Validation Performed
Dry runs before each apply (17 differences for 2.28.6, 8 for 2.28.7, all expected); a second dry run after each apply was empty; php -l on core; modes 640 www-data; config.inc.php untouched; panel and storefront HTTP 200 with no error text; install.php 404; no matching Apache errors; unit tests run under core's PHP.
Production end-to-end check: the real agent_refresh_snapshot() against agent 7 (game3, older agent): snapshot saved, inventory recorded as unsupported, a second refresh did not ask the agent again, table agent_inventory_state created with the plain name, no DB_PREFIX tables.
Regression: 66 of 73 panel test files pass, identical before and after the fix. The new integration test fails against the old hook (5 failures) and passes with the fix.
Mistakes made and caught: (1) 2.28.6 was installed with the placeholder bug; found by a read-only check before any table was created, switched off within about two minutes by restoring agent_helpers.php, no wrong tables existed. (2) My first wait for the 2.28.7 release saw the previous run and downloaded the old ZIP; the content check caught it before anything was deployed.

## Git Commits
Repository: gse-panel, branch working. 1dfa6443, 05e0f6b2, e95b348d, 64157cbb, d5461e7d, 72c852aa (the fix). CI bump commits: 5abcab11 (2.28.6) and 8fe916aa (2.28.7).
Releases: working 2.28.6 (installed, then hook switched off), working 2.28.7 (installed).

## Remaining Work
Install the inventory build on the agents (they follow the testing channel; needs a decision). Billing migration 18 on core. agent_inventory_latest() has no guard for a missing table. BRANCHING.md and the release.yml header describe automatic bumps on push, but the workflow is manual only. See docs/TODO.md.
