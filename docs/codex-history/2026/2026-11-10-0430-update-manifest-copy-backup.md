# Task

## User Request

Update 2.6.0 (GitHub issue #3): repair ownership and modes before the update, keep update_manifest.json and update_exclusions.txt, review edited files before the install, back up with a plain cp -a copy, add an Exclusions section, add tests.

## Problem

The 2.5.10 updater refused to run when ownership was wrong, overwrote edited files, and backed up to a tar.gz archive.

## Root Cause

Ownership was only checked, never repaired. There was no record of what the update installed, so edits could not be told apart from release content.

## Repositories Inspected

Panel (this repository).

## Files Read

AGENTS.md, docs/TODO.md, modules/update/update_safety.php, modules/administration/panel_update.php, tools/install_panel_cli.php, scripts/install-panel.sh, modules/update/tests/*.

## Files Changed

includes/panel_permissions.php (new), modules/update/update_manifest.php (new), modules/update/update_safety.php, modules/administration/panel_update.php, tools/install_panel_cli.php, modules/update/tests/ (update_manifest_test.php, edited_files_test.php new; ownership_check_test.php, update_safety_test.php changed), VERSION, includes/panel_version.php, docs/TODO.md.

## Architecture Decisions

- One permissions definition (includes/panel_permissions.php) used by the installer CLI and the updater; owner/mode functions are injected so tests need no root.
- update_exclusions.txt is the file a person edits; when it exists its list becomes the manifest list.
- Backup copy lives in <backup>/panel/ next to database.sql and backup.json so a restore never copies those into the panel.
- The manifest is written only after the update succeeded, so a rollback never has to undo it.

## Validation Performed

php -l on every changed PHP file; every test in modules/update/tests run (counts in the final report).

## Git Commits

Repository: Panel. Branch: working. Hash: see git log (2.6.0).

## Remaining Work

Browser test of the review and Exclusions forms; live test of an update and a forced rollback; first-update baseline (see docs/TODO.md).
