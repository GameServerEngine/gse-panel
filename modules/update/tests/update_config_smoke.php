<?php
$settings = array();
require_once(dirname(__DIR__) . '/../administration/panel_update.php');

function update_smoke_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$cfg = update_settings();
$panelRoot = realpath(dirname(__DIR__) . '/../..');
update_smoke_assert(function_exists('do_configured_git_update'), 'legacy configured updater alias is top-level');
update_smoke_assert(function_exists('do_configured_archive_update'), 'configured release ZIP updater helper is top-level');
update_smoke_assert(function_exists('checkout_update_source'), 'release ZIP source helper is top-level');
update_smoke_assert(function_exists('disable_ssl_vhost'), 'SSL vhost disable helper is top-level');
update_smoke_assert($cfg['channel'] === 'stable', 'default release channel is stable');
update_smoke_assert($cfg['release_zip_url'] === 'https://github.com/GameServerEngine/gse-panel/archive/refs/heads/stable.zip', 'default release ZIP URL is stable archive');
update_smoke_assert($cfg['repo_root'] === $panelRoot, 'repository root is derived from the running Panel');
update_smoke_assert($cfg['panel_path'] === $panelRoot, 'Panel path is derived from the running Panel');
update_smoke_assert(!array_key_exists('backup_retention', $cfg), 'backup retention setting was removed; one restore point is always kept');
update_smoke_assert($cfg['panel_source_path'] === '.', 'default Panel source folder is repository root');
update_smoke_assert(empty(validate_update_settings($cfg)), 'default update settings validate');

$settings['update_repo_root'] = '/stale/path';
$settings['update_panel_path'] = '/another/stale/path';
$settings['update_panel_source_path'] = 'Panel';
$settings['update_backup_before'] = '0';
$settings['update_backup_path'] = '/tmp/old-backups';
$derived = update_settings();
update_smoke_assert($derived['repo_root'] === $panelRoot, 'stale repository root setting is ignored');
update_smoke_assert($derived['panel_path'] === $panelRoot, 'stale Panel path setting is ignored');
update_smoke_assert($derived['panel_source_path'] === '.', 'stale source folder setting is ignored');
update_smoke_assert($derived['backup_before_update'] === '1', 'backup before update is mandatory');

$bad = $cfg;
$bad['channel'] = 'testing';
update_smoke_assert(!empty(validate_update_settings($bad)), 'invalid release channel is rejected');

$badUrl = $cfg;
$badUrl['release_zip_url'] = 'https://git.runlevelsystems.com/GSE/Panel.git';
update_smoke_assert(!empty(validate_update_settings($badUrl)), 'non-zip release URL is rejected');

$unstable = $cfg;
$unstable['channel'] = 'unstable';
$unstable['release_zip_url'] = release_channel_url('unstable');
update_smoke_assert(empty(validate_update_settings($unstable)), 'unstable release settings validate');

update_smoke_assert(is_safe_backup_name('panel-update_2026-07-10_12-34-56'), 'named backup is accepted');
update_smoke_assert(is_safe_backup_name('2026-07-10_12-34-56'), 'legacy timestamp backup is accepted');
update_smoke_assert(!is_safe_backup_name('../2026-07-10_12-34-56'), 'unsafe backup name is rejected');

$source = file_get_contents(dirname(__DIR__) . '/../administration/panel_update.php');
$start = strpos($source, 'function do_configured_archive_update');
$end = strpos($source, 'function component_source_path', $start);
$updateFunction = substr($source, $start, $end - $start);
update_smoke_assert(substr_count($updateFunction, 'create_full_backup(') === 1, 'configured update creates exactly one managed backup');

$tmp = sys_get_temp_dir() . '/update_smoke_' . getmypid();
@mkdir($tmp, 0777, true);
update_smoke_assert(component_source_path($tmp, '.') !== false, 'component source path resolves checkout root');
update_smoke_assert(component_source_path($tmp, '../Panel') === false, 'component source path rejects traversal');
@rmdir($tmp);

$panelTmp = sys_get_temp_dir() . '/update_panel_' . getmypid();
@mkdir($panelTmp . '/includes', 0777, true);
@file_put_contents($panelTmp . '/includes/config.inc.php', '<?php $secret = "keep";');
$snapshot = create_runtime_state_snapshot($panelTmp);
@file_put_contents($panelTmp . '/includes/config.inc.php', '<?php $secret = "changed";');
$restore = restore_runtime_state_snapshot($snapshot, $panelTmp);
update_smoke_assert($snapshot['success'], 'runtime state snapshot is created');
update_smoke_assert($restore['success'], 'runtime state snapshot is restored');
update_smoke_assert(file_get_contents($panelTmp . '/includes/config.inc.php') === '<?php $secret = "keep";', 'runtime config file content is preserved');
cleanup_runtime_state_snapshot($snapshot);
@unlink($panelTmp . '/includes/config.inc.php');
@rmdir($panelTmp . '/includes');
@rmdir($panelTmp);

echo "All update config smoke tests passed.\n";
