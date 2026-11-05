<?php
/*
 * Backup/Restore - purge job for customer backups of deleted game servers.
 *
 * Run once a day from cron on the panel host, for example:
 *   30 3 * * * php /var/www/html/GSE/Panel/modules/backup-restore/cron-purge.php >> /var/log/gse-restic-purge.log 2>&1
 * Removes the REST account and repo of every home whose 7-day delay has passed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(realpath(dirname(__FILE__)));
chdir('../..'); // panel base path
error_reporting(E_ALL);
define('CONFIG_FILE', 'includes/config.inc.php');
require_once 'includes/functions.php';
require_once 'includes/helpers.php';
require_once CONFIG_FILE;
$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix);

require_once 'modules/backup-restore/function.php';

$purged = restic_purge_due_homes();
echo date('c') . ' restic purge: ' . count($purged) . ' home(s) purged' . (count($purged) ? ' ids=' . implode(',', $purged) : '') . "\n";
