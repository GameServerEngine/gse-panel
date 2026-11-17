<?php
// Analyze Log page: access rules, output, escaping, AI opt-in. Fake database, fake agent process (real XML-RPC).
// Run: php modules/litefm/tests/analyze_log_page_test.php   (skips with exit 0 without the xmlrpc extension)
require_once dirname(__DIR__, 3) . '/includes/tests/diag_test_helper.php';
if (!function_exists('xmlrpc_encode_request')) {
	echo "SKIP: needs the xmlrpc extension\n";
	exit(0);
}

$key = 'litefm-test-key!';
$log = tempnam(sys_get_temp_dir(), 'gse') . '_console.log';
$lines = "[10:00:00] Server started\n<script>alert(1)</script> error loading\n" . str_repeat("Connection refused to 10.0.0.9\n", 6)
	. "./srcds: error while loading shared libraries: libtier0.so: cannot open shared object file\nrcon_password \"topsecret99\"\nSegmentation fault (core dumped)\n";
file_put_contents($log, $lines);

$port = 0;
do {
	$s = stream_socket_server('tcp://127.0.0.1:0');
	$port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
	fclose($s);
} while ($port === 0);
$router = dirname(__DIR__, 3) . '/includes/tests/fixtures/fake_diag_agent.php';
$proc = proc_open('php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router), array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a')), $pipes, null,
	array('FAKE_AGENT_KEY' => $key, 'FAKE_AGENT_LOGFILE' => $log, 'PATH' => getenv('PATH')));
register_shutdown_function(function () use ($proc, $log) {
	proc_terminate($proc);
	@unlink($log);
});
usleep(800000);

function page($spec)
{
	global $key, $port, $log;
	$spec += array('agent_port' => $port, 'key' => $key, 'rights' => 'uf', 'settings' => array(), 'file' => basename($log));
	$raw = shell_exec('php ' . escapeshellarg(__DIR__ . '/analyze_log_harness.php') . ' ' . escapeshellarg(json_encode($spec)) . ' 2>/dev/null');
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		fwrite(STDERR, "Harness output was not JSON:\n$raw\n");
		exit(1);
	}
	return $data;
}

$r = page(array());
$out = $r['out'];
check('page: crash found with severity and count', strpos($out, 'Critical: Server crash (1 lines)') !== false);
check('page: missing library found', strpos($out, 'Missing library or dependency') !== false);
check('page: repeated connection failures counted', strpos($out, 'Connection failed (6 lines)') !== false);
check('page: next steps shown', strpos($out, 'Next steps:') !== false);
check('page: log markup is escaped, never printed raw', strpos($out, '<script>') === false && strpos($out, '&lt;script&gt;') !== false);
check('page: verdict box uses theme classes', strpos($out, 'class="failure"') !== false);
check('page: audit log line written', count($r['logs']) === 1 && strpos($r['logs'][0], 'Analyzed log') !== false);
check('page: file manager folder restored after reading', $r['cwd'] === '');
check('page: no AI section when no provider is configured', strpos($out, 'AI analysis') === false && strpos($out, 'Analyze with AI') === false);

$r = page(array('rights' => 'u'));
check('access: user without the file management right is refused', strpos($r['out'], 'Next steps') === false && strpos($r['out'], 'failure') !== false);
$r = page(array('file' => 'server.cfg'));
check('type: config file is not analyzed', strpos($r['out'], 'cannot be analyzed') !== false);
$r = page(array('agent_port' => 1));
check('agent offline: clear failure', strpos($r['out'], 'failure') !== false && strpos($r['out'], 'Segmentation') === false);

// --- AI opt-in
$ai = array('log_ai_provider' => 'openai', 'log_ai_endpoint' => 'https://127.0.0.1:1/v1/chat', 'log_ai_model' => 'm', 'log_ai_key' => 'SECRETAPIKEY');
$r = page(array('settings' => $ai));
$out = $r['out'];
check('ai: section offered when configured, names the destination', strpos($out, 'Analyze with AI') !== false && strpos($out, '127.0.0.1') !== false);
preg_match('#<details>.*?</details>#s', $out, $preview);
check('ai: preview of the sent text is redacted', isset($preview[0]) && strpos($preview[0], 'topsecret99') === false && strpos($preview[0], '10.0.0.9') === false && strpos($preview[0], '[redacted]') !== false && strpos($preview[0], '[ip]') !== false);
check('ai: API key never in the page', strpos($out, 'SECRETAPIKEY') === false);
check('ai: needs an explicit confirmation checkbox', strpos($out, 'name="ai_confirm" value="1" required') !== false);
check('ai: nothing was sent just by opening the page', array_filter($r['audit'], function ($a) { return $a['tool'] === 'log_ai'; }) === array());

$r = page(array('settings' => $ai, 'method' => 'POST', 'post' => array('ai_send' => '1', 'ai_confirm' => '1')));
check('ai: POST without CSRF token is refused and nothing is sent', strpos($r['out'], 'Not sent') !== false && array_filter($r['audit'], function ($a) { return $a['tool'] === 'log_ai'; }) === array());
$r = page(array('settings' => $ai, 'method' => 'POST', 'token' => true, 'post' => array('ai_send' => '1', 'csrf' => 'tok123')));
check('ai: POST without the confirmation is refused', strpos($r['out'], 'Not sent') !== false);
$r = page(array('settings' => $ai, 'method' => 'POST', 'token' => true, 'post' => array('ai_send' => '1', 'ai_confirm' => '1', 'csrf' => 'tok123')));
check('ai: confirmed send is audited and the provider failure is reported', strpos($r['out'], 'AI provider answered with an error') !== false);
$audited = array_values(array_filter($r['audit'], function ($a) { return $a['tool'] === 'log_ai'; }));
check('ai: audit row names the provider host', count($audited) === 1 && $audited[0]['target'] === '127.0.0.1' && $audited[0]['user_id'] === 5);
check('ai: transmission written to the panel log', count(array_filter($r['logs'], function ($l) { return strpos($l, 'sent to external AI provider') !== false; })) === 1);
echo "All Analyze Log page tests passed\n";
