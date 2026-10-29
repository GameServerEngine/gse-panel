# Task

## User Request

Fix the GSP Panel game-server startup log AJAX endpoint so `modules/gamemanager/get_server_log.php` bootstraps database access correctly, rejects unauthenticated or unauthorized log access, validates `home_id` and `mod_id`, preserves existing remote agent log behavior, avoids raw PHP fatal output, updates durable documentation, validates the implementation, commits the Panel changes, and pushes only when an intended upstream exists.

## Problem

The server start page continued to poll `modules/gamemanager/get_server_log.php` every two seconds, but the AJAX endpoint could fatal before returning log content. That broke the live startup log pane while the underlying start/status workflow could still show `STARTING`.

## Root Cause

`get_server_log.php` was a standalone endpoint that included `includes/functions.php`, `includes/lib_remote.php`, and the XML parser, but did not include `includes/helpers.php`. `createDatabaseConnection()` is defined in `includes/helpers.php`, so direct endpoint requests could fail with `Call to undefined function createDatabaseConnection()`. The same endpoint also trusted GET-only `home_id` and `mod_id` parameters, did not initialize the Panel session, did not enforce home assignment for non-admin users, and did not guard missing home/mod/XML data before making remote log calls.

## Repositories Inspected

- `/home/dev/Projects/GSP`
- `/home/dev/Projects/GSP/Panel`

## Files Read

- `/home/dev/Projects/GSP/AGENTS.md`
- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/development/CODEX_GUIDE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/GAMEMANAGER.md`
- `/home/dev/Projects/GSP/Panel/docs/features/LOGGING_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/features/STATUS_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/admin-guide.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/xml-notes.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-08-1822-panel-lifecycle-display-cleanup.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-09-1000-panel-fast-status-lights.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-09-php-compat-game-monitor-hotfix.md`
- `/home/dev/Projects/GSP/Panel/home.php`
- `/home/dev/Projects/GSP/Panel/server_status.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/get_server_log.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/start_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/log.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/view_server_log.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/includes/functions.php`
- `/home/dev/Projects/GSP/Panel/includes/helpers.php`
- `/home/dev/Projects/GSP/Panel/includes/config.inc.php.example`
- `/home/dev/Projects/GSP/Panel/includes/lib_remote.php`
- `/home/dev/Projects/GSP/Panel/includes/database.php`
- `/home/dev/Projects/GSP/Panel/includes/database_mysqli.php`
- `/home/dev/Projects/GSP/Panel/modules/config_games/server_config_parser.php`
- `/home/dev/Projects/GSP/Panel/modules/addonsmanager/tests/workshop_helpers_test.php`
- `/home/dev/Projects/GSP/Panel/modules/addonsmanager/tests/server_bot_helpers_test.php`

## Files Changed

- `modules/gamemanager/get_server_log.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `docs/modules/GAMEMANAGER.md`
- `docs/features/LOGGING_SYSTEM.md`
- `docs/codex-history/2026/2026-07-15-1905-game-server-log-endpoint-bootstrap.md`

## Architecture Decisions

- Keep the fix in the Panel repository; no Windows or Linux agent changes were needed for this Panel bootstrap defect.
- Keep `get_server_log.php` as a standalone AJAX endpoint, but bootstrap it like known direct Panel entry points by loading `functions.php`, `helpers.php`, installed config, and XML parser from the Panel root.
- Reuse the current Panel session and existing database authorization patterns: `isAdmin()` plus `getGameHome()` for admins, and `getUserGameHome()` for non-admin assigned homes.
- Preserve the existing remote log contract: `GSPRemoteLibrary::get_log()`, `console_log` path substitution, `%mod%` handling, wildcard no-match behavior, and last-40-line output.
- Return escaped `<pre>` HTML because the startup page inserts the endpoint response into `#server-log-output`.
- Update startup polling to replace the log pane only on successful AJAX responses so transient HTTP failures do not overwrite the pane with an error body.
- Add a focused CLI smoke test with fake database and remote objects instead of requiring a live production database or agent.

## Validation Performed

- `git status --short --branch`
- `git remote -v`
- `git log --oneline -n 8`
- `git diff --stat`
- `git diff -- modules/gamemanager/get_server_log.php modules/gamemanager/start_server.php includes/functions.php includes/helpers.php includes/config.inc.php includes/lib_remote.php modules/config_games/server_config_parser.php home.php server_status.php`
- `php -l modules/gamemanager/get_server_log.php`
- `php -l modules/gamemanager/start_server.php`
- `php -l modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `php modules/gamemanager/tests/get_server_log_endpoint_test.php`
- `php -r 'require_once "includes/functions.php"; require_once "includes/helpers.php"; echo function_exists("createDatabaseConnection") ? "createDatabaseConnection OK\n" : "createDatabaseConnection MISSING\n";'`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/addonsmanager/tests/server_bot_helpers_test.php`
- `php modules/update/tests/update_config_smoke.php`
- `php modules/billing/tests/BillingActionSecurityTest.php`
- `php modules/billing/tests/BillingLifecycleServiceTest.php`
- `php modules/billing/tests/BillingRequestPathSourceTest.php`
- `php modules/config_games/tests/validate_server_configs.php`
- `git diff --check`

Live authenticated browser, unauthorized browser, and server-start validation were not performed in this workspace because the local checkout does not contain an installed `includes/config.inc.php`, does not provide a configured Panel database, and does not provide a live agent/game server target.

## Git Commits

Repository

`/home/dev/Projects/GSP/Panel`

Commit

fix: bootstrap and authorize game server log endpoint

Hash

49fabd94064922e875778b91ff84e2b5f774351f

## Remaining Work

- Run production/staging browser validation with an installed Panel config, a valid admin/user session, assigned and unassigned homes, and a live Windows or Linux agent.
- Review other direct PHP endpoints that create database connections for the same helper/session bootstrap risk in a separate focused task. Static review found `modules/billing/check_table.php` has database-bootstrap issues, but it was not changed because it is unrelated to the gamemanager startup log endpoint.
