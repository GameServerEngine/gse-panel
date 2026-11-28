# Task
Resume GSE resource intelligence and capacity-based placement: verify the work a Codex run left uncommitted, fix failures, document rollout.

## User Request
Resume the work in gse-panel and gse-agent-linux. Inspect the missing Windows agent tree read-only and defer it if it is not found. Do not touch the gse-panel-agent-deploy worktree. Rerun the resource model, storefront location, configuration view, placement flow, inventory and main-thread tests. Review checkout and provisioning parity, migrations, and the CS2 disk example (game5 43 GiB free fails, game6 129 GiB passes disk only). No reset, stash, commit, push, deploy, live change or benchmark. Do not invent results.

## Problem
The earlier Codex run stopped on its usage limit, and an earlier Claude run was blocked on permissions. The tests had never been run together, and the Linux agent's main-thread test failed.

## Root Cause
- `process_window()` in the Linux agent's agent_core.pl read `$a` and `$b` (sort globals) instead of its `$first_detail` and `$last_detail` arguments. Every `usage.<id>` line would have lacked CPU seconds and IO bytes.
- `BillingLifecycleService::adminUpdateInvoicePayment` contained a copy of the placement gate that used variables the function never defines. It never ran, and it printed warnings in the test.
- `SlotsDisabledTest` matched a SQL string in storefront_locations.php that the rewrite had moved to the catalog loader.

## Repositories Inspected
gse-panel (changed), gse-agent-linux (changed). The gse-panel-agent-deploy worktree was read only through `git ls-tree` of its branch (committed migrations end at 0016). gse-agent-windows was outside the session's allowed directories, so it was not inspected.

## Files Read
AGENTS.md, CLAUDE.md, docs/TODO.md (both repos), the Codex stderr log, the earlier Claude report, includes/resource_model.php, tests/resource_model_test.php, migrations 0017 and 0018, modules/update/migration_runner.php, modules/billing/includes/{storefront_locations,placement_store}.php, BillingLifecycleService.php, the agent's status_sample code.

## Files Changed
- gse-agent-linux: agent_core.pl (process_window), docs/PANEL_INTEGRATION.md, docs/TODO.md.
- gse-panel: modules/billing/classes/BillingLifecycleService.php (removed the dead gate), modules/billing/tests/SlotsDisabledTest.php, docs/GSE-RESOURCE-PLACEMENT-ROLLOUT.md (new), docs/TODO.md, this record.
- Not changed by this run, but part of the same uncommitted work: the 23 files of Codex's earlier run (see git status).

## Architecture Decisions
No redesign. The fail-closed gate stays: unknown, stale or uncalibrated input rejects. Public placement stays closed until approved calibrations and profiles are stored by an operator. Migration numbering is documented, not changed.

## Validation Performed
Passed: tests/resource_model_test.php; StorefrontLocationTest; PlacementFlowTest; PlacementCapacityTest; node ConfigurationViewTest.js; tests/game_usage_test.php; tests/agent_inventory_test.php; tests/status_sync_test.php; tests/game_query_port_test.php; BillingLifecycleServiceTest (warnings gone); PayPalCheckoutTest; StripeBillingTest; BillingActionSecurityTest; CatalogPricingTest; CatalogGamesTest; BillingRequestPathSourceTest; StatusSnapshotTest; CatalogAdminGridTest; ServicesCatalogTest; OrderOptionsInstallTest; SlotsDisabledTest (after the test fix); modules/update migration_runner_test; agent tests/status_main_thread_test.pl (13 of 13 after the fix), inventory_linux_test.pl, gamemanager_desired_state_test.pl. php -l passed on all 23 changed or new PHP files.
Not run or failing: tests/agent_inventory_db_test.php (skipped, no test DB); tests/check_schema_drift.php (fails, 85 tables, already in TODO); `perl -c agent_core.pl` (Frontier module missing); migrations 0017 and 0018 (no scratch MySQL, Docker denied); real MySQL row locking; the Windows agent; browser checks of the storefront.

## Git Commits
None. The instructions forbid committing in this run.

## Remaining Work
See the "Not done / blockers" section of docs/GSE-RESOURCE-PLACEMENT-ROLLOUT.md and the new rows in docs/TODO.md.
