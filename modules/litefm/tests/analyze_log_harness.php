<?php
// Runs the Analyze Log page with a fake database against a fake agent. Used by analyze_log_page_test.php.
// Usage: php analyze_log_harness.php '<json spec>'  spec: agent_port, key, rights, method, post, token, settings, file
$spec = json_decode($argv[1], true);
chdir(dirname(__DIR__, 3));
define('MODULES', 'modules/');
define('SERVER_CONFIG_LOCATION', 'modules/config_games/server_configs');
define('DB_PREFIX', 'gsp_');
$GLOBALS['LangPre'] = 'LANG_';
foreach (array('global', 'modules/litefm') as $file) {
	include_once 'lang/English/' . $file . '.php';
}
function get_lang($k) { return defined('LANG_' . $k) ? constant('LANG_' . $k) : '_' . $k . '_'; }
function getClientIPAddress() { return '192.0.2.50'; }
function print_success($t) { echo '<p class="success">' . $t . '</p>'; }
function print_failure($t, $c = 'failure') { echo '<p class="' . $c . '">' . $t . '</p>'; }
function clean_path($p) { return preg_replace('#/+#', '/', $p); }
function endsWith($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; }
function startsWith($h, $n) { return strpos($h, $n) === 0; }

class FakeDb
{
	public $spec;
	public $audit = array();
	public $logs = array();
	public function __construct($spec) { $this->spec = $spec; }
	public function isAdmin($id) { return false; }
	public function getGameHome($id) { return $this->getUserGameHome(1, $id); }
	public function getUserGameHome($user, $home)
	{
		return array('home_id' => 1, 'home_path' => '/srv/gse/1', 'home_cfg_file' => 'cstrike.xml', 'access_rights' => $this->spec['rights'], 'remote_server_id' => 3,
			'agent_ip' => '127.0.0.1', 'agent_port' => $this->spec['agent_port'], 'encryption_key' => $this->spec['key'], 'timeout' => 5, 'server_os' => 'linux', 'game_key' => 'cstrike');
	}
	public function getSettings() { return $this->spec['settings']; }
	public function realEscapeSingle($v) { return addslashes((string)$v); }
	public function resultInsertId($t, $row) { $this->audit[] = $row; return count($this->audit) - 1; }
	public function query($sql) { return true; }
	public function resultQuery($sql) { return array(array('n' => 0)); }
	public function logger($m) { $this->logs[] = $m; }
}

$_SESSION = array('user_id' => 5, 'fm_files_1' => array(0 => $spec['file']));
if (!empty($spec['token'])) {
	$_SESSION['diag_csrf'] = 'tok123';
}
$_GET = array('home_id' => '1', 'item' => '0', 'p' => 'analyze_log', 'name' => $spec['file'], 'type' => 'file');
$_POST = isset($spec['post']) ? $spec['post'] : array();
$_REQUEST = $_GET + $_POST;
$_SERVER['REQUEST_METHOD'] = isset($spec['method']) ? $spec['method'] : 'GET';
$db = new FakeDb($spec);
$GLOBALS['db'] = $db;
ini_set('display_errors', '0');

ob_start();
include 'modules/litefm/analyze_log.php';
exec_module();
$out = ob_get_clean();
echo json_encode(array('out' => $out, 'audit' => $db->audit, 'logs' => $db->logs, 'cwd' => $_SESSION['fm_cwd_1'] ?? null));
