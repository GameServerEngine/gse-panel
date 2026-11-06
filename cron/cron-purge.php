<?php
/*
 * CRON: cron-purge.php  (customer restic backups of deleted game servers)
 *
 * Location: cron/cron-purge.php (moved from cron/cron-purge.php).
 *
 * What it does: when a game server has been deleted and its 7-day delay has passed,
 *   removes its restic REST account and repository on the backup (DR) server.
 *   Does nothing for homes that are not marked deleted yet.
 *
 * Run by hand (CLI only; a browser request gets a 404):
 *   php /var/www/html/GSE/Panel/cron/cron-purge.php
 *
 * Suggested schedule: once a day, after the cron-shop run.
 *   30 3 * * * php /var/www/html/GSE/Panel/cron/cron-purge.php >> /var/log/gse-restic-purge.log 2>&1
 *
 * Add it with: crontab -e   (as the web server user)
 * Safe to re-run: yes. Needs the restic feature enabled in Backup/Restore Admin.
 * See cron/README.md for the full cron list and the rules for adding scripts.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(realpath(dirname(__FILE__)));
chdir('..'); // panel base path
error_reporting(E_ALL);
define('CONFIG_FILE', 'includes/config.inc.php');
require_once 'includes/functions.php';
require_once 'includes/helpers.php';
require_once CONFIG_FILE;
$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix);

require_once 'modules/backup-restore/function.php';

$purged = restic_purge_due_homes();
echo date('c') . ' restic purge: ' . count($purged) . ' home(s) purged' . (count($purged) ? ' ids=' . implode(',', $purged) : '') . "\n";
