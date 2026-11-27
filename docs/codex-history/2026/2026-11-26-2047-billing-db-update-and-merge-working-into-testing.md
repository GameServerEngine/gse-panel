# Task
Update core's billing database, merge working into testing in all three repositories, and report whether production is current.

## User Request
"Update the billing db. Lets merge working into testing. And is everything current new on prod."

## Problem
Core's billing module was at database version 17 while its code said 18. The testing branches had diverged from working in all three repositories, and the repo's sync-testing workflow would have force-reset the panel's testing branch.

## Root Cause
Versions since 2026-11-18 reached core without the updater's post-update step, so billing migration 18 (commit a4150477) was never applied. The testing branches only differed from working by merge commits (panel) and CI release-bump commits (agents), which is why a merge was clean.

## Repositories Inspected
gse-panel, gse-agent-linux, gse-agent-windows (all three changed on testing); core's panel database and Apache logs; game3, game5 and game6 (read only).

## Files Read
tools/post_update_cli.php, includes/helpers.php (runPostUpdateOperations, removeOldPanelFiles), modules/cron/shared_cron_functions.php (updateCronJobsToNewApi), includes/functions.php (updateAllPanelModules, update_module), modules/billing/includes/order_options.php, .github/workflows/sync-testing.yml and release.yml in all three repositories.

## Files Changed
Core database only: billing db_version 17 to 18; new tables billing_order_options and billing_invoice_options (InnoDB); one option "Server Setup" at 5.00 enabled; service type server-setup set to disabled (it was enabled); Lite File Manager registered version 1.0 to 1.1 (code already 1.1). No panel files changed on core in this task.
Git: merge commits on testing in all three repositories; documentation commits on working.

## Architecture Decisions
- Used the repo's own tools/post_update_cli.php. Checked first that its other steps are no-ops here: modules/cron/update.check exists on core (the cron-job rewrite, which would write an invalid token from the CLI, is skipped) and includes/database_mysql.php is absent.
- Took a fresh database dump (128 tables) before running it.
- Merged with real merge commits and plain pushes. Did not use sync-testing, which force-resets testing when it has diverged.
- Dry-ran each merge in a throwaway worktree first: no conflicts. All release workflows are manual (workflow_dispatch), so pushing testing built nothing.
- Testing keeps its higher agent versions (Linux 1.8.1, Windows 1.8.0) because the bump commits exist only on testing.

## Validation Performed
Before and after capture of the modules and billing_service_types tables; the only differences are the ones listed above. Module versions: 39 compared, 0 differ between database and code. Panel and storefront return 200 with no error text after the update. Merged testing: panel lint and tests pass; Windows agent compiles and its 45 inventory checks pass; Linux agent compiles and its contract validator passes (needs stub Cfg modules, because Cfg/Config.pm is git-ignored and absent from a clean checkout).
Currency check: core 2.28.7 equals working and testing; game5 and game6 run Linux agent 1.8.0 (testing-1.8.1 exists); game3 reports Windows agent 1.7.0 (testing-1.8.0 exists); none has the inventory build.

## Git Commits
Repository: gse-panel, testing 9730384e. Repository: gse-agent-linux, testing bfa93cf. Repository: gse-agent-windows, testing 5b3a8f79. Later documentation merges are in git log.
Database backup: /var/backups/gse-panel/panel-db-before-billing18-* on core.

## Remaining Work
No testing release has been built for the merged code. Building one (Run workflow, Release, on testing) and updating the agents is the next step. Stable is far behind (panel 2.28.4, latest stable release 2.24.0). See docs/TODO.md.
