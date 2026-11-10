# Task

Make the panel update (modules/administration/panel_update.php, scripts/install-panel.sh) always succeed or cleanly roll back, including database migrations. Version 2.5.5.

## User Request

Update installs one selected release (version + ZIP URL), no channel concept. Preflight before any change, stage and validate the ZIP before backup, ownership-blind restore, files and database restored together with verification, installer must not leave root-owned trees, tests, version 2.5.5. Work only on branch `working`; no merge, no deploy.

## Problem

- Updates failed halfway on permission errors (root-owned files) and left files and database out of step.
- copy_file() hid failures; component_copy_tree() ignored them.
- do_revert() treated a failed database restore as a warning and reported success.
- tar restore tried to restore owner, permissions and mtimes.
- The installer ran unzip and rsync as root and left root-owned trees.
- Channel mapping (stable/unstable) was baked into the update settings and the installer.

## Root Cause

The update changed files before proving it could write them, never staged or validated the release first, and its rollback did not treat files and database as one unit or check the result.

## Repositories Inspected

Panel (gse-panel) only.

## Files Read

AGENTS.md, CLAUDE.md, docs/TODO.md, modules/administration/panel_update.php, modules/update/migration_runner.php, release_source.php, unzip.php, existing tests in modules/update/tests, scripts/install-panel.sh, tools/install_panel_cli.php (--repair-permissions), includes/install_engine.php.

## Files Changed

- modules/update/update_safety.php (new): preflight, staging validation, copy_file result, tar restore command, transaction + rollback + verification.
- modules/administration/panel_update.php: uses the new helpers; channel mapping removed; legacy update_channel/update_branch read only to build a release ZIP URL.
- modules/update/tests/update_safety_test.php (new), update_config_smoke.php (updated).
- scripts/install-panel.sh, VERSION, includes/panel_version.php, docs/TODO.md.

## Architecture Decisions

- Order: preflight (changes nothing) -> stage + validate in temp -> release-target write check -> backup (files + dump) -> copy -> restore runtime files -> migrations -> write version -> verify. Any failure after the backup restores files AND the database dump, drops tables created during the failed run, removes files the failed run created, then checks VERSION and schema_migrations against the state captured before the backup.
- The update returns success only after that check passes; otherwise it names the failed rollback step and keeps the backup path in the message and the log.
- Database dump is now required for a backup (before, a missing config skipped it silently).
- The installed version comes from the staged VERSION file (before, the channel name was saved as the version).
- panel_update_log keeps its column names: `channel` holds the version, `branch` holds the source ZIP URL (cut to its column size). No schema change.
- fix_permissions() only chmods; documented in a comment. Ownership is fixed by install-panel.sh --repair-permissions, which the preflight names in its error.
- Installer: rsync --no-owner --no-group, extracted tree chowned to the web user, install path chowned after copy, repair_permissions is required and the install fails if it or its owner check fails. --channel is rejected; --release-zip-url chooses the release. Existing-LAMP-first policy untouched.

## Validation Performed

See the final report of the task: update_safety_test (41 checks), update_config_smoke, all other modules/*/tests, php -l, bash -n, shellcheck. Five existing tests fail the same way before and after this work.

## Git Commits

Repository | Commit | Hash
Panel | branch working | see `git log working`

## Remaining Work

- Run an update on the test panel (not the live one) with a real failing migration to see the rollback end to end.
- Release files in sql/migrations named like `2026-10-28-name.sql` do not match the runner's NNN_name pattern and never run. Staging now logs a warning for them.
- Not tested here: a full mysqldump/mysql restore against a real database, and the installer run as root.
