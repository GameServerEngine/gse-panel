# Task

## User Request

Verify the nested `/home/dev/Projects/GSP/Panel` repository only, confirm the recent single-server monitor commits are landed and pushed, inspect monitor, update/install, migration, and billing lifecycle paths, fix only verified readiness gaps, validate, then commit and push if verification passes.

## Problem

The monitor roadmap matched the current shared render path, but production update documentation still treated the web update page as the main update workflow. The repo had module `db_version` migrations and post-update cleanup, yet no documented non-web command for operators to run those steps after a direct `git pull`.

## Root Cause

Panel database migrations are implemented through installed module metadata and `update_module()`, invoked by `runPostUpdateOperations()`. That path existed behind web update/module flows, while the intended production deployment path is a Git checkout update. The missing bridge was operational: a narrow CLI runner and documentation tying Git pull to the existing migration mechanism.

## Repositories Inspected

- GSP Panel

## Files Read

- `/home/dev/Projects/GSP/Panel/README.md`
- `/home/dev/Projects/GSP/Panel/docs/ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/PANEL_ARCHITECTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/AGENT_COMMUNICATION.md`
- `/home/dev/Projects/GSP/Panel/docs/COMMAND_FLOW.md`
- `/home/dev/Projects/GSP/Panel/docs/REPOSITORY_STRUCTURE.md`
- `/home/dev/Projects/GSP/Panel/docs/UPDATE_POLICY.md`
- `/home/dev/Projects/GSP/Panel/docs/features/STATUS_SYSTEM.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/PANEL_UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/update.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/INSTALL_README.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/GSP_INSTALLER.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/admin-guide.md`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/server_monitor.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/home_handling_functions.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/start_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/stop_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/restart_server.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/mini_start.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/*/modules/gamemanager/game_monitor.css`
- `/home/dev/Projects/GSP/Panel/themes/*/modules/gamemanager/game_monitor.js`
- `/home/dev/Projects/GSP/Panel/install.php`
- `/home/dev/Projects/GSP/Panel/check.php`
- `/home/dev/Projects/GSP/Panel/includes/install_engine.php`
- `/home/dev/Projects/GSP/Panel/includes/helpers.php`
- `/home/dev/Projects/GSP/Panel/includes/functions.php`
- `/home/dev/Projects/GSP/Panel/modules/modulemanager/module_handling.php`
- `/home/dev/Projects/GSP/Panel/modules/update/update.php`
- `/home/dev/Projects/GSP/Panel/modules/update/post_update.php`
- `/home/dev/Projects/GSP/Panel/modules/update/tests/update_config_smoke.php`
- `/home/dev/Projects/GSP/Panel/modules/administration/panel_update.php`
- `/home/dev/Projects/GSP/Panel/scripts/install-panel.sh`
- `/home/dev/Projects/GSP/Panel/tools/install_panel_cli.php`
- `/home/dev/Projects/GSP/Panel/modules/gamemanager/module.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/module.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/classes/BillingLifecycleService.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/classes/BillingRepository.php`
- `/home/dev/Projects/GSP/Panel/modules/billing/cron-shop.php`

## Files Changed

- `/home/dev/Projects/GSP/Panel/tools/post_update_cli.php`
- `/home/dev/Projects/GSP/Panel/docs/modules/PANEL_UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/modules/UPDATE.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/INSTALL_README.md`
- `/home/dev/Projects/GSP/Panel/docs/deployment/admin-guide.md`
- `/home/dev/Projects/GSP/Panel/docs/codex-history/2026/2026-07-13-1030-panel-production-update-path.md`

## Architecture Decisions

- Preserve the existing monitor render path because single-server compact controls and multi-server bulk controls are already correctly separated in `server_monitor.php`.
- Preserve the web updater code without attempting to make it the production deployment authority.
- Add a CLI post-update runner that reuses `runPostUpdateOperations()` rather than creating a separate migration system.
- Document standalone SQL files as repair or explicitly named runbook steps, not automatic post-pull steps.

## Validation Performed

- Confirmed `/home/dev/Projects/GSP/Panel` is the active Git repository.
- Confirmed branch `unstable` tracks `origin/unstable` and was clean before edits.
- Confirmed commits `7c4a7521 Restore single-server monitor controls` and `709c6931 Restore single monitor control placement` are present locally and contained by `origin/unstable`.
- `php -l tools/post_update_cli.php`
- `php -l modules/administration/panel_update.php`
- `php -l modules/update/update.php`
- `php -l modules/update/post_update.php`
- `php -l install.php`
- `php -l check.php`
- `php -l includes/install_engine.php`
- `php -l tools/install_panel_cli.php`
- `php -l modules/modulemanager/module_handling.php`
- `php -l includes/helpers.php`
- `php -l includes/functions.php`
- `bash -n scripts/install-panel.sh`
- `php -l modules/gamemanager/server_monitor.php`
- `php -l modules/gamemanager/home_handling_functions.php`
- `php -l modules/gamemanager/start_server.php`
- `php -l modules/gamemanager/stop_server.php`
- `php -l modules/gamemanager/restart_server.php`
- `php -l modules/gamemanager/mini_start.php`
- `php -l modules/gamemanager/update_actions.php`
- `php -l modules/gamemanager/update_server.php`
- `php -l modules/billing/classes/BillingLifecycleService.php`
- `php -l modules/billing/classes/BillingRepository.php`
- `php -l modules/billing/cron-shop.php`
- `php -l modules/billing/admin_invoices.php`
- `php -l modules/billing/admin_orders.php`
- `php -l modules/billing/create_servers.php`
- `php -l modules/billing/renew_server.php`
- `php -l modules/billing/my_account.php`
- `php -l modules/billing/my_orders_panel.php`
- `php -l modules/billing/my_servers.php`
- `php modules/update/tests/update_config_smoke.php`
- `php tools/post_update_cli.php --dry-run`
- `php modules/config_games/tests/validate_server_configs.php`
- `php modules/addonsmanager/tests/workshop_helpers_test.php`
- `php modules/billing/tests/BillingLifecycleServiceTest.php`
- `php modules/billing/tests/BillingActionSecurityTest.php`
- `php modules/billing/tests/BillingRequestPathSourceTest.php`
- `php tools/install_panel_cli.php --base "$PWD" --config /tmp/nonexistent-gsp-install.json --dry-run` confirmed the missing-config guard.
- Static monitor checks confirmed single-server detection uses `home_id` and `home_id-mod_id-ip-port`, bulk stop/start radio controls and Execute footer remain gated by `!$single_server_monitor`, compact controls still render, and removed default image/legal/display blocks remain absent.
- `git diff --check`

## Git Commits

Repository

GSP Panel

Commit

Pending at validation; see final report for commit hash.

Hash

Pending at validation; see final report for commit hash.

## Remaining Work

Live Panel/Agent/browser/database validation requires a configured production-like environment.
