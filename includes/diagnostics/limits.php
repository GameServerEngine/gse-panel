<?php
/*
 * Rate limits, concurrency caps and the audit trail for diagnostics.
 * One table does all three jobs: DB_PREFIXdiag_audit. Every attempt is a row, written
 * BEFORE the agent is called with status 'running', then finished. Because the row exists
 * before the counts are read, two requests at the same moment both see each other
 * and the cap holds without table locks.
 */

define('DIAG_RUNNING_EXPIRES_S', 120);

// Settings names -> defaults. The admin can change them in the panel settings table.
function diag_limit_settings($settings)
{
	$defaults = array(
		'diag_rate_window_s' => 300,
		'diag_rate_per_user' => 12,
		'diag_rate_per_agent' => 30,
		'diag_running_per_user' => 1,
		'diag_running_per_agent' => 3,
	);
	$out = array();
	foreach ($defaults as $name => $default) {
		$out[$name] = (isset($settings[$name]) && ctype_digit((string)$settings[$name]) && (int)$settings[$name] > 0)
			? (int)$settings[$name] : $default;
	}
	return $out;
}

// Store on the panel database. Same methods as the in-memory store the tests use.
class DiagAuditDb
{
	private $db;

	public function __construct($db)
	{
		$this->db = $db;
	}

	public function insert($row)
	{
		return (int)$this->db->resultInsertId('diag_audit', $row);
	}

	public function update($id, $fields)
	{
		$sets = array();
		foreach ($fields as $name => $value) {
			$sets[] = '`' . $name . "`='" . $this->db->realEscapeSingle($value) . "'";
		}
		return $this->db->query('UPDATE `DB_PREFIXdiag_audit` SET ' . implode(',', $sets) . ' WHERE `id`=' . (int)$id);
	}

	// $filter keys (all optional): user_id, remote_server_id, statuses[], since (unix time).
	public function count_rows($filter)
	{
		$where = array('1=1');
		if (isset($filter['user_id'])) {
			$where[] = '`user_id`=' . (int)$filter['user_id'];
		}
		if (isset($filter['remote_server_id'])) {
			$where[] = '`remote_server_id`=' . (int)$filter['remote_server_id'];
		}
		if (isset($filter['since'])) {
			$where[] = '`created_at`>=' . (int)$filter['since'];
		}
		if (!empty($filter['statuses'])) {
			$list = array();
			foreach ($filter['statuses'] as $status) {
				$list[] = "'" . $this->db->realEscapeSingle($status) . "'";
			}
			$where[] = '`status` IN (' . implode(',', $list) . ')';
		}
		$rows = $this->db->resultQuery('SELECT COUNT(*) AS n FROM `DB_PREFIXdiag_audit` WHERE ' . implode(' AND ', $where));
		return is_array($rows) && isset($rows[0]['n']) ? (int)$rows[0]['n'] : 0;
	}
}

// Writes the attempt row. Returns the row id.
function diag_audit_start($store, $info, $now)
{
	return $store->insert(array(
		'created_at' => $now,
		'user_id' => (int)$info['user_id'],
		'remote_server_id' => (int)$info['remote_server_id'],
		'tool' => substr((string)$info['tool'], 0, 32),
		'target' => substr((string)$info['target'], 0, 255),
		'port' => (int)$info['port'],
		'protocol' => substr((string)$info['protocol'], 0, 16),
		'status' => 'running',
		'outcome' => '',
		'duration_ms' => 0,
		'detail' => '',
		'client_ip' => substr((string)$info['client_ip'], 0, 64),
	));
}

// status: ok, failed, denied, rate_limited, unsupported.
function diag_audit_finish($store, $id, $status, $outcome, $duration_ms, $detail)
{
	$store->update($id, array(
		'status' => $status,
		'outcome' => substr((string)$outcome, 0, 40),
		'duration_ms' => (int)$duration_ms,
		'detail' => substr((string)$detail, 0, 255),
	));
}

// Checks the caps for the row just written. Returns '' when the run may go ahead,
// otherwise a code: busy_user, busy_agent, rate_user, rate_agent.
function diag_guard_check($store, $user_id, $agent_id, $now, $limits)
{
	$running_since = $now - DIAG_RUNNING_EXPIRES_S;
	if ($store->count_rows(array('user_id' => $user_id, 'statuses' => array('running'), 'since' => $running_since)) > $limits['diag_running_per_user']) {
		return 'busy_user';
	}
	if ($store->count_rows(array('remote_server_id' => $agent_id, 'statuses' => array('running'), 'since' => $running_since)) > $limits['diag_running_per_agent']) {
		return 'busy_agent';
	}
	$since = $now - $limits['diag_rate_window_s'];
	$counted = array('running', 'ok', 'failed', 'denied');
	if ($store->count_rows(array('user_id' => $user_id, 'statuses' => $counted, 'since' => $since)) > $limits['diag_rate_per_user']) {
		return 'rate_user';
	}
	if ($store->count_rows(array('remote_server_id' => $agent_id, 'statuses' => $counted, 'since' => $since)) > $limits['diag_rate_per_agent']) {
		return 'rate_agent';
	}
	return '';
}

function diag_guard_message($code)
{
	$messages = array(
		'busy_user' => 'You already have a test running. Wait for it to finish.',
		'busy_agent' => 'This agent is busy with other tests. Try again in a moment.',
		'rate_user' => 'Too many tests in a short time. Wait a few minutes and try again.',
		'rate_agent' => 'This agent has had too many tests in a short time. Try again in a few minutes.',
	);
	return isset($messages[$code]) ? $messages[$code] : 'Not allowed right now.';
}
