<?php
/*
 * File Manager: Analyze Log. Reads the end of a log file through the agent and shows what the
 * rule-based analyzer found. Same access rules as the editor (fm_read.php): the user needs the
 * file management right on the game server. Nothing in the file is ever executed.
 * Optional AI analysis happens only on an explicit button press, after redaction, and only when
 * an admin configured a provider (Utilities > Diagnostics policy).
 */
require_once(MODULES . "/litefm/litefm.php");
require_once('includes/diagnostics/log_analyzer.php');
require_once('includes/diagnostics/csrf.php');
require_once('includes/diagnostics/limits.php');

// The game server the user may open files on, or null after printing the reason.
function analyze_log_home($home_id)
{
	global $db;
	if (empty($home_id)) {
		print_failure(get_lang('home_id_missing'));
		return null;
	}
	$home_cfg = $db->isAdmin($_SESSION['user_id']) ? $db->getGameHome($home_id) : $db->getUserGameHome($_SESSION['user_id'], $home_id);
	$path_error = '';
	$home_cfg = is_array($home_cfg) ? litefm_attach_game_root($home_cfg, $path_error) : false;
	if ($home_cfg === false) {
		print_failure(get_lang('no_access_to_home'));
		return null;
	}
	if (preg_match("/f/", $home_cfg['access_rights']) != 1) {
		print_failure(get_lang('no_rights'));
		return null;
	}
	return $home_cfg;
}

function analyze_log_severity_label($severity)
{
	$labels = array('critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info');
	return $labels[$severity];
}

function analyze_log_render_findings($result)
{
	$verdict = log_verdict($result['findings']);
	$kinds = array('critical' => 'failure', 'error' => 'failure', 'warning' => 'warning', 'info' => 'success');
	$text = $verdict === 'info' ? 'No known problem patterns were found.' : 'Most serious finding: ' . analyze_log_severity_label($verdict) . '.';
	echo '<div class="' . $kinds[$verdict] . '" role="status">' . litefm_escape_html($text) . '</div>';
	foreach ($result['findings'] as $finding) {
		echo '<div class="util-finding"><h4>' . litefm_escape_html(analyze_log_severity_label($finding['severity']) . ': ' . $finding['title']) . ' (' . (int)$finding['count'] . ' lines)</h4>';
		echo '<pre>' . litefm_escape_html(implode("\n", $finding['samples'])) . '</pre>';
		echo '<p><strong>Next steps:</strong> ' . litefm_escape_html($finding['advice']) . '</p></div>';
	}
	if ($result['repeated']) {
		echo '<h4>Repeated events</h4><ul>';
		foreach ($result['repeated'] as $event) {
			echo '<li>' . (int)$event['count'] . ' times: <code>' . litefm_escape_html($event['sample']) . '</code></li>';
		}
		echo '</ul>';
	}
}

// The AI part: a form with a clear warning, or the answer after the user confirmed.
function analyze_log_ai_section($config, $text, $url, $home_cfg)
{
	$host = parse_url($config['endpoint'], PHP_URL_HOST);
	$redacted = log_redact($text);
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_send'])) {
		analyze_log_ai_send($config, $redacted['text'], $home_cfg);
		return;
	}
	echo '<h4>AI analysis (optional)</h4>';
	echo '<p>This sends a redacted copy of the analyzed part of the log to <strong>' . litefm_escape_html($host) . '</strong>. Passwords, tokens, e-mail addresses, IP addresses, Steam IDs and user folder names are replaced first. Nothing is sent unless you press the button.</p>';
	echo '<details><summary>Show exactly what would be sent (first 20 KB)</summary><pre>' . litefm_escape_html(substr($redacted['text'], 0, 20480)) . '</pre></details>';
	echo '<form method="post" action="' . litefm_escape_html($url) . '"><input type="hidden" name="csrf" value="' . litefm_escape_html(diag_csrf_token()) . '">';
	echo '<label><input type="checkbox" name="ai_confirm" value="1" required> I understand this log text leaves the panel and goes to an external service.</label> ';
	echo '<button type="submit" name="ai_send" value="1">Analyze with AI</button></form>';
}

function analyze_log_ai_send($config, $redacted_text, $home_cfg)
{
	global $db;
	if (!diag_csrf_valid(diag_csrf_from_request()) || empty($_POST['ai_confirm'])) {
		print_failure('Not sent: confirm the box and try again.');
		return;
	}
	$store = new DiagAuditDb($db);
	$settings = diag_limit_settings($db->getSettings());
	$info = array('user_id' => $_SESSION['user_id'], 'remote_server_id' => $home_cfg['remote_server_id'], 'tool' => 'log_ai', 'target' => parse_url($config['endpoint'], PHP_URL_HOST),
		'port' => 0, 'protocol' => 'https', 'client_ip' => getClientIPAddress());
	$id = diag_audit_start($store, $info, time());
	$blocked = diag_guard_check($store, $_SESSION['user_id'], $home_cfg['remote_server_id'], time(), $settings);
	if ($blocked !== '') {
		diag_audit_finish($store, $id, 'rate_limited', $blocked, 0, '');
		print_failure(litefm_escape_html(diag_guard_message($blocked)));
		return;
	}
	$start = hrtime(true);
	$answer = log_ai_run($config, $redacted_text, 'log_ai_http');
	diag_audit_finish($store, $id, $answer['ok'] ? 'ok' : 'failed', $answer['ok'] ? 'sent' : 'error', (int)((hrtime(true) - $start) / 1000000), '');
	$db->logger('Log text sent to external AI provider for home ' . (int)$home_cfg['home_id']);
	echo '<h4>AI analysis</h4>' . ($answer['ok'] ? '<pre>' : '<p class="failure">') . litefm_escape_html($answer['text']) . ($answer['ok'] ? '</pre><p>Generated by an AI service. It can be wrong; check it against the log.</p>' : '</p>');
}

function exec_module()
{
	global $db;
	$home_cfg = analyze_log_home(isset($_REQUEST['home_id']) ? $_REQUEST['home_id'] : '');
	if ($home_cfg === null || litefm_check($home_cfg['home_id']) === FALSE) {
		return;
	}
	$home_id = $home_cfg['home_id'];
	$rel_path = isset($_SESSION['fm_cwd_' . $home_id]) ? $_SESSION['fm_cwd_' . $home_id] : '';
	$filepath = litefm_safe_join_home_path(litefm_payload_root($home_cfg), $rel_path);
	if ($filepath === false) {
		print_failure(get_lang('unallowed_char'));
		return;
	}
	// litefm_check() pointed the File Manager at the file. Point it back at the folder, as get_file.php does.
	$_SESSION['fm_cwd_' . $home_id] = litefm_parent_relative_path($rel_path);
	echo '<h2>' . litefm_escape_html(get_lang('analyze_log')) . '</h2>';
	echo '<p>' . litefm_escape_html(basename($rel_path)) . '</p>';
	if (!log_file_supported(basename($rel_path))) {
		print_failure('This file type cannot be analyzed. Supported: .log, .txt, .out, .err, .crash, .rpt');
		return;
	}
	$remote = new RemoteLibrary($home_cfg['agent_ip'], $home_cfg['agent_port'], $home_cfg['encryption_key'], $home_cfg['timeout']);
	$read = log_read_tail($remote, $filepath);
	if (!$read['ok']) {
		print_failure(litefm_escape_html($read['message']));
		return;
	}
	$db->logger('Analyzed log ' . basename($rel_path) . ' for home ' . (int)$home_id);
	$result = log_analyze($read['text']);
	echo '<p>Analyzed ' . (int)$result['lines'] . ' lines (' . strlen($read['text']) . ' bytes)' . ($read['size'] > LOG_MAX_BYTES ? ' from the end of a ' . (int)$read['size'] . ' byte file; older lines were not read' : '') . '.' . ($result['truncated'] ? ' Only the last ' . LOG_MAX_LINES . ' lines were used.' : '') . '</p>';
	analyze_log_render_findings($result);
	$config = log_ai_config($db->getSettings());
	if ($config !== null) {
		$url = '?m=litefm&p=analyze_log&home_id=' . (int)$home_id . '&item=' . (int)$_GET['item'] . '&name=' . rawurlencode(isset($_GET['name']) ? $_GET['name'] : '') . '&type=file';
		analyze_log_ai_section($config, $read['text'], $url, $home_cfg);
	}
	echo '<p><a href="?m=litefm&amp;home_id=' . (int)$home_id . '">&laquo; ' . litefm_escape_html(get_lang('back')) . '</a></p>';
}
