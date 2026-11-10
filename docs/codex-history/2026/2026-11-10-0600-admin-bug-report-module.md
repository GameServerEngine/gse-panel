# Task

## User Request

Version 2.7.0: admin bug report module that creates a GitHub issue in GameServerEngine/gse-panel.

## Problem

Admins had no way to report a bug or request a feature from the panel.

## Root Cause

No such module existed.

## Repositories Inspected

Panel (this repository).

## Files Read

AGENTS.md, docs/TODO.md, modules/update/module.php, modules/update/navigation.xml, modules/administration/module.php, includes/database_mysqli.php (settings, logger).

## Files Changed

modules/bugreport/ (module.php, navigation.xml, bugreport.php, bugreport_lib.php, tests/bugreport_test.php), VERSION, includes/panel_version.php, docs/TODO.md.

## Architecture Decisions

- Logic lives in bugreport_lib.php with no database or session use; the HTTP poster is injected so tests never call GitHub.
- Token is stored in the settings table (bugreport_github_token), is write-only in the form, and is never logged.
- Menu entry comes from $module_menus (group admin), the same way the Update module registers.

## Validation Performed

php -l on every changed PHP file; modules/bugreport/tests and modules/update/tests run.

## Git Commits

Repository: Panel. Branch: working. Hash: see git log (2.7.0).

## Remaining Work

Browser test, real issue post, install of the module on a test panel (module manager).
