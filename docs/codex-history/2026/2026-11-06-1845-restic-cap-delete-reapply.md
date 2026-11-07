# Task

## User Request

Re-apply the restic panel fixes lost when the core checkout was reset: upload cap, Delete button on the customer backup page, account setup quoting fix, and the admin require. Work in the development clone on `working`, then merge to `testing`.

## Problem

- The core checkout was re-cloned and reset to `testing` (d1125aad) on 2026-11-06, dropping uncommitted panel changes and two unpushed commits.
- Backups from the live panel ran without an upload cap.
- Customer backups had no Delete action.
- Account setup (set/purge) failed with exit 126 because the rest user was quoted.
- The admin page called restic_rest_base_valid() without requiring restic_lib.php.

## Root Cause

Panel edits were made in place on the core checkout instead of a clone with a branch. The checkout was reset by an outside change, so nothing was pushed to hold the work.

## Repositories Inspected

- GSE-Panel (development clone, /home/dev/projects/gse-panel): branches working and testing, both at d1125aa before this change.
- GSE-Agent-Windows: local working has an unpushed commit 410b5e9 and an uncommitted cap change in AgentScripts/gse_agent.pl.

## Files Read

- AGENTS.md, docs/TODO.md
- modules/backup-restore/admin.php, function.php, restic_lib.php, backup-restore.php, tests/restic_lib_test.php

## Files Changed

- modules/backup-restore/admin.php: require restic_lib.php.
- modules/backup-restore/function.php: send the rest user unquoted for set and purge; Delete button in the list; restic_do_delete and restic_render_delete_confirm.
- modules/backup-restore/restic_lib.php: RESTIC_UPLOAD_KBPS = 2048; backup argv starts with --limit-upload.
- modules/backup-restore/backup-restore.php: deleteConfirm and deleteNow actions.
- modules/backup-restore/tests/restic_lib_test.php: expected argv includes the cap.
- docs/TODO.md: T-007 to T-010.

## Architecture Decisions

- The cap is set in the panel and passed in the job argv. The agent checks that the flag is in the allowed range before running restic.
- Delete uses restic forget --prune on a single snapshot id, then removes the panel row only after restic succeeds.
- Baseline backups are not deletable from the page.

## Validation Performed

- php -l on all changed PHP files: no syntax errors.
- php tests/restic_lib_test.php: all passed.

## Git Commits

See the commit on working and the matching merge to testing.

## Remaining Work

- Agent side: the cap flag is accepted by the KCWIN agent only after the agent change is deployed (GSE-Agent-Windows).
- Deploy this panel commit to the core checkout from git.
- Browser test of Delete on a non-baseline backup.
