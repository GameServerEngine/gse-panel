<?php
// Runs one Utilities page with a fake database and prints what it outputs. Used by util_pages_test.php.
// Usage: php page_harness.php '<json spec>'   spec: page, role, get, post, method, token, agent_port
$spec = json_decode($argv[1], true);
chdir(dirname(__DIR__, 3));
$GLOBALS['LangPre'] = 'LANG_';
foreach (array('global', 'modules/util') as $file) {
	include_once 'lang/English/' . $file . '.php';
}
function get_lang($k) { return defined('LANG_' . $k) ? constant('LANG_' . $k) : '_' . $k . '_'; }
function get_lang_f() { $a = func_get_args(); return vsprintf(get_lang(array_shift($a)), $a); }
function getClientIPAddress() { return '192.0.2.50'; }
function print_success($t) { echo '<p class="success">' . $t . '</p>'; }
function print_failure($t, $c = 'failure') { echo '<p class="' . $c . '">' . $t . '</p>'; }

class FakeDb
{
	public $audit = array();
	public $logs = array();
	public $saved = array();
	public $role;
	public $agent_port;

	public function __construct($role, $agent_port)
	{
		$this->role = $role;
		$this->agent_port = $agent_port;
	}
	public function getUserById($id) { return array('users_role' => $this->role, 'user_id' => $id); }
	public function getSettings() { return $this->saved; }
	public function setSettings($s) { $this->saved = $s + $this->saved; return true; }
	public function getIpPortsForUser($id)
	{
		return array(array('remote_server_id' => 3, 'ip' => '198.51.101.7', 'port' => 27015, 'home_id' => 1, 'home_name' => 'My CS Server', 'home_cfg_file' => 'cstrike.xml', 'server_os' => 'linux'));
	}
	public function getRemoteServers()
	{
		return array(array('remote_server_id' => 3, 'remote_server_name' => 'Agent Three', 'agent_ip' => '127.0.0.1', 'agent_port' => $this->agent_port, 'encryption_key' => 'SECRETKEY-3'),
			array('remote_server_id' => 4, 'remote_server_name' => 'Agent Four', 'agent_ip' => '127.0.0.1', 'agent_port' => $this->agent_port, 'encryption_key' => 'SECRETKEY-4'));
	}
	public function getRemoteServerById($id)
	{
		return array('agent_ip' => '127.0.0.1', 'agent_port' => $this->agent_port, 'encryption_key' => 'SECRETKEY', 'timeout' => 5);
	}
	public function realEscapeSingle($v) { return addslashes((string)$v); }
	public function resultInsertId($table, $row) { $this->audit[] = $row; return count($this->audit) - 1; }
	public function query($sql)
	{
		if (preg_match("/SET `status`='([a-z_]+)'.*WHERE `id`=([0-9]+)/s", $sql, $m)) {
			$this->audit[(int)$m[2]]['status'] = $m[1];
		}
		return true;
	}
	public function resultQuery($sql) { return array(array('n' => 0)); }
	public function logger($m) { $this->logs[] = $m; }
}

session_name('gameserverpanel_web');
$_SESSION = array('user_id' => 5);
if (!empty($spec['token'])) {
	$_SESSION['diag_csrf'] = 'tok123';
}
$_GET = isset($spec['get']) ? $spec['get'] : array();
$_POST = isset($spec['post']) ? $spec['post'] : array();
$_REQUEST = $_GET + $_POST;
$_SERVER['REQUEST_METHOD'] = isset($spec['method']) ? $spec['method'] : 'GET';
if (!empty($spec['header_token'])) {
	$_SERVER['HTTP_X_CSRF_TOKEN'] = $spec['header_token'];
}
$db = new FakeDb($spec['role'], isset($spec['agent_port']) ? $spec['agent_port'] : 1);
$GLOBALS['db'] = $db;
define('DB_PREFIX', 'gsp_');
define('MODULES', 'modules/');

ob_start();
include 'modules/util/' . $spec['page'] . '.php';
exec_module();
$out = ob_get_clean();
echo json_encode(array('out' => $out, 'audit' => $db->audit, 'logs' => $db->logs, 'saved' => $db->saved));
