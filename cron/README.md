# Panel cron scripts

Every scheduled PHP script for the panel lives in this folder. To find a job, look here.
To add one, copy the header from an existing script (see "Adding a script" below).

Run any script by hand from the panel root with the CLI, for example:

    php /var/www/html/GSE/Panel/cron/cron-shop.php

Each script refuses browser requests (it returns 404 unless it runs under the CLI), so
these files cannot be triggered from a web page.

## Scripts and suggested schedule

| Script | Job | Suggested cron line | Status |
|---|---|---|---|
| `cron-shop.php` | Billing lifecycle: renewal invoices, expiry, suspension, deletion after grace. | `0 3 * * *` | Scheduled (required for billing) |
| `cron-purge.php` | Removes customer restic backup repos of deleted game servers after 7 days. | `30 3 * * *` | Scheduled when restic backups are enabled |
| `reseller-cron-shop.php` | Removes expired reseller game homes. | `0 4 * * *` | Work in progress, not scheduled. |

Replace `/var/www/html/GSE/Panel` with your panel path. Add the lines with `crontab -e`
as the web server user (for example `www-data` or `apache`). Logs go to `/var/log/gse-*.log`.

## Adding a script

1. Put the file in this folder. Name it after its job, in lowercase with dashes.
2. Start it with a header that says what it does, how to run it by hand, the suggested
   cron line, and whether it is safe to re-run.
3. Add the CLI guard right after the header (the one in `cron-purge.php`):
   `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`
4. Use paths relative to the panel root after `chdir('..')`, since the file lives one folder down.
5. Add a row to the table above.
