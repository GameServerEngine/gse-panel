<?php

if (!defined('TABLE_PREFIX_PLACEHOLDER')) {
	define('TABLE_PREFIX_PLACEHOLDER', '');
}

if (!function_exists('clean_path')) {
	function clean_path($path)
	{
		$path = str_replace('\\', '/', (string)$path);
		$path = preg_replace('#/+#', '/', $path);
		return rtrim($path, '/');
	}
}

require_once(dirname(__DIR__) . '/server_content_helpers.php');

class ScmServerBotFakeRemote
{
	public $files = array();
	public $commands = array();

	public function remote_readfile($path, &$data)
	{
		if (!array_key_exists($path, $this->files)) {
			$data = '';
			return 0;
		}
		$data = $this->files[$path];
		return 1;
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

class ScmServerBotFakeDb
{
	public $rows = array();
	public $queries = array();
	public $logs = array();

	public function realEscapeSingle($value)
	{
		return addslashes((string)$value);
	}

	public function resultQuery($query)
	{
		$this->queries[] = $query;
		if (strpos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
			return array(array(1));
		}
		if (strpos($query, 'WHERE m.id = 1') !== false) {
			return array($this->rows[0]);
		}
		if (strpos($query, 'LEFT JOIN') !== false || strpos($query, 'WHERE m.home_id = 20') !== false) {
			return $this->rows;
		}
		return array();
	}

	public function query($query)
	{
		$this->queries[] = $query;
		return true;
	}

	public function logger($message)
	{
		$this->logs[] = $message;
	}
}

function scm_server_bot_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$linuxHome = array(
	'home_id' => 20,
	'home_path' => '/srv/games/20',
	'home_cfg_file' => 'dayz_linux64.xml',
	'game_key' => 'dayz_linux64',
);
$windowsHome = array(
	'home_id' => 21,
	'home_path' => '/cygdrive/c/GSE/Servers/21',
	'home_cfg_file' => 'dayz_win64.xml',
	'game_key' => 'dayz_win64',
);
$serverXml = simplexml_load_string('<game_config><exe_location>server/bin</exe_location></game_config>');
$botRows = array(array(
	'addon_name' => 'Generic Bot',
	'bot_working_dir' => '/srv/games/dayz/bot',
	'bot_executable' => './bot',
	'bot_arguments' => '--config bot.cfg --watch',
));

$remote = new ScmServerBotFakeRemote();
$error = '';
scm_server_bot_test_assert(scm_generate_server_bot_also_run_file($remote, $linuxHome, $serverXml, $botRows, $error), 'generates Linux Server Bot launch file');
scm_server_bot_test_assert(isset($remote->files['/srv/games/20/_alsoRun.cmd']), 'Linux launch file is written at management home root');
$linuxFile = $remote->files['/srv/games/20/_alsoRun.cmd'];
scm_server_bot_test_assert(strpos($linuxFile, "workdir=/srv/games/dayz/bot\n") !== false, 'Linux launch file includes workdir key');
scm_server_bot_test_assert(strpos($linuxFile, "executable=./bot\n") !== false, 'Linux launch file includes executable key');
scm_server_bot_test_assert(strpos($linuxFile, "arguments=--config bot.cfg --watch\n") !== false, 'Linux launch file includes arguments key');
scm_server_bot_test_assert(strpos($linuxFile, "# SERVER_CONTENT_SERVER_BOT_GENERATED\n") === 0, 'launch file includes managed ownership marker');

$remote = new ScmServerBotFakeRemote();
$winRows = array(array(
	'addon_name' => 'Windows Bot',
	'bot_working_dir' => 'C:\GSE\Servers\dayz\bot',
	'bot_executable' => 'bot.exe',
	'bot_arguments' => '--config bot.cfg',
));
scm_server_bot_test_assert(scm_generate_server_bot_also_run_file($remote, $windowsHome, $serverXml, $winRows, $error), 'generates Windows Server Bot launch file');
scm_server_bot_test_assert(isset($remote->files['/cygdrive/c/GSE/Servers/21/_alsoRun.cmd']), 'Windows launch file is written at management home root');
$windowsFile = $remote->files['/cygdrive/c/GSE/Servers/21/_alsoRun.cmd'];
scm_server_bot_test_assert(strpos($windowsFile, "workdir=C:\\GSE\\Servers\\dayz\\bot\n") !== false, 'Windows launch file preserves Windows workdir value');
scm_server_bot_test_assert(strpos($windowsFile, "executable=bot.exe\n") !== false, 'Windows launch file includes executable key');

$remote = new ScmServerBotFakeRemote();
$remote->files['/srv/games/20/_alsoRun.cmd'] = scm_server_bot_generated_marker() . "\nworkdir=/old\n";
scm_server_bot_test_assert(scm_generate_server_bot_also_run_file($remote, $linuxHome, $serverXml, array(), $error), 'removes generated launch file when no enabled Server Bots remain');
scm_server_bot_test_assert(in_array("rm -f '/srv/games/20/_alsoRun.cmd'", $remote->commands, true), 'empty Server Bot set removes generated launch file from management home');

$remote = new ScmServerBotFakeRemote();
$remote->files['/srv/games/20/_alsoRun.cmd'] = "workdir=/manual\nexecutable=manual\n";
scm_server_bot_test_assert(!scm_generate_server_bot_also_run_file($remote, $linuxHome, $serverXml, $botRows, $error), 'refuses to overwrite non-generated _alsoRun.cmd');
scm_server_bot_test_assert(strpos($error, 'non-generated') !== false, 'reports non-generated launch file conflict');

$runtime = scm_resolve_server_bot_runtime_fields(array(
	'bot_working_dir' => '{GAME_ROOT}/dzsa',
	'bot_executable' => 'node-mod-server.exe',
	'bot_arguments' => '"-port={PORT}" "{MODLIST}"',
	'bot_install_folder' => 'dzsa',
), array(
	'home_id' => 30,
	'home_path' => '/srv/games/30',
	'game_key' => 'dayz_linux64',
	'ip' => '192.0.2.10',
	'port' => '2302',
), $serverXml, array('bot_modlist' => '-mod=@CF;@Trader'));
scm_server_bot_test_assert($runtime['bot_working_dir'] === '/srv/games/30/dayz_linux64/dzsa', 'Server Bot placeholders resolve GAME_ROOT');
scm_server_bot_test_assert($runtime['bot_arguments'] === '"-port=2302" "-mod=@CF;@Trader"', 'Server Bot placeholders resolve port and mod list');
scm_server_bot_test_assert(scm_server_bot_resolve_install_path(array('home_path' => '/srv/games/30', 'game_key' => 'dayz_linux64'), 'dzsa') === '/srv/games/30/dayz_linux64/dzsa', 'relative Server Bot payload installs under game_root');
scm_server_bot_test_assert(scm_server_bot_resolve_install_path(array('home_path' => '/srv/games/30', 'game_key' => 'dayz_linux64'), '/srv/games/30/shared-bots/dzsa') === '/srv/games/30/shared-bots/dzsa', 'absolute Server Bot payload may stay above game_root inside management home');
scm_server_bot_test_assert(scm_server_bot_resolve_install_path(array('home_path' => '/srv/games/30', 'game_key' => 'dayz_linux64'), '/srv/games/30/../outside') === false, 'absolute Server Bot payload rejects parent segment escape');

$orderedContents = scm_server_bot_build_also_run_contents(scm_server_bot_sort_rows(array(
	array('addon_id' => 50, 'id' => 2, 'addon_name' => 'B', 'bot_working_dir' => '/b', 'bot_executable' => './b', 'bot_arguments' => ''),
	array('addon_id' => 40, 'id' => 3, 'addon_name' => 'A', 'bot_working_dir' => '/a', 'bot_executable' => './a', 'bot_arguments' => ''),
)));
scm_server_bot_test_assert(strpos($orderedContents, "workdir=/a\n") !== false, 'enabled Server Bot ordering is deterministic by catalog item');

$seedDb = new ScmServerBotFakeDb();
scm_server_bot_test_assert(scm_seed_server_bot_catalog($seedDb), 'seeds Server Bot catalog definitions');
scm_server_bot_test_assert(strpos(implode("\n", $seedDb->queries), 'BEC - BattlEye Extended Controls') !== false, 'BEC package definition is present');
scm_server_bot_test_assert(strpos(implode("\n", $seedDb->queries), 'node-mod-server.exe') !== false, 'DZSA sidecar package definition is present');

$lifeDb = new ScmServerBotFakeDb();
$lifeDb->rows = array(array(
	'id' => 1,
	'home_id' => 20,
	'addon_id' => 40,
	'addon_name' => 'Lifecycle Bot',
	'addon_type' => 'server_bot',
	'addon_install_method' => 'server_bot',
	'install_state' => 'installed',
	'enabled' => 1,
	'content_version' => '1',
	'install_path' => '/srv/games/20/dayz_linux64/bot',
	'bot_working_dir' => '/srv/games/20/dayz_linux64/bot',
	'bot_executable' => './bot',
	'bot_arguments' => '--old',
	'addon_bot_working_dir' => '/srv/games/20/dayz_linux64/bot',
	'addon_bot_executable' => './bot',
	'addon_bot_arguments' => '--new',
	'addon_content_version' => '2',
	'addon_checksum_sha256' => '',
	'addon_url' => '',
	'addon_path' => 'bot',
	'bot_uninstall_script' => '',
));
$remote = new ScmServerBotFakeRemote();
scm_server_bot_test_assert(scm_server_bot_manifest_action($lifeDb, $remote, $linuxHome, $serverXml, 1, 'disable', 99, $message), 'disable action succeeds');
scm_server_bot_test_assert(in_array("rm -f '/srv/games/20/_alsoRun.cmd'", $remote->commands, true), 'disable regenerates empty launch state in management home');
scm_server_bot_test_assert(strpos(implode("\n", $lifeDb->queries), "install_state='disabled'") !== false, 'disable updates DB state after generation');

$lifeDb = new ScmServerBotFakeDb();
$lifeDb->rows = array(array(
	'id' => 1,
	'home_id' => 20,
	'addon_id' => 40,
	'addon_name' => 'Lifecycle Bot',
	'addon_type' => 'server_bot',
	'addon_install_method' => 'server_bot',
	'install_state' => 'installed',
	'enabled' => 1,
	'content_version' => '1',
	'install_path' => '/srv/games/20/dayz_linux64/bot',
	'bot_working_dir' => '/srv/games/20/dayz_linux64/bot',
	'bot_executable' => './bot',
	'bot_arguments' => '--old',
	'addon_bot_working_dir' => '/srv/games/20/dayz_linux64/bot',
	'addon_bot_executable' => './bot',
	'addon_bot_arguments' => '--new',
	'addon_content_version' => '2',
	'addon_checksum_sha256' => '',
	'addon_url' => '',
	'addon_path' => 'bot',
	'bot_uninstall_script' => '',
));
$remote = new ScmServerBotFakeRemote();
scm_server_bot_test_assert(scm_server_bot_manifest_action($lifeDb, $remote, $linuxHome, $serverXml, 1, 'update', 99, $message), 'update action succeeds');
scm_server_bot_test_assert(strpos(implode("\n", $lifeDb->queries), "install_state='updated'") !== false, 'update records updated version state');

$lifeDb = new ScmServerBotFakeDb();
$lifeDb->rows = array(array(
	'id' => 1,
	'home_id' => 20,
	'addon_id' => 40,
	'addon_name' => 'Lifecycle Bot',
	'addon_type' => 'server_bot',
	'addon_install_method' => 'server_bot',
	'install_state' => 'installed',
	'enabled' => 1,
	'content_version' => '1',
	'install_path' => '/srv/games/20/dayz_linux64/bot',
	'bot_working_dir' => '/srv/games/20/dayz_linux64/bot',
	'bot_executable' => './bot',
	'bot_arguments' => '',
	'addon_bot_working_dir' => '/srv/games/20/dayz_linux64/bot',
	'addon_bot_executable' => './bot',
	'addon_bot_arguments' => '',
	'addon_content_version' => '1',
	'addon_checksum_sha256' => '',
	'addon_url' => '',
	'addon_path' => 'bot',
	'bot_uninstall_script' => '',
));
$remote = new ScmServerBotFakeRemote();
scm_server_bot_test_assert(scm_server_bot_manifest_action($lifeDb, $remote, $linuxHome, $serverXml, 1, 'uninstall', 99, $message), 'uninstall action succeeds');
scm_server_bot_test_assert(strpos(implode("\n", $lifeDb->queries), 'DELETE FROM `TABLE_PREFIX_PLACEHOLDERserver_content_manifest`') !== false, 'uninstall removes installed-content row after generation');

echo "All Server Bot helper smoke tests passed.\n";
