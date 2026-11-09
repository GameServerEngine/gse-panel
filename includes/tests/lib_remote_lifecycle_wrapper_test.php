<?php

require_once(dirname(__DIR__) . '/lib_remote.php');

class LifecycleWrapperFakeRemote extends RemoteLibrary
{
	private $restartResult;

	public function __construct($restartResult)
	{
		$this->restartResult = $restartResult;
	}

	public function remote_restart_server_result($home_id, $server_ip, $server_port,
			$control_protocol, $control_password, $control_type,
			$home_path, $server_exe, $run_dir, $cmd, $cpu, $nice, $preStart, $envVars, $game_key, $console_log = "", $restart_reason = "", $lifecycle_payload = null)
	{
		return $this->restartResult;
	}
}

function lib_remote_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

$remote = new LifecycleWrapperFakeRemote(array('success' => 1, 'status' => 'restarted', 'message' => 'Restarted.'));
lib_remote_test_assert(
	$remote->remote_restart_server(42, '192.0.2.10', 2302, 'rcon', 'secret', 'tcp', '/srv/gse/42', 'server', 'game/run', 'cmd', 'NA', '0', '', '', 'game_key') === 1,
	'legacy restart wrapper maps structured success to scalar success'
);

$remote = new LifecycleWrapperFakeRemote(array('success' => 0, 'raw_response' => -2));
lib_remote_test_assert(
	$remote->remote_restart_server(42, '192.0.2.10', 2302, 'rcon', 'secret', 'tcp', '/srv/gse/42', 'server', 'game/run', 'cmd', 'NA', '0', '', '', 'game_key') === -2,
	'legacy restart wrapper preserves stop-phase failure code'
);

$remote = new LifecycleWrapperFakeRemote(array('success' => 0, 'raw_response' => -1));
lib_remote_test_assert(
	$remote->remote_restart_server(42, '192.0.2.10', 2302, 'rcon', 'secret', 'tcp', '/srv/gse/42', 'server', 'game/run', 'cmd', 'NA', '0', '', '', 'game_key') === -1,
	'legacy restart wrapper preserves start-phase failure code'
);

echo "All lib_remote lifecycle wrapper tests passed.\n";

?>
