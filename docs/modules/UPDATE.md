# Update Module

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

Primary operational reference:

- `docs/modules/PANEL_UPDATE.md`

## Role

`modules/update` exposes the admin Panel update page. The page delegates its logic to:

- `modules/administration/panel_update.php`

This page now updates the **Panel only**.

Production deployments should use the release ZIP update sequence in `docs/modules/PANEL_UPDATE.md`.

## Current Behavior

The active update page is intentionally narrow:

- shows the installed Panel version
- shows the current git branch and commit when available
- exposes release channel and release ZIP URL as editable update settings
- can create a Panel backup
- can update the Panel from the configured release ZIP
- can roll back to an existing Panel backup
- keeps Apache diagnostics in a collapsed Advanced Diagnostics section
- leaves the legacy `p=updating` route as a no-side-effect compatibility page that points admins back to the CSRF-protected update form

It does not expose agent updates, website updates, or “update all components” behavior.

## Update Settings

The admin page stores these settings in the Panel settings table:

- `gsp_update_channel`
- `gsp_update_release_zip_url`

Other updater values are derived from the running Panel and are no longer stored
or rendered as admin questions by the active update page.

Defaults:

- Release Channel: `stable`
- Release ZIP URL: `https://git.runlevelsystems.com/GSE/Panel/archive/stable.zip`
- Repository Root: the running Panel root
- Panel Source Folder: `.`
- Backup Before Update: mandatory

## Backup and Rollback

Backups are Panel-focused:

- the Panel tree is archived
- `includes/config.inc.php` is preserved by the restore logic
- version markers are preserved
- database backup is included when the environment supports it
- Apache configs can be included for diagnostic/repair workflows

The active update action creates exactly one managed backup directory before
running Git, named like `panel-update_YYYY-MM-DD_HH-MM-SS`. If a failure occurs
after that point, the updater attempts rollback through the existing backup
restore path.

Retention is enforced after each successful backup.

If retention is `5`, the newest five managed backups are kept and older managed backups are pruned automatically.

## Implementation Notes

- `gsp_update_settings()` and `gsp_validate_update_settings()` remain top-level in `modules/administration/panel_update.php`.
- `gsp_checkout_update_source()` remains a top-level compatibility helper name, but it downloads and extracts the configured release ZIP.
- `gsp_do_configured_archive_update()` updates the derived live Panel path from the validated release ZIP payload root. `gsp_do_configured_git_update()` remains as a compatibility alias.
- The page no longer depends on website or agent update settings.

## Update Flow

For production release ZIP updates:

1. Run `sudo bash scripts/install-panel.sh --channel stable --path /var/www/html/panel`.
2. Run `php tools/post_update_cli.php` to apply existing module migrations and post-update cleanup.
3. Run `sudo bash scripts/install-panel.sh --repair-permissions --path /var/www/html/panel --owner "$USER" --group www-data`.
4. Restart/reload PHP or the web server when the host uses opcode caching or long-running workers.

For the web update page:

1. Save or submit release channel and release ZIP URL settings.
2. Validate release URL, derived root, permissions, and preservation state.
3. Create one managed backup.
4. Temporarily preserve runtime-generated secrets/state.
5. Download and extract the configured release ZIP into temporary staging.
6. Locate exactly one Panel payload root and copy application files into the Panel path.
7. Restore runtime-generated secrets/state.
8. Run module updates/post-update hooks.
9. Write version metadata and `LAST_UPDATE.txt`.

## Smoke Tests

Useful validation commands:

```bash
php -l modules/administration/panel_update.php
php -l modules/update/update.php
php modules/update/tests/update_config_smoke.php
php tools/post_update_cli.php --dry-run
```

## Diagnostics

Apache and SSL checks are diagnostics only. Missing SSL certificates do not block Panel updates.

## Repair Notes

- The update page fatal `Call to undefined function gsp_update_settings()` means the deployed `modules/administration/panel_update.php` is missing the top-level helper or is not the current repository copy.
- The update page fatal `Call to undefined function gsp_do_configured_archive_update()` means the configured release ZIP update helper is missing or nested inside another helper in the deployed `panel_update.php`.
- The update page fatal `Call to undefined function gsp_checkout_update_source()` means the release ZIP source helper is missing or nested inside another helper in the deployed `panel_update.php`.
- The current fix removes the multi-component update UI from the active page and keeps the Panel update path self-contained.
- Download/extract failures are logged to `logs/update_trace.log` with the configured release ZIP URL and channel.

## Remaining Issues

- Legacy update helpers remain in the file for compatibility and historical reference, but the active page does not render them.
- Real production testing should confirm file ownership and web-server permissions on the detected Panel root.
