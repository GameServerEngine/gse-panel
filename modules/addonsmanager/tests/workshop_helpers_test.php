<?php

if (!function_exists('clean_path')) {
	function clean_path($path)
	{
		$path = str_replace('\\', '/', (string)$path);
		$path = preg_replace('#/+#', '/', $path);
		return rtrim($path, '/');
	}
}

require_once(dirname(__DIR__) . '/server_content_helpers.php');

class ScmWorkshopFakeRemote
{
	public $files = array();
	public $commands = array();
	public $existing = array();

	public function rfile_exists($path)
	{
		return in_array($path, $this->existing, true) || isset($this->files[$path]) ? 1 : 0;
	}

	public function remote_writefile($path, $contents)
	{
		$this->files[$path] = $contents;
		return 1;
	}

	public function exec($command)
	{
		$this->commands[] = $command;
		return '';
	}
}

function scm_workshop_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$invalid = array();
$ids = scm_parse_workshop_ids("https://steamcommunity.com/sharedfiles/filedetails/?id=450814997\n450814997, 463939057  463939057", $invalid);
scm_workshop_test_assert($ids === array('450814997', '463939057'), 'parses URLs, whitespace, commas, and duplicates');
scm_workshop_test_assert(empty($invalid), 'valid mixed Workshop input has no invalid entries');

$ids = scm_parse_workshop_ids("abc, https://steamcommunity.com/sharedfiles/filedetails/?id=0, 123", $invalid);
scm_workshop_test_assert($ids === array('123'), 'keeps valid IDs when invalid values are present');
scm_workshop_test_assert(count($invalid) === 2, 'reports invalid Workshop entries');
scm_workshop_test_assert(scm_normalize_workshop_update_policy('download_only') === 'download_only', 'accepts download-only Workshop update policy');
scm_workshop_test_assert(scm_normalize_workshop_update_policy('bad_policy') === 'manual', 'invalid Workshop update policy falls back to manual');
scm_workshop_test_assert(strpos(scm_workshop_catalog_sort_sql('install_count'), 'install_count DESC') !== false, 'catalog sort supports install count');

$linuxHome = array(
	'home_id' => 10,
	'home_path' => '/srv/games/10',
	'home_cfg_file' => 'arma3_linux64.xml',
	'game_key' => 'arma3_linux64',
	'game_name' => 'Arma 3',
);
$windowsHome = array(
	'home_id' => 11,
	'home_path' => '/cygdrive/c/User_Files/11',
	'home_cfg_file' => 'arma3_win64.xml',
	'game_key' => 'arma3_win64',
	'game_name' => 'Arma 3',
);

$emptyXml = simplexml_load_string('<game_config></game_config>');
$linuxRemote = new ScmWorkshopFakeRemote();
$error = '';
$script = scm_prepare_workshop_script_for_agent($linuxRemote, $linuxHome, $emptyXml, $error);
scm_workshop_test_assert(strpos($script, '/srv/games/10/server_content/jobs/workshop/workshop_job_') === 0, 'stages per-job Linux script under management home');
scm_workshop_test_assert(strpos($script, '/srv/games/10/arma3_linux64/') !== 0, 'does not stage Workshop management script under game_root');
scm_workshop_test_assert(isset($linuxRemote->files[$script]), 'writes Linux per-job script to fake agent');
scm_workshop_test_assert(strpos($linuxRemote->files[$script], '+runscript') !== false, 'Linux per-job script runs SteamCMD through runscript');
scm_workshop_test_assert(strpos($linuxRemote->files[$script], 'workshop_download_item $APPID $id validate') !== false, 'Linux per-job script generates workshop_download_item runscript command');
scm_workshop_test_assert($error === '', 'default Linux job staging does not report missing agent script');
scm_workshop_test_assert(scm_get_workshop_manifest_path($linuxHome) === '/srv/games/10/server_content/workshop_manifest.json', 'Workshop manifest path stays in management home');
scm_workshop_test_assert(strpos(scm_get_workshop_manifest_path($linuxHome), '/srv/games/10/arma3_linux64/') !== 0, 'Workshop manifest path is excluded from game_root');
scm_workshop_test_assert(scm_path_is_under_home('/srv/games/10', '/srv/games/10/../secrets') === false, 'Server Content path boundary rejects parent segment escapes');

$windowsRemote = new ScmWorkshopFakeRemote();
$script = scm_prepare_workshop_script_for_agent($windowsRemote, $windowsHome, $emptyXml, $error);
scm_workshop_test_assert(strpos($script, '/cygdrive/c/User_Files/11/server_content/jobs/workshop/workshop_job_') === 0, 'stages per-job Windows/Cygwin script under server home');
scm_workshop_test_assert(isset($windowsRemote->files[$script]), 'writes Windows/Cygwin per-job script to fake agent');
scm_workshop_test_assert(strpos($windowsRemote->files[$script], '+runscript') !== false, 'Windows/Cygwin per-job script runs SteamCMD through runscript');
scm_workshop_test_assert($error === '', 'default Windows/Cygwin job staging does not report missing agent script');

$configuredXml = simplexml_load_string('<game_config><workshop_support><enabled>1</enabled><workshop_app_id>107410</workshop_app_id></workshop_support></game_config>');
$customRemote = new ScmWorkshopFakeRemote();
$script = scm_prepare_workshop_script_for_agent($customRemote, $linuxHome, $configuredXml, $error);
scm_workshop_test_assert(strpos($script, '/srv/games/10/server_content/jobs/workshop/workshop_job_') === 0, 'XML Workshop support still stages generated per-job script under management home');
scm_workshop_test_assert($error === '', 'generated per-job script does not expose script-not-found error');
@unlink(dirname(__DIR__) . '/logs/content_install.log');
@rmdir(dirname(__DIR__) . '/logs');

$genericHome = array(
	'home_id' => 12,
	'home_path' => '/srv/games/12',
	'home_cfg_file' => 'generic_linux.xml',
	'game_key' => 'generic_linux',
	'game_name' => 'Generic Game',
);
$runtime = scm_build_workshop_runtime_context(new stdClass(), $genericHome, $emptyXml, array('workshop_item_id' => '12345'), $message);
scm_workshop_test_assert($runtime['target_path_template'] === '{SERVER_ROOT}/workshop/{MOD_FOLDER}', 'generic fallback target path uses server_root/workshop');
scm_workshop_test_assert($runtime['target_path_resolved'] === '/srv/games/12/generic_linux/workshop/@12345', 'generic fallback target path resolves under game_root workshop folder');
scm_workshop_test_assert($runtime['server_root'] === '/srv/games/12/generic_linux', 'Workshop runtime exposes game_root as server_root payload destination');

$runtime = scm_build_workshop_runtime_context(new stdClass(), $linuxHome, $emptyXml, array('workshop_item_id' => '450814997', 'install_strategy' => 'arma_mod_folder'), $message);
scm_workshop_test_assert($runtime['target_path_template'] === '{SERVER_ROOT}/{MOD_FOLDER}', 'Arma strategy keeps @mod folder at server root');
scm_workshop_test_assert($runtime['target_path_resolved'] === '/srv/games/10/arma3_linux64/@450814997', 'Arma target path resolves to game_root @WorkshopID folder');

$appXml = simplexml_load_string('<game_config><workshop_support><steam_app_id>107410</steam_app_id><workshop_app_id>107410</workshop_app_id><install_strategy>arma_mod_folder</install_strategy><install_path>{SERVER_ROOT}/{MOD_FOLDER}</install_path><copy_keys enabled="1"><target_path>{SERVER_ROOT}/keys</target_path></copy_keys></workshop_support></game_config>');
scm_workshop_test_assert(scm_extract_workshop_app_id($appXml) === '107410', 'extracts canonical Workshop app ID from game XML');
scm_workshop_test_assert(scm_extract_workshop_steam_app_id($appXml) === '107410', 'extracts canonical Steam app ID from game XML');
scm_workshop_test_assert(scm_workshop_is_supported($appXml) === true, 'canonical Workshop XML enables Workshop support');
scm_workshop_test_assert(scm_detect_workshop_install_strategy($linuxHome, $appXml) === 'arma_mod_folder', 'extracts canonical Workshop install strategy from game XML');
$runtime = scm_build_workshop_runtime_context(new stdClass(), $linuxHome, $appXml, array('workshop_item_id' => '450814997'), $message);
scm_workshop_test_assert($runtime['target_path_template'] === '{SERVER_ROOT}/{MOD_FOLDER}', 'canonical Workshop install_path controls target template');
scm_workshop_test_assert($runtime['target_path_resolved'] === '/srv/games/10/arma3_linux64/@450814997', 'canonical Workshop install_path resolves under game_root');
scm_workshop_test_assert(scm_workshop_should_copy_keys($appXml, 'copy_to_mod_folder') === true, 'canonical copy_keys enabled flag is honored');

echo "All Workshop helper smoke tests passed.\n";
