# Panel Update Module

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

This module documents the Panel-only web update flow exposed at `modules/update/update.php` and implemented in `modules/administration/panel_update.php`.

The update page now updates the Panel only. Website and agent updates are separate workflows and are not rendered by this page.

For production deployments, the supported path is a release ZIP update plus the documented post-update steps below. The web update page uses the same ZIP-first model and remains useful for diagnostics, backup, and rollback work.

The legacy `modules/update/updating.php` route no longer performs file-copy updates from GET parameters. It is retained only as a compatibility page that points admins to the CSRF-protected Panel Updates form.

## Configured Settings

The update page uses these settings:

- `gsp_update_channel`
- `gsp_update_release_zip_url`

The Panel root, source path, backup path, retention, and mandatory
backup-before-update behavior are derived by the updater and are not admin
questions.

## Defaults

- Release Channel: `stable`
- Release ZIP URL: `https://git.runlevelsystems.com/GSE/Panel/archive/stable.zip`
- Repository Root: the running Panel root detected from `modules/administration/panel_update.php`
- Panel Source Folder: `.`
- Backup Before Update: always enabled

## Update Workflow

### Supported Release ZIP Update

Run from the installed Panel path:

```bash
sudo bash scripts/install-panel.sh --channel stable --path /var/www/html/panel
php tools/post_update_cli.php
sudo bash scripts/install-panel.sh --repair-permissions --path /var/www/html/panel --owner "$USER" --group www-data
```

Required post-update behavior:

- `php tools/post_update_cli.php` runs the existing Panel post-update path, including installed module `db_version` migrations through `updateAllPanelModules()` and `update_module()`.
- `scripts/install-panel.sh --repair-permissions` restores the documented ownership/mode model after files are copied.
- Restart or reload the web server/PHP runtime if the deployment uses long-running PHP workers or opcode caching.
- Keep `includes/config.inc.php`, `includes/install.state.json`, billing local config, logs, backups, cache, uploads, and customer-owned server data out of overwrite flows.

Standalone SQL files under `sql/` and module directories are historical or repair-oriented unless a module migration or current runbook explicitly names them. Do not run them blindly after every application file update.

### Web Update Page

1. Admin opens the Update page.
2. Panel loads the current settings.
3. Admin can save the release channel and derived/custom release ZIP URL.
4. Admin can create a manual Panel backup.
5. Admin can run the Panel-only update.
6. The updater confirms the derived Panel root is writable and that runtime config can be preserved.
7. The updater creates exactly one managed backup directory named like `panel-update_YYYY-MM-DD_HH-MM-SS`.
8. Runtime secrets/state such as `includes/config.inc.php` are copied to temporary update-local preservation storage.
9. The updater downloads the configured release ZIP, extracts it to temporary staging, and locates exactly one Panel payload root.
10. The updater copies application files into the Panel root while excluding deployment metadata and preserving config/runtime paths.
11. Runtime secrets/state are restored.
12. Version metadata and `LAST_UPDATE.txt` are written.

## Backup Workflow

When backup-before-update is enabled:

- the Panel tree is archived once per update run
- the database backup is attempted when available
- version metadata is copied
- Apache config backup can be included for repair workflows

The backup directory is created recursively if missing.

Retention is enforced after each successful backup.

The active update action does not create a second component backup. If a failure
occurs after the managed backup is created, the updater attempts to roll back
from that same backup through the existing restore path.

## Rollback Workflow

- The rollback selector lists managed backups.
- Restoring a backup restores the Panel tree and metadata.
- Missing website archives are ignored because the active backup path is Panel-focused.
- Apache config restore is optional.

## Error Handling

Failures are logged to `logs/update_trace.log` and shown as user-facing errors on the page.

The page should remain usable even if:

- the release ZIP URL is invalid or unavailable
- backup directory creation fails
- backup retention pruning fails
- the post-update command fails

## Troubleshooting

If the page shows `Call to undefined function gsp_do_configured_archive_update()` or a related updater fatal:

1. Verify the deployed `modules/administration/panel_update.php` matches the repository version.
2. Confirm the helper exists at top level in the deployed file.
3. Confirm the page is not loading a stale cached copy.
4. Check `logs/update_trace.log` for the detailed error.

If backups fail to create:

- verify the backup path exists or can be created recursively
- verify the web server user can write to the path
- verify the path is absolute and does not contain unsafe relative segments

## Notes

- Agents now update using their own scripts and workflows.
- Website updates are handled separately.
- The Panel update page intentionally does not render agent or website update controls.
- Production deployments should use the release ZIP update sequence above.
